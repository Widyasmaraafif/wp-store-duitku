<?php
namespace WP_Store_Duitku\Api;

use WP_REST_Request;
use WP_REST_Response;

class CallbackController {
    public function register_routes() {
        register_rest_route('wp-store/v1', '/duitku/callback', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_callback'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function handle_callback(WP_REST_Request $request) {
        $params = $request->get_params();
        $settings = get_option('wp_store_settings', []);
        $merchant_code = $settings['duitku_merchant_code'] ?? '';
        $api_key = $settings['duitku_api_key'] ?? '';

        if (!$merchant_code || !$api_key) {
            return new WP_REST_Response(['message' => 'Settings missing'], 400);
        }

        $amount = isset($params['amount']) ? $params['amount'] : '';
        $merchant_order_id = isset($params['merchantOrderId']) ? $params['merchantOrderId'] : '';
        $signature = isset($params['signature']) ? $params['signature'] : '';
        $result_code = isset($params['resultCode']) ? $params['resultCode'] : '';

        // Validate signature
        $calc_signature = md5($merchant_code . $amount . $merchant_order_id . $api_key);

        if ($signature !== $calc_signature) {
            return new WP_REST_Response(['message' => 'Invalid signature'], 403);
        }

        // Find order by merchantOrderId (order_number)
        $orders = get_posts([
            'post_type' => 'store_order',
            'meta_key' => '_store_order_number',
            'meta_value' => $merchant_order_id,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);

        if (empty($orders)) {
            return new WP_REST_Response(['message' => 'Order not found'], 404);
        }

        $order_id = $orders[0];

        if ($result_code === '00') {
            // Payment success
            update_post_meta($order_id, '_store_order_status', 'processing');
            update_post_meta($order_id, '_store_order_payment_status', 'paid');
            do_action('wp_store_order_payment_success', $order_id, $params);
        } else {
            // Payment failed or other status
            update_post_meta($order_id, '_store_order_payment_status', 'failed');
            do_action('wp_store_order_payment_failed', $order_id, $params);
        }

        return new WP_REST_Response(['message' => 'OK'], 200);
    }
}
