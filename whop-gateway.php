<?php
if (!defined('ABSPATH')) exit; // direct access band

class WC_Whop_Gateway extends WC_Payment_Gateway {

    public $api_key;
    public $company_id;
    public $exchange_rate;
    public $gateway_fee_pkr;
    public $return_url_custom;

    public function __construct() {
        $this->id = 'whop_payments';
        $this->method_title = 'Whop Payments';
        $this->method_description = 'Pay via Whop - Cards, BNPL, Crypto & more (charged in USD)';
        $this->has_fields = false; // redirect-based

        $this->init_form_fields();
        $this->init_settings();
        $this->title            = $this->get_option('title');
        $this->api_key          = $this->get_option('api_key');
        $this->company_id       = $this->get_option('company_id');
        $this->exchange_rate    = $this->get_option('exchange_rate');
        $this->gateway_fee_pkr  = $this->get_option('gateway_fee_pkr');
        $this->return_url_custom = $this->get_option('return_url_custom');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled'    => ['type' => 'checkbox', 'title' => 'Enable'],
            'title'      => ['type' => 'text', 'default' => 'Pay with Whop'],
            'api_key'    => ['type' => 'password', 'title' => 'Whop API Key'],
            'company_id' => ['type' => 'text', 'title' => 'Company ID (biz_xxx)'],
            'webhook_secret' => [
                'type'        => 'password',
                'title'       => 'Webhook Secret',
                'description' => 'Whop dashboard ke webhook table se copy karo. Iske bina webhook reject ho jayega (security).',
                'desc_tip'    => true,
            ],
            'exchange_rate' => [
                'type'        => 'text',
                'title'       => 'PKR to USD rate',
                'description' => 'Kitne PKR = 1 USD. Example: 280',
                'default'     => '280',
                'desc_tip'    => true,
            ],
            'gateway_fee_pkr' => [
                'type'        => 'text',
                'title'       => 'Gateway fee (PKR)',
                'description' => 'Order total mein add hone wali fee, USD convert hone se pehle. Example: 500',
                'default'     => '500',
                'desc_tip'    => true,
            ],
            'return_url_custom' => [
                'type'        => 'text',
                'title'       => 'Return URL (optional)',
                'description' => 'Filhaal Whop hosted checkout apna default success page dikhata hai; order webhook se complete hota hai.',
                'default'     => '',
                'desc_tip'    => true,
            ],
        ];
    }

    /** (pkr_total + fee_pkr) / rate -> USD */
    private function convert_to_usd($order) {
        $pkr_total = floatval($order->get_total());
        $fee_pkr   = floatval($this->gateway_fee_pkr);
        $rate      = floatval($this->exchange_rate);
        if ($rate <= 0) $rate = 280;
        return round(($pkr_total + $fee_pkr) / $rate, 2);
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $pkr_total  = floatval($order->get_total());
        $usd_amount = $this->convert_to_usd($order);

        // Reconciliation note + meta
        $order->add_order_note(sprintf(
            'Whop checkout create attempt: PKR %s + fee PKR %s @ rate %s = USD %s',
            number_format($pkr_total, 2), $this->gateway_fee_pkr, $this->exchange_rate, $usd_amount
        ));
        $order->update_meta_data('_whop_pkr_total', $pkr_total);
        $order->update_meta_data('_whop_fee_pkr', floatval($this->gateway_fee_pkr));
        $order->update_meta_data('_whop_rate', floatval($this->exchange_rate));
        $order->update_meta_data('_whop_usd_charged', $usd_amount);
        $order->save();

        // Whop API call - CORRECT format (Whop docs ke mutabiq)
        // currency top-level, baqi sab plan ke andar.
        $response = wp_remote_post('https://api.whop.com/api/v1/checkout_configurations', [
            'timeout' => 30, // default 5s kam parta hai
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'currency' => 'usd',
                'plan' => [
                    'initial_price' => $usd_amount,   // dollars (e.g. 17.86)
                    'plan_type'     => 'one_time',
                    'company_id'    => $this->company_id, // <-- plan ke ANDAR
                    'currency'      => 'usd',
                ],
                'metadata' => [
                    'woo_order_id'   => (string) $order_id,
                    'customer_email' => $order->get_billing_email(),
                    'pkr_total'      => (string) $pkr_total,
                    'usd_charged'    => (string) $usd_amount,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            wc_add_notice('Payment error: ' . $response->get_error_message(), 'error');
            $order->add_order_note('Whop WP error: ' . $response->get_error_message());
            return ['result' => 'failure'];
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $body = json_decode($raw, true);

        // plan id nikaalo - response shape ke liye fallback
        $plan_id = '';
        if (!empty($body['plan']['id']))      $plan_id = $body['plan']['id'];
        elseif (!empty($body['plan_id']))     $plan_id = $body['plan_id'];

        // Success: 2xx + plan id mila
        if ($code >= 200 && $code < 300 && $plan_id) {
            $checkout_url = 'https://whop.com/checkout/' . $plan_id;

            // checkout config id store karo (jo bhi mile)
            $cfg_id = !empty($body['id']) ? $body['id'] : $plan_id;
            $order->update_meta_data('_whop_checkout_id', sanitize_text_field($cfg_id));
            $order->add_order_note('Whop checkout created. Plan: ' . $plan_id);
            $order->save();

            return ['result' => 'success', 'redirect' => $checkout_url];
        }

        // Failure: poora raw response order note me daalo (debugging ke liye)
        $order->add_order_note('Whop API FAILED (HTTP ' . $code . '): ' . $raw);
        error_log('Whop checkout failed (HTTP ' . $code . '): ' . $raw);
        wc_add_notice('Whop checkout could not be created. Try again.', 'error');
        return ['result' => 'failure'];
    }
}