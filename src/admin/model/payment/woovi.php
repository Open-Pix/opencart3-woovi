<?php

namespace Opencart\Admin\Model\Extension\Woovi\Payment;

use Opencart\System\Engine\Model;

/**
 * This model integrates the extension with the OpenCart database.
 *
 * This adds the events and creates a table of relationships between orders and charges, for example.
 * 
 * @property \Opencart\System\Library\DB $db
 * @property \Opencart\System\Engine\Loader $load
 * @property \Opencart\Admin\Model\Customer\CustomField $model_customer_custom_field
 * @property \Opencart\Admin\Model\Setting\Setting $model_setting_setting
 * @property \Opencart\Admin\Model\Setting\Event $model_setting_event
 * @property \Opencart\Admin\Model\Localisation\Language $model_localisation_language
 * @property \Opencart\Admin\Model\Setting\Extension $model_setting_extension
 * @property \Opencart\System\Engine\Config $config
 */
class Woovi extends Model
{
    /**
     * Regex for validating CPF/CNPJ field format.
     */
    private const TAX_ID_CUSTOM_FIELD_VALIDATION_REGEX = "/(^\d{3}\.\d{3}\.\d{3}\-\d{2}$)|(^\d{11}$)|(^\d{2}\.\d{3}\.\d{3}\/\d{4}\-\d{2}$)/";

    /**
     * Installs the necessary structures for the extension to work, such as the table to track charges on orders.
     */
    public function install(): void
    {
        $this->installEvents();
        $this->createWooviOrderTable();
        $this->installSettings();
        $this->installCustomFields();
        $this->upgrade();
    }

    /**
     * Executed when the extension is uninstalled.
     */
    public function uninstall(): void
    {
        $this->uninstallEvents();
    }

    /**
     * Install custom fields like CPF/CNPJ.
     */
    private function installCustomFields(): void
    {
        $fingerprintSettings = $this->model_setting_setting->getSetting("thirdparty_payment_woovi");
        $settings = $this->model_setting_setting->getSetting("payment_woovi");

        // Does not create if the extension has already been installed.
        if (! empty($fingerprintSettings)) return;

        $this->load->model("customer/custom_field");
        $this->load->model("localisation/language");

        // Add same name for all languages.
        $descriptions = [];

        $languageIds = array_values(array_map(
            fn ($language) => intval($language["language_id"]), $this->model_localisation_language->getLanguages()
        ));

        foreach ($languageIds as $languageId) {
            $descriptions[$languageId] = [
                "name" => "CPF/CNPJ",
            ];
        }

        // Use default customer group ID.
        $customerGroupId = $this->config->get("config_customer_group_id");

        $taxIdCustomFieldId = $this->model_customer_custom_field->addCustomField([
            "custom_field_description" => $descriptions,
            "type" => "text",
            "validation" => self::TAX_ID_CUSTOM_FIELD_VALIDATION_REGEX,
            "location" => "account",
            "status" => 1,
            "sort_order" => "",
            "value" => "",
            "custom_field_customer_group" => [
                [
                    "customer_group_id" => $customerGroupId,
                    "required" => true,
                ],
            ],
        ]);

        // Store setting.
        $settings["payment_woovi_tax_id_custom_field_id"] = $taxIdCustomFieldId;

        $this->model_setting_setting->editSetting("payment_woovi", $settings);
    }

    /**
     * Install default settings like order statuses ID's.
     */
    private function installSettings(): void
    {
        $this->load->model("setting/setting");

        $settings = $this->model_setting_setting->getSetting("payment_woovi");

        // Use OpenCart configured order status.
        // Otherwise, use "pending" as default waiting status.
        // On OpenCart installer, the pending status ID is 1:
        // https://github.com/opencart/opencart/blob/e3ae482e66671167b44f86e798f07f8084561117/upload/install/opencart.sql#L1578
        $orderStatusWhenWaitingId = $settings["payment_woovi_order_status_when_waiting_id"] ?? "";
        
        if (empty($orderStatusWhenWaitingId)) {
            $orderStatusWhenWaitingId = $this->model_setting_setting->getValue("config_order_status_id");
        }

        if (empty($orderStatusWhenWaitingId)) {
            $orderStatusWhenWaitingId = $this->findOrderStatusIdByName(["Pendente", "Pending"], 1);
        }

        $settings["payment_woovi_order_status_when_waiting_id"] = $orderStatusWhenWaitingId;

        // Use "processing" as default paid status.
        // On OpenCart installer, the processing status ID is 2:
        // https://github.com/opencart/opencart/blob/e3ae482e66671167b44f86e798f07f8084561117/upload/install/opencart.sql#L1568
        if (empty($settings["payment_woovi_order_status_when_paid_id"])) {
            $settings["payment_woovi_order_status_when_paid_id"] = $this->findOrderStatusIdByName(["Processando", "Processing"], 2);
        }

        $this->model_setting_setting->editSetting("payment_woovi", $settings);
    }

