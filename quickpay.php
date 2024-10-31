<?php
/**
 * Plugin Name: QuickaPay for WooCommerce
 * Description: Accept all major credit/debit cards and offer your customers 0% interest instalment plans through QuickaPay with your WooCommerce online store.
 * Version: 1.0.5
 * Author: QuickaPay
 * Author URI: https://www.quickapay.com/
 */

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_filter( 'woocommerce_payment_gateways', 'wt_quickapay_add_gateway_class' );
function wt_quickapay_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_quickapay_Wt_Gateway'; // your class name is here
	return $gateways;
}

function quickapay_my_load_scripts($hook) {
    // create my own version codes
    $my_css_ver = date("ymd-Gis", filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/quickapay.css' ));
     
    wp_register_style( 'my_quickapay_css',    plugins_url( 'assets/css/quickapay.css',    __FILE__ ), false,   $my_css_ver );
    wp_enqueue_style ( 'my_quickapay_css' );
}
add_action('wp_enqueue_scripts', 'quickapay_my_load_scripts');

//  function njengah_woocommerce_cod_icon( $icon, $id ) {
// 	 if ( $id === 'quickapay_wt' ){
// 	   return $icon_url; 
// 	} else { 
// 	return $icon; } 
// } 
// add_filter( 'woocommerce_gateway_icon', 'njengah_woocommerce_cod_icon', 10, 2 );

function quickapay_add_query_vars_filter( $vars ){
	$vars[] = "token";
	$vars[] = "rejected";
  	return $vars;
}
add_filter( 'query_vars', 'quickapay_add_query_vars_filter' );


add_filter( 'woocommerce_gateway_icon', 'gateway_quickapay_description', 10, 2 );
function gateway_quickapay_description( $icon, $payment_id ){
    if ( \strpos($payment_id, 'quickapay_wt') !== false ) {
    	$icon_url = '<div class="quickapay_payment_icon_cont">';
	 		$icon_url .= '<img src="'.plugins_url( 'assets/img/quickapay_regular.svg',    __FILE__ ).'" alt="Quicka Pay" style="max-width: 80px !important;"/>';
	 		$icon_url .= '<img src="'.plugins_url( 'assets/img/mastercard.svg',    __FILE__ ).'" alt="Master Card" />';
	 		$icon_url .= '<img src="'.plugins_url( 'assets/img/visa.svg',    __FILE__ ).'" alt="Visa" />';
 		$icon_url .= '</div>';

        $description_text = __(quickapay_plans_on_different_pages(), "woocommerce");
        $icon .= $icon_url.'</label><div class="payment_box payment_method_'.$payment_id.'">'.$description_text.'</div>';
    }
    return $icon;
}


add_action( 'template_redirect', 'wt_custom_redirect_after_purchase' );
function wt_custom_redirect_after_purchase() {
	global $wp;
	if ( is_checkout() && !empty( $wp->query_vars['order-received'] ) ) {
		$settlementToken = get_query_var('token');
		$settlementTokenRejected = get_query_var('rejected');
		if($settlementToken == 'none' && $settlementTokenRejected){
			$cart_page_id = wc_get_page_id( 'cart' );
			$cart_page_url = get_permalink( $cart_page_id );
			wp_redirect($cart_page_url);
		}
	}
}
function wt_quickapay_action_woocommerce_thankyou( $order_id ) { 
	$settlementToken = get_query_var('token');
	$settlementTokenRejected = get_query_var('rejected');
	if($settlementToken == 'none' && $settlementTokenRejected){
		$order = new WC_Order($order_id);
		$order->update_status('failed');
	}else{
		update_post_meta($order_id, 'quicka_settlement_code', $settlementToken);

		$order = new WC_Order($order_id);
		$quickapaySettings = get_option( 'woocommerce_quickapay_wt_settings' );
		$wt_quickapay_access_key = $quickapaySettings['wt_quickapay_access_key'];
		$wt_quickapay_business_id = $quickapaySettings['wt_quickapay_business_id'];

		if(!empty(get_post_meta($order_id, 'quicka_invoice'))){
			$quicka_invoice_id = get_post_meta($order_id, 'quicka_invoice', true);
			$getStatusJson = '{
				"settlement_token": "'.$settlementToken.'",
				"invoice_id": "'.$quicka_invoice_id.'"
			}';

			$url = "https://my.quicka.co/v1/payments/verify";
			$request = wp_remote_post( $url, array(
				'body'    => $getStatusJson,
				'headers' => array(
					'X-Access-Key' => $wt_quickapay_access_key,
					'X-Business-Id' => $wt_quickapay_business_id,
					'Content-Type' => "application/json",
				),
			) );

			if ( is_wp_error( $request ) ) {
				$error_message = $request->get_error_message();
				echo "Something went wrong: $error_message";
			} else {
				$response = wp_remote_retrieve_body( $request );
				$paymentStatus = (array) json_decode($response);
				if(isset($paymentStatus['status'])){
					if($paymentStatus['status'] == 'paid'){
						$order->update_status('processing');
					} else if($paymentStatus['status'] == 'pending'){
						$order->update_status('pending');
					} else if($paymentStatus['status'] == 'refunded'){
						$order->update_status('refunded');
					} else if($paymentStatus['status'] == 'cancelled'){
						$order->update_status('failed');
					} else if($paymentStatus['status'] == 'failed'){
						$order->update_status('failed');
					}
				}
			}
			//"paid", "pending", "refunded", "cancelled" or "failed".
		}
	}
    // make action magic happen here... 
}
add_action( 'woocommerce_thankyou', 'wt_quickapay_action_woocommerce_thankyou', 10, 1 ); 

