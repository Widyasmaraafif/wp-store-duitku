<?php
namespace WP_Store_Duitku\Core;

class Database {
    private $wpdb;
    public $tb_invoice;
    public $tb_callback;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tb_invoice = $wpdb->prefix . 'wp_store_duitku_invoice';
        $this->tb_callback = $wpdb->prefix . 'wp_store_duitku_callback';
    }

    public function create_tables() {
        if (get_option('wp_store_duitku_db_version', 0) < 1) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

            $sql_invoice = "CREATE TABLE IF NOT EXISTS $this->tb_invoice (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                invoice varchar(255) NOT NULL,
                merchant_code varchar(114) NOT NULL,
                reference varchar(255) NOT NULL,
                payment_url text NOT NULL,
                status_code varchar(5) NOT NULL,
                status_message varchar(255) NOT NULL,
                amount varchar(114) DEFAULT NULL,
                created_at datetime NOT NULL,
                update_at datetime NOT NULL,
                PRIMARY KEY (id)
            );";

            $sql_callback = "CREATE TABLE IF NOT EXISTS $this->tb_callback (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                invoice varchar(255) NOT NULL,
                merchant_code varchar(114) NOT NULL,
                amount varchar(255) NOT NULL,
                payment_code varchar(255) NOT NULL,
                result_code varchar(255) NOT NULL,
                reference varchar(255) NOT NULL,
                detail text DEFAULT NULL,
                created_at datetime NOT NULL,
                update_at datetime NOT NULL,
                PRIMARY KEY (id)
            );";

            dbDelta($sql_invoice);
            dbDelta($sql_callback);

            update_option('wp_store_duitku_db_version', 1);
        }
    }

    public function save_invoice($invoice, $result_request, $amount = null) {
        if (is_wp_error($result_request)) {
            return false;
        }

        $cek_invoice = $this->get_by_invoice($invoice);
        $amount = $result_request['amount'] ?? $amount;
        $now = current_time('mysql');

        $data = [
            'invoice'        => $invoice,
            'merchant_code'  => $result_request['merchantCode'] ?? '',
            'reference'      => $result_request['reference'] ?? '',
            'payment_url'    => $result_request['paymentUrl'] ?? '',
            'status_code'    => $result_request['statusCode'] ?? '',
            'status_message' => $result_request['statusMessage'] ?? '',
            'update_at'      => $now,
            'amount'         => $amount,
        ];

        if ($cek_invoice) {
            $this->wpdb->update($this->tb_invoice, $data, ['id' => $cek_invoice->id]);
            $id = $cek_invoice->id;
        } else {
            $data['created_at'] = $now;
            $this->wpdb->insert($this->tb_invoice, $data);
            $id = $this->wpdb->insert_id;
        }

        return [
            'id'        => $id,
            'invoice'   => $invoice,
            'reference' => $result_request['reference'] ?? '',
        ];
    }

    public function save_callback($post_callback) {
        $invoice = $post_callback['merchantOrderId'] ?? '';
        if (!$invoice) return false;

        $available = $this->wpdb->get_row($this->wpdb->prepare("SELECT id FROM $this->tb_callback WHERE invoice = %s", $invoice));
        $now = current_time('mysql');

        $data = [
            'invoice'       => $post_callback['merchantOrderId'],
            'merchant_code' => $post_callback['merchantCode'],
            'amount'        => $post_callback['amount'],
            'payment_code'  => $post_callback['paymentCode'],
            'result_code'   => $post_callback['resultCode'],
            'reference'     => $post_callback['reference'],
            'detail'        => json_encode($post_callback),
            'update_at'     => $now,
        ];

        if ($available) {
            $this->wpdb->update($this->tb_callback, $data, ['id' => $available->id]);
            $id = $available->id;
        } else {
            $data['created_at'] = $now;
            $this->wpdb->insert($this->tb_callback, $data);
            $id = $this->wpdb->insert_id;
        }

        return $id;
    }

    public function get_by_invoice($invoice) {
        return $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM $this->tb_invoice WHERE invoice = %s", $invoice));
    }

    public function get_invoices($limit = 50) {
        return $this->wpdb->get_results("SELECT * FROM $this->tb_invoice ORDER BY created_at DESC LIMIT $limit");
    }

    public function get_callbacks($limit = 50) {
        return $this->wpdb->get_results("SELECT * FROM $this->tb_callback ORDER BY created_at DESC LIMIT $limit");
    }
}
