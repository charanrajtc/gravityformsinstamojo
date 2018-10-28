<?php

GFForms::include_payment_addon_framework();

class GFInstamojo extends GFPaymentAddOn {

	protected $_version = GF_INSTAMOJO_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityformsinstamojo';
	protected $_path = 'gravityformsinstamojo/instamojo.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.ganguli.com';
	protected $_title = 'Gravity Forms Instamojo Standard Add-On';
	protected $_short_title = 'Instamojo';

	protected $_supports_callbacks = false;

	//set the credicard to false for the payment fields
	protected $_requires_credit_card = false;

	protected $_instamojo_api_url = 'https://www.instamojo.com/api/1.1/payment-requests/';
	protected $_instamojo_sandbox_api_url = 'https://test.instamojo.com/api/1.1/payment-requests/';

	private static $_instance = null;

	const PAYMENT_MODE_TEST = 'test';
	const PAYMENT_MODE_PROD = 'production';
	const PAYMENT_METHOD = 'Instamojo';

	public static function get_instance() {
		if (self::$_instance == null) {
			self::$_instance = new GFInstamojo();
		}

		return self::$_instance;
	}

	public function billing_info_fields() {
		$fields = array(
			array('name' => 'name', 'label' => __('Name', 'gravityforms'), 'required' => false),
			array('name' => 'email', 'label' => __('Email', 'gravityforms'), 'required' => false),
			array('name' => 'phone', 'label' => __('Phone', 'gravityforms'), 'required' => false),
		);
		$this->log_debug(__METHOD__ . "(): Adding billing fields to the form");
		return $fields;
	}

	public function other_settings_fields() {
		$default_settings = parent::other_settings_fields();

		$sample_choice_option = array_search('options', array_column($default_settings, 'name'));

		if (!empty($sample_choice_option)) {
			// unset($default_settings[$sample_choice_option]);
			$default_settings[$sample_choice_option]['choices'] = [
				array('label' => 'Send SMS', 'name' => 'send_sms',
					'tooltip' => 'a payment request SMS will be sent to the phone supplied of the customer',
					'value' => true),
				array('label' => 'Send Email',
					'tooltip' => 'a payment request email will be sent to the email supplied of the customer',
					'name' => 'send_email', 'value' => true),
			];

			$this->log_debug(__METHOD__ . "(): adding send sms choice option");
		}
		return $default_settings;
	}

	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		$fields = array(
			array(
				'name' => 'instamojoAPIKey',
				'label' => esc_html__('Private API Key ', $this->_slug),
				'type' => 'text',
				'class' => 'medium',
				'required' => true,
				'tooltip' => '<h6>' . esc_html__('Private API Key of Instamojo Account', $this->_slug) . '</h6>' . esc_html__('Enter the API Key of Instamojo Account where payment should be received.', $this->_slug),
			),
			array(
				'name' => 'instamojoPaymentPurposeDescription',
				'label' => esc_html__('Payment Purpose ', $this->_slug),
				'type' => 'text',
				'class' => 'medium',
				'required' => true,
				'tooltip' => '<h6>' . esc_html__('Payment Purpose Description Instamojo Account', $this->_slug) . '</h6>' . esc_html__('Payment Purpose Description Instamojo where it is reflected in payment form.', $this->_slug),
			),
			array(
				'name' => 'instamojoAuthToken',
				'label' => esc_html__('Private Auth Token ', $this->_slug),
				'type' => 'text',
				'class' => 'medium',
				'required' => true,
				'tooltip' => '<h6>' . esc_html__('Private Auth Token of Instamojo Account', $this->_slug) . '</h6>' . esc_html__('Enter the API Key of Instamojo Account where payment should be received.', $this->_slug),
			),
			array(
				'name' => 'mode',
				'label' => __('Mode', $this->_slug),
				'type' => 'radio',
				'choices' => array(
					array('id' => 'gf_instamoj_mode_production', 'label' => __('Production', $this->_slug), 'value' => self::PAYMENT_MODE_PROD),
					array('id' => 'gf_instamoj_mode_test', 'label' => __('Test', $this->_slug), 'value' => self::PAYMENT_MODE_TEST),

				),
				'horizontal' => true,
				'default_value' => 'production',
				'tooltip' => '<h6>' . __('Mode', $this->_slug) . '</h6>' . __('Select Production to receive live payments. Select Test for testing purposes when using the Instamojo development sandbox.', $this->_slug),
			),
		);

		// remove the subscriptions for the payments
		$transaction_type = $this->get_field('transactionType', $default_settings);
		$subscription_choice = array_search('subscription',
			array_column($transaction_type['choices'], 'value'));
		if (!empty($subscription_choice)) {
			unset($transaction_type['choices'][$subscription_choice]);
			$this->log_debug(__METHOD__ . "(): removing subscription choice from transaction type ");
		}
		$transaction_type['required'] = true;
		$this->log_debug(__METHOD__ . "(): setting transaction type as required parameter");
		$default_settings = $this->replace_field('transactionType', $transaction_type, $default_settings);

