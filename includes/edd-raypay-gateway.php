<?php

if (!class_exists('EDD_RayPay_Gateway')) exit;

new EDD_RayPay_Gateway;

class EDD_RayPay_Gateway
{
  /**
   * @var string
   */
  public $keyname;

  /**
   * EDD_RayPay_Gateway constructor.
   */
  public function __construct()
  {
    $this->keyname = 'raypay';
    $this->payment_endpoint = 'https://api.raypay.ir/raypay/api/v1/Payment/getPaymentTokenWithUserID';
    $this->verify_endpoint = 'https://api.raypay.ir/raypay/api/v1/Payment/checkInvoice';
    add_filter('edd_payment_gateways', array($this, 'add'));
    add_action($this->format('edd_{key}_cc_form'), array($this, 'cc_form'));
    add_action($this->format('edd_gateway_{key}'), array($this, 'process'));
    add_action($this->format('edd_verify_{key}'), array($this, 'verify'));
    add_filter('edd_settings_gateways', array($this, 'settings'));
    add_action('init', array($this, 'listen'));
  }

  /**
   * @param $gateways
   * @return mixed
   */
  public function add($gateways)
  {
    if ( ! isset( $_SESSION ) ) {
      session_start();
    }
    $gateways[$this->keyname] = array(
      'admin_label' => __('RayPay', 'edd-raypay-gateway'),
      'checkout_label' => __('RayPay payment gateway', 'edd-raypay-gateway'),
    );

    return $gateways;
  }

  /**
   *
   */
  public function cc_form()
  {
    return;
  }

  /**
   * @param $purchase_data
   * @return bool
   */
  public function process($purchase_data)
  {
    global $edd_options;
    //create payment
    $payment_id = $this->insert_payment($purchase_data);
    if ($payment_id) {
      $user_id = empty($edd_options['raypay_user_id']) ? '' : $edd_options['raypay_user_id'];
      $acceptor_code = empty($edd_options['raypay_acceptor_code']) ? '' : $edd_options['raypay_acceptor_code'];
      $invoice_id = round(microtime(true)*1000) ;
      $customer_name = $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'];
      $callback = add_query_arg(array('verify_' . $this->keyname => '1', 'order_id' => $payment_id), get_permalink($edd_options['success_page']));
      $callback .= "&";
      $email = $purchase_data['user_info']['email'];
      $amount = $this->raypay_edd_get_amount(intval($purchase_data['price']), edd_get_currency());

      if (empty($amount)) {
        $message = __('Selected currency is not supported.', 'edd-raypay-gateway');
        edd_insert_payment_note($payment_id, $message);
        edd_update_payment_status($payment_id, 'failed');
        edd_set_error('raypay_connect_error', $message);
        edd_send_back_to_checkout();

        return FALSE;
      }

        $data = array(
            'amount' => strval($amount),
            'invoiceID' => strval($invoice_id),
            'userID' => $user_id,
            'redirectUrl' => $callback,
            'factorNumber' => strval($payment_id),
            'acceptorCode' => $acceptor_code,
            'email' => $email,
            'fullName' => $customer_name,
        );

        $headers = array(
            'Content-Type' => 'application/json',
        );

        $args = array(
            'body' => json_encode($data),
            'headers' => $headers,
            'timeout' => 15,
        );


        $response = $this->raypay_edd_call_gateway_endpoint($this->payment_endpoint, $args);
      if (is_wp_error($response)) {
        $note = $response->get_error_message();
        edd_insert_payment_note($payment_id, $note);

        return FALSE;
      }

      $http_status = wp_remote_retrieve_response_code($response);
      $result = wp_remote_retrieve_body($response);
      $result = json_decode($result);

      if ($http_status != 200 || empty($result) || empty($result->Data)) {
          $note = '';
          $note .= __('An error occurred while creating the transaction.', 'edd-raypay-gateway');
          if (!empty($result->Message)) {
              edd_insert_payment_note($payment_id, $http_status . ' - ' . $result->Message);
          }
        edd_update_payment_status($payment_id, 'failed');
        edd_set_error('raypay_connect_error', $result->Message);
        edd_send_back_to_checkout();

        return FALSE;
      }

        $access_token = $result->Data->Accesstoken;
        $terminal_id = $result->Data->TerminalID;

      // Saves accessToken and TerminalID

      edd_insert_payment_note($payment_id, __('Redirecting to the payment gateway.', 'edd-raypay-gateway'));

      edd_update_payment_meta($payment_id, '_raypay_edd_access_token', $access_token);
      edd_update_payment_meta($payment_id, '_raypay_edd_terminal_id', $terminal_id);
      edd_update_payment_meta($payment_id, '_raypay_edd_invoice_id', $invoice_id);

        // Set remote status of the transaction to 1 as it's primary value.
        edd_update_payment_meta($payment_id, '_raypay_edd_transaction_status',1);

        $this->edd_raypay_send_data_shaparak($access_token , $terminal_id);
        return FALSE;


    } else {
      $message = __("An error occurred.", 'edd-raypay-gateway');
      edd_set_error('raypay_connect_error', $message);
      edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }

  }

