<?php
namespace WP_Store_Duitku\Core;

class PaymentGateway {
    public function __construct() {
        add_filter('wp_store_allowed_payment_methods', array($this, 'allow_duitku'));
        add_filter('wp_store_payment_init', array($this, 'handle_payment_init'), 10, 4);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_footer', array($this, 'render_popup_script'));
    }

    public function enqueue_scripts() {
        if (!is_singular('page')) return;
        
        $settings = get_option('wp_store_settings', []);
        $mode = $settings['duitku_mode'] ?? 'sandbox';
        $js_url = ($mode === 'production') 
            ? 'https://app-prod.duitku.com/lib/js/duitku.js'
            : 'https://app-sandbox.duitku.com/lib/js/duitku.js';
            
        wp_enqueue_script('duitku-pop', $js_url, [], null, true);
    }

    public function render_popup_script() {
        $order_number = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '';
        if (!$order_number) return;

        $orders = get_posts([
            'post_type' => 'store_order',
            'meta_key' => '_store_order_number',
            'meta_value' => $order_number,
            'posts_per_page' => 1,
            'fields' => 'ids'
        ]);

        if (empty($orders)) {
            // Try by ID
            if (is_numeric($order_number)) {
                $order_id = (int) $order_number;
                if (get_post_type($order_id) !== 'store_order') {
                    return;
                }
            } else {
                return;
            }
        } else {
            $order_id = $orders[0];
        }

        $payment_method = get_post_meta($order_id, '_store_order_payment_method', true);
        if ($payment_method !== 'duitku') return;

        $status = get_post_meta($order_id, '_store_order_status', true);
        if ($status !== 'awaiting_payment') return;

        $payment_token = get_post_meta($order_id, '_store_order_payment_token', true);
        if (!$payment_token) return;

        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof checkout !== 'undefined') {
                    // Function to trigger Duitku Pop
                    window.triggerDuitkuPop = function() {
                        checkout.process("<?php echo esc_js($payment_token); ?>", {
                            successEvent: function(result) {
                                window.location.reload();
                            },
                            pendingEvent: function(result) {
                                window.location.reload();
                            },
                            errorEvent: function(result) {
                                console.error('Duitku Error:', result);
                            },
                            closeEvent: function(result) {
                                console.log('Duitku Closed');
                            }
                        });
                    };

                    // Auto trigger on load
                    triggerDuitkuPop();

                    // Try to add a button if possible
                    const paymentInfo = document.querySelector('.wps-container .wps-grid div:last-child');
                    if (paymentInfo) {
                        const btnContainer = document.createElement('div');
                        btnContainer.style.marginTop = '20px';
                        btnContainer.innerHTML = '<button type="button" class="wps-btn wps-btn-primary wps-w-full" onclick="triggerDuitkuPop()">Bayar Sekarang (Duitku Pop)</button>';
                        paymentInfo.appendChild(btnContainer);
                    }
                }
            });
        </script>
        <?php
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
