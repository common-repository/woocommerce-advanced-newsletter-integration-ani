<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Newsletter Integration
 */
class WC_Integration_Newsletter extends WC_Integration {

	/**
	 * Init and hook in the integration.
	 */
	public function __construct() {
		$this->id					= 'newsletter';
		$this->method_title     	= __( 'Advance Newsletter Integration (ANI)', 'ss_wc_newsletter' );
		$this->method_description	= __( 'Advance Newsletter Integration is a popular email marketing service.', 'ss_wc_newsletter' );

		// Load the settings.
		$this->init_settings();

		$this->newletter_type = $this->get_option( 'newletter_type' );
		// We need the API key to set up for the lists in teh form fields
		$this->api_key = $this->get_option( 'api_key' );
		
		if(isset($_REQUEST['save']))
		{
			$this->newletter_type = isset($_REQUEST['woocommerce_newsletter_newletter_type'])?$_REQUEST['woocommerce_newsletter_newletter_type']:'';
			$this->api_key = isset($_REQUEST['woocommerce_newsletter_api_key'])?$_REQUEST['woocommerce_newsletter_api_key']:'';
		}
		
		// INCLUDE CLASS FOR SELECTED NEWLETTER
		if ( !class_exists( 'MCAPI' ) && $this->newletter_type=='mailchimp') {
			include_once( 'api/class-mailchimp.php' );
			$mailchimp = new MCAPI( $this->api_key );
			$this->mailchimp = $mailchimp;		
		}
		elseif ( !class_exists( 'Mailerlite' ) && $this->newletter_type=='mailerlite') {
			include_once( 'api/class-mailerlite-list.php' );
			$mailerlitelist = new ML_Lists( $this->api_key );
			$this->mailerlitelist = $mailerlitelist;

			include_once( 'api/class-mailerlite-subscribe.php' );
			$mailerlitesubscribe = new ML_Subscribers( $this->api_key );
			$this->mailerlitesubscribe = $mailerlitesubscribe;
		}

		$this->ani_init_form_fields();
		
		// Get setting values
		$this->enabled        = $this->get_option( 'enabled' );
		$this->occurs         = $this->get_option( 'occurs' );
		$this->list           = $this->get_option( 'list' );
		$this->display_opt_in = $this->get_option( 'display_opt_in' );
		$this->opt_in_label   = $this->get_option( 'opt_in_label' );
		$this->opt_in_checkbox_default_status = $this->get_option( 'opt_in_checkbox_default_status' );
		$this->opt_in_checkbox_display_location = $this->get_option( 'opt_in_checkbox_display_location' );

		// Hooks
		add_action( 'admin_notices', array( &$this, 'ani_checks' ) );
		add_action( 'woocommerce_update_options_integration', array( $this, 'process_admin_options') );
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options') );

		// We would use the 'woocommerce_new_order' action but first name, last name and email address (order meta) is not yet available, 
		// so instead we use the 'woocommerce_checkout_update_order_meta' action hook which fires after the checkout process on the "thank you" page
		add_action( 'woocommerce_checkout_update_order_meta', array( &$this, 'ani_order_status_changed' ), 1000, 1 );

		// hook into woocommerce order status changed hook to handle the desired subscription event trigger
		add_action( 'woocommerce_order_status_changed', array( &$this, 'ani_order_status_changed' ), 10, 3 );

		// Maybe add an "opt-in" field to the checkout
		add_filter( 'woocommerce_checkout_fields', array( &$this, 'ani_maybe_add_checkout_fields' ) );
		add_filter( 'default_checkout_ss_wc_newsletter_opt_in', array( &$this, 'ani_checkbox_default_status' ) );

		// Maybe save the "opt-in" field on the checkout
		add_action( 'woocommerce_checkout_update_order_meta', array( &$this, 'ani_maybe_save_checkout_fields' ) );
	}

	/**
	 * Check if the user has enabled the plugin functionality, but hasn't provided an api key
	 **/
	function ani_checks() {
		global $woocommerce;
		if ( $this->enabled == 'yes' ) {
			// Check required fields
			if (empty($this->api_key)) {
				echo '<div class="error"><p>' . sprintf( __('Newsletter error: Please enter your API key')) . '</p></div>';
				return;
			}
		}
	}

