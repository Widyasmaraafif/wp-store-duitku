<?php
namespace WP_Store_Duitku\Admin;

use WP_Store_Duitku\Core\Database;

class AdminMenu {
    private $db;

    public function __construct() {
        $this->db = new Database();

        // Hook for the button in settings
        add_action('wp_store_settings_payment_methods_end', array($this, 'add_duitku_button'));
        
        // Hook for the settings panel
        add_action('wp_store_settings_payment_tab_end', array($this, 'add_duitku_settings'));

        // Hook for saving settings
        add_filter('wp_store_save_settings', array($this, 'save_duitku_settings'), 10, 2);

        // Add history menu
        add_action('admin_menu', array($this, 'register_history_menu'));
        
        // Enqueue scripts for Alpine.js (if not already present)
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }

    public function admin_scripts($hook) {
        if (strpos($hook, 'duitku-history') !== false) {
            wp_enqueue_script('alpinejs', 'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js', array(), '3.0.0', true);
        }
    }

    public function register_history_menu() {
        add_submenu_page(
            'edit.php?post_type=store_order',
            'Duitku History',
            'Duitku History',
            'manage_options',
            'duitku-history',
            array($this, 'render_history_page')
        );
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
            <div class="wp-store-mt-4">
                <a href="<?php echo admin_url('edit.php?post_type=store_order&page=duitku-history'); ?>" class="button button-secondary">Lihat Riwayat Transaksi Duitku</a>
            </div>
        </div>
        <?php
    }

    public function render_history_page() {
        $tab_active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'invoice';
        ?>
        <div class="wrap" x-data="{ activeTab: '<?php echo esc_attr($tab_active); ?>' }">
            <h1>Duitku Payment History</h1>

            <h2 class="nav-tab-wrapper">
                <a href="#" class="nav-tab" :class="{ 'nav-tab-active': activeTab === 'invoice' }" @click.prevent="activeTab = 'invoice'">Riwayat Invoice</a>
                <a href="#" class="nav-tab" :class="{ 'nav-tab-active': activeTab === 'callback' }" @click.prevent="activeTab = 'callback'">Riwayat Callback</a>
            </h2>

            <div x-show="activeTab === 'invoice'" class="tab-content">
                <?php $this->render_invoice_table(); ?>
            </div>

            <div x-show="activeTab === 'callback'" class="tab-content">
                <?php $this->render_callback_table(); ?>
            </div>
        </div>
        <style>
            .tab-content { margin-top: 20px; }
        </style>
        <?php
    }

    private function render_invoice_table() {
        $invoices = $this->db->get_invoices();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Invoice / Order</th>
                    <th>Reference</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($invoices): foreach ($invoices as $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html($row->invoice); ?></strong></td>
                        <td><?php echo esc_html($row->reference); ?></td>
                        <td><?php echo number_format($row->amount, 0, ',', '.'); ?></td>
                        <td>
                            <span class="badge"><?php echo esc_html($row->status_code); ?></span>
                            <small><?php echo esc_html($row->status_message); ?></small>
                        </td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5">No invoice records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_callback_table() {
        $callbacks = $this->db->get_callbacks();
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Invoice / Order</th>
                    <th>Reference</th>
                    <th>Amount</th>
                    <th>Result Code</th>
                    <th>Payment Code</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($callbacks): foreach ($callbacks as $row): ?>
                    <tr>
                        <td><strong><?php echo esc_html($row->invoice); ?></strong></td>
                        <td><?php echo esc_html($row->reference); ?></td>
                        <td><?php echo number_format($row->amount, 0, ',', '.'); ?></td>
                        <td><?php echo esc_html($row->result_code); ?></td>
                        <td><?php echo esc_html($row->payment_code); ?></td>
                        <td><?php echo esc_html($row->created_at); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">No callback records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
}
