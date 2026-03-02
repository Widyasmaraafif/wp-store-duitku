<?php

/**
 * Plugin Name: WP Store Duitku
 * Description: Duitku Payment Gateway for WP Store
 * Version: 1.0.0
 * Author: Velocity Developer
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_STORE_DUITKU_VERSION', '1.0.0');
define('WP_STORE_DUITKU_PATH', plugin_dir_path(__FILE__));
define('WP_STORE_DUITKU_URL', plugin_dir_url(__FILE__));

// autoloader
spl_autoload_register(function ($class) {
    $prefix = 'WP_Store_Duitku';
    $base_dir = WP_STORE_DUITKU_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// init plugin
function wp_store_duitku_init() {
    // check if WP Store is installed
    // The main plugin class is \WpStore\Core\Plugin, let's check for that or the constant
    if (!defined('WP_STORE_VERSION')) {
        return;
    }
    // init admin menu
    new \WP_Store_Duitku\Admin\AdminMenu();
    // init core payment gateway logic
    new \WP_Store_Duitku\Core\PaymentGateway();
    // init api callback
    $callback = new \WP_Store_Duitku\Api\CallbackController();
    add_action('rest_api_init', [$callback, 'register_routes']);
}
add_action('plugins_loaded', 'wp_store_duitku_init');