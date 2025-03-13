<?php
/*
Plugin Name: PayDo WooCommerce Payment Gateway
Plugin URI: https://github.com/PaydoW/woocommerce-plugin
Description: PayDo: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries. Payments gateway for Growing Your Business in New Locations and fast online payments
Author URI: https://paydo.com/
Version: 2.0.0
Requires at least: 6.3
Tested up to: 6.7.2
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 9.7.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
	exit;
}

define('PAYDO_PLUGIN_FILE', __FILE__);
define('PAYDO_PLUGIN_PATH', plugin_dir_path(PAYDO_PLUGIN_FILE));
define('PAYDO_PLUGIN_URL', plugin_dir_url(PAYDO_PLUGIN_FILE));
define('PAYDO_PLUGIN_BASENAME', plugin_basename(PAYDO_PLUGIN_FILE));
define('PAYDO_LANGUAGES_PATH', plugin_basename(dirname(__FILE__)) . '/languages/');
define('PAYDO_PAYMENT_GATEWAY_NAME', 'paydo');
define('PAYDO_INVITATE_RESPONSE', 'paydo_invitate_response');
define('PAYDO_PLUGIN_NAME', 'Paydo WooCommerce Payment Gateway');
define('PAYDO_MIN_PHP_VERSION', '7.4');
define('PAYDO_MIN_WP_VERSION', '6.3');
define('PAYDO_MIN_WC_VERSION', '8.3');
define('PAYDO_IPN_VERSION_V1', 'V1');
define('PAYDO_IPN_VERSION_V2', 'V2');
define('PAYDO_HASH_ALGORITHM', 'sha256');
define('PAYDO_API_IDENTIFIER', 'identifier');

require_once PAYDO_PLUGIN_PATH . '/includes/class-wc-payment-plugin.php';

new Paydo_WC_Payment_Plugin();
