<?php
/**
 * Settings for Paydo Standard Gateway.
 *
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

return [
	'enabled' => [
		'title' => __('Enable PayDo payments', 'paydo-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Enable/Disable', 'paydo-woocommerce'),
		'default' => 'yes',
	],
	'title' => [
		'title' => __('Name of payment gateway', 'paydo-woocommerce'),
		'type' => 'text',
		'description' => __('The name of the payment gateway that the user see when placing the order', 'paydo-woocommerce'),
		'default' => __('PayDo', 'paydo-woocommerce'),
	],
	'public_key' => [
		'title' => __('Public key', 'paydo-woocommerce'),
		'type' => 'text',
		'description' => __('Issued in the client panel https://paydo.com', 'paydo-woocommerce'),
		'default' => '',
	],
	'secret_key' => [
		'title' => __('Secret key', 'paydo-woocommerce'),
		'type' => 'text',
		'description' => __('Issued in the client panel https://paydo.com', 'paydo-woocommerce'),
		'default' => '',
	],
	'description' => [
		'title' => __('Description', 'paydo-woocommerce'),
		'type' => 'textarea',
		'description' => __(
			'Description of the payment gateway that the client will see on your site.',
			'paydo-woocommerce'
		),
		'default' => __('Accept online payments using PayDo.com', 'paydo-woocommerce'),
	],
	'auto_complete' => [
		'title' => __('Order completion', 'paydo-woocommerce'),
		'type' => 'checkbox',
		'label' => __(
			'Automatic transfer of the order to the status "Completed" after successful payment',
			'paydo-woocommerce'
		),
		'description' => __('', 'paydo-woocommerce'),
		'default' => '1',
	],
	'skip_confirm' => [
		'title' => __('Skip confirmation', 'paydo-woocommerce'),
		'type' => 'checkbox',
		'label' => __(
			'Skip page checkout confirmation',
			'paydo-woocommerce'
		),
		'description' => __('', 'paydo-woocommerce'),
		'default' => 'yes',
	],
];