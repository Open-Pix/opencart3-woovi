<?php

/**
 * Invoked by the Woovi API.
 *
 * @property Loader $load
 * @property Request $request
 * @property Response $response
 * @property Language $language
 * @property Config $config
 * @property ModelCheckoutOrder $model_checkout_order
 * @property ModelExtensionPaymentWooviOrder $model_extension_payment_woovi_order
 * @property ModelExtensionPaymentWooviWebhooks $model_extension_payment_woovi_webhooks
 * @property \OpenPix\PhpSdk\Client $woovi_api_client
 * @property \Woovi\Opencart\Logger $woovi_logger
 *
 * @phpstan-type TestWebhookPayload array{event: self::TEST_WEBHOOK_EVENT}
 * @phpstan-type OpencartConfigurePayload array{event: self::OPENCART_CONFIGURE_EVENT, appID: string}
 * @phpstan-type ChargeCompletedPayload array{event: self::OPENPIX_CHARGE_COMPLETED_EVENT, charge: array{correlationID: string}}
 */
class ControllerExtensionWooviPaymentWooviWebhooks extends Controller
{
    /**
     * Called when testing webhooks.
     */
    public const TEST_WEBHOOK_EVENT = "teste_webhook";

    /**
     * Called when client configure plugin on Woovi platform.
     */
    public const OPENCART_CONFIGURE_EVENT = "opencart-configure";

    /**
     * Charge completed is when a charge is fully paid.
     */
    public const OPENPIX_CHARGE_COMPLETED_EVENT = "OPENPIX:CHARGE_COMPLETED";

    /**
     * Charge completed is when a charge is fully paid.
     */
    public const OPENPIX_TRANSACTION_RECEIVED = "OPENPIX:TRANSACTION_RECEIVED";

    /**
     * Load dependencies.
     */
    private function load(): void
    {
        $this->load->library("woovi"); // Setup API client.
        $this->load->language("extension/payment/woovi");
        $this->load->model("extension/payment/woovi_order");
        $this->load->model("extension/payment/woovi_webhooks");
        $this->load->model("checkout/order");
    }

    /**
     * Just an alias to the `callback` method.
     */
    public function index(): void
    {
        $this->callback();
    }

    /**
     * Executed when an event that we are monitoring in the API occurs,
     * such as a PIX transaction received by it.
     */
    public function callback(): void
    {
        $this->load();

        $payload = $this->getValidatedWebhookPayload();

        if (is_null($payload)) {
            return;
        }

        $this->handleWebhookEvents($payload);
    }

    /**
     * Dispatch webhook to appropriate handler.
     *
     * @param array<array-key, mixed> $payload
     */
    private function handleWebhookEvents(array $payload): void
    {
        if ($this->isValidTestWebhookPayload($payload)) {
            $this->handleTestWebhook();
            return;
        }

        if ($this->isValidOpencartConfigurePayload($payload)) {
            $this->handleOpencartConfigureWebhook($payload);
            return;
        }

        if ($this->isValidChargeCompletedPayload($payload)) {
            $this->handleChargeCompletedWebhook($payload);
            return;
        }

        $this->emitWebhookInvalidPayloadError();
    }

    /**
     * Handle test webhook.
     */
    private function handleTestWebhook(): void
    {
        $this->emitJson(["message" => "Success."]);
        $this->woovi_logger->debug("Test Webhook received.", "catalog/webhooks");
    }

    /**
     * Executed when an user configure plugin on Woovi platform.
     *
     * @param OpencartConfigurePayload $payload
     */
    private function handleOpencartConfigureWebhook(array $payload): void
    {
        $appId = $payload["appID"];

        // Do not allow new AppID if you already have one set up.
        $currentAppId = trim($this->config->get("payment_woovi_app_id"));
        $alreadyHasAppId = ! empty($currentAppId);

        if ($alreadyHasAppId) {
            $this->emitJson(["error" => "App ID already configured."], 400);
            return;
        }

        $this->model_extension_payment_woovi_webhooks->configureAppId($appId);

        $this->woovi_logger->debug("Webhook configured.", "catalog/webhooks");
        $this->emitJson(["message" => "Success."]);
    }

    /**
     * Executed when an charge is completed.
     *
     * @param ChargeCompletedPayload $payload
     */
    private function handleChargeCompletedWebhook(array $payload): void
    {
        $correlationID = $payload["charge"]["correlationID"];

        $order = $this->model_extension_payment_woovi_order->getOpencartOrderByCorrelationID($correlationID);

        if (empty($order)) {
            $this->woovi_logger->notice("Cound not find OpenCart order with correlation ID `" . $correlationID . "`", "catalog/webhooks");
            $this->emitJson(["error" => "Order not found."], 404);

            return;
        }

        $orderStatusId = $order["order_status_id"];

        /** @var string $newStatusId */
        $newStatusId = $this->config->get("payment_woovi_order_status_when_paid_id");

        // Ignore webhook event if order is already on paid status.
        if ($newStatusId == $orderStatusId) {
            $this->emitJson(["message" => "Success."]);
            $this->woovi_logger->debug("Order already paid: " . $order["order_id"], "catalog/webhooks");

            return;
        }

        $comment = $this->language->get("The payment was confirmed by Woovi.");
        $notifyCustomer = !! $this->config->get("payment_woovi_notify_customer");

        $this->model_checkout_order->addOrderHistory(
            intval($order["order_id"]),
            intval($newStatusId),
            $comment,
            $notifyCustomer
        );

        $this->emitJson(["message" => "Success."]);
    }

