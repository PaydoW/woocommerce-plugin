<?php
/**
 * WooCommerce Paydo Payment Gateway.
 *
 * @extends WC_Payment_Gateway
 * @version 1.0.0
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

		//Actions
		add_action('paydo-ipn-request', [$this, 'successful_request']);
		add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
		add_action('woocommerce_thankyou_' . $this->id, [$this, 'receipt_page']);

		add_filter( 'woocommerce_order_needs_payment', [$this, 'prevent_payment_for_failed_orders'], 10, 3 );

		// hide buttons "Buy again"
		add_action('woocommerce_my_account_my_orders_actions', [$this, 'hide_pay_button_for_failed_orders'], 10, 2);
		add_filter('render_block', [$this, 'modify_wc_order_confirmation_block_content'], 10, 2);

		//Payment listner/API hook
		add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);

		//Save options
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

		if (!$this->is_valid_for_use()) {
			$this->enabled = false;
		}
	}

	/**
	 * Display receipt page after successful payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id )
	{
		$order = wc_get_order($order_id);

		if(!$order->get_meta(PAYDO_INVITATE_RESPONSE) || $order->has_status('pending')){
			echo '<p>' . __('Thank you for your order, please click the button below to pay', 'paydo-woocommerce') . '</p>';
			echo $this->generate_form($order_id);
		}else{
			$this->empty_cart();
		}
	}

	/**
	 * Generate payment form.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	public function generate_form( $order_id )
	{
		$order = wc_get_order($order_id);

		$response = $order->get_meta(PAYDO_INVITATE_RESPONSE);
		if ( !$response ) {
			$out_summ = number_format($order->get_total(), 4, '.', '');
			$currency = $order->get_currency();
			$site_url = get_site_url();

			$order_info = [
				'id' => $order_id,
				'amount' => $out_summ,
				'currency' => $order->get_currency()
			];

			ksort($order_info, SORT_STRING);
			$data_set = array_values($order_info);
			$data_set[] = $this->secret_key;
			$signature = hash(PAYDO_HASH_ALGORITHM, implode(':', $data_set));

			$first_name = $order->get_billing_first_name();
			$last_name = $order->get_billing_last_name();

			$result_url = add_query_arg(
				[
					'wc-api' => 'wc_paydo',
					'paydo'  => 'success',
					'orderId' => $order_id
				],
				$order->get_checkout_order_received_url()
			);

			$fail_path = add_query_arg(
				[
					'wc-api' => 'wc_paydo',
					'paydo'  => 'fail',
					'orderId' => $order_id
				],
				$order->get_cancel_order_url()
			);

			$arr_data = [
				'publicKey' => $this->public_key,
				'order' => [
					'id' => strval($order_id),
					'amount' => $out_summ,
					'currency' => $currency,
					'description' => __('Payment order #', 'paydo-woocommerce') . $order_id,
					'items' => []
				],
				'payer' => [
					'email' => $order->get_billing_email(),
					'name' => implode(' ', array_filter([$first_name, $last_name])),
					'phone' => $order->get_billing_phone() ?: ''
				],
				'language' => $this->language,
				'productUrl' => $site_url,
				'resultUrl' => $result_url,
				'failPath' => $fail_path,
				'signature' => $signature
			];

			$response = $this->api_request($arr_data, PAYDO_API_IDENTIFIER);
			$order->add_meta_data(PAYDO_INVITATE_RESPONSE, $response);
			$order->save_meta_data();
		}

		if(isset($response['messages'])) {
			return '<p>' . __('Request to payment service was sent incorrectly', 'paydo-woocommerce') . '</p><br><p>' . $response['messages'] .'</p>';
		}

		$action_adr = 'https://checkout.paydo.com/' . $this->language . '/payment/invoice-preprocessing/' . $response;

		if ($this->skip_confirm === "yes"){
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
		$request_type = !empty($_GET['paydo']) ? $_GET['paydo'] : '';

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
	 * Process the result request.
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
	private function process_result_request( $posted_data )
	{
		@ob_clean();
		$posted_data = wp_unslash($posted_data);
		$valid = $this->check_ipn_request_is_valid($posted_data);

		if ($valid === PAYDO_IPN_VERSION_V2) {
			$state = $posted_data['transaction']['state'];
			$order_id = $posted_data['transaction']['order']['id'];
			$order = wc_get_order($order_id);

			$wc_status = $this->map_status_to_wc($state);
			if ($wc_status) {
				$order->update_status($wc_status, __('Transaction status updated', 'paydo-woocommerce'));
				wp_die('Status updated', 'Status updated', 200);
			} else {
				wp_die('Unknown status', 'Unknown status', 400);
			}

			do_action('paydo-ipn-request', $posted_data);
		} elseif ($valid === PAYDO_IPN_VERSION_V1) {
			$status = $posted_data['status'];
			$order_id = $posted_data['orderId'];
			$order = wc_get_order($order_id);

			switch ($status) {
				case 'wait':
					$order->update_status('pending', __('Transaction pending', 'paydo-woocommerce'));
					wp_die('Status pending', 'Status pending', 200);
					break;

				case 'success':
					if ($this->auto_complete === 'yes') {
						$order->update_status('completed', __('Payment successfully paid', 'paydo-woocommerce'));
					} else {
						$order->update_status('processing', __('Payment successfully paid', 'paydo-woocommerce'));
					}
					wp_die('Status success', 'Status success', 200);
					break;

				case 'error':
					$order->update_status('failed', __('Payment not paid', 'paydo-woocommerce'));
					wp_die('Status fail', 'Status fail', 200);
					break;

				default:
					wp_die('Unknown status', 'Unknown status', 400);
			}

			do_action('paydo-ipn-request', $posted_data);
		} else {
			wp_die($valid, $valid, 400);
		}
	}

	 /**
	 * Process the success request.
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
	private function process_success_request($posted_data)
	{
		$order_id = $posted_data['transaction']['order']['id'] ?? $posted_data['orderId'] ?? $posted_data['order-received'] ?? null;
		$order = wc_get_order($order_id);
		$transaction_state = isset($posted_data['transaction']['state']) ? $posted_data['transaction']['state'] : false;

		if ($transaction_state == 9) {
			$order->update_status('on-hold', __('Payment pre-approved by provider', 'paydo-woocommerce'));
		} elseif ($transaction_state == 2) {
			$order->payment_complete();
			$this->empty_cart();

			$redirect_url = $this->get_return_url($order);

			wp_redirect($this->get_return_url($order));
			exit;
		} else {
			$order->update_status('on-hold', __('Order awaiting payment confirmation', 'paydo-woocommerce'), true);

			$this->empty_cart();

			wp_redirect($this->get_return_url($order));
			exit;
		}
	}

	/**
	 * Process the fail request.
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
	private function process_fail_request($posted_data){
		$order_id = $posted_data['transaction']['order']['id'] ?? $posted_data['orderId'] ?? $posted_data['order-received'] ?? null;
		$order = wc_get_order($order_id);

		$order->update_status('failed', __('Payment not paid', 'paydo-woocommerce'), true);

		$this->empty_cart();

		wp_redirect($this->get_return_url($order));
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
	 * @param bool   $needs_payment		The current value indicating whether payment is needed for the order.
	 * @param object $order				The order object.
	 * @param array  $valid_order_statuses An array of valid order statuses.
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

		$this->empty_cart();

		// Return thankyou redirect.
		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	/**
	 * Check Paydo IPN validity.
	 *
	 * @param array $posted Data received from Paydo IPN.
	 *
	 * @return bool|string
	 */
	public function check_ipn_request_is_valid( $posted )
	{
		$invoice_id = isset($posted['invoice']['id']) ? $posted['invoice']['id'] : null;
		$tx_id = isset($posted['invoice']['txid']) ? $posted['invoice']['txid'] : null;
		$order_id = isset($posted['transaction']['order']['id']) ? $posted['transaction']['order']['id'] : null;
		$signature = isset($posted['signature']) ? $posted['signature'] : null;
		// check IPN V1
		if (!$invoice_id) {
			if (!$signature) {
				return 'Empty invoice id';
			} else {
				$order_id = isset($posted['orderId']) ? $posted['orderId'] : null;
				if (!$order_id) {
					return 'Empty order id V1';
				}
				$order = wc_get_order($order_id);
				$currency = $order->get_currency();
				$amount = number_format($order->get_total(), 4, '.', '');

				$status = $posted['status'];

				if ($status !== 'success' && $status !== 'error') {
					return 'Status is not valid';
				}

				$order_info = [
					'id' => $order_id,
					'amount' => $amount,
					'currency' => $currency
				];

				ksort($order_info, SORT_STRING);
				$data_set = array_values($order_info);

				if ($status) {
					array_push($data_set, $status);
				}

				array_push($data_set, $this->secret_key);

				if ($posted['signature'] === hash(PAYDO_HASH_ALGORITHM, implode(':', $data_set))) {
					return PAYDO_IPN_VERSION_V1;
				}
				return 'Invalid signature';
			}
		}
		if (!$tx_id) {
			return 'Empty transaction id';
		}
		if (!$order_id) {
			return 'Empty order id V2';
		}

		$order = wc_get_order($order_id);
		$currency = $order->get_currency();
		$state = $posted['transaction']['state'];
		if (!(1 <= $state && $state <= 5)) {
			return 'State is not valid';
		}
		return PAYDO_IPN_VERSION_V2;
	}

	/**
	 * Handle successful IPN request.
	 *
	 * @param array $posted Data received from Paydo IPN.
	 */
	public function successful_request( $posted )
	{
		$order_id = $posted_data['transaction']['order']['id'] ?? $posted_data['orderId'] ?? $posted_data['order-received'] ?? null;

		$order = wc_get_order($order_id);

		// Check order not already completed
		if ($order->status == 'completed') {
			exit;
		}

		// Payment completed
		$order->add_order_note(__('Payment completed successfully', 'paydo-woocommerce'));

		$order->payment_complete();

		exit;
	}

	/**
	 * Make an API request to Paydo.
	 *
	 * @param array  $arr_data Data to be sent in the request.
	 * @param string $retrieved_header Retrieved header.
	 *
	 * @return mixed
	 */
	public function api_request( $arr_data = [], $retrieved_header = '')
	{
		$request_url = $this->api_url;
		$args = [
			'sslverify' => false,
			'timeout' => 45,
			'headers' => [
				'Content-Type' => 'application/json'
			],
			'body' => json_encode($arr_data),
		];

		$response = wp_remote_post($request_url, $args);
		if ($retrieved_header !== ''){
			$response = wp_remote_retrieve_header($response, $retrieved_header);
			if (!empty($response)){
				return $response;
			}
		} else {
			$response = wp_remote_retrieve_body($response);
		}
		return json_decode($response, true);
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
	}

	/**
	 * Payment fields displayed on the checkout page.
	 */
	public function payment_fields()
	{
		if ($this->description) {
			echo wpautop(wptexturize($this->description));
		}
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
}