add_action( 'woocommerce_before_add_to_cart_button', 'quickapay_plans_on_different_pages' );
add_action('woocommerce_proceed_to_checkout', 'quickapay_plans_on_different_pages');
function quickapay_plans_on_different_pages(){
	global $woocommerce;

	$quickapaySettings = get_option( 'woocommerce_quickapay_wt_settings' );

	$wt_quickapay_access_key = $quickapaySettings['darkmode'];

	$checkoutClass = '';
	$darkModeClass = '';

	if ( is_single() ){ 
		$quickaProduct = wc_get_product( get_the_id() );
		$quickaPrice = $quickaProduct->get_price();
		$totalPriceQuicka = $quickaPrice;
		$quickaFortnightPrice = $quickaPrice / 6;
	} else if(is_cart() || is_checkout()) {
	 	$quickaCart = $woocommerce->cart->total;
	 	$totalPriceQuicka = $quickaCart;
	 	$quickaFortnightPrice  = $quickaCart / 6;
	} else {
	 	$quickaFortnightPrice = '0.00';
	 	$totalPriceQuicka = 0;
	}

	$finalInstallmentDate = date('d F Y', strtotime('+70 days'));
	if(is_checkout()){
		$checkoutClass = 'quicka-pay-checkout';
		ob_start();
	}

	if($wt_quickapay_access_key == 'yes'){
		$darkModeClass = 'quicka-pay-dark';
	}

	if(is_single() && $quickapaySettings['single_page_payment_box'] == 'yes'){
		// quickapay box will not be populated
	}else if(is_cart() && $quickapaySettings['cart_page_payment_box'] == 'yes'){
		// quickapay box will not be populated
	}else{
		if($totalPriceQuicka > 249.99) { 
			if($totalPriceQuicka >= 250 && $totalPriceQuicka <= 299.99){
				$lineOneText = 'Spend over $300 & pay in 6 fortnightly instalments.';
				$lineTwoText = 'No credit check, no interest, and no fees.';
			} else if($totalPriceQuicka >= 300){
				$lineOneText = 'Pay in 6 fortnightly instalments.';
				$lineTwoText = 'No credit check, no interest, and no fees.';
			}
			?>
			<div class="quickapay-payment-plans <?php echo $darkModeClass; ?>  <?php echo $checkoutClass; ?>">
				<div class="quickapay-payment-plans-inner">
					<div class="quickapay-payment-plans-top-section">
						<div class="quickapay-payment-plans-top-section-inner">
							<div class="quickapay-payment-plans-top-section-left">
								<span><strong><?php echo $lineOneText; ?></strong></span>
								<p><?php echo $lineTwoText; ?>, <a href="https://www.quickapay.com/" target="_blank">find out more</a></p>
							</div>
							<div class="quickapay-payment-plans-top-section-right">
								<?php if($wt_quickapay_access_key == 'yes'){ ?>
									<img src="<?php echo plugins_url( 'assets/img/quickapay_white.svg',    __FILE__ ); ?>" alt="quickpay" />
								<?php } else { ?>
									<img src="<?php echo plugins_url( 'assets/img/quickapay_regular.svg',    __FILE__ ); ?>" alt="quickpay" />
								<?php } ?>
							</div>
						</div>
					</div>
					<?php

					if($quickapaySettings['cart_page_slimmed_down'] == 'no'){
					?>
					<div class="quickapay-payment-plans-bottom-section">
						<ul>
							<li>
								<div class="quickapay-payment-plans-bottom-section-left">
									<span>$<?php echo number_format((float)$quickaFortnightPrice, 2, '.', ''); ?></span>
								</div>
								<div class="quickapay-payment-plans-bottom-section-right">
									<p><strong>First Payment</strong></p>
									<p>Paid today</p>
								</div>
							</li>
							<li>
								<div class="quickapay-payment-plans-bottom-section-left">
									<span>$<?php echo number_format((float)$quickaFortnightPrice, 2, '.', ''); ?></span>
								</div>
								<div class="quickapay-payment-plans-bottom-section-right">
									<p><strong>4x Fortnightly Instalments</strong></p>
									<p>Debited every two weeks</p>
								</div>
							</li>
							<li>
								<div class="quickapay-payment-plans-bottom-section-left">
									<span>$<?php echo number_format((float)$quickaFortnightPrice, 2, '.', ''); ?></span>
								</div>
								<div class="quickapay-payment-plans-bottom-section-right">
									<p><strong>Final Instalment</strong></p>
									<p>Debited on the <?php echo $finalInstallmentDate; ?></p>
								</div>
							</li>
						</ul>
					</div>

				<?php } ?>
				</div>
			</div>
			<?php 
		} 
	}
	if(is_checkout()){
		return ob_get_clean();
	}
}

