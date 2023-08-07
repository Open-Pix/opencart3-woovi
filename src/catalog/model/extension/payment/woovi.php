<?php

namespace Opencart\Catalog\Model\Extension\Woovi\Payment;

use Opencart\System\Engine\Model;

/**
 * Add Pix payment method to OpenCart.
 * 
 * @property \Opencart\System\Engine\Loader $load
 * @property \Opencart\System\Library\Language $language
 */
class Woovi extends Model
{
    /**
     * Called when OpenCart show available payment methods to user.
     * 
     * @return array{title: string, code: string, sort_order: int}
     */
    public function getMethod(): array
    {
        $this->load->language("extension/woovi/payment/woovi");

        return [
            "title" => $this->language->get("heading_title"),
            "code" => "woovi",
            "sort_order" => 0,
        ];
    }
}