		$default_settings = parent::add_field_after('feedName', $fields, $default_settings);

		return $default_settings;
	}

	public function is_callback_valid() {
		if (rgget('page') != 'gf_instamoj_webhook') {
			return false;
		}

		return true;
	}

	protected function is_return_callback_valid() {
		if (rgget('callback') != $this->_slug) {
			return false;
		}
		return true;
	}

	public function redirect_url($feed, $submission_data, $form, $entry) {

		// get payment amount form the product url list  this should not be less than 10 RS
		$payment_amount = rgar($submission_data, 'payment_amount');
		$meta = rgar($feed, 'meta');

		//return after success
		$return_url = $this->return_url($form['id'], $entry['id']);

		//URL that will listen to notifications from Instamoj
		$webhook_callback_url = get_bloginfo('url') . '/?page=gf_instamoj_webhook&ref=' . $entry['id'];

		// pay load for the Instamojo options
		$payload = Array(
			'purpose' => $meta['instamojoPaymentPurposeDescription'],
			'amount' => $payment_amount,
			'redirect_url' => $return_url,
			'webhook' => $webhook_callback_url,
			'allow_repeated_payments' => false,
		);

		// add options from settings form
		$payload['send_email'] = (boolean) rgar($meta, 'send_email');
		$payload['send_sms'] = (boolean) rgar($meta, 'send_sms');

		// add customer name if not set
		$buyer_name = rgar($submission_data, 'name');

		if (!empty($buyer_name)) {
			$payload['buyer_name'] = $buyer_name;
		}

		$buyer_email = rgar($submission_data, 'email');
		if (!empty($buyer_email)) {
			$payload['email'] = $buyer_email;
		}

		$buyer_phone = rgar($submission_data, 'phone');
		if (!empty($buyer_phone)) {
			$payload['phone'] = $buyer_phone;
		}

		$this->log_debug(__METHOD__ . "(): Name | Email | Phone : " . $buyer_name . ' | ' . $buyer_email . ' | ' . $buyer_phone);

		$payment_request_response = $this->create_instamojo_paymentrequest(
			$payload,
			$meta['mode'],
			$meta['instamojoAPIKey'],
			$meta['instamojoAuthToken']
		);

		$amount_formatted = GFCommon::to_money($payment_amount, $entry['currency']);

		// reset the payment URL to null
		$url = '';
		if (!is_wp_error($payment_request_response)) {
			$url = rgar($payment_request_response, 'redirect_url');
			$request_id = rgar($payment_request_response, 'request_id');
			$action['note'] = sprintf(esc_html__('Payment is pending. Amount: %s. Request Id : %s.  Request URL : %s.', $this->_slug), $amount_formatted, $request_id, $url);
			// record a pending payment
			$this->add_pending_payment($entry, $action);
			$this->log_debug(__METHOD__ . '(): Added Pending payment record');

		} else {

			// fail the payment as not able to redirect to the user
			$action['note'] = sprintf(esc_html__('Payment has failed. Amount: %s Reason: %s', $this->_slug), $amount_formatted, $payment_request_response->get_error_message());
			$action['payment_status'] = 'Failed';
			// record a pending payment
			$this->fail_payment($entry, $action);
			return false;
		}

		return gf_apply_filters('gform_instamojo_request', $form['id'], $url, $form, $entry, $feed, $submission_data);
	}

	private function unpack_data($data) {

		// FIXME: move this code block to unpack_data function and optimize
		$results = [];
		if (!empty($data)) {
			$data = base64_decode($data);
			$data = explode('&', $data);
			$cal_hash = wp_hash($data[0]);
			$hash = str_replace('hash=', '', $data[1]);

			if ($hash != $cal_hash) {
				$this->log_debug(__METHOD__ . '(): Tampered hash ');
			} else {
				$data = explode('|', str_replace('ids=', '', $data[0]));
				$results = array('form_id' => $data[0], 'lead_id' => $data[1]);
				$this->log_debug(__METHOD__ . '():  callback hash verified');
			}
		} else {
			$this->log_debug(__METHOD__ . '(): unable to unpack ');
		}
		return $results;
	}

	protected function verify_instamojo_payment($payment_id, $payment_request_id, $mode, $instamojo_api_key, $instamojo_auth_token) {
		$request = new WP_Http();

		//add authentication to the insa mojo account
		$headers = ['X-Api-Key' => $instamojo_api_key, 'X-Auth-Token' => $instamojo_auth_token];

		$base_api_url = $this->_instamojo_sandbox_api_url;
		if (!empty($meta) && $mode == self::PAYMENT_MODE_PROD) {
			$base_api_url = $this->_instamojo_api_url;
		}
		$url = $base_api_url . $payment_request_id . '/';

		$this->log_debug(__METHOD__ . "(): Sending verify  request to Instamojo for validation. URL: $url");

		$request = new WP_Http();
		$response = $request->get($url, array(
			'sslverify' => false, 'ssl' => true,
			'headers' => $headers, 'timeout' => 20));

		// try to parse josn
		$body = json_decode(rgar($response, 'body'));

		if (!is_wp_error($response) && $body->success) {
			$this->log_debug(__METHOD__ . "(): Payment status : " . $body->payment_request->status);
			return $body->payment_request;
		} else {
			$this->log_debug(__METHOD__ . "(): Unableto find the verify Payment");
			return new WP_Error('broke', __("Unable to create the payment Instamoj URL ", $this->_slug));
		}

		// return empty array
		return [];
	}

	public function maybe_process_return_callback() {
		// Ignoring requests that are not this addon's callbacks.
		if (!$this->is_return_callback_valid()) {
			return;
		}

		$this->log_debug(__METHOD__ . '(): Processing retrun callback option');

		$gf_instamojo_return = $this->unpack_data(rgget('gf_instamojo_return'));
		$payment_id = rgget('payment_id');
		$payment_request_id = rgget('payment_request_id');

		// invalid parameters passed
		if (empty($gf_instamojo_return) || empty($payment_id) || empty($payment_request_id)) {
			return;
		}
		// get the form entry and payment details
		$entry = GFAPI::get_entry($gf_instamojo_return['lead_id']);
		$feed = $this->get_payment_feed($entry);
		$meta = rgar($feed, 'meta');
		$payment_request_details = $this->verify_instamojo_payment(
			$payment_id, $payment_request_id,
			$meta['mode'],
			$meta['instamojoAPIKey'],
			$meta['instamojoAuthToken']
		);

		if (!is_wp_error($payment_request_details) || !empty($payment_request_details)) {
			// fin the payment details in the request
			$payment_details = '';
			foreach ($payment_request_details->payments as $key => $value) {
				if ($value->payment_id == $payment_id) {
					$payment_details = $value;
				}
			}

			// switch only if the payment is completed
			switch ($payment_request_details->status) {
			case 'Completed':
				$action = [
					'type' => $payment_request_details->status,
				];
				$this->log_debug(__METHOD__ . '(): Debug ' . print_r($payment_details, true));
				break;
			default:
				# code...
				break;
			}
		}

		return;
	}

	// pre init constructors
	public function pre_init() {
		parent::pre_init();

		// Intercepting callback retruns.
		add_action('parse_request', array($this, 'maybe_process_return_callback'));

	}

	protected function create_instamojo_paymentrequest($payload, $mode, $instamojo_api_key, $instamojo_auth_token) {

		$request = new WP_Http();

		//add authentication to the insa mojo account
		$headers = ['X-Api-Key' => $instamojo_api_key, 'X-Auth-Token' => $instamojo_auth_token];

		$sucess_results = array(
			'redirect_url' => '',
		);

		$base_api_url = $this->_instamojo_sandbox_api_url;
		if (!empty($meta) && $mode == self::PAYMENT_MODE_PROD) {
			$base_api_url = $this->_instamojo_api_url;
		}

		$response = $request->post($base_api_url, array(
			'sslverify' => false, 'ssl' => true,
			'headers' => $headers, 'timeout' => 20, 'body' => $payload));

		// try to parse josn
		$body = json_decode(rgar($response, 'body'));

		if (!is_wp_error($response) && $body->success) {
			$sucess_results['request_id'] = $body->payment_request->id;
			$sucess_results['payment_amount'] = $body->payment_request->amount;
			$sucess_results['payment_date'] = $body->payment_request->created_at;
			$sucess_results['redirect_url'] = $body->payment_request->longurl;
			$this->log_debug(__METHOD__ . "(): Payment  URL Instamoj: " . $body->payment_request->longurl);
		} else {
			$this->log_debug(__METHOD__ . "(): Unable to create the payment Instamoj URL : ");
			return new WP_Error('broke', __("Unable to create the payment Instamoj URL ", $this->_slug));
		}
		return $sucess_results;
	}

	public function return_url($form_id, $lead_id) {
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters('gform_instamojo_return_url_port', $_SERVER['SERVER_PORT']);

		if ($server_port != '80') {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		// FIXME: move this code block to pack_data function
		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash($ids_query);

		$url = add_query_arg(array(
			'gf_instamojo_return' => base64_encode($ids_query),
		), $pageURL);

		$query = 'gf_instamojo_return=' . base64_encode($ids_query) . '&callback=' . $this->_slug;
		/**
		 * Filters Instamoj's return URL, which is the URL that users will be sent to after completing the payment on Instamoj's site.
		 * Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
		 *
		 * @since 2.4.5
		 *
		 * @param string  $url 	The URL to be filtered.
		 * @param int $form_id	The ID of the form being submitted.
		 * @param int $entry_id	The ID of the entry that was just created.
		 * @param string $query	The query string portion of the URL.
		 */
		return apply_filters('gform_instamojo_return_url', $url, $form_id, $lead_id, $query);

	}

}
