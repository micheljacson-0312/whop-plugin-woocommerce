// whop-gateway.php
add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_Whop_Gateway';
    return $gateways;
});
