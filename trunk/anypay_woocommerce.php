<?php
/**
 * @package Anypay_WooCommerce
 * Version: 0.3.4
 * Plugin Name: Anypay WooCommerce
 * Plugin URI: http://wordpress.org/plugins/anypay-woocommerce/
 * Description: Give your customers the simplest Bitcoin checkout experience. Get paid in BTC, BCH, BSV, and DASH.
 * Author: Anypay Inc
 * Author URI: https://anypayinc.com 
 * Contributors: stevenzeiler, brandonbryant
 * Tags: bitcoin, payments, BSV, BCH, DASH, BTC, LTC, bitcoincash, litecoin, bitcoinsv, cryptocurrency
 * Requires PHP: 5.6
 * Requires at least: 4.0
 * Tested up to: 4.9.7
 * License: GPLv2 or later
 *License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_filter( 'woocommerce_payment_gateways', 'add_anypay_gateway_class' );

function add_anypay_gateway_class($methods) {
    $methods[] = 'WC_Gateway_Anypay';
    return $methods;
} 

global $anypay_db_version;

$anypay_db_version = '1.0.0';

function anypay_install()
{

    global $wpdb;
    global $anypay_db_version;
    
    $invoice_table = $wpdb->prefix . 'woocommerce_anypay_invoices';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql .= "CREATE TABLE $invoice_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id mediumint(9) NOT NULL,
        uid text NOT NULL,
        account_id mediumint(9) NOT NULL,
        amount float NOT NULL,
        currency text NOT NULL,
        status text NOT NULL,
        hash text,
        output_hash text,
        amount_paid float,
        denomination text NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    dbDelta($sql);

    add_option('anypay_db_version', $anypay_db_version);

}

register_activation_hook(__FILE__, 'anypay_install');

add_action( 'plugins_loaded', 'init_anypay_gateway_class' );

function init_anypay_gateway_class() {

  class WC_Gateway_Anypay extends WC_Payment_Gateway {

    public function __construct() {

      $this->id = 'wc_gateway_anypay';
      $this->has_fields = false;
      $this->description = 'Pay with Bitcoin via Anypay';
      $this->title = 'Anypay';
      $this->icon = 'https://media.bitcoinfiles.org/65602243db575e3c275d6f12daff4c35860c26176f44ca88ff9d271d8201e686.jpeg';
      $this->order_button_text = __('Pay with Anypay', 'woocommerce');
      $this->method_title = 'Anypay';
      $this->method_description = sprintf( 'The simplest way to earn Bitcoin in your business.' );;
      $this->supports = array('products');

     	// Load the settings.
	  $this->init_form_fields();
      $this->init_settings();

      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_anypay_settings' ) );
      add_action('woocommerce_api_'.strtolower(get_class($this)), array(&$this, 'handle_callback'));


    }


    function admin_options() {

     ?>
       <h2><?php _e('Anypay Payment Gateway Settings','woocommerce'); ?></h2>
       <table class="form-table">
       <?php $this->generate_settings_html(); ?>
       </table> <?php

    }


    public function handle_callback() {

      $raw_post = file_get_contents( 'php://input' );

	  $decoded  = json_decode( $raw_post );

      $safe_status = sanitize_text_field($decoded->status);

      $safe_uid = sanitize_text_field($decoded->uid);

      if( strlen($safe_uid) > 36 ){
        $safe_uid = substr( $safe_uid, 0 , 36);
      }

      $safe_hash = sanitize_text_field($decoded->hash);

      if(strlen($safe_hash) > 64){
        $safe_hash = substr($safe_hash, 0, 64);
      }

      $safe_invoice_amount_paid = floatval($decoded->invoice_amount_paid);

      $safe_output_hash = sanitize_text_field($decoded->output_hash);

      if(strlen($safe_output_hash) > 64){
        $safe_output_hash = substr($safe_output_hash, 0, 64);
      }

      $safe_external_id = intval($decoded->external_id);

      if($decoded->status === 'paid' || $decoded->status === 'overpaid'){

        $order = wc_get_order( $safe_external_id  );

        $order->payment_complete($safe_uid);

      }

      $this->anypay_updateInvoice($safe_uid, $safe_hash, $safe_status, $safe_invoice_amount_paid, $safe_output_hash);

      exit(); 

	}

     /**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 */
     function save_anypay_settings() {

      $my_string = 'Save Settings';
      do_action( 'php_console_log', $my_string );


      $anypay_settings = $this->get_option('woocommerce_anypay_settings');

      if( !$anypay_settings ){

        $anypay_settings = array();

      }

      $email = sanitize_email($_POST['woocommerce_wc_gateway_anypay_email']);
      echo '<script>console.log("Email: ' . $email . '")</script>';

      $url = 'https://api.anypayinc.com/accounts/' . $email ;

      $result = wp_remote_get( $url );

      if( $result['response']['code'] === 200 ){
        echo '<script>console.log("' . $url . '")</script>';

        $body = wp_remote_retrieve_body( $result );

        echo '<script>console.log("BODY:")</script>';
        echo '<script>console.log("' . $body . '")</script>';

        $account = json_decode($body);

        echo '<script>console.log("' . $account->id . '")</script>';

        echo '<script>console.log("ANYPAY ACCOUNT CODE' .
        $result['response']['code'] . '")</script>';

        echo '<script>console.log("ANYPAY EMAIL' . $email . '")</script>';
        $anypay_settings['email'] = $body->email;
        echo '<script>console.log("ANYPAY ID: ' . $account->id . '")</script>';
        $anypay_settings['account_id'] = $account->id;
        $anypay_settings['coins'] = $body->coins;

        $this->update_option('woocommerce_anypay_settings', $anypay_settings);
        echo '<script>console.log("Settings: ' . $anypay_settings . '")</script>';

      } else if( $result['response']['code'] === 404 ){
        // not found
        echo '<script>console.log("ANYPAY ACCOUNT NOT FOUND")</script>';
        //$error_message = 'Anypay Account Not Found!'
        echo '<script>alert("ANYPAY ACCOUNT NOT FOUND")</script>';
        $anypay_settings['email'] = null;

        unset($_POST);

      } else {

        echo '<script>console.log("ANYPAY ERROR")</script>';

      }

      //$this->update_option('woocommerce_anypay_settings', $anypay_settings);

    }

    /**
	 * Initialise Gateway Settings Form Fields.
     */
	function init_form_fields() {

     $this->form_fields = array(
        'email' => array(
                    'title' => 'Anypay Account Email',
                    'type' => 'text',
                    'description' => 'All payments will be sent to the coin addresses you set on your Anypay account. No
                    account? <a target="_blank" href="https://anypayapp.com/registration">Register for free</a>',
                    'default' => '' 
        ),
	  );
     }

     public function payment_fields() {

       $anypay_settings = $this->get_option('woocommerce_anypay_settings');

            try {

                $coins = $anypay_settings['coins'];

                echo '<script>console.log("Coins: ' . $anypay_settings['coins'] . '")</script>';

                //echo '<p class="pwe-eth-pricing-note"><strong>';

                //printf( __( 'Payment available in %s', implode(', ', $coins) ), $response );

                //echo '</p></strong>';

            } catch ( \Exception $e ) {

            echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';

            echo '<ul class="woocommerce-error">';

            echo '<li>';

            _e(
                'Unable to provide an order value in BSV at this time.',
                'anypay'
            );

            echo '</li>';

            echo '</ul>';

            echo '</div>';

      }
            
    }

    public function anypay_coins($email){

       echo '<script>console.log("Email: ' . $email . '")</script>';

       $response = wp_remote_get("https://api.anypayinc.com/accounts/{$email}"); 
          
       $coins =  json_decode($response['body'])->coins;

       return  $coins;

    }

    function process_payment( $order_id ) {

       global $woocommerce;

       $anypay_settings = $this->get_option('woocommerce_anypay_settings');

       $order = new WC_Order( $order_id );

      echo '<script>console.log("Order: ' . $order_id . '")</script>';

       $args = array(
         'body' => array(
           'amount' =>(float) $order->get_total(),
           //'currency' => 'BSV',
           'redirect_url' => $this->get_return_url( $order ),
           'webhook_url' => home_url('/?wc-api=wc_gateway_anypay'), 
           'external_id' => $order->get_id(),
         ),
         'headers' => array(
            'Authorization' => 'Basic ' . base64_encode( 'a:b')
          )
       );
       echo '<script>console.log("json: ' . json_encode($args) . '")</script>';


       $url = 'https://api.anypayinc.com/accounts/' . $anypay_settings['account_id'] . '/invoices';

       echo '<script>console.log("URL: ' . $url . '")</script>';
       echo '<script>console.log("ACcount: ' . $anypay_settings['account_id'] . '")</script>';

       $result = wp_remote_post( $url, $args );

       echo '<script>console.log("result: ' . $result . '")</script>';

       if( $result['response']['code'] === 200 ){

          // Mark as on-hold (we're awaiting the bitcoin payment 
         $order->update_status('pending-payment', __( 'Awaiting bitcoin payment', 'woocommerce' ));

          // Remove cart
         $woocommerce->cart->empty_cart();

         $invoice = json_decode($result['body']);

         $safe_status = sanitize_text_field($invoice->status);

         $safe_uid = sanitize_text_field($invoice->uid);

         if( strlen($safe_uid) > 36 ){
           $safe_uid = substr( $safe_uid, 0 , 36);
         }

         $safe_account_id = intval($invoice->account_id);

         $safe_amount = floatval($invocie->amount);

         $safe_currency = sanitize_text_field($invoice->currency);

         $safe_denomination_currency = sanitize_text_field($invoice->denomination_currency);

         $this->anypay_createInvoice( $order->get_id(), $safe_uid, $safe_account_id, $safe_amount, $safe_currency, $safe_status, $safe_denomination_currency);

         return array(
          'result'   => 'success',
          'redirect' => 'https://anypayapp.com/invoices/' . $safe_uid
         );

        }

    }


    public function anypay_createInvoice($order_id, $uid, $account_id, $amount, $currency, $status, $denomination){
   
     global $wpdb;
      
     $invoice_table = $wpdb->prefix . 'woocommerce_anypay_invoices';

     if(!is_null($order_id)){

       $order_id = esc_sql($order_id);

     }

     if( !is_null($uid) ){

       $uid = esc_sql($uid);

     }

     if( !is_null($account_id) ){

       $account_id = esc_sql($account_id);

     }

     if( !is_null($amount) ){

       $amount = esc_sql($amount);

     }

     if(!is_null($currency) ){

       $currency = esc_sql($currency);

     }

     if(!is_null($status) ){

       $status = esc_sql($status);

     }

     if(!is_null($denomination)){

       $denomination = esc_sql($denomination);

     }

     return $wpdb->insert($invoice_table, array(
                'uid' => $uid,
                'account_id' => $account_id,
                'status' => $status,
                'order_id' => $order_id,
                'amount' => $amount,
                'denomination' => $denomination,
                'currency' => $currency
            ));

    }

    public function anypay_updateInvoice($where_uid, $hash, $status, $amount_paid, $output_hash){

     global $wpdb;

     $invoices_table = $wpdb->prefix . 'woocommerce_anypay_invoices';
     if (!is_null($hash)) {
       $hash = esc_sql($hash);
     }

     if (!is_null($where_uid)) {
       $where_uid = esc_sql($where_uid);
     }

     if( !is_null($status)){
       $status = esc_sql($status);

     }

     if( !is_null($amount_paid)){
       $amount_paid = esc_sql($amount_paid);
     }

     if( !is_null($output_hash)){
       $output_hash = esc_sql($output_hash);
     }

     $update_query = array(
             'hash' => $hash,
             'status' => $status,
             'amount_paid' => $amount_paid,
             'output_hash' => $output_hash
     );

     $where = array(
             'uid' => $where_uid
     );

     return $wpdb->update($invoices_table, $update_query, $where);

   }

  }

}
