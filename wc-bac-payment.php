<?php
/* Authorize.net AIM Payment Gateway Class */
class Bac_Payment_Gateway extends WC_Payment_Gateway {
  // Setup our Gateway's id, description and other values
  function __construct() {
    // The global ID for this Payment method
    $this->id = "bac_payment";

    // The Title shown on the top of the Payment Gateways Page next to all the other Payment Gateways
    $this->method_title = __( "BAC PAYMENT GATEWAY", 'bac-payment' );

    // The description for this Payment Gateway, shown on the actual Payment options page on the backend
    $this->method_description = __( "BAC Payment Gateway Plug-in for WooCommerce", 'bac-payment' );

    // The title to be used for the vertical tabs that can be ordered top to bottom
    $this->title = __( "BAC Payment Gateway", 'bac-payment' );

    // If you want to show an image next to the gateway's name on the frontend, enter a URL to an image.
    $this->icon = null;

    // Bool. Can be set to true if you want payment fields to show on the checkout 
    // if doing a direct integration, which we are doing in this case
    $this->has_fields = true;

    // Supports the default credit card form
    $this->supports = array( 'default_credit_card_form' );

    // This basically defines your settings which are then loaded with init_settings()
    $this->init_form_fields();

    // After init_settings() is called, you can get the settings and load them into variables, e.g:
    // $this->title = $this->get_option( 'title' );
    $this->init_settings();
    
    // Turn these settings into variables we can use
    foreach ( $this->settings as $setting_key => $value ) {
      $this->$setting_key = $value;
    }
    
    // Lets check for SSL
    add_action( 'admin_notices', array( $this,  'do_ssl_check' ) );
    
    // Save settings
    if ( is_admin() ) {
      // Versions over 2.0
      // Save our administration options. Since we are not going to be doing anything special
      // we have not defined 'process_admin_options' in this class so the method in the parent
      // class will be used instead
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }   
  } // End __construct()


