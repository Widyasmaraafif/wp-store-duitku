<?php
namespace WP_Store_Duitku\Core;

class PaymentGateway {
    private $db;

    public function __construct() {
        $this->db = new Database();
        
        // Create tables on init if needed
        add_action('init', [$this->db, 'create_tables']);

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
                ? 'https://app-prod.duitku.com/lib/js/duitku.js'
                : 'https://app-sandbox.duitku.com/lib/js/duitku.js';
            
            wp_enqueue_script('duitku-js', $js_url, array(), null, true);
        }
    }

    public function maybe_render_duitku_popup() {
        $settings = get_option('wp_store_settings', []);
        $page_thanks_id = (int) ($settings['page_thanks'] ?? 0);

        // If we're on the thanks page, the button handles the trigger.
        // We only use this for other order types that don't have a button, like event_order or membership.
        if ($page_thanks_id > 0 && is_page($page_thanks_id)) {
            return;
        }

        $order_param = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '';
        if (!$order_param) return;

            // Resolve order ID
            $order_id = 0;
            $orders = get_posts([
                'post_type' => ['store_order', 'event_order'],
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key' => '_store_order_number',
                        'value' => $order_param,
                        'compare' => '='
                    ],
                    [
                        'key' => 'invoice_number',
                        'value' => $order_param,
                        'compare' => '='
                    ]
                ],
                'posts_per_page' => 1,
                'fields' => 'ids'
            ]);

            if (!empty($orders)) {
                $order_id = $orders[0];
            } elseif (is_numeric($order_param)) {
                $order_id = absint($order_param);
            }

            if ($order_id > 0 && in_array(get_post_type($order_id), ['store_order', 'event_order'])) {
                $post_type = get_post_type($order_id);
                $payment_method = get_post_meta($order_id, ($post_type === 'event_order' ? 'payment_method' : '_store_order_payment_method'), true);
                $reference = get_post_meta($order_id, ($post_type === 'event_order' ? 'payment_token' : '_store_order_payment_token'), true);
                $status = get_post_meta($order_id, ($post_type === 'event_order' ? 'order_status' : '_store_order_status'), true);
                
                $is_awaiting = ($post_type === 'event_order') ? ($status === 'pending') : ($status === 'awaiting_payment');

                if ($payment_method === 'duitku' && !empty($reference) && $is_awaiting) {
                    ?>
                    <script type="text/javascript">
                        document.addEventListener('DOMContentLoaded', function() {
                            if (typeof checkout !== 'undefined') {
                                checkout.process('<?php echo esc_js($reference); ?>', {
                                    defaultLanguage: "id",
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

        $post_type = get_post_type($order_id);

        if ($post_type === 'event_order') {
            $order_number = get_post_meta($order_id, 'invoice_number', true) ?: (string) $order_id;
            $customer_name = get_post_meta($order_id, 'full_name', true);
            $email = get_post_meta($order_id, 'email', true);
            $phone = get_post_meta($order_id, 'phone', true);
            
            $event_id = get_post_meta($order_id, 'event_id', true);
            $quantity = max(1, (int)get_post_meta($order_id, 'quantity', true));
            
            $item_details = [
                [
                    'name' => get_the_title($event_id) ?: 'Event Ticket',
                    'price' => (int) ($order_total / $quantity),
                    'quantity' => $quantity
                ]
            ];
            
            $first_name = $customer_name;
            $last_name = '';
        } elseif ($post_type === 'membership') {
            $order_number = get_post_meta($order_id, 'invoice_number', true) ?: (string) $order_id;
            $customer_name = get_the_title($order_id);
            $email = get_post_meta($order_id, 'email', true);
            $phone = get_post_meta($order_id, 'phone', true);
            
            $item_details = [
                [
                    'name' => 'Membership: ' . $customer_name,
                    'price' => (int) $order_total,
                    'quantity' => 1
                ]
            ];
            
            $first_name = $customer_name;
            $last_name = '';
        } else {
            $order_number = get_post_meta($order_id, '_store_order_number', true) ?: (string) $order_id;
            $first_name = get_post_meta($order_id, '_store_order_first_name', true);
            $last_name = get_post_meta($order_id, '_store_order_last_name', true);
            $customer_name = trim($first_name . ' ' . $last_name);
            if (empty($customer_name)) {
                $customer_name = get_post_meta($order_id, '_store_order_name', true) ?: 'Customer';
            }
            $email = get_post_meta($order_id, '_store_order_email', true);
            $phone = get_post_meta($order_id, '_store_order_phone', true);

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

            // Add Shipping cost as an item
            $shipping_cost = (int) get_post_meta($order_id, '_store_order_shipping_cost', true);
            if ($shipping_cost > 0) {
                $item_details[] = [
                    'name' => 'Shipping Cost',
                    'price' => $shipping_cost,
                    'quantity' => 1
                ];
            }

            // Add Discount as an item (negative price)
            $discount_amount = (int) get_post_meta($order_id, '_store_order_discount_amount', true);
            if ($discount_amount > 0) {
                $item_details[] = [
                    'name' => 'Discount',
                    'price' => -$discount_amount,
                    'quantity' => 1
                ];
            }
        }
            
        if (empty($first_name)) {
            $first_name = $customer_name;
        }

        $amount = (int) $order_total;
        
        // Jakarta timezone timestamp in milliseconds
        date_default_timezone_set('Asia/Jakarta');
        $timestamp = round(microtime(true) * 1000);
        
        $signature = hash('sha256', $merchant_code . $timestamp . $api_key);

        $callback_url = rest_url('wp-store/v1/duitku/callback');
        if ($post_type === 'event_order') {
            $callback_url = rest_url('custom-plugin/v1/duitku/callback');
            $return_url = home_url('/invoice/?order_id=' . $order_id);
        } elseif ($post_type === 'membership') {
            $callback_url = rest_url('custom-plugin/v1/memberships/duitku/callback');
            $return_url = home_url('/membership-invoice/?membership_id=' . $order_id);
        } else {
            $return_url = home_url('/thanks/?order=' . $order_number);
        }

        $params = [
            'paymentAmount'    => $amount,
            'merchantOrderId'  => $order_number,
            'productDetails'   => 'Order ' . $order_number,
            'additionalParam'  => '',
            'merchantUserInfo' => '',
            'customerVaName'   => $customer_name,
            'email'            => $email,
            'phoneNumber'      => $phone,
            'itemDetails'      => $item_details,
            'callbackUrl'      => $callback_url,
            'returnUrl'        => $return_url,
            'customerDetail'   => [
                'firstName' => $first_name,
                'lastName'  => $last_name,
                'email'     => $email,
                'phoneNumber' => $phone,
            ]
        ];

        $url = ($mode === 'production') 
            ? 'https://api-prod.duitku.com/api/merchant/createinvoice'
            : 'https://api-sandbox.duitku.com/api/merchant/createinvoice';

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers' => [
                'Accept'                => 'application/json',
                'Content-Type'          => 'application/json',
                'x-duitku-signature'    => $signature,
                'x-duitku-timestamp'    => $timestamp,
                'x-duitku-merchantcode' => $merchant_code,
            ],
            'body' => json_encode($params),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('Duitku API Request Error: ' . $response->get_error_message());
            return $info;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($http_code !== 200 || !isset($body['reference'])) {
            error_log('Duitku API Response Error (HTTP ' . $http_code . '): ' . $body_raw);
            return $info;
        }
            $info['payment_url'] = $body['paymentUrl'] ?? '';
            $info['payment_token'] = $body['reference'];
            
            // Save to database logging
            $this->db->save_invoice($order_number, $body, $amount);
            
            // Update order meta
            if ($post_type === 'event_order') {
                update_post_meta($order_id, 'duitku_reference', $info['payment_token']);
                update_post_meta($order_id, 'payment_token', $info['payment_token']);
            } elseif ($post_type === 'membership') {
                update_post_meta($order_id, 'duitku_reference', $info['payment_token']);
                update_post_meta($order_id, 'payment_token', $info['payment_token']);
            } else {
                update_post_meta($order_id, '_store_order_payment_url', $info['payment_url']);
                update_post_meta($order_id, '_store_order_payment_token', $info['payment_token']);
                update_post_meta($order_id, 'duitku_reference', $info['payment_token']);
                update_post_meta($order_id, 'payment_token', $info['payment_token']);
            }

        return $info;
    }
}