    /**
     * Validates the webhook request and returns validated data or `null` if an error occurs.
     *
     * @return ?array<array-key, mixed>
     */
    private function getValidatedWebhookPayload(): ?array
    {
        $rawPayload = strval(file_get_contents("php://input"));

        /** @var array<array-key, mixed> $rawHeaders */
        $rawHeaders = getallheaders();

        $headers = array_change_key_case($rawHeaders, CASE_LOWER);

        $signature = $headers["x-webhook-signature"] ?? "";
        $signature = is_string($signature) ? $signature : "";

        if (! $this->isValidSignature($rawPayload, $signature)) {
            $serializedRequest = json_encode($this->request, JSON_PRETTY_PRINT);

            $this->woovi_logger->warning(
                "Invalid webhook signature from request " . $serializedRequest,
                "catalog/webhooks"
            );

            $this->emitJson(["error" => "Invalid webhook signature."], 401);
            return null;
        }

        $payload = json_decode($rawPayload, true);
        $isJsonInvalid = json_last_error() !== JSON_ERROR_NONE;

        if ($isJsonInvalid || ! is_array($payload)) {
            $this->emitWebhookInvalidPayloadError();
            return null;
        }

        if ($this->isPixDetachedPayload($payload)) {
            $this->emitJson(["error" => "Pix Detached."]);
            return null;
        }

        return $payload;
    }

    /**
     * Emit an webhook invalid payload error.
     */
    private function emitWebhookInvalidPayloadError(): void
    {
        $serializedRequest = json_encode($this->request, JSON_PRETTY_PRINT);

        $this->woovi_logger->warning(
            "Invalid webhook payload from request " . $serializedRequest,
            "catalog/webhooks"
        );

        $this->emitJson(["error" => "Invalid webhook payload."], 400);
    }

    /**
     * Check if webhook signature is valid.
     */
    private function isValidSignature(string $rawPayload, string $signature): bool
    {
        if (empty($signature) || ! $this->woovi_api_client->webhooks()->isWebhookValid($rawPayload, $signature)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if it is an valid webhook test payload.
     *
     * @param array<array-key, mixed> $payload
     * @phpstan-assert-if-true TestWebhookPayload $payload
     */
    private function isValidTestWebhookPayload(array $payload): bool
    {
        return ! empty($payload["evento"])
            && $payload["evento"] === self::TEST_WEBHOOK_EVENT;
    }

    /**
     * Checks if it is an valid webhook payload for "opencart-configure" event.
     *
     * @param array<array-key, mixed> $payload
     * @phpstan-assert-if-true OpencartConfigurePayload $payload
     */
    private function isValidOpencartConfigurePayload(array $payload): bool
    {
        return ! empty($payload["event"])
            && $payload["event"] === self::OPENCART_CONFIGURE_EVENT
            && ! empty($payload["appID"])
            && is_string($payload["appID"]);
    }

    /**
     * Checks if it is an valid webhook payload for charge completed event.
     *
     * @param array<array-key, mixed> $payload
     * @phpstan-assert-if-true ChargeCompletedPayload $payload
     */
    private function isValidChargeCompletedPayload(array $payload): bool
    {
        $isChargePaidPayload =
            $payload["event"] === self::OPENPIX_CHARGE_COMPLETED_EVENT
            || $payload["event"] === self::OPENPIX_TRANSACTION_RECEIVED;

        return ! empty($payload["event"])
            && $isChargePaidPayload
            && ! empty($payload["charge"]["correlationID"])
            && is_string($payload["charge"]["correlationID"]);
    }

    /**
     * Checks if the payload is of an detached pix.
     *
     * @param array<array-key, mixed> $payload
     */
    private function isPixDetachedPayload(array $payload): bool
    {
        if (empty($payload["pix"])) {
            return false;
        }

        if (! empty($payload["charge"]) && ! empty($payload["charge"]["correlationID"])) {
            return false;
        }

        return true;
    }

    /**
     * Encode given data as JSON and emit it with correct `Content-type`.
     *
     * @param mixed $data
     */
    private function emitJson($data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        $this->response->addHeader("Content-Type: application/json");
        $this->response->setOutput(strval(json_encode($data)));
    }
}
