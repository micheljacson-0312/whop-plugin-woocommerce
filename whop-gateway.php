class WC_Whop_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id = 'whop_payments';
        $this->method_title = 'Whop Payments';
        $this->method_description = 'Pay via Whop - Cards, BNPL, Crypto & more';
        $this->has_fields = false; // redirect-based
        
        // Load settings
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->api_key = $this->get_option('api_key');
        $this->company_id = $this->get_option('company_id');
    }

    // Admin settings fields
    public function init_form_fields() {
        $this->form_fields = [
            'enabled'    => ['type' => 'checkbox', 'title' => 'Enable'],
            'title'      => ['type' => 'text', 'default' => 'Pay with Whop'],
            'api_key'    => ['type' => 'password', 'title' => 'Whop API Key'],
            'company_id' => ['type' => 'text', 'title' => 'Company ID (biz_xxx)'],
        ];
    }

    // Jab customer "Place Order" kare
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $total = $order->get_total(); // e.g. 49.99

        // Step 1: Whop API se checkout session banao
        $response = wp_remote_post('https://api.whop.com/api/v1/checkout_configurations', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'company_id' => $this->company_id,
                'plan' => [
                    'initial_price' => floatval($total),
                    'plan_type'     => 'one_time',
                ],
                'metadata' => [
                    'woo_order_id' => (string) $order_id,
                    'customer_email' => $order->get_billing_email(),
                ],
                'redirect_url' => $this->get_return_url($order),
            ]),
        ]);

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Step 2: Customer ko Whop checkout pe redirect karo
        return [
            'result'   => 'success',
            'redirect' => $body['purchase_url'], // Whop hosted checkout
        ];
    }
}