	/**
	 * order_status_changed function.
	 */
	public function ani_order_status_changed( $id, $status = 'new', $new_status = 'pending' ) {
		if ( $this->is_valid() && $new_status == $this->occurs ) {
			$order = new WC_Order( $id );
			// get the ss_wc_newsletter_opt_in value from the post meta. "order_custom_fields" was removed with WooCommerce 2.1
			$ss_wc_newsletter_opt_in = get_post_meta( $id, 'ss_wc_newsletter_opt_in', true );
			self::log( '$ss_wc_newsletter_opt_in: ' . $ss_wc_newsletter_opt_in );
			// If the 'ss_wc_newsletter_opt_in' meta value isn't set (because 'display_opt_in' wasn't enabled at the time the order was placed) or the 'ss_wc_newsletter_opt_in' is yes, subscriber the customer
			if ( ! isset( $ss_wc_newsletter_opt_in ) || empty( $ss_wc_newsletter_opt_in ) || 'yes' == $ss_wc_newsletter_opt_in ) {
				self::log( 'Subscribing user (' . $order->billing_email . ') to list(' . $this->list . ') ' );
				$this->ani_subscribe( $id, $order->billing_first_name, $order->billing_last_name, $order->billing_email, $this->list );
			}
		}
	}

	/**
	 * ani_has_list function.
	 */
	public function ani_has_list() {
		if ( $this->list )
			return true;
	}

	/**
	 * ani_has_api_key function.
	 */
	public function ani_has_api_key() {
		if ( $this->api_key )
			return true;
	}

	/**
	 * is_valid function.
	 */
	public function is_valid() {
		if ( $this->enabled == 'yes' && $this->ani_has_api_key() && $this->ani_has_list() ) {
			return true;
		}
		return false;
	}
	
	/**
	 * Initialize Settings Form Fields
	 */
	function ani_init_form_fields() {
		if ( is_admin() ) {
			$lists = $this->ani_get_lists();
 			if ($lists === false ) {
 				$lists = array ();
 			}
			
			if($this->newletter_type == 'mailchimp')
			{
				$api_link  = '(<a href="https://us9.admin.mailchimp.com/account/api/" target="_blank">Click here to get API key</a>)';
			}
			elseif($this->newletter_type == 'mailerlite')
			{
				$api_link  = '(<a href="https://app.mailerlite.com/integrations/api/" target="_blank">Click here to get API key</a>)';
			}
			else
			{
				$api_link = '';
			}
			
			$blank = array( '0' => __('Select a list...', 'ss_wc_mailchimp' )) ;
			$finallist = $blank + $lists;
 			$newsletter_lists = $this->ani_has_api_key() ? $finallist : array( '' => __( 'Enter your key and save to see your lists', 'ss_wc_newsletter' ) );
			
			$this->form_fields = array(
				'enabled' => array(
								'title' => __( 'Enable/Disable', 'ss_wc_newsletter' ),
								'label' => __( 'Enable Newsletter', 'ss_wc_newsletter' ),
								'type' => 'checkbox',
								'description' => '',
								'default' => 'no'
							),
				'newletter_type' => array(
								'title' => __( 'Newsletter Type', 'ss_wc_newsletter' ),
								'type' => 'select',
								'description' => __( 'Which Newsletter you want to integrate?', 'ss_wc_newsletter' ),
								'default' => '',
								'class' => 'chosen_select',
								'options' => array(
									''  => __( 'Select Newsletter Type', 'ss_wc_newsletter' ),
									'mailchimp'  => __( 'Mailchimp', 'ss_wc_newsletter' ),
									'mailerlite' => __( 'MailerLite', 'ss_wc_newsletter' ),
								)
							),
				'occurs' => array(
								'title' => __( 'Subscribe Event', 'ss_wc_newsletter' ),
								'type' => 'select',
								'description' => __( 'When should customers be subscribed to lists?', 'ss_wc_newsletter' ),
								'default' => 'pending',
								'class' => 'chosen_select',
								'options' => array(
									'pending' => __( 'Order Created', 'ss_wc_newsletter' ),
									'completed'  => __( 'Order Completed', 'ss_wc_newsletter' ),
								),
							),
				'api_key' => array(
								'title' 		=> __( 'API Key', 'ss_wc_newsletter' ),
								'type'  		=> 'text',
								'description'   => __( 'Enter your API key connected with your account. '.$api_link.'', 'ss_wc_newsletter' ),
								'default' 		=> '',
								'placeholder'	=> 'Enter your API Key'
							),
				'list' => array(
								'title'  => __( 'Main List', 'ss_wc_newsletter' ),
								'type'   => 'select',
								'class'  => 'chosen_select',
								'description' => __( 'All customers will be added to this list.', 'ss_wc_newsletter' ),
								'default' => '',
								'options' => $newsletter_lists,
							),
				'display_opt_in' => array(
								'title'       => __( 'Display Opt-In Field', 'ss_wc_newsletter' ),
								'label'       => __( 'Display an Opt-In Field on Checkout', 'ss_wc_newsletter' ),
								'type'        => 'checkbox',
								'description' => __( 'If enabled, customers will be presented with a "Opt-in" checkbox during checkout and will only be added to the list above if they opt-in.', 'ss_wc_newsletter' ),
								'default'     => 'no',
							),
				'opt_in_label' => array(
								'title'       => __( 'Opt-In Field Label', 'ss_wc_newsletter' ),
								'type'        => 'text',
								'description' => __( 'Optional: customize the label displayed next to the opt-in checkbox.', 'ss_wc_newsletter' ),
								'default'     => __( 'Add me to the newsletter (we will never share your email).', 'ss_wc_newsletter' ),
							),
				'opt_in_checkbox_default_status' => array(
								'title'       => __( 'Opt-In Checkbox Default Status', 'ss_wc_newsletter' ),
								'type'        => 'select',
								'class' => 'chosen_select',
								'description' => __( 'The default state of the opt-in checkbox.', 'ss_wc_newsletter' ),
								'default'     => 'checked',
								'options'	=> array( 'checked' => __( 'Checked', 'ss_wc_newsletter' ), 'unchecked' => __( 'Unchecked', 'ss_wc_newsletter' ) )
							),
				'opt_in_checkbox_display_location' => array(
								'title'       => __( 'Opt-In Checkbox Display Location', 'ss_wc_newsletter' ),
								'type'        => 'select',
								'class' => 'chosen_select',
								'description' => __( 'Where to display the opt-in checkbox on the checkout page (under Billing info or Order info).', 'ss_wc_newsletter' ),
								'default'     => 'billing',
								'options'	=> array( 'billing' => __( 'Billing', 'ss_wc_newsletter' ), 'order' => __( 'Order', 'ss_wc_newsletter' ) )
							),
			);
		}

	} // End ani_init_form_fields()