  /**
   * Verify the payment
   * @return bool
   */
  public function verify()
  {
      global $edd_options;
      $order_id = sanitize_text_field($_GET['order_id']);

    if ( empty($order_id)) {
      wp_die(__('The information sent is not correct.', 'edd-raypay-gateway'));
      return FALSE;
    }

    $payment = edd_get_payment($order_id);
    if (!$payment) {
      wp_die(__('The information sent is not correct.', 'edd-raypay-gateway'));
      return FALSE;
    }

    if ($payment->status != 'pending') {
      edd_send_back_to_checkout();
      return FALSE;
    }

      $invoice_id = edd_get_payment_meta($order_id, '_raypay_edd_invoice_id', TRUE);
      $verify_url = add_query_arg('pInvoiceID', $invoice_id, $this->verify_endpoint);


      $data = array(
          'order_id' => $order_id,
      );

      $headers = array(
          'Content-Type' => 'application/json',
      );

      $args = array(
          'body' => json_encode($data),
          'headers' => $headers,
          'timeout' => 15,
      );

      $response = $this->raypay_edd_call_gateway_endpoint($verify_url, $args);

      if (is_wp_error($response)) {
        $note = $response->get_error_message();
        edd_insert_payment_note($payment->ID, $note);
        edd_send_back_to_checkout();

        return FALSE;
      }
      $http_status = wp_remote_retrieve_response_code($response);
      $result = wp_remote_retrieve_body($response);
      $result = json_decode($result);

      if ($http_status != 200) {
          $note = '';
          $note .= __('An error occurred while verifying the transaction.', 'edd-raypay-gateway');


          if (!empty($result->Message)) {
              edd_insert_payment_note($payment->ID, $http_status . ' - ' . $result->Message);
              edd_set_error('raypay_connect_error', $result->Message);
          }

        edd_update_payment_status($payment->ID, 'failed');
        edd_send_back_to_checkout();
        return FALSE;
      }

      $state = $result->Data->State;
      $verify_order_id = $result->Data->FactorNumber;
      $verify_amount = $result->Data->Amount;

      if ($state === 1 ){
          $state_description= __('Payment has been verified.','edd-raypay-gateway');
      } else{
          $state_description= __('Payment has been unsuccessful.','edd-raypay-gateway');
      }

      update_post_meta($payment->ID, 'raypay_factor_number', $verify_order_id);
      update_post_meta($payment->ID, 'raypay_transaction_amount', $verify_amount);
      update_post_meta($payment->ID, 'raypay_factor_number', $verify_order_id);

      edd_insert_payment_note($payment->ID, __('RayPay Invoice ID:', 'edd-raypay-gateway') . $invoice_id);
      edd_insert_payment_note($payment->ID, __('RayPay Transaction State:', 'edd-raypay-gateway') . $state_description);
      edd_insert_payment_note($payment->ID, __('RayPay Transaction Amount:', 'edd-raypay-gateway') . $verify_amount);

      if ($state === 1) {
        $session = edd_get_purchase_session();
        if (!$session) {
          edd_set_purchase_session(['purchase_key' => urldecode($_GET['payment_key'])]);
          $session = edd_get_purchase_session();
        }

        edd_empty_cart();
        edd_update_payment_status($payment->ID, 'publish');
        edd_insert_payment_note($payment->ID, $state . ' - ' . $state_description);
        edd_send_to_success_page();
      } else {
        $message= __('Payment has been unsuccessful.','edd-raypay-gateway');
        edd_insert_payment_note($payment->ID, $message);
        edd_set_error('raypay_connect_error', $message);
        edd_update_payment_status($payment->ID, 'failed');
        edd_send_back_to_checkout();
        //wp_redirect( get_permalink($edd_options['failure_page']) );
        //exit;
        return FALSE;
      }
  }

