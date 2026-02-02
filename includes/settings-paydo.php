<?php
/**
 * Settings for Paydo Standard Gateway.
 *
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
	exit;
}

return [
	'enabled' => [
		'title'   => __('Enable/Disable', 'paydo-woocommerce'),
		'type'    => 'checkbox',
		'label'   => __('Enable PayDo', 'paydo-woocommerce'),
		'default' => 'no',
	],

	'title' => [
		'title'       => __('Title', 'paydo-woocommerce'),
		'type'        => 'text',
		'description' => __('This controls the title which the user sees during checkout.', 'paydo-woocommerce'),
		'default'     => __('PayDo', 'paydo-woocommerce'),
		'desc_tip'    => true,
	],

	'description' => [
		'title'       => __('Description', 'paydo-woocommerce'),
		'type'        => 'textarea',
		'description' => __('Payment method description that the customer will see on your checkout.', 'paydo-woocommerce'),
		'default'     => __('Pay securely via PayDo.', 'paydo-woocommerce'),
	],

	'public_key' => [
		'title'       => __('Public Key', 'paydo-woocommerce'),
		'type'        => 'text',
		'default'     => '',
	],

	'secret_key' => [
		'title'       => __('Secret Key', 'paydo-woocommerce'),
		'type'        => 'password',
		'default'     => '',
	],

	'skip_confirm' => [
		'title'   => __('Skip confirm step', 'paydo-woocommerce'),
		'type'    => 'checkbox',
		'label'   => __('Redirect customer to PayDo immediately', 'paydo-woocommerce'),
		'default' => 'no',
	],

	'auto_complete' => [
		'title'   => __('Auto-complete order', 'paydo-woocommerce'),
		'type'    => 'checkbox',
		'label'   => __('Mark order as completed after successful payment', 'paydo-woocommerce'),
		'default' => 'no',
	],

	// --- Methods mode ---
	'methods_mode' => [
		'title'       => __('Payment methods selection', 'paydo-woocommerce'),
		'type'        => 'checkbox',
		'label'       => __('Let customer choose PayDo method on checkout', 'paydo-woocommerce'),
		'default'     => 'no',
		'description' => __('If enabled, customer must pick a PayDo method (from enabled list) during checkout.', 'paydo-woocommerce'),
	],

	'project_id' => [
		'title'       => __('Project ID', 'paydo-woocommerce'),
		'type'        => 'text',
		'default'     => '',
		'description' => __('Used for syncing available methods from PayDo.', 'paydo-woocommerce'),
	],

	'jwt_token' => [
		'title'       => __('JWT Token', 'paydo-woocommerce'),
		'type'        => 'textarea',
		'default'     => '',
		'description' => __('Bearer token for PayDo API (methods sync).', 'paydo-woocommerce'),
	],

	// Sync button (custom field type)
	'sync_methods' => [
		'title'       => __('Sync payment methods', 'paydo-woocommerce'),
		'type'        => 'paydo_sync_methods',
		'description' => __('Fetch available methods from PayDo and update list below.', 'paydo-woocommerce'),
	],

	// checkbox list (custom field type)
	'enabled_methods' => [
		'title'       => __('Enabled PayDo methods', 'paydo-woocommerce'),
		'type'        => 'paydo_methods_checkboxes',
		'description' => __('Select which methods can be used on checkout.', 'paydo-woocommerce'),
		'default'     => [],
		'options'     => [], // fill in init_form_fields()
	],
];
