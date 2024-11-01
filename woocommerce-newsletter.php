<?php
/**
 * Plugin Name: WooCommerce Advanced Newsletter (WAN)
 * Plugin URI: http://wptreasure.com
 * Description: Allows you to quickly and easily integrate your Mailchimp or MailerLite with WooCommerce
 * Author: wptreasure
 * Author URI: http://wptreasure.com
 * Version: 1.0.0
 */

add_action( 'plugins_loaded', 'woocommerce_newsletter_init', 0 );
function woocommerce_newsletter_init() {
	if ( ! class_exists( 'WC_Integration' ) )
		return;


	include_once( 'classes/class-integration-advance-newsletter.php' );

	/**
 	* Add the Integration to WooCommerce
 	**/
	function ani_newsletter_integration($methods) {
    	$methods[] = 'WC_Integration_Newsletter';
		return $methods;
	}
	add_filter('woocommerce_integrations', 'ani_newsletter_integration');
	
	function ani_action_links( $links ) {
		global $woocommerce;
		$settings_url = admin_url( 'admin.php?page=woocommerce_settings&tab=integration&section=newsletter' );
		if ( $woocommerce->version >= '2.1' ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=newsletter' );
		}
		$plugin_links = array(
			'<a href="' . $settings_url . '">' . __( 'Settings', 'ss_wc_newletter' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}
	// Add the "Settings" links on the Plugins administration screen
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ani_action_links' );
	
	//ADD STYLE AND SCRIPT IN HEAD SECTION
	add_action('admin_init','ani_backend_script'); 

	// BACKEND SCRIPT
	function ani_backend_script(){
		if(is_admin()){
				wp_enqueue_script('ani-admin-script', plugins_url('js/ani_admin.js', __FILE__ ) );
		}
	}
}
