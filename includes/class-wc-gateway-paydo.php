<?php
/**
 * WooCommerce Paydo Payment Gateway.
 *
 * @extends WC_Payment_Gateway
 * @version 2.2.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_Gateway_Paydo extends WC_Payment_Gateway {

	/**
	 * Public key for authentication with Paydo API.
	 *
	 * @var string
	 */
	public $public_key;

	/**
	 * URL for making requests to Paydo API.
	 *
	 * @var string
	 */
	public $api_url;

	/**
	 * Secret key for signing requests to Paydo API.
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Flag indicating whether to skip confirmation step before payment.
	 *
	 * @var string
	 */
	public $skip_confirm;

	/**
	 * Lifetime of the payment link.
	 *
	 * @var string
	 */
	public $lifetime;

	/**
	 * Flag indicating whether orders should be auto-completed after successful payment.
	 *
	 * @var string
	 */
	public $auto_complete;

	/**
	 * Language code for the payment form.
	 *
	 * @var string
	 */
	public $language;

	/**
	 * Instructions for the payment.
	 *
	 * @var string
	 */
	public $instructions;

	/**
	 * @var bool
	 */
	public $methods_mode;

	/**
	 * @var string
	 */
	public $project_id;

	/**
	 * @var string
	 */
	public $jwt_token;

	/**
	 * @var array
	 */
	public $enabled_methods;

	/**
	 * Whether verbose diagnostic logging is enabled.
	 *
	 * @var string
	 */
	public $debug_logging;

	/**
	 * Correlates all PayDo log records created during the current HTTP request.
	 *
	 * @var string
	 */
	private $log_trace_id = '';

	public function __construct()
	{
		$this->api_url = 'https://api.paydo.com/v1/invoices/create';

		$this->id = PAYDO_PAYMENT_GATEWAY_NAME;
		$this->icon = apply_filters('woocommerce_paydo_icon', PAYDO_PLUGIN_URL . 'logo.png');

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->public_key = $this->get_option('public_key');
		$this->secret_key = $this->get_option('secret_key');
		$this->skip_confirm = $this->get_option('skip_confirm');
		$this->lifetime = $this->get_option('lifetime');
		$this->auto_complete = $this->get_option('auto_complete');
		$this->language = 'en';
		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');

		$this->methods_mode = $this->get_option('methods_mode') === 'yes';
		$this->project_id = $this->get_option('project_id');
		$this->jwt_token = $this->get_option('jwt_token');
		$this->enabled_methods = (array) $this->get_option('enabled_methods', []);
		$this->debug_logging = $this->get_option('debug_logging', 'no');

		//Actions
		add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);

		add_filter( 'woocommerce_order_needs_payment', [$this, 'prevent_payment_for_failed_orders'], 10, 3 );

		// hide buttons "Buy again"
		add_action('woocommerce_my_account_my_orders_actions', [$this, 'hide_pay_button_for_failed_orders'], 10, 2);
		add_filter('render_block', [$this, 'modify_wc_order_confirmation_block_content'], 10, 2);

		//Payment listner/API hook
		add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);

		//Save options
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
		add_action('wp_ajax_paydo_sync_methods', [$this, 'ajax_sync_methods']);
		add_action('woocommerce_checkout_process', [$this, 'validate_paydo_method']);
		add_action('woocommerce_checkout_create_order', [$this, 'save_paydo_method'], 10, 2);
		add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'store_api_save_paydo_method'], 10, 2);

		if (!$this->is_valid_for_use()) {
			$this->enabled = false;
		}
	}

	/**
	 * Write a structured, redacted diagnostic record to WooCommerce logs.
	 *
	 * @param string $level   WooCommerce log level.
	 * @param string $event   Stable event name.
	 * @param array  $context Diagnostic values.
	 */
	private function log_event($level, $event, $context = [])
	{
		if ($this->debug_logging !== 'yes' || !function_exists('wc_get_logger')) {
			return;
		}

		if ($this->log_trace_id === '') {
			$this->log_trace_id = function_exists('wp_generate_uuid4')
				? wp_generate_uuid4()
				: uniqid('paydo-', true);
		}

		$allowed_levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
		if (!in_array($level, $allowed_levels, true)) {
			$level = 'debug';
		}

		$payload = $this->sanitize_log_value(array_merge([
			'trace_id' => $this->log_trace_id,
			'event' => (string) $event,
			'plugin_version' => defined('PAYDO_PLUGIN_VERSION') ? PAYDO_PLUGIN_VERSION : '',
		], is_array($context) ? $context : []));

		$message = '[' . $event . '] ' . wp_json_encode(
			$payload,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		wc_get_logger()->log($level, $message, ['source' => 'paydo']);
	}

	/**
	 * Remove credentials and customer data before writing diagnostic context.
	 *
	 * @param mixed  $value Value to sanitize.
	 * @param string $key   Parent key.
	 * @return mixed
	 */
	private function sanitize_log_value($value, $key = '')
	{
		$key_lower = strtolower((string) $key);
		$redacted_fragments = [
			'secret', 'token', 'signature', 'authorization', 'password',
			'cookie', 'publickey', 'payer', 'email', 'phone', 'billing',
			'first_name', 'last_name', 'card', 'pan', 'cvv', 'iban',
		];

		foreach ($redacted_fragments as $fragment) {
			if ($key_lower !== '' && strpos($key_lower, $fragment) !== false) {
				return '[REDACTED]';
			}
		}

		if (is_wp_error($value)) {
			return [
				'error_codes' => $value->get_error_codes(),
				'error_messages' => $value->get_error_messages(),
			];
		}

		if (is_array($value)) {
			$clean = [];
			foreach ($value as $child_key => $child_value) {
				$clean[$child_key] = $this->sanitize_log_value($child_value, (string) $child_key);
			}
			return $clean;
		}

		if (is_object($value)) {
			return ['object_class' => get_class($value)];
		}

		if (is_string($value)) {
			$value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[REDACTED_EMAIL]', $value);
			if (strlen($value) > 4000) {
				$value = substr($value, 0, 4000) . '...[TRUNCATED]';
			}
		}

		return $value;
	}

	/**
	 * Display receipt page after successful payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			$this->log_event('error', 'receipt.order_not_found', ['order_id' => $order_id]);
			return;
		}

		$this->log_event('info', 'receipt.opened', [
			'order_id' => $order->get_id(),
			'order_status' => $order->get_status(),
			'is_paid' => $order->is_paid(),
			'invoice_id' => (string) $order->get_meta(PAYDO_INVITATE_RESPONSE),
			'payment_method_id' => (string) $order->get_meta('_paydo_method'),
		]);

		if ($order->is_paid() || $order->has_status(['processing','completed'])) {
			$this->log_event('info', 'receipt.already_paid', ['order_id' => $order->get_id()]);
			$this->empty_cart();
			return;
		}

		if ($order->has_status(['failed','cancelled','refunded'])) {
			$this->log_event('warning', 'receipt.final_bad_status', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			echo '<p>This order cannot be paid. Please place a new order.</p>';
			return;
		}

		$invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));

		if ($invoice_id !== '') {
			$this->log_event('info', 'receipt.reusing_invoice', [
				'order_id' => $order->get_id(),
				'invoice_id' => $invoice_id,
			]);
			echo '<p>Payment is being confirmed. If you already paid, do not pay again. Refresh in a minute.</p>';
			$url = 'https://checkout.paydo.com/en/payment/invoice-preprocessing/' . $invoice_id;
			echo '<p><a class="button" href="'.esc_url($url).'">Continue payment</a></p>';
			return;
		}

		echo '<p>Thank you for your order, please click the button below to pay</p>';
		echo $this->generate_form($order_id);
	}

	/**
	 * Generate payment form.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	public function generate_form($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order) {
			$this->log_event('error', 'invoice.order_not_found', ['order_id' => $order_id]);
			return '<p>' . esc_html__('Order not found.', 'paydo-woocommerce') . '</p>';
		}

		$this->log_event('info', 'invoice.flow_started', [
			'order_id' => $order->get_id(),
			'order_status' => $order->get_status(),
			'amount' => (string) $order->get_total(),
			'currency' => $order->get_currency(),
			'payment_method' => $order->get_payment_method(),
			'payment_method_id' => (string) $order->get_meta('_paydo_method'),
			'existing_invoice_id' => (string) $order->get_meta(PAYDO_INVITATE_RESPONSE),
		]);

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'invoice.invalid_payment_method', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			]);
			return '<p>' . esc_html__('Invalid payment method for this order.', 'paydo-woocommerce') . '</p>';
		}

		$invoice_id = (string) $order->get_meta(PAYDO_INVITATE_RESPONSE);

		if (!$invoice_id) {
			$out_summ = number_format($order->get_total(), 4, '.', '');
			$currency = $order->get_currency();
			$site_url = get_site_url();

			$order_info = [
				'id'			 => $order_id,
				'amount'	 => $out_summ,
				'currency' => $currency,
			];

			ksort($order_info, SORT_STRING);
			$data_set = array_values($order_info);
			$data_set[] = $this->secret_key;
			$signature = hash(PAYDO_HASH_ALGORITHM, implode(':', $data_set));

			$first_name = $order->get_billing_first_name();
			$last_name	= $order->get_billing_last_name();

			$callback_base_url = site_url('/');

			$result_url = add_query_arg(
				[
					'wc-api'		=> 'wc_paydo',
					'paydo'		 => 'success',
					'orderId'	 => $order_id,
				],
				$callback_base_url
			);

			$fail_path = add_query_arg(
				[
					'wc-api'		=> 'wc_paydo',
					'paydo'		 => 'fail',
					'orderId'	 => $order_id,
				],
				$callback_base_url
			);

			$arr_data = [
				'publicKey' => $this->public_key,
				'order' => [
					'id'					=> (string) $order_id,
					'amount'			=> $out_summ,
					'currency'		=> $currency,
					'description' => __('Payment order #', 'paydo-woocommerce') . $order_id,
					'items'			 => [],
				],
				'signature' => $signature,
				'payer' => [
					'email' => $order->get_billing_email(),
					'name'	=> implode(' ', array_filter([$first_name, $last_name])),
					'phone' => $order->get_billing_phone() ?: '',
				],
				'language'	 => $this->language,
				'productUrl' => $site_url,
				'resultUrl'	=> $result_url,
				'failPath'	 => $fail_path,
			];

			if ($this->methods_mode) {
				$chosen = $order->get_meta('_paydo_method');
				if ($chosen) {
					$arr_data['paymentMethod'] = (int) $chosen;
				}
			}

			$this->log_event('info', 'invoice.create_request', [
				'order_id' => $order->get_id(),
				'request_url' => $this->api_url,
				'payload' => $arr_data,
				'methods_mode' => $this->methods_mode,
				'jwt_configured' => $this->jwt_token !== '',
			]);

			$invoice_id = $this->api_request($arr_data, PAYDO_API_IDENTIFIER);
			$this->log_event(is_string($invoice_id) && $invoice_id !== '' ? 'info' : 'error', 'invoice.create_result', [
				'order_id' => $order->get_id(),
				'invoice_id' => $invoice_id,
			]);

			if(is_array($invoice_id) && isset($invoice_id['messages'])) {
				return '<p>' . __('Request to payment service was sent incorrectly', 'paydo-woocommerce') . '</p><br><p>' . $invoice_id['messages'] .'</p>';
			}

			if (!is_string($invoice_id) || trim($invoice_id) === '') {
				$this->log_event('error', 'invoice.empty_identifier', ['order_id' => $order->get_id()]);
				return '<p>' . esc_html__('PayDo did not return an invoice identifier.', 'paydo-woocommerce') . '</p>';
			}

			$order->update_meta_data(PAYDO_INVITATE_RESPONSE, $invoice_id);
			$order->save();
			$this->log_event('info', 'invoice.saved_to_order', [
				'order_id' => $order->get_id(),
				'invoice_id' => $invoice_id,
			]);
		}

		$action_adr = 'https://checkout.paydo.com/' . $this->language . '/payment/invoice-preprocessing/' . $invoice_id;

		if ($this->skip_confirm === 'yes') {
			$this->log_event('info', 'checkout.redirecting_to_paydo', [
				'order_id' => $order->get_id(),
				'invoice_id' => $invoice_id,
				'checkout_url' => $action_adr,
			]);
			wp_redirect(esc_url($action_adr));
			exit;
		}

		$this->log_event('info', 'checkout.form_rendered', [
			'order_id' => $order->get_id(),
			'invoice_id' => $invoice_id,
			'checkout_url' => $action_adr,
		]);

		return $this->generate_payment_form_html($action_adr, $order);
	}

	/**
	 * Generates payment form HTML.
	 *
	 * @param string $action_adr The URL where the form should be submitted.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return string The generated HTML for the payment form.
	 */
	private function generate_payment_form_html($action_adr, $order)
	{
		$form_args = [
			'action' => esc_url($action_adr),
			'method' => 'GET',
			'id' => 'paydo_payment_form'
		];

		$form_attributes = array_map(function ($key, $value) {
			return $key . '="' . $value . '"';
		}, array_keys($form_args), $form_args);

		return '<form ' . implode(' ', $form_attributes) . '>' .
			'<input type="submit" class="button alt" id="submit_paydo_payment_form" value="' . __('Pay', 'paydo-woocommerce') . '" /> ' .
			'<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Refuse payment & return to cart', 'paydo-woocommerce') . '</a>' .
			'</form>';
	}

	/**
	 * Check Paydo IPN response and take appropriate actions.
	 */
	public function check_ipn_response()
	{
		$request_type = isset($_GET['paydo']) ? sanitize_key(wp_unslash($_GET['paydo'])) : '';
		$request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
		$raw_body = '';

		if ($request_method === 'POST') {
			$raw_body = (string) file_get_contents('php://input');
			$posted_data = json_decode($raw_body, true);
			if (!is_array($posted_data)) {
				$posted_data = [];
			}
		} else {
			$posted_data = $_GET;
		}

		$this->log_event('info', 'callback.received', [
			'request_type' => $request_type,
			'http_method' => $request_method,
			'remote_address' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
			'content_type' => isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '',
			'query' => wp_unslash($_GET),
			'body_length' => strlen($raw_body),
			'body_sha256' => $raw_body !== '' ? hash('sha256', $raw_body) : '',
			'json_error' => $raw_body !== '' ? json_last_error_msg() : '',
			'payload' => $posted_data,
		]);

		switch ($request_type) {
			case 'result':
				$this->process_result_request($posted_data);
				break;
			case 'success':
				$this->process_success_request($posted_data);
				break;
			case 'fail':
				$this->process_fail_request($posted_data);
				break;
			default:
				$this->log_event('error', 'callback.unknown_type', ['request_type' => $request_type]);
				$this->process_invalid_request();
		}
	}

	/**
	 * Process the result request (IPN V2).
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
	private function process_result_request($posted_data)
	{
		@ob_clean();

		$posted_data = is_array($posted_data) ? wp_unslash($posted_data) : [];
		$this->log_event('info', 'ipn.processing_started', [
			'invoice_id' => (string) ($posted_data['invoice']['id'] ?? ''),
			'txid' => (string) ($posted_data['transaction']['txid'] ?? ($posted_data['invoice']['txid'] ?? '')),
			'order_id' => (string) ($posted_data['transaction']['order']['id'] ?? ''),
			'ipn_state' => $posted_data['transaction']['state'] ?? null,
			'payment_method_id' => $posted_data['transaction']['paymentMethod'] ?? null,
		]);
		$valid = $this->check_ipn_request_is_valid($posted_data);
		$this->log_event($valid === PAYDO_IPN_VERSION_V2 ? 'info' : 'error', 'ipn.validation_result', [
			'result' => $valid,
		]);

		if ($valid !== PAYDO_IPN_VERSION_V2) {
			wp_die((string) $valid, (string) $valid, 400);
		}

		$order_id = $posted_data['transaction']['order']['id'] ?? null;
		$order_id = $order_id ? absint($order_id) : 0;

		if (!$order_id) {
			$this->log_event('error', 'ipn.empty_order_id');
			wp_die('Empty order id', 'Empty order id', 400);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			$this->log_event('error', 'ipn.order_not_found', ['order_id' => $order_id]);
			wp_die('Order not found', 'Order not found', 404);
		}

		$this->log_event('info', 'ipn.order_loaded', [
			'order_id' => $order->get_id(),
			'order_status' => $order->get_status(),
			'is_paid' => $order->is_paid(),
			'payment_method' => $order->get_payment_method(),
			'stored_invoice_id' => (string) $order->get_meta(PAYDO_INVITATE_RESPONSE),
			'stored_txid' => (string) $order->get_meta('_paydo_txid'),
		]);

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'ipn.invalid_payment_method', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			]);
			wp_die('Invalid payment method for this order', 'Invalid payment method', 403);
		}

		$stored_invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));
		$ipn_invoice_id = trim((string) ($posted_data['invoice']['id'] ?? ''));

		if ($ipn_invoice_id !== '' && $stored_invoice_id !== '' && $ipn_invoice_id !== $stored_invoice_id) {
			$this->log_event('error', 'ipn.invoice_mismatch', [
				'order_id' => $order->get_id(),
				'stored_invoice_id' => $stored_invoice_id,
				'ipn_invoice_id' => $ipn_invoice_id,
			]);
			$order->add_order_note(__('PayDo IPN invoiceId mismatch. Ignored.', 'paydo-woocommerce'));
			wp_die('IGNORED', 'IGNORED', 200);
		}

		$txid = '';
		if (isset($posted_data['transaction']['txid'])) {
			$txid = trim((string) $posted_data['transaction']['txid']);
		} elseif (isset($posted_data['invoice']['txid'])) {
			$txid = trim((string) $posted_data['invoice']['txid']);
		}

		if ($txid !== '') {
			$stored_txid = trim((string) $order->get_meta('_paydo_txid'));
			$this->log_event('info', 'ipn.txid_resolved', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'stored_txid' => $stored_txid,
			]);

			if ($stored_txid !== '' && $stored_txid !== $txid) {
				$this->log_event('error', 'ipn.txid_mismatch', [
					'order_id' => $order->get_id(),
					'txid' => $txid,
					'stored_txid' => $stored_txid,
				]);
				$order->add_order_note(__('PayDo IPN txid mismatch. Ignored.', 'paydo-woocommerce'));
				wp_die('IGNORED', 'IGNORED', 200);
			}

			$this->log_event('info', 'confirmation.started', ['order_id' => $order->get_id(), 'txid' => $txid]);
			$res = $this->confirm_paydo_order_by_txid($order, $txid, $posted_data);
			$this->log_event(!empty($res['ok']) ? 'info' : 'error', 'confirmation.result', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'result' => $res,
				'order_status_after' => $order->get_status(),
				'is_paid_after' => $order->is_paid(),
			]);

			$state = (string) ($res['state'] ?? '');
			$verification_scheduled = false;
			if ($state === 'pending' || !empty($res['retryable'])) {
				$verification_scheduled = $this->schedule_payment_verification(
					$order,
					$txid,
					1,
					$state === 'pending' ? 'PayDo status is pending after IPN' : (string) ($res['error'] ?? 'Temporary verification error')
				);
			}

			if (!empty($res['ok']) && ($res['state'] ?? '') === 'paid' && $stored_txid === '') {
				$order->update_meta_data('_paydo_txid', $txid);
				$order->delete_meta_data('_paydo_pending_txid');
				$order->delete_meta_data('_paydo_verification_attempt');
				$order->save();
				$this->log_event('info', 'ipn.txid_saved', ['order_id' => $order->get_id(), 'txid' => $txid]);
			}

			do_action('paydo-ipn-request', $posted_data);

			if (!empty($res['ok']) && $state === 'paid') {
				$this->log_event('info', 'ipn.response_paid', ['order_id' => $order->get_id(), 'http_status' => 200]);
				wp_die('PAID', 'PAID', 200);
			}

			if (!empty($res['ok']) && $state === 'failed') {
				$this->log_event('warning', 'ipn.response_failed', ['order_id' => $order->get_id(), 'http_status' => 200]);
				wp_die('FAILED', 'FAILED', 200);
			}

			if (!empty($res['ok']) && $state === 'pending') {
				$this->log_event('notice', 'ipn.response_wait', [
					'order_id' => $order->get_id(),
					'http_status' => $verification_scheduled ? 200 : 503,
					'verification_scheduled' => $verification_scheduled,
				]);
				wp_die('WAIT', 'WAIT', $verification_scheduled ? 200 : 503);
			}

			$this->log_event('error', 'ipn.response_check_failed', [
				'order_id' => $order->get_id(),
				'http_status' => $verification_scheduled ? 200 : 503,
				'verification_scheduled' => $verification_scheduled,
				'result' => $res,
			]);
			$response_message = $verification_scheduled ? 'CHECK_QUEUED' : 'CHECK_FAILED';
			wp_die($response_message, $response_message, $verification_scheduled ? 200 : 503);
		}

		$this->log_event('warning', 'ipn.missing_txid', ['order_id' => $order->get_id()]);

		if (!$order->has_status(['on-hold', 'pending'])) {
			$order->update_status(
				'on-hold',
				__('PayDo IPN received without txid. Waiting.', 'paydo-woocommerce'),
				true
			);
		}

		do_action('paydo-ipn-request', $posted_data);
		$this->log_event('notice', 'ipn.response_wait_without_txid', ['order_id' => $order->get_id(), 'http_status' => 200]);
		wp_die('WAIT', 'WAIT', 200);
	}

	/**
	 * Process the success request (no-trust redirect).
	 *
	 * We DO NOT trust any GET params (invoiceId/txid). Just mark on-hold and wait IPN/polling.
	 *
	 * @param array $posted_data
	 * @return void
	 */
	private function process_success_request($posted_data)
	{
		$order_id = $posted_data['transaction']['order']['id'] ?? ($posted_data['orderId'] ?? null);
		$order_id = $order_id ? absint($order_id) : 0;
		$this->log_event('info', 'return.success_received', ['order_id' => $order_id, 'payload' => $posted_data]);

		$order = $order_id ? wc_get_order($order_id) : null;
		if (!$order) {
			$this->log_event('error', 'return.success_order_not_found', ['order_id' => $order_id]);
			wp_die('Order not found', 'Order not found', 404);
		}

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'return.success_invalid_payment_method', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			]);
			wp_die('Invalid payment method for this order', 'Invalid payment method', 403);
		}

		// If already paid — just finish UX.
		if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
			$this->log_event('info', 'return.success_already_paid', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// If order is in a final bad state — do not try to change it.
		if ($order->has_status(['failed', 'cancelled', 'refunded'])) {
			$this->log_event('warning', 'return.success_final_bad_status', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// No trust: do NOT use invoiceId/txid from redirect.
		if (!$order->has_status(['on-hold', 'pending'])) {
			$previous_status = $order->get_status();
			$order->update_status(
				'on-hold',
				__('Returned from PayDo checkout (success redirect). Waiting for confirmation (IPN/polling).', 'paydo-woocommerce'),
				true
			);
			$this->log_event('notice', 'return.success_status_changed', [
				'order_id' => $order->get_id(),
				'from_status' => $previous_status,
				'to_status' => $order->get_status(),
			]);
		}

		$this->log_event('info', 'return.success_redirecting_to_order', [
			'order_id' => $order->get_id(),
			'order_status' => $order->get_status(),
		]);
		$this->empty_cart();
		wp_redirect($this->get_return_url($order));
		exit;
	}

	/**
	 * Process the fail request (no-trust redirect).
	 *
	 * We DO NOT trust any GET params (invoiceId/txid). Just mark on-hold and wait IPN/polling.
	 *
	 * @param array $posted_data
	 * @return void
	 */
	private function process_fail_request($posted_data)
	{
		$order_id = $posted_data['transaction']['order']['id'] ?? ($posted_data['orderId'] ?? null);
		$order_id = $order_id ? absint($order_id) : 0;
		$this->log_event('warning', 'return.fail_received', ['order_id' => $order_id, 'payload' => $posted_data]);

		$order = $order_id ? wc_get_order($order_id) : null;
		if (!$order) {
			$this->log_event('error', 'return.fail_order_not_found', ['order_id' => $order_id]);
			wp_die('Order not found', 'Order not found', 404);
		}

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'return.fail_invalid_payment_method', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			]);
			wp_die('Invalid payment method for this order', 'Invalid payment method', 403);
		}

		// If already paid — just finish UX.
		if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
			$this->log_event('info', 'return.fail_but_already_paid', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// If already final bad — keep it as is.
		if ($order->has_status(['failed', 'cancelled', 'refunded'])) {
			$this->log_event('warning', 'return.fail_final_bad_status', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// No trust: do NOT use invoiceId/txid from redirect.
		if (!$order->has_status(['on-hold', 'pending'])) {
			$previous_status = $order->get_status();
			$order->update_status(
				'on-hold',
				__('Returned from PayDo checkout (fail/close). Waiting for confirmation (IPN/polling).', 'paydo-woocommerce'),
				true
			);
			$this->log_event('notice', 'return.fail_status_changed', [
				'order_id' => $order->get_id(),
				'from_status' => $previous_status,
				'to_status' => $order->get_status(),
			]);
		} else {
			$order->add_order_note(__('Returned from PayDo with FAIL/CLOSE. Waiting for confirmation (IPN/polling).', 'paydo-woocommerce'), true);
			$this->log_event('notice', 'return.fail_status_unchanged', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
		}

		$this->empty_cart();
		wp_redirect($this->get_return_url($order));
		exit;
	}

	 /**
	 * Process the invalid request.
	 *
	 * @return void
	 */
	private function process_invalid_request()
	{
		$this->log_event('error', 'callback.invalid_request', ['http_status' => 400]);
		wp_die('Invalid request', 'Invalid request', 400);
	}

	/**
	 * Checks if payment is needed for an order with the Paydo payment gateway
	 * and disables payment for orders with 'failed' status.
	 *
	 * @param bool	 $needs_payment		The current value indicating whether payment is needed for the order.
	 * @param object $order				The order object.
	 * @param array	$valid_order_statuses An array of valid order statuses.
	 * @return bool Returns false if payment is not required for orders with 'failed' status and the Paydo payment gateway.
	 */
	public function prevent_payment_for_failed_orders( $needs_payment, $order, $valid_order_statuses )
	{
		if ( $order->has_status( 'failed' ) && $order->get_payment_method() === PAYDO_PAYMENT_GATEWAY_NAME ) {
			$needs_payment = false;
		}

		return $needs_payment;
	}

	/**
	 * Process payment and redirect to payment gateway.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id )
	{
		$order = wc_get_order( $order_id );
		$this->log_event($order ? 'info' : 'error', 'checkout.process_payment', [
			'order_id' => $order_id,
			'order_status' => $order ? $order->get_status() : null,
			'amount' => $order ? (string) $order->get_total() : null,
			'currency' => $order ? $order->get_currency() : null,
			'payment_method_id' => $order ? (string) $order->get_meta('_paydo_method') : '',
			'redirect_url' => $order ? $order->get_checkout_payment_url(true) : '',
		]);

		return [
			'result'	 => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	/**
	 * Schedule a server-side PayDo status verification without trusting IPN state.
	 *
	 * @param WC_Order $order   WooCommerce order.
	 * @param string   $txid    PayDo transaction ID from the matched IPN.
	 * @param int      $attempt Verification attempt number.
	 * @param string   $reason  Diagnostic reason.
	 * @return bool Whether the job is scheduled (or was already scheduled).
	 */
	private function schedule_payment_verification($order, $txid, $attempt, $reason = '')
	{
		if (!$order instanceof WC_Order) {
			return false;
		}

		$txid = trim((string) $txid);
		$attempt = max(1, absint($attempt));
		if ($txid === '' || $order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'verification.schedule_rejected', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'attempt' => $attempt,
				'payment_method' => $order->get_payment_method(),
			]);
			return false;
		}

		$max_attempts = max(1, (int) apply_filters('paydo_verification_max_attempts', 8, $order));
		if ($attempt > $max_attempts) {
			$this->log_event('error', 'verification.max_attempts_reached', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'attempt' => $attempt,
				'max_attempts' => $max_attempts,
			]);
			return false;
		}

		$pending_txid = trim((string) $order->get_meta('_paydo_pending_txid'));
		if ($pending_txid !== '' && $pending_txid !== $txid) {
			$this->log_event('error', 'verification.pending_txid_mismatch', [
				'order_id' => $order->get_id(),
				'pending_txid' => $pending_txid,
				'ipn_txid' => $txid,
			]);
			return false;
		}

		$order->update_meta_data('_paydo_pending_txid', $txid);
		$order->update_meta_data('_paydo_verification_attempt', $attempt);
		$order->save();

		$default_delays = [1 => 10, 2 => 30, 3 => 60, 4 => 120, 5 => 300, 6 => 600, 7 => 1200, 8 => 1800];
		$delay = isset($default_delays[$attempt]) ? $default_delays[$attempt] : 1800;
		$delay = max(1, (int) apply_filters('paydo_verification_retry_delay', $delay, $attempt, $order));
		$args = [$order->get_id(), $txid, $attempt];
		$scheduled = false;
		$already_scheduled = false;

		if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
			$already_scheduled = as_next_scheduled_action('paydo_verify_payment', $args, 'paydo') !== false;
			$scheduled = $already_scheduled || (bool) as_schedule_single_action(
				time() + $delay,
				'paydo_verify_payment',
				$args,
				'paydo'
			);
		} else {
			$already_scheduled = wp_next_scheduled('paydo_verify_payment', $args) !== false;
			$scheduled = $already_scheduled || wp_schedule_single_event(time() + $delay, 'paydo_verify_payment', $args);
		}

		$this->log_event($scheduled ? 'notice' : 'error', 'verification.scheduled', [
			'order_id' => $order->get_id(),
			'txid' => $txid,
			'attempt' => $attempt,
			'max_attempts' => $max_attempts,
			'delay_seconds' => $delay,
			'already_scheduled' => $already_scheduled,
			'scheduled' => $scheduled,
			'reason' => $reason,
		]);

		return $scheduled;
	}

	/**
	 * Re-query PayDo from the server and complete an order only after all
	 * transaction and invoice checks succeed.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $txid     PayDo transaction ID.
	 * @param int    $attempt  Current attempt number.
	 */
	public function retry_payment_verification($order_id, $txid, $attempt = 1)
	{
		$order_id = absint($order_id);
		$txid = trim((string) $txid);
		$attempt = max(1, absint($attempt));
		$order = $order_id ? wc_get_order($order_id) : null;

		$this->log_event('info', 'verification.retry_started', [
			'order_id' => $order_id,
			'txid' => $txid,
			'attempt' => $attempt,
			'order_found' => (bool) $order,
		]);

		if (!$order || $txid === '' || $order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'verification.retry_invalid_input', [
				'order_id' => $order_id,
				'txid' => $txid,
				'payment_method' => $order ? $order->get_payment_method() : '',
			]);
			return;
		}

		if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
			$order->delete_meta_data('_paydo_pending_txid');
			$order->delete_meta_data('_paydo_verification_attempt');
			$order->save();
			$this->log_event('info', 'verification.retry_already_paid', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			return;
		}

		if ($order->has_status(['failed', 'cancelled', 'refunded'])) {
			$order->delete_meta_data('_paydo_pending_txid');
			$order->delete_meta_data('_paydo_verification_attempt');
			$order->save();
			$this->log_event('warning', 'verification.retry_final_order_status', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
			return;
		}

		$pending_txid = trim((string) $order->get_meta('_paydo_pending_txid'));
		if ($pending_txid === '' || $pending_txid !== $txid) {
			$this->log_event('error', 'verification.retry_txid_mismatch', [
				'order_id' => $order->get_id(),
				'pending_txid' => $pending_txid,
				'job_txid' => $txid,
			]);
			return;
		}

		$invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));
		$verification_data = [
			'invoice' => ['id' => $invoice_id, 'txid' => $txid],
			'transaction' => [
				'order' => ['id' => (string) $order->get_id()],
				'state' => 2,
			],
		];

		$result = $this->confirm_paydo_order_by_txid($order, $txid, $verification_data);
		$state = (string) ($result['state'] ?? '');
		$this->log_event(!empty($result['ok']) ? 'info' : 'error', 'verification.retry_result', [
			'order_id' => $order->get_id(),
			'txid' => $txid,
			'attempt' => $attempt,
			'result' => $result,
			'order_status' => $order->get_status(),
			'is_paid' => $order->is_paid(),
		]);

		if (!empty($result['ok']) && $state === 'paid') {
			$order->update_meta_data('_paydo_txid', $txid);
			$order->delete_meta_data('_paydo_pending_txid');
			$order->delete_meta_data('_paydo_verification_attempt');
			$order->save();
			$this->log_event('info', 'verification.retry_completed_paid', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'attempt' => $attempt,
				'order_status' => $order->get_status(),
			]);
			return;
		}

		if (!empty($result['ok']) && $state === 'failed') {
			$order->delete_meta_data('_paydo_pending_txid');
			$order->delete_meta_data('_paydo_verification_attempt');
			$order->save();
			$this->log_event('warning', 'verification.retry_completed_failed', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'attempt' => $attempt,
			]);
			return;
		}

		$should_retry = $state === 'pending' || !empty($result['retryable']);
		$max_attempts = max(1, (int) apply_filters('paydo_verification_max_attempts', 8, $order));
		if ($should_retry && $attempt < $max_attempts) {
			$this->schedule_payment_verification(
				$order,
				$txid,
				$attempt + 1,
				$state === 'pending' ? 'PayDo still reports pending' : (string) ($result['error'] ?? 'Temporary verification error')
			);
			return;
		}

		$order->add_order_note(
			__('PayDo payment verification could not be completed automatically. Manual review is required.', 'paydo-woocommerce')
		);
		$this->log_event('error', 'verification.retry_stopped', [
			'order_id' => $order->get_id(),
			'txid' => $txid,
			'attempt' => $attempt,
			'max_attempts' => $max_attempts,
			'result' => $result,
		]);
	}

	private function confirm_paydo_order_by_txid($order, $txid, $posted_data = [])
	{
		if (!$order instanceof WC_Order) {
			$this->log_event('error', 'confirmation.invalid_order_object', ['txid' => $txid]);
			return ['ok' => false, 'error' => 'Invalid order'];
		}

		$this->log_event('info', 'confirmation.input', [
			'order_id' => $order->get_id(),
			'order_status' => $order->get_status(),
			'is_paid' => $order->is_paid(),
			'txid' => $txid,
			'expected_invoice_id' => (string) $order->get_meta(PAYDO_INVITATE_RESPONSE),
			'ipn_invoice_id' => (string) ($posted_data['invoice']['id'] ?? ''),
			'ipn_order_id' => (string) ($posted_data['transaction']['order']['id'] ?? ''),
			'ipn_state' => $posted_data['transaction']['state'] ?? null,
		]);

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			$this->log_event('error', 'confirmation.invalid_payment_method', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			]);
			return ['ok' => false, 'error' => 'Invalid payment method'];
		}

		$expected_invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));
		$ipn_invoice_id = trim((string) ($posted_data['invoice']['id'] ?? ''));
		$ipn_order_id = (string) ($posted_data['transaction']['order']['id'] ?? '');
		$ipn_state = (int) ($posted_data['transaction']['state'] ?? 0);

		if ($expected_invoice_id === '' || $ipn_invoice_id === '' || $expected_invoice_id !== $ipn_invoice_id) {
			$this->log_event('error', 'confirmation.invoice_mismatch', [
				'order_id' => $order->get_id(),
				'expected_invoice_id' => $expected_invoice_id,
				'ipn_invoice_id' => $ipn_invoice_id,
			]);
			$order->add_order_note(__('PayDo invoice mismatch.', 'paydo-woocommerce'));
			return ['ok' => false, 'error' => 'Invoice mismatch'];
		}

		if ($ipn_order_id === '' || $ipn_order_id !== (string) $order->get_id()) {
			$this->log_event('error', 'confirmation.order_id_mismatch', [
				'order_id' => $order->get_id(),
				'ipn_order_id' => $ipn_order_id,
			]);
			$order->add_order_note(__('PayDo order ID mismatch in IPN.', 'paydo-woocommerce'));
			return ['ok' => false, 'error' => 'Order ID mismatch'];
		}

		if ($ipn_state !== 2) {
			$this->log_event('notice', 'confirmation.ipn_not_accepted', [
				'order_id' => $order->get_id(),
				'ipn_state' => $ipn_state,
			]);
			if (in_array($ipn_state, [3, 5], true)) {
				if (!$order->has_status('failed')) {
					$order->update_status('failed', __('PayDo transaction confirmed as FAILED.', 'paydo-woocommerce'), true);
				}
				$this->log_event('warning', 'confirmation.failed_from_ipn', [
					'order_id' => $order->get_id(),
					'ipn_state' => $ipn_state,
					'order_status' => $order->get_status(),
				]);

				return ['ok' => true, 'final' => true, 'state' => 'failed'];
			}

			if (!$order->has_status(['on-hold', 'pending'])) {
				$order->update_status('on-hold', __('PayDo transaction pending. Waiting for confirmation.', 'paydo-woocommerce'), true);
			}
			$this->log_event('notice', 'confirmation.pending_from_ipn', [
				'order_id' => $order->get_id(),
				'ipn_state' => $ipn_state,
				'order_status' => $order->get_status(),
			]);

			return ['ok' => true, 'final' => false, 'state' => 'pending', 'retryable' => true];
		}

		$this->log_event('info', 'confirmation.transaction_check_started', [
			'order_id' => $order->get_id(),
			'txid' => $txid,
		]);
		$status_check = $this->fetch_transaction_status($txid);
		$this->log_event(!empty($status_check['ok']) ? 'info' : 'error', 'confirmation.transaction_check_result', [
			'order_id' => $order->get_id(),
			'txid' => $txid,
			'result' => $status_check,
		]);
		if (empty($status_check['ok'])) {
			return $status_check;
		}

		$status_code = (int) ($status_check['status_code'] ?? 0);
		$status_txid = (string) ($status_check['txid'] ?? '');

		if ($status_txid === '' || $status_txid !== $txid) {
			$this->log_event('error', 'confirmation.transaction_id_mismatch', [
				'order_id' => $order->get_id(),
				'expected_txid' => $txid,
				'api_txid' => $status_txid,
			]);
			$order->add_order_note(__('PayDo transaction identifier mismatch.', 'paydo-woocommerce'));
			return ['ok' => false, 'error' => 'Transaction identifier mismatch'];
		}

		if (in_array($status_code, [3, 5], true)) {
			$this->log_event('notice', 'confirmation.transaction_not_accepted', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'status_code' => $status_code,
				'status_raw' => $status_check['status_raw'] ?? null,
			]);
			if (!$order->has_status('failed')) {
				$order->update_status('failed', __('PayDo transaction confirmed as FAILED.', 'paydo-woocommerce'), true);
			}
			$this->log_event('warning', 'confirmation.failed_from_transaction_api', [
				'order_id' => $order->get_id(),
				'status_code' => $status_code,
				'order_status' => $order->get_status(),
			]);

			return ['ok' => true, 'final' => true, 'state' => 'failed'];
		}

		$transaction_is_accepted = $status_code === 2;
		$transaction_allows_invoice_check = $transaction_is_accepted || in_array($status_code, [1, 4], true);

		if (!$transaction_allows_invoice_check) {
			$this->log_event('error', 'confirmation.transaction_status_unknown', [
				'order_id' => $order->get_id(),
				'status_code' => $status_code,
				'status_raw' => $status_check['status_raw'] ?? null,
			]);
			return ['ok' => false, 'error' => 'Unknown PayDo transaction status', 'state' => 'pending', 'retryable' => true];
		}

		if (!$transaction_is_accepted) {
			$this->log_event('notice', 'confirmation.transaction_pending_checking_invoice', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'status_code' => $status_code,
				'status_raw' => $status_check['status_raw'] ?? null,
			]);
		}

		$this->log_event('info', 'confirmation.invoice_check_started', [
			'order_id' => $order->get_id(),
			'invoice_id' => $expected_invoice_id,
		]);
		$invoice_check = $this->fetch_invoice_details($expected_invoice_id);
		$this->log_event(!empty($invoice_check['ok']) ? 'info' : 'error', 'confirmation.invoice_check_result', [
			'order_id' => $order->get_id(),
			'invoice_id' => $expected_invoice_id,
			'result' => $invoice_check,
		]);
		if (empty($invoice_check['ok'])) {
			return $invoice_check;
		}

		$expected_order_id = (string) $order->get_id();
		$expected_amount = number_format((float) $order->get_total(), 4, '.', '');
		$expected_currency = strtoupper((string) $order->get_currency());

		$invoice_id = (string) ($invoice_check['invoice_id'] ?? '');
		$invoice_status = (int) ($invoice_check['status'] ?? -1);
		$invoice_txid = (string) ($invoice_check['txid'] ?? '');
		$invoice_order_id = (string) ($invoice_check['order_id'] ?? '');
		$invoice_amount = $invoice_check['amount'] !== ''
			? number_format((float) $invoice_check['amount'], 4, '.', '')
			: '';
		$invoice_currency = strtoupper((string) ($invoice_check['currency'] ?? ''));

		if ($invoice_id === '' || $invoice_id !== $expected_invoice_id) {
			$this->log_event('error', 'confirmation.invoice_identifier_mismatch', [
				'order_id' => $order->get_id(),
				'expected_invoice_id' => $expected_invoice_id,
				'api_invoice_id' => $invoice_id,
			]);
			$order->add_order_note(__('PayDo invoice identifier mismatch.', 'paydo-woocommerce'));
			return ['ok' => false, 'error' => 'Invoice identifier mismatch'];
		}

		if ($invoice_status !== 1) {
			$this->log_event('notice', 'confirmation.invoice_not_paid', [
				'order_id' => $order->get_id(),
				'invoice_id' => $expected_invoice_id,
				'invoice_status' => $invoice_status,
			]);
			if (!$order->has_status(['on-hold', 'pending'])) {
				$order->update_status('on-hold', __('PayDo invoice is not paid yet. Waiting for confirmation.', 'paydo-woocommerce'), true);
			}

			return ['ok' => true, 'final' => false, 'state' => 'pending', 'retryable' => true];
		}

		if ($invoice_txid === '' || $invoice_txid !== $txid) {
			$this->log_event('error', 'confirmation.invoice_transaction_id_mismatch', [
				'order_id' => $order->get_id(),
				'expected_txid' => $txid,
				'invoice_txid' => $invoice_txid,
			]);
			$order->add_order_note(__('PayDo invoice transaction identifier mismatch.', 'paydo-woocommerce'));
			return ['ok' => false, 'error' => 'Invoice transaction identifier mismatch'];
		}

		if ($invoice_order_id === '' || $invoice_order_id !== $expected_order_id) {
			$this->log_event('error', 'confirmation.invoice_order_id_mismatch', [
				'order_id' => $order->get_id(),
				'expected_order_id' => $expected_order_id,
				'invoice_order_id' => $invoice_order_id,
			]);
			$order->add_order_note(
				sprintf(
					__('PayDo invoice order ID mismatch. Expected %1$s, got %2$s.', 'paydo-woocommerce'),
					$expected_order_id,
					$invoice_order_id !== '' ? $invoice_order_id : 'empty'
				)
			);

			return ['ok' => false, 'error' => 'Invoice order ID mismatch'];
		}

		if ($invoice_amount === '' || $invoice_amount !== $expected_amount) {
			$this->log_event('error', 'confirmation.invoice_amount_mismatch', [
				'order_id' => $order->get_id(),
				'expected_amount' => $expected_amount,
				'invoice_amount' => $invoice_amount,
			]);
			$order->add_order_note(
				sprintf(
					__('PayDo invoice amount mismatch. Expected %1$s, got %2$s.', 'paydo-woocommerce'),
					$expected_amount,
					$invoice_amount !== '' ? $invoice_amount : 'empty'
				)
			);

			return ['ok' => false, 'error' => 'Invoice amount mismatch'];
		}

		if ($invoice_currency === '' || $invoice_currency !== $expected_currency) {
			$this->log_event('error', 'confirmation.invoice_currency_mismatch', [
				'order_id' => $order->get_id(),
				'expected_currency' => $expected_currency,
				'invoice_currency' => $invoice_currency,
			]);
			$order->add_order_note(
				sprintf(
					__('PayDo invoice currency mismatch. Expected %1$s, got %2$s.', 'paydo-woocommerce'),
					$expected_currency,
					$invoice_currency !== '' ? $invoice_currency : 'empty'
				)
			);

			return ['ok' => false, 'error' => 'Invoice currency mismatch'];
		}

		if (!$transaction_is_accepted) {
			$this->log_event('notice', 'confirmation.paid_by_verified_invoice', [
				'order_id' => $order->get_id(),
				'invoice_id' => $invoice_id,
				'txid' => $txid,
				'transaction_status_code' => $status_code,
				'transaction_status_raw' => $status_check['status_raw'] ?? null,
				'invoice_status' => $invoice_status,
			]);
		}

		if (!$order->is_paid()) {
			$this->log_event('info', 'confirmation.payment_complete_call', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'order_status_before' => $order->get_status(),
			]);
			$order->payment_complete($txid);
			$this->log_event('info', 'confirmation.payment_complete_result', [
				'order_id' => $order->get_id(),
				'txid' => $txid,
				'order_status_after' => $order->get_status(),
				'is_paid_after' => $order->is_paid(),
			]);
		} else {
			$this->log_event('info', 'confirmation.order_already_paid', [
				'order_id' => $order->get_id(),
				'order_status' => $order->get_status(),
			]);
		}

		if ($this->auto_complete === 'yes' && !$order->has_status('completed')) {
			$order->update_status('completed', __('PayDo transaction confirmed as PAID.', 'paydo-woocommerce'));
		} elseif (!$order->has_status(['processing', 'completed'])) {
			$order->update_status('processing', __('PayDo transaction confirmed as PAID.', 'paydo-woocommerce'));
		}

		$this->log_event('info', 'confirmation.completed', [
			'order_id' => $order->get_id(),
			'txid' => $txid,
			'order_status' => $order->get_status(),
			'is_paid' => $order->is_paid(),
			'auto_complete' => $this->auto_complete,
		]);

		return [
			'ok' => true,
			'final' => true,
			'state' => 'paid',
			'status_check' => $status_check,
			'invoice_check' => $invoice_check,
		];
	}

	/**
	 * Check Paydo IPN validity.
	 *
	 * @param array $posted Data received from Paydo IPN.
	 *
	 * @return bool|string
	 */
	public function check_ipn_request_is_valid($posted)
	{
		$invoice_id = isset($posted['invoice']['id']) ? trim((string) $posted['invoice']['id']) : '';

		$tx_id = '';
		if (isset($posted['transaction']['txid'])) {
			$tx_id = trim((string) $posted['transaction']['txid']);
		} elseif (isset($posted['invoice']['txid'])) {
			$tx_id = trim((string) $posted['invoice']['txid']);
		}

		$order_id = isset($posted['transaction']['order']['id']) ? absint($posted['transaction']['order']['id']) : 0;

		if ($invoice_id === '') return 'Empty invoice id (V2)';
		if ($tx_id === '') return 'Empty transaction id (V2)';
		if (!$order_id) return 'Empty order id (V2)';

		$order = wc_get_order($order_id);
		if (!$order) return 'Order not found';

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			return 'Invalid payment method';
		}

		$stored_invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));
		if ($stored_invoice_id !== '' && $stored_invoice_id !== $invoice_id) {
			return 'Invoice id mismatch (V2)';
		}

		return PAYDO_IPN_VERSION_V2;
	}

	/**
	 * Make an API request to Paydo.
	 *
	 * @param array	$arr_data Data to be sent in the request.
	 * @param string $retrieved_header Retrieved header.
	 *
	 * @return mixed
	 */
	public function api_request($arr_data = [], $retrieved_header = '')
	{
		$request_url = $this->api_url;
		$this->log_event('info', 'api.invoice_create.request', [
			'url' => $request_url,
			'payload' => $arr_data,
			'retrieved_header' => $retrieved_header,
			'jwt_configured' => $this->jwt_token !== '',
		]);

		$headers = [
			'Content-Type' => 'application/json',
		];

		if (!empty($this->jwt_token)) {
			$headers['token'] = trim((string) $this->jwt_token);
		}

		$args = [
			'timeout'	 => 45,
			'headers'	 => $headers,
			'body'			=> wp_json_encode($arr_data),
		];

		$response = wp_remote_post($request_url, $args);
		if (is_wp_error($response)) {
			$this->log_event('error', 'api.invoice_create.transport_error', [
				'url' => $request_url,
				'error' => $response,
			]);
			return null;
		}

		$http_code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$decoded_body = json_decode($body, true);
		$this->log_event($http_code >= 200 && $http_code < 300 ? 'info' : 'error', 'api.invoice_create.response', [
			'url' => $request_url,
			'http_status' => $http_code,
			'response' => is_array($decoded_body) ? $decoded_body : $body,
			'json_error' => is_array($decoded_body) ? '' : json_last_error_msg(),
		]);

		if ($retrieved_header !== '') {
			$header = wp_remote_retrieve_header($response, $retrieved_header);
			$this->log_event(!empty($header) ? 'info' : 'error', 'api.invoice_create.header', [
				'http_status' => $http_code,
				'header_name' => $retrieved_header,
				'header_value' => !empty($header) ? (string) $header : '',
			]);
			return !empty($header) ? $header : null;
		}

		return $decoded_body;
	}

	/**
	 * Check if this gateway is enabled and available in the user's country
	 * 
	 * @return bool
	 */
	public function is_valid_for_use()
	{
		return true;
	}

	/**
	 * Admin Panel Options.
	 *
	 * Options for bits like 'title' and availability on a country-by-country basis.
	 */
	public function admin_options()
	{
		?>
		<h3><?php _e('Paydo', 'paydo-woocommerce'); ?></h3>
		<p><?php _e('Take payments via Paydo.', 'paydo-woocommerce'); ?></p>

		<?php if ($this->is_valid_for_use()) : ?>

			<table class="form-table">
				<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
				?>
			</table>

		<?php
		endif;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields()
	{
		$this->form_fields = include PAYDO_PLUGIN_PATH . '/includes/settings-paydo.php';

		$methods = get_option('paydo_available_methods', []);
		if (!is_array($methods)) {
			$methods = [];
		}

		if (isset($this->form_fields['enabled_methods'])) {
			$this->form_fields['enabled_methods']['options'] = $methods;
		}
	}

	/**
	 * Payment fields displayed on the checkout page.
	 */
	public function payment_fields()
	{
		if ($this->description) {
			echo wpautop(wptexturize($this->description));
		}

		if (!$this->methods_mode) {
			return;
		}

		$available = get_option('paydo_available_methods', []);
		if (!is_array($available)) {
			$available = [];
		}

		$selected = array_values(array_filter((array) $this->enabled_methods));
		if (!$selected) {
			return;
		}

		echo '<fieldset class="paydo-methods" style="margin-top:12px;">';
		echo '<p><strong>' . esc_html__('Choose PayDo method:', 'paydo-woocommerce') . '</strong></p>';

		foreach ($selected as $identifier) {

			$item	= $available[$identifier] ?? [];
			$title = $item['title'] ?? ('Method #' . $identifier);
			$logo	= $item['logo'] ?? '';

			echo '<label style="
				display:flex;
				align-items:center;
				gap:10px;
				padding:8px 10px;
				margin:6px 0;
				border:1px solid #ddd;
				border-radius:8px;
				cursor:pointer;
			">';

			echo '<input
				type="radio"
				name="paydo_method"
				value="' . esc_attr($identifier) . '"
				required
				style="margin:0;"
			>';

			if ($logo) {
				echo '<img
					src="' . esc_url($logo) . '"
					alt=""
					style="
						height:22px;
						width:auto;
						object-fit:contain;
					"
				>';
			}

			echo '<span>' . esc_html($title) . '</span>';

			echo '</label>';
		}

		echo '</fieldset>';
	}

	/**
	 * Empty the WooCommerce cart.
	 *
	 * This method can be used to clear the cart when needed.
	 */
	public function empty_cart()
	{
		WC()->cart->empty_cart();
	}

	/**
	 * Hide the 'pay' button for failed orders.
	 *
	 * @param array $actions The list of actions.
	 * @param object $order The order object.
	 * @return array Modified list of actions.
	 */
	public function hide_pay_button_for_failed_orders( $actions, $order )
	{
		if ( $order->get_status() === 'failed' ) {
			unset( $actions['pay'] );
		}

		return $actions;
	}

	/**
	 * Modify the content of the WooCommerce order confirmation status block.
	 *
	 * @param string $block_content The content of the block.
	 * @param array $block The block data.
	 * @return string Modified block content.
	 */
	public function modify_wc_order_confirmation_block_content($block_content, $block)
	{
		if ($block['blockName'] === 'woocommerce/order-confirmation-status') {
			$pattern = '/<a[^>]*\bhref="([^"]*?pay_for_order=true[^"]*)"[^>]*>.*?<\/a>/i';

			if (preg_match($pattern, $block_content, $matches)) {
				$block_content = preg_replace($pattern, '', $block_content);
			}
		}

		return $block_content;
	}

	public function enqueue_admin_assets($hook)
	{
		if ($hook !== 'woocommerce_page_wc-settings') {
			return;
		}

		$section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';
		if ($section !== $this->id) {
			return;
		}

		wp_enqueue_script(
			'paydo-admin-settings',
			PAYDO_PLUGIN_URL . 'js/admin-settings.js',
			['jquery'],
			'1.0.0',
			true
		);
	}

	public function generate_paydo_sync_methods_html($key, $data)
	{
		$nonce = wp_create_nonce('paydo_sync_methods');

		ob_start(); ?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title'] ?? ''); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button" id="paydo-sync-methods-btn"
						data-nonce="<?php echo esc_attr($nonce); ?>">
					<?php esc_html_e('Sync from PayDo', 'paydo-woocommerce'); ?>
				</button>
				<span id="paydo-sync-methods-status" style="margin-left:10px;"></span>
				<p class="description"><?php echo esc_html($data['description'] ?? ''); ?></p>

				<script>
				(function(){
					const btn = document.getElementById('paydo-sync-methods-btn');
					if(!btn) return;

					btn.addEventListener('click', async function(){
						const status = document.getElementById('paydo-sync-methods-status');
						status.textContent = '...';

						const body = new URLSearchParams();
						body.append('action', 'paydo_sync_methods');
						body.append('nonce', btn.dataset.nonce);

						try {
							const res = await fetch(ajaxurl, { method:'POST', credentials:'same-origin', body });
							const json = await res.json();

							if(!json || !json.success) {
								status.textContent = (json && json.data && json.data.message) ? json.data.message : 'Error';
								return;
							}

							status.textContent = 'OK. Reloading...';
							window.location.reload();
						} catch (e) {
							status.textContent = 'Request failed';
						}
					});
				})();
				</script>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function ajax_sync_methods()
	{
		$this->log_event('info', 'methods.sync_started', [
			'user_can_manage_woocommerce' => current_user_can('manage_woocommerce'),
		]);
		if (!current_user_can('manage_woocommerce')) {
			$this->log_event('error', 'methods.sync_forbidden');
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('paydo_sync_methods', 'nonce');

		$project_id = trim((string) $this->get_option('project_id'));
		$jwt				= trim((string) $this->get_option('jwt_token'));

		if (!$project_id || !$jwt) {
			$this->log_event('error', 'methods.sync_missing_credentials', [
				'project_id_configured' => $project_id !== '',
				'jwt_configured' => $jwt !== '',
			]);
			wp_send_json_error(['message' => 'Fill Project ID and JWT token first'], 422);
		}

		$url = 'https://api.paydo.com/v1/instrument-settings/payment-methods/available-for-application/' . rawurlencode($project_id);
		$this->log_event('info', 'methods.sync_request', ['url' => $url, 'project_id' => $project_id]);

		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept'				=> 'application/json',
				'Authorization' => 'Bearer ' . $jwt,
			],
		]);

		if (is_wp_error($resp)) {
			$this->log_event('error', 'methods.sync_transport_error', ['url' => $url, 'error' => $resp]);
			wp_send_json_error(['message' => $resp->get_error_message()], 500);
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);

		$json = json_decode($body, true);
		$this->log_event($code === 200 && is_array($json) ? 'info' : 'error', 'methods.sync_response', [
			'url' => $url,
			'http_status' => $code,
			'response' => is_array($json) ? $json : $body,
		]);
		if ($code !== 200 || !is_array($json)) {
			wp_send_json_error([
				'message' => 'PayDo API error: invalid response',
				'http'		=> $code,
				'body'		=> mb_substr($body, 0, 1000),
			], $code ?: 500);
		}

		$data = $json['data'] ?? [];
		if (!is_array($data)) {
			$data = [];
		}

		$map = [];

		foreach ($data as $row) {

			if (!isset($row['paymentMethod']) || !is_array($row['paymentMethod'])) {
				continue;
			}

			$pm = $row['paymentMethod'];

			if (empty($pm['isEnabled'])) {
				continue;
			}

			if (empty($pm['identifier']) || empty($pm['title'])) {
				continue;
			}

			$map[(string) $pm['identifier']] = [
				'identifier' => (string) $pm['identifier'],
				'title' => (string) $pm['title'],
				'logo'	=> (string) $pm['logo'],
			];
		}

		if (!$map) {
			wp_send_json_error([
				'message' => 'No ENABLED payment methods found in PayDo response.',
			], 422);
		}

		asort($map, SORT_NATURAL | SORT_FLAG_CASE);

		update_option('paydo_available_methods', $map, false);
		$this->log_event('info', 'methods.sync_completed', [
			'count' => count($map),
			'identifiers' => array_keys($map),
		]);

		wp_send_json_success([
			'count' => count($map),
		]);
	}

	public function generate_paydo_methods_checkboxes_html($key, $data)
	{
		$field_key = $this->get_field_key($key); // woocommerce_{gatewayid}_enabled_methods
		$saved		 = (array) $this->get_option($key, []);
		$saved		 = array_values(array_unique(array_map('strval', $saved)));

		$options = $data['options'] ?? [];
		if (!is_array($options)) $options = [];

		ob_start(); ?>
		<tr valign="top" id="wrap-paydo-methods-search">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title'] ?? ''); ?></label>
			</th>
			<td class="forminp">

				<?php if (!empty($data['description'])) : ?>
					<p class="description"><?php echo esc_html($data['description']); ?></p>
				<?php endif; ?>

				<div style="margin:8px 0 10px; max-width:520px;">
					<input type="text"
							 id="paydo-methods-search"
							 placeholder="<?php echo esc_attr__('Search method...', 'paydo-woocommerce'); ?>"
							 style="width:100%; padding:8px 10px; border:1px solid #dcdcde; border-radius:6px;">
				</div>

				<div id="paydo-methods-box"
					 style="display:grid; gap:8px; max-height:320px; overflow:auto; padding:10px; border:1px solid #dcdcde; border-radius:8px; background:#fff; max-width:520px;">

					<?php if (!$options) : ?>
						<div style="opacity:.75;">
							<?php esc_html_e('No methods loaded yet. Click "Sync from PayDo" above.', 'paydo-woocommerce'); ?>
						</div>
					<?php else : ?>
						<?php foreach ($options as $identifier => $item) :
							$identifier = (string) $item['identifier'] ?? '';
							$title = (string) $item['title'] ?? '';
							$checked = in_array($identifier, $saved, true);
						?>
							<label class="paydo-method-row" data-title="<?php echo esc_attr(mb_strtolower($title)); ?>"
									 style="display:flex; align-items:flex-start; gap:10px; padding:6px 6px; border-radius:6px;">
								<input type="checkbox"
										 name="<?php echo esc_attr($field_key); ?>[]"
										 value="<?php echo esc_attr($identifier); ?>"
										 <?php checked($checked); ?>
										 style="margin-top:2px;" />
								<span>
									<strong><?php echo esc_html($title); ?></strong>
									<div style="font-size:12px; opacity:.65;">
										<?php echo esc_html('#' . $identifier); ?>
									</div>
								</span>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<script>
				(function(){
					const input = document.getElementById('paydo-methods-search');
					const box = document.getElementById('paydo-methods-box');
					if(!input || !box) return;

					const rows = box.querySelectorAll('.paydo-method-row');

					input.addEventListener('input', function(){
						const q = (input.value || '').trim().toLowerCase();
						rows.forEach(row => {
							const t = row.getAttribute('data-title') || '';
							row.style.display = (!q || t.includes(q)) ? '' : 'none';
						});
					});
				})();
				</script>

			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function validate_enabled_methods_field($key, $value)
	{
		if (!is_array($value)) {
			return [];
		}

		$value = array_map('wc_clean', $value);
		$value = array_filter($value, static fn($v) => $v !== '');

		return array_values(array_unique($value));
	}

	public function validate_paydo_method()
	{
		if (!$this->methods_mode) {
			return;
		}

		if (empty($_POST['payment_method']) || $_POST['payment_method'] !== $this->id) {
			return;
		}

		$method = isset($_POST['paydo_method']) ? wc_clean(wp_unslash($_POST['paydo_method'])) : '';
		$allowed = array_map('strval', (array)$this->enabled_methods);
		$this->log_event($method && in_array((string)$method, $allowed, true) ? 'info' : 'warning', 'checkout.method_validated', [
			'selected_method_id' => (string) $method,
			'is_allowed' => $method && in_array((string)$method, $allowed, true),
			'allowed_method_ids' => $allowed,
		]);

		if (!$method || !in_array((string)$method, $allowed, true)) {
			wc_add_notice(__('Please choose a PayDo payment method.', 'paydo-woocommerce'), 'error');
		}
	}

	public function save_paydo_method($order, $data)
	{
		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		if (!$this->methods_mode) {
			$order->delete_meta_data('_paydo_method');
			$this->log_event('info', 'checkout.method_cleared_classic', [
				'order_id' => $order->get_id(),
				'reason' => 'methods_mode_disabled',
			]);
			return;
		}

		$method = isset($_POST['paydo_method']) ? wc_clean(wp_unslash($_POST['paydo_method'])) : '';
		if ($method) {
			$order->update_meta_data('_paydo_method', (string)$method);
			$this->log_event('info', 'checkout.method_saved_classic', [
				'order_id' => $order->get_id(),
				'selected_method_id' => (string) $method,
			]);
		}
	}

	public function store_api_save_paydo_method($order, $request)
	{
		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		if (!$this->methods_mode) {
			$order->delete_meta_data('_paydo_method');
			$this->log_event('info', 'checkout.method_cleared_blocks', [
				'order_id' => $order->get_id(),
				'reason' => 'methods_mode_disabled',
			]);
			return;
		}

		$payment_data = $request->get_param('payment_data');
		if (!is_array($payment_data)) {
			$this->log_event('warning', 'checkout.blocks_payment_data_invalid', ['order_id' => $order->get_id()]);
			return;
		}

		$method = '';
		foreach ($payment_data as $row) {
			if (!is_array($row)) continue;
			if (($row['key'] ?? '') === 'paydo_method') {
				$method = wc_clean((string) ($row['value'] ?? ''));
				break;
			}
		}

		if ($method) {
			$order->update_meta_data('_paydo_method', $method);
			$this->log_event('info', 'checkout.method_saved_blocks', [
				'order_id' => $order->get_id(),
				'selected_method_id' => $method,
			]);
		}
	}

	private function fetch_transaction_status($txid)
	{
		$txid = trim((string) $txid);
		if ($txid === '') {
			$this->log_event('error', 'api.transaction_status.empty_txid');
			return ['ok' => false, 'error' => 'Empty txid'];
		}

		$url = 'https://api.paydo.com/v1/checkout/check-transaction-status/' . rawurlencode($txid);
		$this->log_event('info', 'api.transaction_status.request', ['url' => $url, 'txid' => $txid]);

		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($resp)) {
			$this->log_event('error', 'api.transaction_status.transport_error', [
				'url' => $url,
				'txid' => $txid,
				'error' => $resp,
			]);
			return ['ok' => false, 'error' => $resp->get_error_message(), 'retryable' => true];
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$json = json_decode($body, true);
		$this->log_event($code === 200 && is_array($json) ? 'info' : 'error', 'api.transaction_status.response', [
			'url' => $url,
			'txid' => $txid,
			'http_status' => $code,
			'response' => is_array($json) ? $json : $body,
			'json_error' => is_array($json) ? '' : json_last_error_msg(),
		]);

		if ($code !== 200 || !is_array($json)) {
			return [
				'ok' => false,
				'error' => 'Invalid PayDo status response',
				'retryable' => true,
				'http' => $code,
				'body' => mb_substr($body, 0, 1000),
			];
		}

		$data = $json['data'] ?? [];
		if (!is_array($data)) {
			$this->log_event('error', 'api.transaction_status.invalid_data', ['txid' => $txid, 'data' => $data]);
			return ['ok' => false, 'error' => 'Invalid PayDo status data', 'retryable' => true];
		}

		$status_raw = $data['status'] ?? null;
		$status_code = null;

		if (is_numeric($status_raw)) {
			$status_code = (int) $status_raw;
		} elseif (is_string($status_raw)) {
			$s = strtolower(trim($status_raw));

			switch ($s) {
				case 'new':
					$status_code = 1;
					break;
				case 'accepted':
				case 'success':
					$status_code = 2;
					break;
				case 'pending':
					$status_code = 4;
					break;
				case 'fail':
				case 'failed':
				case 'error':
					$status_code = 3;
					break;
			}
		}

		$result = [
			'ok' => true,
			'status_code' => $status_code,
			'status_raw' => $status_raw,
			'form' => $data['form'] ?? null,
			'url' => $data['url'] ?? null,
			'txid' => (string) ($data['transactionIdentifier'] ?? ($data['txid'] ?? '')),
		];
		$this->log_event('info', 'api.transaction_status.parsed', ['txid' => $txid, 'result' => $result]);
		return $result;
	}

	private function fetch_invoice_details($invoice_id)
	{
		$invoice_id = trim((string) $invoice_id);
		if ($invoice_id === '') {
			$this->log_event('error', 'api.invoice_details.empty_invoice_id');
			return ['ok' => false, 'error' => 'Empty invoice id'];
		}

		$url = 'https://api.paydo.com/v1/invoices/' . rawurlencode($invoice_id);
		$this->log_event('info', 'api.invoice_details.request', ['url' => $url, 'invoice_id' => $invoice_id]);

		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($resp)) {
			$this->log_event('error', 'api.invoice_details.transport_error', [
				'url' => $url,
				'invoice_id' => $invoice_id,
				'error' => $resp,
			]);
			return ['ok' => false, 'error' => $resp->get_error_message(), 'retryable' => true];
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);
		$json = json_decode($body, true);
		$this->log_event($code === 200 && is_array($json) ? 'info' : 'error', 'api.invoice_details.response', [
			'url' => $url,
			'invoice_id' => $invoice_id,
			'http_status' => $code,
			'response' => is_array($json) ? $json : $body,
			'json_error' => is_array($json) ? '' : json_last_error_msg(),
		]);

		if ($code !== 200 || !is_array($json)) {
			return [
				'ok' => false,
				'error' => 'Invalid PayDo invoice response',
				'retryable' => true,
				'http' => $code,
				'body' => mb_substr($body, 0, 1000),
			];
		}

		$data = $json['data'] ?? [];
		if (!is_array($data)) {
			$this->log_event('error', 'api.invoice_details.invalid_data', ['invoice_id' => $invoice_id, 'data' => $data]);
			return ['ok' => false, 'error' => 'Invalid PayDo invoice data', 'retryable' => true];
		}

		$result = [
			'ok' => true,
			'invoice_id' => (string) ($data['identifier'] ?? $invoice_id),
			'status' => isset($data['status']) ? (int) $data['status'] : null,
			'txid' => (string) ($data['transactionIdentifier'] ?? ''),
			'amount' => isset($data['amount']) ? (string) $data['amount'] : '',
			'currency' => isset($data['currency']) ? (string) $data['currency'] : '',
			'order_id' => (string) ($data['orderIdentifier'] ?? ''),
			'result_url' => isset($data['resultUrl']) ? (string) $data['resultUrl'] : '',
			'fail_url' => isset($data['failUrl']) ? (string) $data['failUrl'] : '',
			'raw' => $json,
		];
		$this->log_event('info', 'api.invoice_details.parsed', [
			'invoice_id' => $invoice_id,
			'result' => $result,
		]);
		return $result;
	}
}
