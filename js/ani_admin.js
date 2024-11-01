//-- WooCommerce Advanced Newsletter(WAN)
//--------------------------------------------------------
jQuery(document).ready(function() 
{
	jQuery('#woocommerce_newsletter_display_opt_in').change(function(){
		jQuery('#mainform [id^=woocommerce_newsletter_opt_in]').closest('tr').hide('fast');
		if ( jQuery(this).prop('checked') == true ) {
			jQuery('#mainform [id^=woocommerce_newsletter_opt_in]').closest('tr').show('fast');
		} else {
			jQuery('#mainform [id^=woocommerce_newsletter_opt_in]').closest('tr').hide('fast');
		}
	}).change();

	jQuery('#woocommerce_newsletter_newletter_type').change(function(){
		
	});
});