// make billing phone field required
add_filter( 'woocommerce_billing_fields', 'make_billing_phone_field_required', 20, 1 );
function make_billing_phone_field_required($fields) {
    $fields ['billing_phone']['required'] = true;
    return $fields;
}

// add the action 

add_action( 'plugins_loaded', 'wt_quickapay_init_gateway_class' );
function wt_quickapay_init_gateway_class() {
 
	class WC_quickapay_Wt_Gateway extends WC_Payment_Gateway {
 			
 		/**
 		 * Class constructor, more about it in Step 3
 		 */
 		public function __construct() {
 
			$this->id = 'quickapay_wt'; // payment gateway plugin ID
			$this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
			$this->has_fields = true; // in case you need a custom credit card form
			$this->method_title = 'QuickaPay Payment Gateway';
			$this->method_description = 'The official WooCommerce payment gateway for QuickaPay. Offer your customers a flexible payment plan over 6 easy fortnightly instalments.'; // will be displayed on the options page
		 
			// gateways can support subscriptions, refunds, saved payment methods,
			// but in this tutorial we begin with simple payments
			$this->supports = array(
				'products'
			);
		 
			// Method with all the options fields
			$this->init_form_fields();
		 
			// Load the settings.
			$this->init_settings();
			$this->title = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled = $this->get_option( 'enabled' );
			$this->testmode = 'yes' === $this->get_option( 'testmode' );

			// 'access_key' => $this->wt_quickapay_access_key,
			// 	'business_id' => $this->wt_quickapay_business_id

			$this->wt_quickapay_access_key = $this->get_option( 'wt_quickapay_access_key' );
			$this->wt_quickapay_business_id = $this->get_option( 'wt_quickapay_business_id' );
		 
			// This action hook saves the settings
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		 
			// We need custom JavaScript to obtain a token
			add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
		 
			// You can also register a webhook here
			// add_action( 'woocommerce_api_{webhook name}', array( $this, 'webhook' ) );
		 }
 
		/**
 		 * Plugin options, we deal with it in Step 3 too
 		 */
 		public function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title'       => 'Enable/Disable',
					'label'       => 'Enable QuickaPay Gateway',
					'type'        => 'checkbox',
					'description' => 'Pay via QuickaPay.',
					'default'     => 'no'
				),
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'This controls the title which the user sees during checkout.',
					'default'     => 'QuickaPay',
					'desc_tip'    => true,
				),
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay with QuickaPay payment Gateway.',
				),
				// 'wt_quickapay_testmode' => array(
				// 	'title'       => 'Test mode',
				// 	'label'       => 'Enable Test Mode',
				// 	'type'        => 'checkbox',
				// 	'description' => 'Place the payment gateway in test mode using test API keys.',
				// 	'default'     => 'yes',
				// 	'desc_tip'    => true,
				// ),
				// 'wt_quickapay_test_access_key' => array(
				// 	'title'       => 'Test Access Key',
				// 	'type'        => 'text'
				// ),
				// 'wt_quickapay_test_business_id' => array(
				// 	'title'       => 'Test Business Id',
				// 	'type'        => 'password',
				// ),
				'wt_quickapay_access_key' => array(
					'title'       => 'Live Access Key',
					'type'        => 'text',
					'description' => 'Please obtain these details from the Settings page in your QuickaPay dashboard by logging in <a href="https://app.quicka.co" target="_blank">here.</a>',
				),
				'wt_quickapay_business_id' => array(
					'title'       => 'Live Business Id',
					'type'        => 'password',
					'description' => 'Please obtain these details from the Settings page in your QuickaPay dashboard by logging in <a href="https://app.quicka.co" target="_blank">here.</a>',
				),
				'darkmode' => array(
					'title'       => 'Dark Mode',
					'label'       => 'Enable Dark Mode',
					'type'        => 'checkbox',
					'description' => 'Payment description dark mode',
					'default'     => 'no'
				),
				'single_page_payment_box' => array(
					'title'       => 'Disable payment plan box on single product pages',
					'label'       => 'Disable Box',
					'type'        => 'checkbox',
					'description' => 'If checked, this will hide the whole "Pay in 6 fortnightly instalments." QuickaPay box on single product pages',
					'default'     => 'no'
				),
				'cart_page_payment_box' => array(
					'title'       => 'Disable payment plan box on cart page',
					'label'       => 'Disable Box',
					'type'        => 'checkbox',
					'description' => 'If checked, this will hide the whole "Pay in 6 fortnightly instalments." QuickaPay box on the cart page',
					'default'     => 'no'
				),
				'cart_page_slimmed_down' => array(
					'title'       => 'Reduced cart text',
					'label'       => 'Enable reduced cart text',
					'type'        => 'checkbox',
					'default'     => 'no' 
				),
			);
		}
 
		/**
		 * You will need it if you want your custom credit card form, Step 4 is about it
		 */
		public function payment_fields() {
			// scope for future purposes
		}
 
		/*
		 * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
		 */
	 	public function payment_scripts() {
 			// scope for future purposes
	 	}
 
		/*
 		 * Fields validation, more in Step 5
		 */
		public function validate_fields() {
			// scope for future purposes
		}
 
		/*
		 * We're processing the payments here, everything about it is in Step 5
		 */
		public function process_payment( $order_id ) {
			$accessKey = $this->wt_quickapay_access_key;
			$businessId  = $this->wt_quickapay_business_id;

			$order=wc_get_order($order_id);
			$order_key = $order->get_order_key();
			$checkout_url = wc_get_checkout_url().'order-received/'.$order_id.'/?key='.$order_key;

			$full_name = $order->get_billing_first_name().' '.$order->get_billing_last_name();
			$requested_json = '{
				"return_url": "'.$checkout_url.'",
				"contact_email": "'.$order->get_billing_email().'",
				"contact_name": "'.$full_name .'",
				"total_amount": "'.$order->get_total().'",
				"payment_options": [
				"Card",
				"Instalments"
				],
				"invoice_pdf": "'.base64_encode($checkout_url).'",
				"invoice_url": "'.$checkout_url.'",
				"invoice_reference": "'.get_bloginfo().' Order #'.$order_id.'"
			}';

			$url = "https://my.quicka.co/v1/payments";
			$request = wp_remote_post( $url, array(
				'body'    => $requested_json,
				'headers' => array(
					'X-Access-Key' => $accessKey,
					'X-Business-Id' => $businessId,
					'Content-Type' => "application/json",
				),
			) );

			if ( is_wp_error( $request ) ) {
				$error_message = $request->get_error_message();
				echo "Something went wrong: $error_message";
			} else {
				$response = wp_remote_retrieve_body( $request );
				$payment_status = (array) json_decode($response);
				if($payment_status['payment_page_url']){
					update_post_meta($order_id, 'quicka_invoice', $payment_status['invoice_id']);
					return array(
						'result'   => 'success',
						'redirect' => $payment_status['payment_page_url'],
					);
				} else{
					throw new Exception( __( 'We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.') );
				}
			}
			die();

		}
		/*
		 * In case you need a webhook, like PayPal IPN etc
		 */
		public function webhook() {
 			// scope for future purposes
	 	}
 	}
}