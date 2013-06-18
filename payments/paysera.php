<?php

require_once(dirname(__FILE__) . '/vendor/webtopay/libwebtopay/WebToPay.php');

if (!defined('AREA')) {
    die('Access denied');
}

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

    if ($mode == 'return') {
        if (fn_check_payment_script('paysera.php', $_REQUEST['orderId'])) {
            $order_info = fn_get_order_info($_REQUEST['orderId'], true);
            if ($order_info['status'] == 'N') {
                fn_change_order_status($_REQUEST['orderId'], 'O', '', false);
            }
        }
        fn_order_placement_routines($_REQUEST['orderId'], false);
    } elseif ($mode == 'callback') {
        $order_info = fn_get_order_info($_REQUEST['orderId']);
        if (empty($processor_data)) {
            $processor_data = fn_get_processor_data($order_info['payment_id']);
        }
        if (empty($order_info)) {
            throw new Exception(sprintf("Missing order by specified id (order_id=%s)", $response['orderid']));
        }

        try {
            $response = WebToPay::checkResponse($_REQUEST, array(
                'projectid'     => $processor_data['params']['project_id'],
                'sign_password' => $processor_data['params']['sign'],

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
    } elseif ($mode == 'cancel') {
        fn_payment_end($_REQUEST['orderId'], $response);
        fn_order_placement_routines($_REQUEST['orderId']);
        exit;
    }
    exit;
} else {

    $_order_id = ($order_info['repaid']) ? ($order_id . '_' . $order_info['repaid']) : $order_id;

    $s_id = Session::get_id();

    $order_info['b_country'] = strtolower($order_info['b_country']);

    $language = strtoupper($_SESSION['settings']['cart_languageC']['value']);
    $currency = strtoupper($_SESSION['settings']['secondary_currencyC']['value']);

    $currencyCo = db_get_field("SELECT ?:currencies.coefficient FROM ?:currencies WHERE ?:currencies.currency_code = ?s", $currency);
    $price      = intval(number_format($order_info['total'] / $currencyCo, 2, '', ''));

    $url      = (strpos($_SERVER['SERVER_PROTOCOL'], 'HTTPS') !== false ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $url      = strtr($url, array('index.php' => '/payments/paysera.php'));
    $urlIndex = strtr($url, array('/payments/paysera.php' => 'index.php'));

    $psData = fn_get_payment_method_data($_SESSION['cart']['payment_id']);

    try {
        $payment_info = array(
            'projectid'     => $psData['params']['project_id'],
            'sign_password' => $psData['params']['sign'],
            'orderid'       => $_order_id,
            'lang'          => ($language === 'LT') ? 'LIT' : 'ENG',
            'amount'        => $price,
            'currency'      => $currency,
            'accepturl'     => "{$urlIndex}?dispatch=payment_notification.return&payment=paysera&orderId={$_order_id}",
            'cancelurl'     => "{$urlIndex}?dispatch=payment_notification.cancel&payment=paysera&orderId={$_order_id}",
            'callbackurl'   => "{$urlIndex}?dispatch=payment_notification.callback&payment=paysera&orderId={$_order_id}",
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

            'test'          => $psData['params']['test'],
        );

        WebToPay::redirectToPayment($payment_info);
    } catch (WebToPayException $e) {
        exit(get_class($e) . ': ' . $e->getMessage());
    }
    fn_start_payment($_order_id, false, $payment_info);
}