  // Build the administration fields for this specific Gateway
  public function init_form_fields() {
    $this->form_fields = array(
      'enabled' => array(
        'title'   => __( 'Activar / Desactivar', 'bac-payment' ),
        'label'   => __( 'Activar este metodo de pago', 'bac-payment' ),
        'type'    => 'checkbox',
        'default' => 'no',
      ),
      'title' => array(
        'title'   => __( 'Título', 'bac-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'Título de pago que el cliente verá durante el proceso de pago.', 'bac-payment' ),
        'default' => __( 'Tarjeta de crédito', 'bac-payment' ),
      ),
      'description' => array(
        'title'   => __( 'Descripción', 'bac-payment' ),
        'type'    => 'textarea',
        'desc_tip'  => __( 'Descripción de pago que el cliente verá durante el proceso de pago.', 'bac-payment' ),
        'default' => __( 'Pague con seguridad usando su tarjeta de crédito.', 'bac-payment' ),
        'css'   => 'max-width:350px;'
      ),
      'key_id' => array(
        'title'   => __( 'Key id', 'bac-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'ID de clave de seguridad del panel de control del comerciante.', 'bac-payment' ),
        'default' => '',
      ),
      'api_key' => array(
        'title'   => __( 'Api key', 'bac-payment' ),
        'type'    => 'text',
        'desc_tip'  => __( 'ID de clave de api del panel de control del comerciante.', 'bac-payment' ),
        'default' => '',
      ),
    );    
  }

  // Submit payment and handle response
  public function process_payment( $order_id ) {
    global $woocommerce;
    
    // Get this Order's information so that we know
    // who to charge and how much
    $customer_order = new WC_Order( $order_id );

    $environment_url = 'https://credomatic.compassmerchantsolutions.com/api/transact.php';
    
    $time = time();

    $key_id = $this->key_id;


    if(count($pagos) == 0){
      $orderid = 1000;
    }else{
      $orderid = 1000 + count($pagos);
    }

    $hash = md5($orderid."|".$customer_order->order_total."|".$time."|".$this->api_key);

    // This is where the fun stuff begins
    $payload = array(
      "key_id"  => $key_id,
      "hash" => $hash,
      "time" => $time,
      "amount" => $customer_order->order_total,
      "ccnumber" => str_replace( array(' ', '-' ), '', $_POST['bac_payment-card-number'] ),
      "ccexp" => str_replace( array( '/', ' '), '', $_POST['bac_payment-card-expiry'] ),
      "orderid" => $orderid,
      "cvv" => ( isset( $_POST['bac_payment-card-cvc'] ) ) ? $_POST['bac_payment-card-cvc'] : '',
      "type" => "auth",
     );

    // Send this payload to Authorize.net for processing
    $response = wp_remote_post( $environment_url, array(
      'method'    => 'POST',
      'body'      => http_build_query( $payload ),
      'timeout'   => 90,
      'sslverify' => false,
    ) );


    if ( is_wp_error( $response ) ) 
      throw new Exception( __( 'We are currently experiencing problems trying to connect to this payment gateway. Sorry for the inconvenience.'.$payload, 'bac-payment' ) );

    if ( empty( $response['body'] ) )
      throw new Exception( __( 'BAC\'s Response was empty.', 'bac-payment' ) );
      
    // Retrieve the body's resopnse if no errors found
    $response_body = wp_remote_retrieve_body( $response );

    // Parse the response into something we can read
    $resp = explode( "&", $response_body );
    $resp = array_map(function($r)
      {
        $r2 =  explode("=", $r);
        return [$r2[0] =>$r2[1]];
      }, $resp);

    $membership_code = $_POST['checkout_membership'];
    $membership_code_exist = $this->validateMembershipCode($membership_code);

    // Test the code to know if the transaction went through or not.
    // 1 or 4 means the transaction was a success
    if ( ($resp[0]['response'] == 1 ) || ( $resp[0]['response_code'] == 100 ) ) {
      // Payment has been successful
      $customer_order->add_order_note( __( 'BAC payment completed.', 'bac-payment' ) );
                         
      // Mark order as Paid
      $customer_order->payment_complete();

      // Empty the cart (Very important step)
      $woocommerce->cart->empty_cart();

      //add amount to membership card

      if(count($membership_code_exist) >= 1){
        $amount = $customer_order->order_total;
        $this->addShippingToMembership($amount,$membership_code,$order_id);
      }

      // Redirect to thank you page
      return array(
        'result'   => 'success',
        'redirect' => $this->get_return_url( $customer_order ),
      );
    } else {
      // Transaction was not succesful
      // Add notice to the cart
      wc_add_notice( $resp[0]['responsetext'], 'error' );
      // Add note to the order for your reference
      $customer_order->add_order_note( 'Error: '. $resp[0]['responsetext'] );
    }

    //Validate fields
   

  }//end process payment

  public function addShippingToMembership($amount,$user_code,$order_id){
    $api_url = "http://toolboxsv.com/dev/drinkit_api_membership/index.php/membership";
    $headers = array( 'key' => "k2hB649dAB",
                      'token' => "797AADE7D1C7F9576D325EC32A241",
                      'mode' => "live",
                      'Content-Type' => "application/json");

    $body_request = array(  'code' => $user_code,
                            'amount' => $amount,
                            'order_id' => $order_id);

    $body_request_json = json_encode($body_request);

    $response = wp_remote_post( $api_url, array(
      'method'    => 'POST',
      'headers' => $headers,
      'body'      => $body_request_json,
      'timeout'   => 90,
      'sslverify' => false,
    ) );


    $response_body = wp_remote_retrieve_body( $response );
    // print_r($response_body);
  }

  public function validateMembershipCode($code){
    $api_url = "http://toolboxsv.com/dev/drinkit_api_membership/index.php/users?filter[]=code,eq,".$code;
    $headers = array( 'key' => "k2hB649dAB",
                      'token' => "797AADE7D1C7F9576D325EC32A241",
                      'mode' => "live",
                      'Content-Type' => "application/json");

    $args = array(
      'timeout'     => 90,
      'redirection' => 5,
      'httpversion' => '1.0',
      'blocking'    => true,
      'headers'     => $headers,
      'body'        => null,
      'sslverify'   => false,
    ); 

    $response = wp_remote_get( $api_url, $args );

    $body = $response['body']; // use the content

    $character = json_decode($body);

    return $character->users->records;

  }

  public function validate_fields() {
    return true;
  }
  
  // Check if we are forcing SSL on checkout pages
  // Custom function not required by the Gateway
  public function do_ssl_check() {
    if( $this->enabled == "yes" ) {
      if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
        echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";  
      }
    }   
  }

}

?>