	/**
	 * ani_get_lists function.
	 */
	public function ani_get_lists() {
		if ( ! $lists = get_transient( 'ss_wc_newsletter_list_' . md5( $this->api_key ) ) ) {
			$lists = array();
			
			if ($this->newletter_type=='mailchimp') {
				$mailchimp = $this->mailchimp;
				$retval    = $mailchimp->lists();

				if ( $mailchimp->errorCode ) {
					echo '<div class="error"><p>' . sprintf( __( 'Unable to load Mailchimp lists() from Newsletter: %s', 'ss_wc_newsletter' ), $mailchimp->errorMessage ) . '</p></div>';
					return false;
				} 
				else{
					foreach ( $retval['data'] as $list ){
						$lists[ $list['id'] ] = $list['name'];
					}

					if ( sizeof( $lists ) > 0 ){
						set_transient( 'ss_wc_newsletter_list_' . md5( $this->api_key ), $lists, 60*60*1 );
					}
				}
			}
			else if ($this->newletter_type=='mailerlite') {
				$mailerlite = $this->mailerlitelist;
				$list_data = $mailerlite->getAll();
				$retval = json_decode($list_data);
				$mailerlite_data = $retval->Results;
				
				if (!empty($retval->message)) {
					echo '<div class="error"><p>' . sprintf( __( 'Unable to load Mailerlite lists() from Newsletter: %s', 'ss_wc_newsletter' ), $retval->message ) . '</p></div>';
					return false;
				} 
				else{
					if(!empty($mailerlite_data))
					{
						foreach ( $mailerlite_data as $list )
						{
							$lists[$list->id] = $list->name;
						}
					}
				}
			}
		}
		return $lists;
	}

