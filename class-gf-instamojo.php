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

	protected $_supports_callbacks = true;

	//set the credicard to false for the payment fields
	protected $_requires_credit_card = false;

	protected $_instamojo_api_url='https://www.instamojo.com/api/1.1/payment-requests/';

	private static $_instance = null;

	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFInstamojo();
		}

		return self::$_instance;
	}

	public function billing_info_fields() {
	  $fields = array(
	       // array( 'name' => 'email', 'label' => __( 'Email', 'gravityforms' ), 'required' => false )
	  );
	  return $fields;
	}

	public function feed_settings_fields() {
		$default_settings = parent::feed_settings_fields();

		 $fields = array(
			array(
				'name'     => 'instamojoAPIKey',
				'label'    => esc_html__( 'Private API Key ', 'gravityformsinstamojo' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'Private API Key of Instamojo Account', 'gravityformsinstamojo' ) . '</h6>' . esc_html__( 'Enter the API Key of Instamojo Account where payment should be received.', 'gravityformsinstamojo' )
			),
			array(
				'name'     => 'instamojoPaymentPurposeDescription',
				'label'    => esc_html__( 'Payment Purpose ', 'gravityformsinstamojo' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'Payment Purpose Description Instamojo Account', 'gravityformsinstamojo' ) . '</h6>' . esc_html__( 'Payment Purpose Description Instamojo where it is reflected in payment form.', 'gravityformsinstamojo' )
			),
			array(
				'name'     => 'instamojoAuthToken',
				'label'    => esc_html__( 'Private Auth Token ', 'gravityformsinstamojo' ),
				'type'     => 'text',
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . esc_html__( 'Private Auth Token of Instamojo Account', 'gravityformsinstamojo' ) . '</h6>' . esc_html__( 'Enter the API Key of Instamojo Account where payment should be received.', 'gravityformsinstamojo' )
			),
		);

		$default_settings = parent::add_field_after( 'feedName', $fields, $default_settings );

		return $default_settings;
	}


	public function is_callback_valid() {
		if ( rgget( 'page' ) != 'gf_instamoj_webhook' ) {
			return false;
		}

		return true;
	}

	public function redirect_url( $feed, $submission_data, $form, $entry ) {


		//Don't process redirect url if request is a Instamoj return
		if ( ! rgempty( 'gf_instamojo_return', $_GET ) ) {
			return false;
		}

		//updating lead's payment_status to Processing
		$entry['payment_status']= 'Processing' ;
		$entry['payment_method']='instamojo';

		// get payment amount form the product url list  this should not be less than 10 RS
		$payment_amount = rgar( $submission_data, 'payment_amount' );
		$buyer_name= $entry[25.2].$entry[25.3].$entry[25.6];
		 $this->log_debug( __METHOD__ . "():buyer name: ".$entry[25.3] );
		if ( empty($buyer_name) )
		{
			$buyer_name='Customer';
		}
		 $this->log_debug( __METHOD__ . "():buyer name: ".$buyer_name );


		//return after success
		$return_url = $this->return_url( $form['id'], $entry['id'] ) ;

		//URL that will listen to notifications from Instamoj
		$webhook_callback_url = get_bloginfo( 'url' ) . '/?page=gf_instamoj_webhook&ref='.$entry['id'] ;

		$request  = new WP_Http();
		$meta=$feed['meta'];

		//add authentication to the insa mojo account
		$headers =['X-Api-Key'=>$meta['instamojoAPIKey'] ,'X-Auth-Token'=>$meta['instamojoAuthToken']];

		$payload = Array(
		    'purpose' => $meta['instamojoPaymentPurposeDescription'],
		    'amount' => $payment_amount,
		    // 'phone' => '9999999999',
		    'buyer_name' => $buyer_name,
		    'redirect_url' => $return_url,
 			// 'send_email' => true,
		    'webhook' => $webhook_callback_url,
		    // 'send_sms' => true,
		    // 'email' => $meta['billingInformation_email'],
		    'allow_repeated_payments' => false
		);

		$response = $request->post($this->_instamojo_api_url , array(
			'sslverify' => false, 'ssl' => true,
			'headers'=>$headers, 'timeout' => 20,'body'=>$payload ) );


		// try to parse josn
		$body=json_decode(rgar( $response, 'body' ));


		if ( ! is_wp_error( $response ) && $body->success ) {
			$url=$body->payment_request->longurl;
		   $this->log_debug( __METHOD__ . "(): Payment  URL Instamoj: { $url }" );

		   	//update the entry status
		   	$entry['transaction_id']=$body->payment_request->id;
			$entry['payment_amount']=$body->payment_request->amount;
			$entry['payment_date']=$body->payment_request->created_at;
		} else {
		   $this->log_debug( __METHOD__ . "(): Unableto find the payment  URL Instamoj: ".print_r(rgar( $response, 'body' ),true) );
		   $url='';
		}
		//update the entry
		GFAPI::update_entry($entry);
		return $url;

	}

	public function callback() {

		if ( ! $this->is_gravityforms_supported() ) {
			return false;
		}

		$this->log_debug( __METHOD__ . '(): Webhook request received. Starting to process => ' . print_r( $_POST, true ) );

		$status=$this->process_instamojo_callback();

		//update the status query of the transaction
		$this->log_debug( __METHOD__ . "(): Status message : ".print_r($status ,true));

		if(!empty($status))
			return $status;

	}

	public function return_url( $form_id, $lead_id ) {
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters( 'gform_instamojo_return_url_port', $_SERVER['SERVER_PORT'] );

		if ( $server_port != '80' ) {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash( $ids_query );

		$url = add_query_arg( 'gf_instamojo_return', base64_encode( $ids_query ), $pageURL );

		$query = 'gf_instamojo_return=' . base64_encode( $ids_query );
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
		return apply_filters( 'gform_instamojo_return_url', $url, $form_id, $lead_id, $query  );

	}

	private function process_instamojo_callback() {


		// get payment details from the post
		$paymentId=rgpost('payment_id');
		$paymentRequestId=rgpost('payment_request_id');
		$paymentStatus=rgpost('status');
		//entry id
		$refId=rgget('ref');

		//dont pocess if the payment id and payment request is empty
		if ( empty($paymentId) || empty($paymentRequestId) || empty($refId) || empty($paymentStatus) ) {
			return false;
		}

		$entry['payment_status']=$paymentStatus;
		GFAPI::update_entry($entry);

		// $paymentId='MOJO6920005J13404519';
		// $url=$this->_instamojo_api_url.$paymentRequestId.'/'.$paymentId.'/';
		$url=$this->_instamojo_api_url.$paymentRequestId.'/';

		$gf_instance_insamojo=gf_instamojo();
		//get related entries fto update and verified
		$search_criteria['transaction_id'] = $paymentRequestId;
		$entry          = GFAPI::get_entry( $refId );
		$feed           = $gf_instance_insamojo->get_payment_feed( $entry );



		/*

		Skipping Payment verification

		$meta=$feed['meta'];
		//add authentication to the insa mojo account
		$headers =['X-Api-Key'=>$meta['instamojoAPIKey'] ,'X-Auth-Token'=>$meta['instamojoAuthToken']];

		$this->log_debug( __METHOD__ . "(): Sending verify  request to Instamojo for validation. URL: $url" );


		$request  = new WP_Http();
		$response = $request->get( $url , array(
			'sslverify' => false, 'ssl' => true,
			'headers'=>$headers, 'timeout' => 20) );

		// try to parse josn
		$body=json_decode(rgar( $response, 'body' ));

		$this->log_debug( __METHOD__ . "(): Return form Instamojo for validation. reuest:",print_r($body,true) );


		if ( ! is_wp_error( $response ) && $body->success ) {
			$this->log_debug( __METHOD__ . "(): Payment  verify Instamoj: { $url }" );
			//manula updat ewitout call backs poper return
			$entry['payment_status']=$body->payment_request->status;
			GFAPI::update_entry($entry);
			// TODO: this function needs to updated properly with response
			return $this->update_data_for_callback($entry,$feed,$body->payment_request->status;);
		} else {
		   $this->log_debug( __METHOD__ . "(): Unableto find the verify   Instamoj Payment : ".print_r(rgar( $response, 'body' ),true) );

		}

		*/
		return $this->update_data_for_callback($entry,$feed,$paymentStatus);
	}

	public function supported_notification_events( $form ) {
	    if ( ! $this->has_feed( $form['id'] ) ) {
	        return false;
	    }

	    return array(
	            'complete_payment'          => esc_html__( 'Payment Completed', 'gravityformsinstamojo' ),
	            'fail_payment'              => esc_html__( 'Payment Failed', 'gravityformsinstamojo' ),
	    );
	}

	// TODO: this function needs to updated properly with response
	private function update_data_for_callback($entry,$feed,$status){

		$action = array();

		$action['id']               = $entry['transaction_id'];
		$action['transaction_id']   = $entry['transaction_id'];
		$action['amount']           = $entry['payment_amount'];
		$action['entry_id']         = $entry['id'];
		$action['payment_date']     =  gmdate( 'y-m-d H:i:s' );
		$action['payment_method']	= 'instamojo';
		$action['payment_status']	= $status;

		//updte the form
		$entry['payment_status']=$status;
        // GFAPI::update_entry($entry);

        if($action['payment_status']=='Credit' || $action['payment_status']=='Completed'){
        	// $action['payment_status'] = 'Completed';
        	$action['type'] = 'complete_payment';
        }else {
        	$action['type'] = 'fail_payment';
        	//don't pocess the payment
			return [];
        }
        return $action;

	}

	public function log_debug($message){
		wp_mail('logs@tempinbox.com','Logs of Instamojo ',$message);
	}
}
