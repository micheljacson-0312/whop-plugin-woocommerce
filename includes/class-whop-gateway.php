<?php
if (!defined('ABSPATH')) exit;

// Whop gateway ko WooCommerce ke payment methods me register karo
add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Whop_Gateway';
    return $gateways;
});