	/**
	 * ani_subscribe function.
	 */
	public function ani_subscribe( $order_id, $first_name, $last_name, $email, $listid = 'false' ) {
		if ( ! $email )
			return; // Email is required
		if ( $listid == 'false' )
			$listid = $this->list;
			
		if ($this->newletter_type=='mailchimp') {
			$api = new MCAPI( $this->api_key );	

			$merge_vars = array( 'FNAME' => $first_name, 'LNAME' => $last_name );

			$vars = apply_filters( 'ss_wc_newsletter_subscribe_merge_vars', $merge_vars, $order_id );
			$email_type = 'html';
			$double_optin = false;
			$update_existing = true;
			$replace_interests = false;
			$send_welcome = false;

			self::log( 'Calling Newsletter API listSubscribe method with the following: ' .
				'listid=' . $listid .
				', email=' . $email .
				', vars=' . print_r( $vars, true ) . 
				', email_type=' . $email_type . 
				', double_optin=' . $double_optin .
				', update_existing=' . $update_existing .
				', replace_interests=' . $replace_interests .
				', send_welcome=' . $send_welcome
			);

			$retval = $api->listSubscribe( $listid, $email, $vars, $email_type, $double_optin, $update_existing, $replace_interests, $send_welcome );
			self::log( 'Newsletter return value:' . $retval );
			if ( $api->errorCode && $api->errorCode != 214 ) {
				self::log( 'WooCommerce Newsletter subscription failed: (' . $api->errorCode . ') ' . $api->errorMessage );
				do_action( 'ss_wc_newsletter_subscribed', $email );
				// Email admin
				wp_mail( get_option('admin_email'), __( 'WooCommerce Newsletter subscription failed', 'ss_wc_newsletter' ), '(' . $api->errorCode . ') ' . $api->errorMessage );
			}
		}
		else if ($this->newletter_type=='mailerlite') {
			$mailerlitesubscribe = $this->mailerlitesubscribe;

			$subscriber = array(
				'email' => $email,
				'name' => $first_name,
			); 

			$subscriber = $mailerlitesubscribe->setId($listid)->add( $subscriber, 1);
		}
	}

	/**
	 * Admin Panel Options
	 */
	function admin_options() {
    	?>
		<h3><?php _e( 'WooCommerce Advanced Newsletter (WAN)', 'ss_wc_newsletter' ); ?></h3>
    	<p><?php _e( 'Enter your Newsletter settings below to control how WooCommerce integrates with your Newsletter lists.', 'ss_wc_newsletter' ); ?></p>
    		<table class="form-table">
	    		<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
		<?php
	}

	/**
	 * Add the opt-in checkbox to the checkout fields (to be displayed on checkout).
	 */
	function ani_maybe_add_checkout_fields( $checkout_fields ) {
		$opt_in_checkbox_display_location = $this->opt_in_checkbox_display_location;
		if ( empty( $opt_in_checkbox_display_location ) ) {
			$opt_in_checkbox_display_location = 'billing';
		}
		if ( 'yes' == $this->display_opt_in ) {
			$checkout_fields[$opt_in_checkbox_display_location]['ss_wc_newsletter_opt_in'] = array(
				'type'    => 'checkbox',
				'label'   => esc_attr( $this->opt_in_label ),
				'default' => ( $this->opt_in_checkbox_default_status == 'checked' ? 1 : 0 ),
			);
		}
		return $checkout_fields;
	}

	/**
	 * Opt-in checkbox default support for WooCommerce 2.1
	 */
	function ani_checkbox_default_status( $input ) {
		return $this->opt_in_checkbox_default_status == 'checked' ? 1 : 0;
	}

	/**
	 * When the checkout form is submitted, save opt-in value.
	 */
	function ani_maybe_save_checkout_fields( $order_id ) {
		if ( 'yes' == $this->display_opt_in ) {
			$opt_in = isset( $_POST['ss_wc_newsletter_opt_in'] ) ? 'yes' : 'no';
			update_post_meta( $order_id, 'ss_wc_newsletter_opt_in', $opt_in );
		}
	}

	/**
	 * Helper log function for debugging
	 */
	static function log( $message ) {
		if ( WP_DEBUG === true ) {
			if ( is_array( $message ) || is_object( $message ) ) {
				error_log( print_r( $message, true ) );
			} else {
				error_log( $message );
			}
		}
	}

}