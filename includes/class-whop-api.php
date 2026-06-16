<?php
if (!defined('ABSPATH')) exit;

// POST /wp-json/whop/v1/webhook
add_action('rest_api_init', function() {
    register_rest_route('whop/v1', '/webhook', [
        'methods'             => 'POST',
        'callback'            => 'handle_whop_webhook',
        'permission_callback' => '__return_true', // auth signature ke through hoti hai (neeche)
    ]);
});

/**
 * Standard Webhooks spec ke mutabiq signature verify karta hai.
 * Whop in headers ke sath bhejta hai: webhook-id, webhook-timestamp, webhook-signature
 * Signed content = "{id}.{timestamp}.{raw_body}"
 * Signature     = base64( HMAC_SHA256( key, signed_content ) )
 *
 * @return bool true sirf jab signature valid ho.
 */
function whop_verify_signature($request, $secret) {
    if (empty($secret)) {
        // Secret set hi nahi -> verify nahi kar sakte -> safe taraf reject.
        return false;
    }

    $msg_id     = $request->get_header('webhook-id');
    $timestamp  = $request->get_header('webhook-timestamp');
    $sig_header = $request->get_header('webhook-signature');
    $payload    = $request->get_body(); // RAW body exact bytes (json_decode se pehle)

    if (empty($msg_id) || empty($timestamp) || empty($sig_header)) {
        return false;
    }

    // Replay protection: 5 min se purana timestamp reject
    if (abs(time() - intval($timestamp)) > 300) {
        return false;
    }

    // Signed content banao
    $signed_content = $msg_id . '.' . $timestamp . '.' . $payload;

    // Key derive karo:
    //  - "whsec_" prefix ho to remainder ko base64-decode karo (Standard Webhooks default)
    //  - warna dashboard secret ko raw bytes ki tarah use karo (Whop ke btoa flow ke mutabiq)
    if (strpos($secret, 'whsec_') === 0) {
        $key = base64_decode(substr($secret, 6));
    } else {
        $key = $secret;
    }

    $expected = base64_encode(hash_hmac('sha256', $signed_content, $key, true));

    // Header me space-separated "v1,<sig>" ho sakte hain (multiple)
    $signatures = explode(' ', $sig_header);
    foreach ($signatures as $versioned) {
        $parts = explode(',', $versioned, 2);
        $sig   = (count($parts) === 2) ? $parts[1] : $parts[0];
        if (hash_equals($expected, $sig)) {
            return true; // constant-time match
        }
    }

    return false;
}

function handle_whop_webhook($request) {

    // ---- Security gate: pehle signature verify, phir kuch bhi process ----
    $settings = get_option('woocommerce_whop_payments_settings', []);
    $secret   = isset($settings['webhook_secret']) ? $settings['webhook_secret'] : '';

    if (!whop_verify_signature($request, $secret)) {
        error_log('Whop webhook: signature verification failed - request rejected.');
        return new WP_REST_Response('Invalid signature', 401);
    }
    // ---- yahan se aage request trusted hai ----

    $data = $request->get_json_params();

    if (!empty($data['type']) && $data['type'] === 'payment.succeeded') {

        $meta     = isset($data['data']['metadata']) ? $data['data']['metadata'] : [];
        $order_id = isset($meta['woo_order_id']) ? $meta['woo_order_id'] : null;
        $order    = $order_id ? wc_get_order($order_id) : null;

        if ($order) {
            // Idempotency: agar order already paid hai to dobara process mat karo
            // (Whop duplicate/retry webhooks bhej sakta hai)
            if ($order->is_paid()) {
                return new WP_REST_Response('Already processed', 200);
            }

            $whop_payment_id = isset($data['data']['id']) ? sanitize_text_field($data['data']['id']) : '';

            $order->payment_complete($whop_payment_id);
            $order->update_meta_data('_whop_payment_id', $whop_payment_id);

            $usd = isset($meta['usd_charged']) ? $meta['usd_charged'] : 'n/a';
            $pkr = isset($meta['pkr_total'])  ? $meta['pkr_total']  : 'n/a';

            $order->add_order_note(sprintf(
                'Whop payment confirmed. Whop ID: %s | Received USD %s (against PKR %s order)',
                $whop_payment_id,
                $usd,
                $pkr
            ));

            $order->save();
        }
    }

    return new WP_REST_Response('OK', 200);
}