<?php
namespace WP_Store_Duitku\Admin;

class AdminMenu {
    public function __construct() {
        // Hook for the button in settings
        add_action('wp_store_settings_payment_methods_end', array($this, 'add_duitku_button'));
        
        // Hook for the settings panel
        add_action('wp_store_settings_payment_tab_end', array($this, 'add_duitku_settings'));

        // Hook for saving settings
        add_filter('wp_store_save_settings', array($this, 'save_duitku_settings'), 10, 2);
    }

    public function save_duitku_settings($settings, $params) {
        if (isset($params['duitku_merchant_code'])) {
            $settings['duitku_merchant_code'] = sanitize_text_field($params['duitku_merchant_code']);
        }
        if (isset($params['duitku_api_key'])) {
            $settings['duitku_api_key'] = sanitize_text_field($params['duitku_api_key']);
        }
        if (isset($params['duitku_mode'])) {
            $settings['duitku_mode'] = sanitize_text_field($params['duitku_mode']);
        }
        return $settings;
    }

    public function add_duitku_button() {
        ?>
        <label class="wp-store-btn" :class="{'wp-store-btn-primary': paymentMethods.includes('duitku')}">
            <input type="checkbox" name="payment_methods[]" value="duitku" x-model="paymentMethods" style="position:absolute;opacity:0;width:0;height:0;">
            Duitku
        </label>
        <?php
    }

    public function add_duitku_settings() {
        $settings = get_option('wp_store_settings', []);
        ?>
        <div x-show="paymentMethods.includes('duitku')" class="wp-store-box-gray wp-store-mt-4">
            <h3 class="wp-store-subtitle">Duitku Payment Gateway</h3>
            <p class="wp-store-helper">Konfigurasi API Duitku untuk pembayaran otomatis.</p>
            
            <div class="wp-store-grid-2">
                <div>
                    <label class="wp-store-label">Merchant Code</label>
                    <input type="text" name="duitku_merchant_code" class="wp-store-input" value="<?php echo esc_attr($settings['duitku_merchant_code'] ?? ''); ?>" placeholder="Contoh: D1234">
                </div>
                <div>
                    <label class="wp-store-label">API Key</label>
                    <input type="password" name="duitku_api_key" class="wp-store-input" value="<?php echo esc_attr($settings['duitku_api_key'] ?? ''); ?>" placeholder="API Key Duitku">
                </div>
            </div>
            
            <div class="wp-store-grid-2 wp-store-mt-4">
                <div>
                    <label class="wp-store-label">Mode</label>
                    <select name="duitku_mode" class="wp-store-input">
                        <option value="sandbox" <?php selected($settings['duitku_mode'] ?? 'sandbox', 'sandbox'); ?>>Sandbox (Testing)</option>
                        <option value="production" <?php selected($settings['duitku_mode'] ?? 'sandbox', 'production'); ?>>Production (Live)</option>
                    </select>
                </div>
                <div>
                    <label class="wp-store-label">Callback URL</label>
                    <input type="text" class="wp-store-input" value="<?php echo esc_url(rest_url('wp-store/v1/duitku/callback')); ?>" readonly onclick="this.select()">
                    <p class="wp-store-helper">Gunakan URL ini di dashboard Duitku.</p>
                </div>
            </div>
        </div>
        <?php
    }
}
