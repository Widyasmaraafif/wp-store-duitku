<?php
namespace WP_Store_Duitku\Core;

class PaymentGateway {
    public function __construct() {
        add_filter('wp_store_allowed_payment_methods', array($this, 'allow_duitku'));
        add_filter('wp_store_payment_init', array($this, 'handle_payment_init'), 10, 5);
        add_filter('wp_store_payment_response', array($this, 'remove_redirect_from_response'), 10, 4);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_duitku_scripts'));
        add_action('wp_footer', array($this, 'maybe_render_duitku_popup'));
    }

    public function allow_duitku($methods) {
        $methods[] = 'duitku';
        return $methods;
    }

    public function remove_redirect_from_response($resp, $order_id, $payment_info, $data) {
        $payment_method = get_post_meta($order_id, '_store_order_payment_method', true);
        if ($payment_method === 'duitku' && isset($resp['payment_url'])) {
            unset($resp['payment_url']);
        }
        return $resp;
    }

    public function enqueue_duitku_scripts() {
        $settings = get_option('wp_store_settings', []);
        $page_thanks_id = (int) ($settings['page_thanks'] ?? 0);

        if ($page_thanks_id > 0 && is_page($page_thanks_id)) {
            $mode = $settings['duitku_mode'] ?? 'sandbox';
            $js_url = ($mode === 'production') 
                ? 'https://passport.duitku.com/webapi/js/duitku.js'
                : 'https://sandbox.duitku.com/webapi/js/duitku.js';
            
            wp_enqueue_script('duitku-js', $js_url, array(), null, true);
        }
    }

    public function maybe_render_duitku_popup() {
        $settings = get_option('wp_store_settings', []);
        $page_thanks_id = (int) ($settings['page_thanks'] ?? 0);

        if ($page_thanks_id > 0 && is_page($page_thanks_id)) {
            $order_param = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '';
            if (!$order_param) return;

            // Resolve order ID
            $order_id = 0;
            $orders = get_posts([
                'post_type' => 'store_order',
                'meta_key' => '_store_order_number',
                'meta_value' => $order_param,
                'posts_per_page' => 1,
                'fields' => 'ids'
            ]);

            if (!empty($orders)) {
                $order_id = $orders[0];
            } elseif (is_numeric($order_param)) {
                $order_id = absint($order_param);
            }

            if ($order_id > 0 && get_post_type($order_id) === 'store_order') {
                $payment_method = get_post_meta($order_id, '_store_order_payment_method', true);
                $payment_url = get_post_meta($order_id, '_store_order_payment_url', true);
                $status = get_post_meta($order_id, '_store_order_status', true);

                if ($payment_method === 'duitku' && !empty($payment_url) && $status === 'awaiting_payment') {
                    ?>
                    <script type="text/javascript">
                        document.addEventListener('DOMContentLoaded', function() {
                            if (typeof checkout !== 'undefined') {
                                checkout.process('<?php echo esc_js($payment_url); ?>', {
                                    successEvent: function(result) {
                                        console.log('success', result);
                                        window.location.reload();
                                    },
                                    pendingEvent: function(result) {
                                        console.log('pending', result);
                                        window.location.reload();
                                    },
                                    errorEvent: function(result) {
                                        console.log('error', result);
                                        window.location.reload();
                                    },
                                    closeEvent: function(result) {
                                        console.log('customer closed the popup', result);
                                    }
                                });
                            }
                        });
                    </script>
                    <?php
                }
            }
        }
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