    /**
     * Looks for an ID that matches one of the names in the array or returns
     * the default ID.
     * 
     * @param array<string> $possibleNames
     */
    private function findOrderStatusIdByName(array $possibleNames, int $defaultId): int
    {
        foreach ($possibleNames as $possibleName) {
            $result = $this->db->query("
                SELECT order_status_id FROM `" . DB_PREFIX . "order_status`
                WHERE `name` LIKE '" . $possibleName . "'
                ORDER BY `order_status_id` DESC
            ");

            if (! empty($result->row["order_status_id"])) {
                return $result->row["order_status_id"];
            }
        }

        return $defaultId;
    }

    /**
     * Create woovi orders table.
     *
     * The table is used to relate a OpenPix charge to an OpenCart order.
     */
    private function createWooviOrderTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `". DB_PREFIX ."woovi_order` (
                `opencart_order_id` INT(11) NOT NULL,
                `woovi_correlation_id` VARCHAR(50) NOT NULL,
                `woovi_payment_link_url` VARCHAR(255) NOT NULL,
                `woovi_qrcode_image_url` VARCHAR(255) NOT NULL,
                `woovi_brcode` VARCHAR(500) NOT NULL,
                `woovi_pixkey` VARCHAR(200) NOT NULL,
                UNIQUE INDEX `opencart_order_id` (`opencart_order_id`),
                UNIQUE INDEX `woovi_correlation_id` (`woovi_correlation_id`)
            )"
        );
    }

    /**
     * Install the extension's event listeners in the OpenCart database.
     */
    private function installEvents(): void
    {
        $this->load->model("setting/event");

        $this->model_setting_event->deleteEventByCode("woovi_catalog_view_account_order_info_before");
        $this->model_setting_event->deleteEventByCode("woovi_catalog_view_common_success_before");
        $this->model_setting_event->deleteEventByCode("woovi_catalog_controller_checkout_success_before");

        $this->model_setting_event->addEvent([
            "code" => "woovi_catalog_view_account_order_info_before",
            "description" => "Add Pix Qr Code display button to order info page.",
            "trigger" => "catalog/view/account/order_info/before",
            "action" => "extension/woovi/payment/woovi_events|handleCatalogViewAccountOrderInfoBeforeEvent",
            "status" => true,
            "sort_order" => 0,
        ]);

        $this->model_setting_event->addEvent([
            "code" => "woovi_catalog_controller_checkout_success_before",
            "description" => "Ensures Woovi Qr Code is processed only on correct Pix orders.",
            "trigger" => "catalog/controller/checkout/success/before",
            "action" => "extension/woovi/payment/woovi_events|handleCatalogControllerCheckoutSuccessBeforeEvent",
            "status" => true,
            "sort_order" => 0,
        ]);

        $this->model_setting_event->addEvent([
            "code" => "woovi_catalog_view_common_success_before",
            "description" => "Add a QR Code on checkout success pages.",
            "trigger" => "catalog/view/common/success/before",
            "action" => "extension/woovi/payment/woovi_events|handleCatalogViewCommonSuccessBeforeEvent",
            "status" => true,
            "sort_order" => 0,
        ]);
    }

    /**
     * Uninstall the extension's event listeners in the OpenCart database.
     */
    private function uninstallEvents(): void
    {
        $this->load->model("setting/event");

        $this->model_setting_event->deleteEventByCode("woovi_catalog_view_common_success_before");
        $this->model_setting_event->deleteEventByCode("woovi_catalog_controller_checkout_success_before");
    }

    /**
     * Run upgrades.
     */
    private function upgrade(): void
    {
        $this->load->model("setting/extension");

        // Store latest upgraded version.
        /** @var array{name: string, version: string, code: string, link: string, author: string} $manifest */
        $manifest = json_decode((string) file_get_contents(__DIR__ . "/../../../install.json"), true);

        $currentVersion = $manifest["version"];

        $this->model_setting_setting->editSetting("thirdparty_payment_woovi", [
            "thirdparty_payment_woovi_latest_upgrade" => $currentVersion,
        ]);
    }
}
