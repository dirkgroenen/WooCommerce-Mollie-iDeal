<?php
/* 
	Plugin Name: WooCommerce - Mollie iDeal gateway
	Plugin URI: https://www.bitlabs.nl 
	Version: 1.1
	Author: Bitlabs
	Author URI: https://www.bitlabs.nl
	Description: Adds a Mollie iDeal gateway to your webshop
*/  
	add_action('plugins_loaded', 'woocommerce_gateway_name_init', 0);
 
	function woocommerce_gateway_name_init() {
	 
		if ( !class_exists( 'WC_Payment_Gateway' ) ) return;
	 
		/**
		 * Localisation
		 */
		load_plugin_textdomain('wcblmollieideal', false, dirname( plugin_basename( __FILE__ ) ) . '/lang/');
		
		/**
		 * Gateway class
		 */
		class WC_BL_Mollie_iDeal extends WC_Payment_Gateway {
		
			/*
			* Constructer
			*/
			public function __construct(){
				global $woocommerce;
				
				$this->load_lib();
				
				/* Create iDeal object */
				$this->ideal = new Mollie_iDEAL_Payment(0);
				
				/* Required settings */
				$this->id           = 'ideal';
				$this->icon         = plugins_url('/img/logo.png', __FILE__);
				$this->has_fields   = true;
				$this->method_title = __( 'iDeal', 'wcblmollieideal' );
				$this->method_description = __( 'Payment gateway for Mollie iDeal', 'wcblmollieideal' );
				
				$this->init_form_fields();
				$this->init_settings();
				
				/* Get user saved options */
				$this->title 					= $this->get_option( 'title' );
				$this->description 				= $this->get_option( 'description' );
				$this->partner_id				= $this->get_option( 'partner_id' );
				$this->testmode					= $this->get_option( 'testmode' );
				$this->order_prefix				= $this->get_option( 'order_prefix' );
				
				/* Set the base url */
				$this->base_url	= plugins_url();
				$this->notify_url = str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_BL_Mollie_iDeal', home_url( '/' ) ) );
				
				/* Setup iDeal object */
				$this->setupMollie();
				$this->order = null;
				
				/* Setup order charge */
				$this->order_charge_price = 0.00;
				
				/* Setup the logger */
				$this->log = $woocommerce->logger();
				
				/* Add hooks */
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action( 'woocommerce_receipt_ideal', array($this, 'receipt_page'));
				add_action( 'woocommerce_api_wc_bl_mollie_ideal', array( $this, 'check_ipn_response' ) );
			}
			
			/*
			* Include the iDeal mollie lib
			*/
			public function load_lib(){
				require_once('lib/ideal.php');
			}
			
			/*
			* Setup the mollie ideal object
			*/
			public function setupMollie(){
				/* Set partner id */
				$this->ideal->setPartnerId($this->get_option('partner_id'));
				
				/* Set testmode */
				($this->get_option('testmode') == 'yes') ? $this->ideal->setTestmode() : $this->ideal->setTestmode(false);
			}
			
			public function init_form_fields(){
				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Enable iDeal payment', 'wcblmollieideal' ),
						'default' => 'yes'
					),
					'title' => array(
						'title' => __( 'Title', 'wcblmollieideal' ),
						'type' => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'wcblmollieideal' ),
						'default' => __( 'iDeal', 'wcblmollieideal' ),
						'desc_tip'      => true,
					),
					'description' => array(
						'title' => __( 'Customer Message', 'wcblmollieideal' ),
						'type' => 'textarea',
						'default' => __('Pay your order with iDeal.', 'wcblmollieideal')
					),
					'testmode' => array(
						'title' => __( 'Enable/Disable', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Enable testmode', 'wcblmollieideal' ),
						'default' => 'no',
						'description' => __( 'Add a testbank to your iDeal bank list. Be sure to turn it off when you go online!', 'wcblmollieideal' ),
						'desc_tip'      => true
					),
					'partner_id' => array(
						'title' => __( 'Mollie partner ID', 'wcblmollieideal' ),
						'type' => 'text',
						'description' => __( 'Add your Mollie partner ID here. You can find it on your profile page on http://mollie.nl.', 'wcblmollieideal' ),
						'desc_tip'      => true
					),
					'order_prefix' => array(
						'title' => __( 'Bank statement prefix', 'wcblmollieideal' ),
						'type' => 'text',
						'description' => __( 'Set the prefix that customers will see on their bank statement. (An order ID will automatically be placed at the end) (Up to 20 characters allowed. More will be removed.)', 'wcblmollieideal' ),
						'default' => get_bloginfo( 'name' ),
						'desc_tip'      => true
					)
				);
			}
			
			/*
			* Payment fields, shows a list of the banks
			*/
			function payment_fields(){
				// Get banks
				$bank_array = $this->ideal->getBanks();
				
				$desc = "<p style='margin: 0 0 10px 0;'>".$this->get_option('description')."</p>";
				$desc .= '<select name="bank_id"><option value="">'. __("Choose your bank:", "wcblmollieideal") .'</option>';
				
				foreach ($bank_array as $bank_id => $bank_name) {
					$desc .= "<option value='". htmlspecialchars($bank_id)."'>".htmlspecialchars($bank_name)."</option>";
				}
				
				$desc .= "</select>";
			
				echo $desc;
			}
			
			/**
			 * Check for mollie IPN Response
			 */
			function check_ipn_response() {
				global $woocommerce, $wpdb;

				if ( !empty( $_GET ) ){
					$transid = $_GET['transaction_id'];
					
					$orderid = $wpdb->get_var("SELECT post_id FROM ". $wpdb->postmeta ." WHERE meta_key = 'iDeal transaction ID' AND meta_value = '".$transid."' LIMIT 1 "); 
					if($orderid == null){
						$this->log->add( 'mollie-ideal', 'No order found with transaction_id: '.$transid);
					}
					else{
						// Create objects since they don't exist anymore in this ipn callback
						$this->order = new WC_Order($orderid);
						$this->ideal = new Mollie_iDEAL_Payment( $this->get_option('partner_id') );
						
						// Check the payment
						$this->ideal->checkPayment($_GET['transaction_id']);
						$this->log->add( 'mollie-ideal', 'Checking transaction id: '.$_GET['transaction_id'] );
						
						// Check the payed status
						if ($this->ideal->getPaidStatus()){
							// Get payed amount from iDeal
							$payed_amount = $this->ideal->getAmount();
							
							// Check the orders post meta with the data from mollie
							$transaction = get_post_meta($this->order->id, 'iDeal transaction ID', true);
							$amount = get_post_meta($this->order->id, 'iDeal amount to pay', true);
								
							$this->log->add( 'mollie-ideal', 'Order ['.$orderid.'] found with transaction_id: '.$transid.'. Check amounts: '.$payed_amount.' - '.$amount);
								
							if($transid == $transaction && $amount == $payed_amount){
								$this->log->add( 'mollie-ideal', 'Transaction approved: '.$transid. '. Order: '.$this->order->id );
									
								// Data is correct, mark order as complete
								$this->order->payment_complete();
							}
							else{
								$this->log->add( 'mollie-ideal', 'Transaction denied: '.$transid. '. Order: '.$this->order->id );
								$this->order->update_status('failed', "No payment done or payed amount does not equal asked amount to pay.");
							}
						}
						else{
							$this->log->add( 'mollie-ideal', 'Transaction denied: '.$transid. '. Payed status returned false');
							$this->order->update_status('failed', "Payed status returned false.");
						}
					} 
				}
				else {
					wp_die( "Mollie IPN Request Failure" );
					$this->order->update_status('failed');
					$this->log->add( 'mollie-ideal', 'Transaction denied, no GET parameters');
				}
			}
			
			/*
			* Validate fields
			*/
			function validate_fields(){
				global $woocommerce;
				
				if(isset($_POST['bank_id'])){
					$this->bank_id = $_POST['bank_id'];
					return true;
				}
				else{
					$woocommerce->add_error(__('Payment error:', 'wcblmollieideal') . $error_message);
					return;
				}
			}

			
			/*
			* Process the payment and return the result
			*/
			function process_payment( $order_id ) {
				global $woocommerce;
				
				// Get the order
				$this->order = new WC_Order( $order_id );
				
				if( $this->ideal->createPayment($this->bank_id, $this->order->get_total()*100, $this->order_prefix." (".$order_id.")", $this->get_return_url( $this->order ), $this->notify_url) ){
					
					$this->log->add( 'mollie-ideal', 'Generating payment form for order ' . $this->order->get_order_number() . '. Notify URL: ' . $this->notify_url.'. Transaction ID: '.$this->ideal->getTransactionId() );
					
					// Save transaction ID, ammount to pay and bank
					update_post_meta( $order_id, 'iDeal transaction ID', $this->ideal->getTransactionId() );
					update_post_meta( $order_id, 'iDeal bank ID', $this->bank_id );
					update_post_meta( $order_id, 'iDeal amount to pay', $this->order->get_total()*100 );
					
					// Change status of order to pending
					$this->order->update_status('pending');
					
					// Redirect to bank
					return array(
						'result' => 'success',
						'redirect' => $this->ideal->getBankURL()
					);
				}
				else{
					// On fail
					$this->order->update_status('failed');
					$woocommerce->add_error(__('Payment error:', 'wcblmollieideal') . htmlspecialchars($this->ideal->getErrorMessage()));
					return;
				}
			}
		
		}
		
		/**
		* Add the Gateway to WooCommerce
		**/
		function woocommerce_add_gateway_name_gateway($methods) {
			$methods[] = 'WC_BL_Mollie_iDeal';
			return $methods;
		}
		
		add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_name_gateway' );
	} 