  /**
   * Gateway settings
   *
   * @param array $settings
   * @return        array
   */
  public function settings($settings)
  {
    return array_merge($settings, array(
      $this->keyname . '_header' => array(
        'id' => $this->keyname . '_header',
        'type' => 'header',
        'name' => __('RayPay payment gateway', 'edd-raypay-gateway'),
      ),
        $this->keyname . '_user_id' => array(
            'id' => $this->keyname . '_user_id',
            'name' => __('User ID', 'edd-raypay-gateway'),
            'type' => 'text',
            'size' => 'regular',
            'desc' => __('You can receive your User ID by going to your RayPay panel', 'edd-raypay-gateway'),
            'default' => '20064',
        ),
        $this->keyname . '_acceptor_code' => array(
            'id' => $this->keyname . '_acceptor_code',
            'name' => __('Acceptor Code', 'edd-raypay-gateway'),
            'type' => 'text',
            'size' => 'regular',
            'desc' => __('You can receive your Acceptor Code by going to your RayPay panel', 'edd-raypay-gateway'),
            'default' => '220000000003751',
        ),
    ));
  }

  /**
   * Format a string, replaces {key} with $keyname
   *
   * @param string $string To format
   * @return      string Formatted
   */
  private function format($string)
  {
    return str_replace('{key}', $this->keyname, $string);
  }

  /**
   * Inserts a payment into database
   *
   * @param array $purchase_data
   * @return      int $payment_id
   */
  private function insert_payment($purchase_data)
  {
    global $edd_options;

    $payment_data = array(
      'price' => $purchase_data['price'],
      'date' => $purchase_data['date'],
      'user_email' => $purchase_data['user_email'],
      'purchase_key' => $purchase_data['purchase_key'],
      'currency' => $edd_options['currency'],
      'downloads' => $purchase_data['downloads'],
      'user_info' => $purchase_data['user_info'],
      'cart_details' => $purchase_data['cart_details'],
      'status' => 'pending'
    );

    // record the pending payment
    $payment = edd_insert_payment($payment_data);

    return $payment;
  }

  /**
   * Listen to incoming queries
   *
   * @return      void
   */
  public function listen()
  {
    if (isset($_GET['verify_' . $this->keyname]) && $_GET['verify_' . $this->keyname]) {
      do_action('edd_verify_' . $this->keyname);
    }
  }

  /**
   * @param $url
   * @param $args
   * @return array|WP_Error
   */
  public function raypay_edd_call_gateway_endpoint($url, $args)
  {
      $number_of_connection_tries = 2;
      while ($number_of_connection_tries) {
          $response = wp_remote_post($url, $args);
          if (is_wp_error($response)) {
              $number_of_connection_tries--;
              continue;
          } else {
              break;
          }
      }

      return $response;
  }

  /**
   * @param $amount
   * @param $currency
   * @return float|int
   */
  public function raypay_edd_get_amount($amount, $currency)
  {
    switch (strtolower($currency)) {
      case strtolower('IRR'):
      case strtolower('RIAL'):
        return $amount;

      case strtolower('تومان ایران'):
      case strtolower('تومان'):
      case strtolower('IRT'):
      case strtolower('Iranian_TOMAN'):
      case strtolower('Iran_TOMAN'):
      case strtolower('Iranian-TOMAN'):
      case strtolower('Iran-TOMAN'):
      case strtolower('TOMAN'):
      case strtolower('Iran TOMAN'):
      case strtolower('Iranian TOMAN'):
        return $amount * 10;

      case strtolower('IRHT'):
        return $amount * 10000;

      case strtolower('IRHR'):
        return $amount * 1000;

      default:
        return 0;
    }
  }

    public function edd_raypay_send_data_shaparak($access_token , $terminal_id){
        echo '<form name="frmRayPayPayment" method="post" action=" https://mabna.shaparak.ir:8080/Pay ">';
        echo '<input type="hidden" name="TerminalID" value="' . $terminal_id . '" />';
        echo '<input type="hidden" name="token" value="' . $access_token . '" />';
        echo '<input class="submit" type="submit" value="پرداخت" /></form>';
        echo '<script>document.frmRayPayPayment.submit();</script>';
    }




}
