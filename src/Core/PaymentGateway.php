<?php
namespace WP_Store_Duitku\Core;

class PaymentGateway {
    public function __construct() {
        add_filter('wp_store_allowed_payment_methods', array($this, 'allow_duitku'));
        add_filter('wp_store_payment_init', array($this, 'handle_payment_init'), 10, 4);
    }

    public function allow_duitku($methods) {
        $methods[] = 'duitku';
        return $methods;
    }

    public function handle_payment_init($info, $order_id, $payment_method, $data, $order_total) {
        if ($payment_method !== 'duitku') {
            return $info;
        }

        $settings = get_option('wp_store_settings', []);
        $merchant_code = $settings['duitku_merchant_code'] ?? '';
        $api_key = $settings['duitku_api_key'] ?? '';
        $mode = $settings['duitku_mode'] ?? 'sandbox';

        if (!$merchant_code || !$api_key) {
            return $info;
        }

        $order_number = get_post_meta($order_id, '_store_order_number', true) ?: (string) $order_id;
        $amount = (int) $order_total;
        $timestamp = round(microtime(true) * 1000);
        
        $signature = hash('sha256', $merchant_code . $timestamp . $api_key);

        $callback_url = rest_url('wp-store/v1/duitku/callback');
        $return_url = home_url('/thanks/?order=' . $order_number);

        // Prepare items
        $items_meta = get_post_meta($order_id, '_store_order_items', true) ?: [];
        $item_details = [];
        foreach ($items_meta as $item) {
            $item_details[] = [
                'name' => $item['title'],
                'price' => (int) $item['price'],
                'quantity' => (int) $item['qty']
            ];
        }

        $payload = [
            'merchantCode' => $merchant_code,
            'paymentAmount' => $amount,
            'merchantOrderId' => $order_number,
            'productDetails' => 'Order ' . $order_number,
            'email' => get_post_meta($order_id, '_store_order_email', true),
            'phoneNumber' => get_post_meta($order_id, '_store_order_phone', true),
            'itemDetails' => $item_details,
            'callbackUrl' => $callback_url,
            'returnUrl' => $return_url,
            'signature' => hash('sha256', $merchant_code . $order_number . $amount . $api_key),
            'expiryPeriod' => 1440 // 24 hours
        ];

        $url = ($mode === 'production') 
            ? 'https://passport.duitku.com/webapi/api/merchant/v2/inquiry'
            : 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry';

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return $info;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['paymentUrl'])) {
            $info['payment_url'] = $body['paymentUrl'];
            $info['payment_token'] = $body['reference'] ?? '';
        }

        return $info;
    }
}
