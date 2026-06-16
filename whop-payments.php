<?php
/**
 * Plugin Name: Whop Payments for WooCommerce
 * Description: Accept payments via Whop. Charges in USD with PKR->USD conversion + gateway fee. Includes secure webhook verification.
 * Version:     1.1.0
 * Author:      10x Digital Ventures
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit; // direct access band

// WooCommerce load hone ke baad hi gateway register karo
add_action('plugins_loaded', function () {

    // WooCommerce active na ho to kuch mat karo (fatal error se bachne ke liye)
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    $dir = plugin_dir_path(__FILE__);

    require_once $dir . 'whop-gateway.php';              // gateway class (WC_Whop_Gateway)
    require_once $dir . 'includes/class-whop-gateway.php'; // gateway registration
    require_once $dir . 'includes/class-whop-api.php';     // webhook handler
});
