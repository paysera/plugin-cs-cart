<?php

use Tygh\Http;
use Tygh\Registry;

require_once(dirname(__FILE__) . '/vendor/webtopay/libwebtopay/WebToPay.php');


if (!defined('BOOTSTRAP')) { die('Access denied'); }

//print_r($_GET); echo $mode;

if (defined('PAYMENT_NOTIFICATION')) {
    function fn_payment_end($order_id, $pp_response, $force_notification = array()) {
        $valid_id = db_get_field("SELECT order_id FROM ?:order_data WHERE order_id = ?i AND type = 'S'", $order_id);

        if (!empty($valid_id)) {
            db_query("DELETE FROM ?:order_data WHERE order_id = ?i AND type = 'S'", $order_id);

            fn_update_order_payment_info($order_id, $pp_response);

            if ($pp_response['order_status'] == 'N' && !empty($_SESSION['cart']['placement_action']) && $_SESSION['cart']['placement_action'] == 'repay') {
                $pp_response['order_status'] = 'I';
            }
            fn_set_hook('finish_payment', $order_id, $pp_response, $force_notification);
        }
        fn_change_order_status($order_id, $pp_response['order_status'], '', $force_notification);
    }
//echo $mode; die;
    if ($mode == 'success') { print_r($response);
        if (fn_check_payment_script('paysera.php', $_REQUEST['order_id'])) {
            $order_info = fn_get_order_info($_REQUEST['order_id'], true);
            if ($order_info['status'] == 'N') {
                fn_change_order_status($_REQUEST['order_id'], 'O', '', false);
            }
        }
        fn_order_placement_routines('route', $_REQUEST['order_id']);
        exit;
    } 
    elseif ($mode == 'notify') {
        $order_info = fn_get_order_info($_REQUEST['order_id']);
        if (empty($processor_data)) {
            $processor_data = fn_get_processor_data($order_info['payment_id']);
        }
        if (empty($order_info)) {
            throw new Exception(sprintf("Missing order by specified id (order_id=%s)", $response['order_id']));
        }

        try { 
            $response = WebToPay::checkResponse($_REQUEST, array(
                'projectid'     => $processor_data['processor_params']['project_id'],
                'sign_password' => $processor_data['processor_params']['sign'],

            ));

            if ($response['status'] == 1) {
                if ($response['currency'] != $order_info['secondary_currency']) {
                    throw new Exception('The currency does not match.');
                }

                if ($response['amount'] < intval(number_format($order_info['total'], 2, '', ''))) {
                    throw new Exception('The amounts do not match.');
                }
                $response = $response + array('order_status' => 'O');

                if ($response['order_status'] == 'O') {
                    $response['order_status'] = 'P';
                    fn_payment_end($response['orderid'], $response);
                } else {
                    fn_change_order_status($response['orderid'], 'P');
                }
            }

            exit("OK");
        } catch (Exception $e) {
            exit(sprintf("ERROR: %s", $e->getMessage()));
        }
    } 
    elseif ($mode == 'failed') { //echo 'ok'; die;
        //fn_payment_end($_REQUEST['order_id'], $response);
        fn_order_placement_routines('route', $_REQUEST['order_id']);
        exit;
    }
    exit;
} else {
//print_r($order_info);
$pid = $order_info['payment_method']['processor_params']['project_id'];
$psign = $order_info['payment_method']['processor_params']['sign'];
$ptest = $order_info['payment_method']['processor_params']['test'];
$pcurr = $order_info['payment_method']['processor_params']['currency'];
    $_order_id = ($order_info['repaid']) ? ($order_id . '_' . $order_info['repaid']) : $order_id;

    //$s_id = Session::get_id();

    $order_info['b_country'] = strtolower($order_info['b_country']);

    $language = strtoupper($_SESSION['settings']['cart_languageC']['value']);
    $currency = strtoupper($_SESSION['settings']['secondary_currencyC']['value']);
//echo $currency;
    $currencyCo = db_get_field("SELECT ?:currencies.coefficient FROM ?:currencies WHERE ?:currencies.currency_code = ?s", $currency);
    $price      = intval(number_format($order_info['total'] / $currencyCo, 2, '', ''));

    $url      = (strpos($_SERVER['SERVER_PROTOCOL'], 'HTTPS') !== false ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $url      = strtr($url, array('index.php' => '/payments/paysera.php'));
    $urlIndex = strtr($url, array('/payments/paysera.php' => 'index.php'));

    //$psData = fn_get_payment_method_data($_SESSION['cart']['payment_id']);

    try {
        $payment_info = array(
            'projectid'     => $pid,
            'sign_password' => $psign,
            'orderid'       => $_order_id,
            'lang'          => ($language === 'LT') ? 'LIT' : 'ENG',
            'amount'        => $price,
            'currency'      => $currency,
            'accepturl'     => fn_url("payment_notification.success?payment=paysera&order_id=$order_id", AREA, 'current'),
            'cancelurl'     => fn_url("payment_notification.failed?payment=paysera&order_id=$order_id", AREA, 'current'),
            'callbackurl'   => fn_url("payment_notification.notify?payment=paysera&order_id=$order_id", AREA, 'current'),
            'payment'       => '',
            'country'       => $order_info['b_country'],
            'logo'          => '',

            'p_firstname'   => $order_info['b_firstname'],
            'p_lastname'    => $order_info['b_lastname'],
            'p_email'       => $order_info['email'],
            'p_street'      => $order_info['b_address'],
            'p_city'        => $order_info['b_city'],
            'p_state'       => $order_info['b_state'],
            'p_zip'         => $order_info['b_zipcode'],
            'p_countrycode' => $order_info['b_country'],

            'test'          => $ptest,
        ); ///print_r($payment_info); //die;
  WebToPay::redirectToPayment($payment_info);
      
    } catch (WebToPayException $e) {
        exit(get_class($e) . ': ' . $e->getMessage());
    } die;
    fn_start_payment($_order_id, false, $payment_info);
}