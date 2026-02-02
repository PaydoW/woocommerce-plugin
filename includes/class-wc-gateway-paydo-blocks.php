<?php
/**
 * WooCommerce PayDo Payment Gateway – Blocks integration.
 *
 * Registers PayDo as a payment method for WooCommerce Checkout Blocks
 * and passes additional data (methods, mode, descriptions) to JS.
 *
 * @final
 * @extends AbstractPaymentMethodType
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Paydo_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name (must match gateway ID).
	 *
	 * @var string
	 */
	protected $name = PAYDO_PAYMENT_GATEWAY_NAME;

	/**
	 * PayDo gateway instance from WooCommerce.
	 *
	 * IMPORTANT:
	 * We do NOT create a new WC_Gateway_Paydo instance here,
	 * we reuse the one already created by WooCommerce.
	 *
	 * @var WC_Gateway_Paydo|null
	 */
	private $gateway = null;

	/**
	 * Initialize block integration.
	 *
	 * - Loads gateway settings
	 * - Reuses existing WC_Gateway_Paydo instance
	 */
	public function initialize() {
		$this->settings = get_option('woocommerce_paydo_settings', []);

		// Reuse existing gateway instance (important for consistency)
		if (function_exists('WC') && WC()->payment_gateways()) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if (isset($gateways[$this->name])) {
				$this->gateway = $gateways[$this->name];
			}
		}
	}

	/**
	 * Check whether payment method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		if ($this->gateway) {
			return $this->gateway->is_available();
		}

		return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
	}

	/**
	 * Register JS scripts required for Blocks checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'paydo-blocks-integration',
			PAYDO_PLUGIN_URL . 'js/paydo-blocks-integration.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			'1.0.0',
			true
		);

		// Enable translations for JS
		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('paydo-blocks-integration', 'paydo-woocommerce');
		}

		return ['paydo-blocks-integration'];
	}

	/**
	 * Data passed to JS (window.wc.wcSettings.getSetting('paydo_data')).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		/**
		 * All available methods synced from PayDo API.
		 * Format:
		 * [
		 *   identifier => string | [
		 *     'title' => string,
		 *     'icon'  => string (optional)
		 *   ]
		 * ]
		 */
		$available = get_option('paydo_available_methods', []);
		if (!is_array($available)) {
			$available = [];
		}

		/**
		 * Methods enabled in admin (checkboxes).
		 */
		$enabled_methods = isset($this->settings['enabled_methods'])
			? (array) $this->settings['enabled_methods']
			: [];

		$enabled_methods = array_values(
			array_filter(array_map('strval', $enabled_methods))
		);

		/**
		 * Whether custom methods selection mode is enabled.
		 */
		$methods_mode = !empty($this->settings['methods_mode'])
			&& $this->settings['methods_mode'] === 'yes';

		/**
		 * Title & description (prefer live gateway instance).
		 */
		$title = $this->gateway
			? $this->gateway->title
			: ($this->settings['title'] ?? 'PayDo');

		$desc = $this->gateway
			? $this->gateway->description
			: ($this->settings['description'] ?? '');

		return [
			'title'             => $title,
			'description'       => $desc,
			'methods_mode'      => $methods_mode,
			'enabled_methods'   => $enabled_methods,
			'available_methods' => $available,
		];
	}
}
