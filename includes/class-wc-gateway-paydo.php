<?php
/**
 * WooCommerce Paydo Payment Gateway.
 *
 * @extends WC_Payment_Gateway
 * @version 2.0.0
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

	public function __construct()
	{
		$this->api_url = 'https://api.paydo.com/v1/invoices/create';

		$this->id = PAYDO_PAYMENT_GATEWAY_NAME;
		$this->icon = apply_filters('woocommerce_paydo_icon', '' . PAYDO_PLUGIN_URL . '/logo.png');

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
	 * Display receipt page after successful payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) return;

		if ($order->is_paid() || $order->has_status(['processing','completed'])) {
			$this->empty_cart();
			return;
		}

		if ($order->has_status(['failed','cancelled','refunded'])) {
			echo '<p>This order cannot be paid. Please place a new order.</p>';
			return;
		}

		$invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));

		if ($invoice_id !== '') {
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

		if ($order && $order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
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

			$result_url = add_query_arg(
				[
					'wc-api'		=> 'wc_paydo',
					'paydo'		 => 'success',
					'orderId'	 => $order_id,
				],
				$order->get_checkout_order_received_url()
			);

			$fail_path = add_query_arg(
				[
					'wc-api'		=> 'wc_paydo',
					'paydo'		 => 'fail',
					'orderId'	 => $order_id,
				],
				$order->get_cancel_order_url()
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

			$invoice_id = $this->api_request($arr_data, PAYDO_API_IDENTIFIER);

			if(isset($invoice_id['messages'])) {
				return '<p>' . __('Request to payment service was sent incorrectly', 'paydo-woocommerce') . '</p><br><p>' . $response['messages'] .'</p>';
			}

			$order->update_meta_data(PAYDO_INVITATE_RESPONSE, $invoice_id);
			$order->save();
		}

		$action_adr = 'https://checkout.paydo.com/' . $this->language . '/payment/invoice-preprocessing/' . $invoice_id;

		if ($this->skip_confirm === 'yes') {
			wp_redirect(esc_url($action_adr));
			exit;
		}

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

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$posted_data = json_decode(file_get_contents('php://input'), true);
			if (!is_array($posted_data)) {
				$posted_data = [];
			}
		} else {
			$posted_data = $_GET;
		}

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
				$this->process_invalid_request();
		}
	}

	/**
	 * Map Paydo status to WooCommerce status.
	 *
	 * @param int $paydo_state The Paydo transaction state.
	 * @return string|null WooCommerce status or null if unknown.
	 */
	private function map_status_to_wc($paydo_state)
	{
		switch ($paydo_state) {
			case 1: // New transaction
			case 4: // Pending transaction
				return 'pending';
			case 2: // Accepted, paid successfully
				return $this->auto_complete === 'yes' ? 'completed' : 'processing';
			case 3: // Failed
			case 5: // Failed
			case 15: // Timeout
				return 'failed';
			case 9: // Pre-approved
				return 'on-hold';
			default:
				return null; // Unknown status
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
		$valid = $this->check_ipn_request_is_valid($posted_data);

		if ($valid !== PAYDO_IPN_VERSION_V2) {
			wp_die((string) $valid, (string) $valid, 400);
		}

		$order_id = $posted_data['transaction']['order']['id'] ?? null;
		$order_id = $order_id ? absint($order_id) : 0;

		if (!$order_id) {
			wp_die('Empty order id', 'Empty order id', 400);
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_die('Order not found', 'Order not found', 404);
		}

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			wp_die('Invalid payment method for this order', 'Invalid payment method', 403);
		}

		$stored_invoice_id = trim((string) $order->get_meta(PAYDO_INVITATE_RESPONSE));
		$ipn_invoice_id		= trim((string) ($posted_data['invoice']['id'] ?? ''));

		// If invoice mismatched — IGNORE (do not change status, to avoid "order poisoning").
		if ($ipn_invoice_id !== '' && $stored_invoice_id !== '' && $ipn_invoice_id !== $stored_invoice_id) {
			$order->add_order_note(__('PayDo IPN invoiceId mismatch. Ignored.', 'paydo-woocommerce'));
			wp_die('IGNORED', 'IGNORED', 200);
		}

		$txid = $posted_data['transaction']['txid'] ?? ($posted_data['invoice']['txid'] ?? null);
		$txid = $txid !== null ? trim((string) $txid) : '';

		if ($txid !== '') {
			$stored_txid = trim((string) $order->get_meta('_paydo_txid'));

			// If txid mismatched — IGNORE (do not change status, to avoid "order poisoning").
			if ($stored_txid !== '' && $stored_txid !== $txid) {
				$order->add_order_note(__('PayDo IPN txid mismatch. Ignored.', 'paydo-woocommerce'));
				wp_die('IGNORED', 'IGNORED', 200);
			}

			// Store txid once.
			if ($stored_txid === '') {
				$order->update_meta_data('_paydo_txid', $txid);
				$order->save();
			}

			// Confirm by txid (server-side).
			$res = $this->confirm_paydo_order_by_txid($order, $txid);

			// Optional hook for logs / integrations (must not exit!).
			do_action('paydo-ipn-request', $posted_data);

			if (!empty($res['ok'])) {
				wp_die('OK', 'OK', 200);
			}

			wp_die('Check failed', 'Check failed', 200);
		}

		// No txid: keep calm, wait for later IPN / polling.
		if (!$order->has_status(['on-hold', 'pending'])) {
			$order->update_status('on-hold', __('PayDo IPN received without txid. Waiting.', 'paydo-woocommerce'), true);
		}

		do_action('paydo-ipn-request', $posted_data);
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

		$order = $order_id ? wc_get_order($order_id) : null;
		if (!$order) {
			wp_die('Order not found', 'Order not found', 404);
		}

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			wp_die('Invalid payment method for this order', 'Invalid payment method', 403);
		}

		// If already paid — just finish UX.
		if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// If order is in a final bad state — do not try to change it.
		if ($order->has_status(['failed', 'cancelled', 'refunded'])) {
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// No trust: do NOT use invoiceId/txid from redirect.
		if (!$order->has_status(['on-hold', 'pending'])) {
			$order->update_status(
				'on-hold',
				__('Returned from PayDo checkout (success redirect). Waiting for confirmation (IPN/polling).', 'paydo-woocommerce'),
				true
			);
		}

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

		$order = $order_id ? wc_get_order($order_id) : null;
		if (!$order) {
			wp_die('Order not found', 'Order not found', 404);
		}

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			wp_die('Invalid payment method for this order', 'Invalid payment method', 403);
		}

		// If already paid — just finish UX.
		if ($order->is_paid() || $order->has_status(['processing', 'completed'])) {
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// If already final bad — keep it as is.
		if ($order->has_status(['failed', 'cancelled', 'refunded'])) {
			$this->empty_cart();
			wp_redirect($this->get_return_url($order));
			exit;
		}

		// No trust: do NOT use invoiceId/txid from redirect.
		if (!$order->has_status(['on-hold', 'pending'])) {
			$order->update_status(
				'on-hold',
				__('Returned from PayDo checkout (fail/close). Waiting for confirmation (IPN/polling).', 'paydo-woocommerce'),
				true
			);
		} else {
			$order->add_order_note(__('Returned from PayDo with FAIL/CLOSE. Waiting for confirmation (IPN/polling).', 'paydo-woocommerce'), true);
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

		return [
			'result'	 => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	private function fetch_transaction_status($txid)
	{
		$txid = trim((string) $txid);
		if ($txid === '') {
			return ['ok' => false, 'error' => 'Empty txid'];
		}

		$url = 'https://api.paydo.com/v1/checkout/check-transaction-status/' . rawurlencode($txid);

		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($resp)) {
			return ['ok' => false, 'error' => $resp->get_error_message()];
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);

		$json = json_decode($body, true);
		if ($code !== 200 || !is_array($json)) {
			return [
				'ok' => false,
				'error' => 'Invalid PayDo response',
				'http' => $code,
				'body' => mb_substr($body, 0, 1000),
			];
		}

		$data = $json['data'] ?? [];
		if (!is_array($data)) {
			$data = [];
		}

		if (isset($data['isSuccess']) && !$data['isSuccess']) {
			return ['ok' => false, 'error' => 'PayDo returned isSuccess=false', 'raw' => $json];
		}

		$status_raw = $data['status'] ?? null;

		$number_code = null;

		if (is_numeric($status_raw)) {
			$number_code = (int) $status_raw;
		} elseif (is_string($status_raw)) {
			$s = strtolower(trim($status_raw));
			if ($s === 'new') $number_code = 1;
			elseif ($s === 'accepted' || $s === 'success') $number_code = 2;
			elseif ($s === 'pending') $number_code = 4;
			elseif ($s === 'fail' || $s === 'failed') $number_code = 3;
		}

		return [
			'ok' => true,
			'status_raw' => $status_raw,
			'status_code' => $number_code,
			'form' => $data['form'] ?? null,
			'url'	=> $data['url'] ?? null,
			'txid' => $data['txid'] ?? $txid,
			'raw'	=> $json,
		];
	}

	private function confirm_paydo_order_by_txid($order, $txid)
	{
		if (!$order instanceof WC_Order) {
			return ['ok' => false, 'error' => 'Invalid order'];
		}

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			return ['ok' => false, 'error' => 'Invalid payment method'];
		}

		$check = $this->fetch_transaction_status($txid);
		if (empty($check['ok'])) {
			return $check;
		}

		$code = (int) ($check['status_code'] ?? 0);

		// 2 = accepted/success => paid
		if ($code === 2) {
			if (!$order->is_paid()) {
				$order->payment_complete();
			}

			if ($this->auto_complete === 'yes' && !$order->has_status('completed')) {
				$order->update_status('completed', __('PayDo transaction confirmed as PAID (polling).', 'paydo-woocommerce'));
			} elseif (!$order->has_status(['processing', 'completed'])) {
				$order->update_status('processing', __('PayDo transaction confirmed as PAID (polling).', 'paydo-woocommerce'));
			}

			return ['ok' => true, 'final' => true, 'state' => 'paid', 'check' => $check];
		}

		// 3/5 = failed
		if ($code === 3 || $code === 5) {
			if (!$order->has_status('failed')) {
				$order->update_status('failed', __('PayDo transaction confirmed as FAILED (polling).', 'paydo-woocommerce'), true);
			}
			return ['ok' => true, 'final' => true, 'state' => 'failed', 'check' => $check];
		}

		// 1(new) / 4(pending) / unknown => not final
		if (!$order->has_status(['on-hold', 'pending'])) {
			$order->update_status('on-hold', __('PayDo transaction pending. Waiting for confirmation.', 'paydo-woocommerce'), true);
		}

		return ['ok' => true, 'final' => false, 'state' => 'pending', 'check' => $check];
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
		$invoice_id = isset($posted['invoice']['id']) ? trim((string)$posted['invoice']['id']) : '';
		$tx_id			= isset($posted['invoice']['txid']) ? trim((string)$posted['invoice']['txid']) : '';
		$order_id	 = isset($posted['transaction']['order']['id']) ? absint($posted['transaction']['order']['id']) : 0;

		if ($invoice_id === '') return 'Empty invoice id (V2)';
		if ($tx_id === '')			return 'Empty transaction id (V2)';
		if (!$order_id)				 return 'Empty order id (V2)';

		$order = wc_get_order($order_id);
		if (!$order) return 'Order not found';

		if ($order->get_payment_method() !== PAYDO_PAYMENT_GATEWAY_NAME) {
			return 'Invalid payment method';
		}

		$stored_invoice_id = trim((string)$order->get_meta(PAYDO_INVITATE_RESPONSE));
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

		if ($retrieved_header !== '') {
			$header = wp_remote_retrieve_header($response, $retrieved_header);
			return !empty($header) ? $header : null;
		}

		$body = wp_remote_retrieve_body($response);
		return json_decode($body, true);
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
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => 'Forbidden'], 403);
		}

		check_ajax_referer('paydo_sync_methods', 'nonce');

		$project_id = trim((string) $this->get_option('project_id'));
		$jwt				= trim((string) $this->get_option('jwt_token'));

		if (!$project_id || !$jwt) {
			wp_send_json_error(['message' => 'Fill Project ID and JWT token first'], 422);
		}

		$url = 'https://api.paydo.com/v1/instrument-settings/payment-methods/available-for-application/' . rawurlencode($project_id);

		$resp = wp_remote_get($url, [
			'timeout' => 30,
			'headers' => [
				'Accept'				=> 'application/json',
				'Authorization' => 'Bearer ' . $jwt,
			],
		]);

		if (is_wp_error($resp)) {
			wp_send_json_error(['message' => $resp->get_error_message()], 500);
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);

		$json = json_decode($body, true);
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

		if (!$method || !in_array((string)$method, $allowed, true)) {
			wc_add_notice(__('Please choose a PayDo payment method.', 'paydo-woocommerce'), 'error');
		}
	}

	public function save_paydo_method($order, $data)
	{
		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		$method = isset($_POST['paydo_method']) ? wc_clean(wp_unslash($_POST['paydo_method'])) : '';
		if ($method) {
			$order->update_meta_data('_paydo_method', (string)$method);
		}
	}

	public function store_api_save_paydo_method($order, $request)
	{
		if ($order->get_payment_method() !== $this->id) {
			return;
		}

		$payment_data = $request->get_param('payment_data');
		if (!is_array($payment_data)) {
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
		}
	}
}
