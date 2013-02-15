<?php

require_once(dirname(__FILE__).'/libwebtopay/WebToPay.php');

if ( !defined('AREA') ) { die('Access denied'); }

if (defined('PAYMENT_NOTIFICATION')) {
	//callback	
	//echo '<pre>';
	////print_r($mode);
	//echo '</pre>';
	
	
	//return after order
	if ($mode == 'return') {
		if (fn_check_payment_script('webtopay.php', $_REQUEST['orderId'])) {
			$order_info = fn_get_order_info($_REQUEST['orderId'], true);
			if ($order_info['status'] == 'N') {
				fn_change_order_status($_REQUEST['orderId'], 'O', '', false);
			}
		}
		fn_order_placement_routines($_REQUEST['orderId'], false);
		//return after payment
	} elseif ($mode == 'callback') {
		
		 $order_info = fn_get_order_info($_REQUEST['orderId']);
			 if (empty($processor_data)) {
				 $processor_data = fn_get_processor_data($order_info['payment_id']);
			 }
			 	if (empty($order_info)) {
				throw new Exception(sprintf("Missing order by specified id (order_id=%s)", $response['orderid']));
			}
			// print_r($processor_data);
		// $amount = $order_info['total'];
		// print_r($order_info);
		
		try {
			$response = WebToPay::checkResponse($_REQUEST, array(
		        'projectid'     => $processor_data['params']['project_id'], //32604
		        'sign_password' => $processor_data['params']['sign'], //241cb0094ff3474abbe6a91cbb735ce0
		        
			));
			
			 //print_r($response);
			
			// $order_info = fn_get_order_info($response['orderid']);
			// if (empty($order_info)) {
				// throw new Exception(sprintf("Missing order by specified id (order_id=%s)", $response['orderid']));
			// }
				// if (empty($processor_data)) {
				// $processor_data = fn_get_processor_data($order_info['payment_id']);
			// }
			
			//print_r($processor_data);
			// print_r($response);
			 //print_r($order_info);
			
			// 1. ar status = 1
			// 2. ar sumokejo tiek kiek reikia
			// 3. pazymi uzsakyma kaip apmoketa
			
			// if ($processor_data['test'] == 'N' && $response['test'] == '1') {
		        // throw new Exception('Test payments are not allowed');
		    // }
		      if ($response['status'] = 1) {
		     	 //$response + array('order_status' => 'O')
		     	// fn_change_order_status($response['orderid'], 'P', 'O');
				
			 
				    if ($response['type'] != 'macro') {
				        //throw new Exception('Only macro payment callbacks are accepted');
				    }
					if ($response['currency'] != $order_info['secondary_currency']){
						throw new Exception('The currency does not match.');
					}
					if ($response['amount'] < $order_info['total']){
						throw new Exception('The amounts do not match.');
					}
					$response = $response + array('order_status' => 'O');
					print_r($response);
					 if($response['order_status'] == 'O'){
					 	$response['order_status'] = 'P';
						// print_r($response['order_status']);
					 	fn_finish_payment($response['orderid'], $response);
					 }else{
					fn_change_order_status($response['orderid'], 'P');
					// fn_finish_payment($response['orderid'], $response);
					 }
			 }	
			// $pp_response['order_status'] = $paypal_statuses['completed'];
			// $pp_response['reason_text'] = '';
			// $pp_response['transaction_id'] = @$_REQUEST['txn_id'];
			
			
			exit("OK");
		} catch (Exception $e) {
			exit(sprintf("ERROR: %s", $e->getMessage()));
		}
	} elseif ($mode == 'cancel') {
		
		// if (fn_check_payment_script('webtopay.php', $_REQUEST['orderId'])) {
			fn_finish_payment($_REQUEST['orderId'], $response); //array('order_status' => 'O')
			fn_order_placement_routines($_REQUEST['orderId']);
			exit;
			// $order_info = fn_get_order_info($_REQUEST['orderId']);
		// if ($order_info['status'] == 'O') {
				// //fn_change_order_status($_REQUEST['orderId'], 'I', '', false);
				// //db_query('DELETE FROM ?:orders WHERE order_id = ?i',  $_REQUEST['orderId']);
				// //fn_delete_order($_REQUEST['orderId']);
		// }
			// fn_finish_payment($_REQUEST['orderId'], $response, false);
			// header("Location: {$urlIndex}?dispatch=checkout.checkout");
	// }
}
	exit;
	//die('callback');
	
} else {
	
	$_order_id = ($order_info['repaid']) ? ($order_id . '_' . $order_info['repaid']) : $order_id;

    $s_id = Session::get_id();

    $order_info['b_country'] = strtolower($order_info['b_country']);

    $language	= strtoupper($_SESSION['settings']['cart_languageC']['value']);
    $currency	= strtoupper($_SESSION['settings']['secondary_currencyC']['value']);
	
    $currencyCo	= db_get_field("SELECT ?:currencies.coefficient FROM ?:currencies WHERE ?:currencies.currency_code = ?s", $currency);
    $price		= round($order_info['total'] / $currencyCo * 100);

    $url		= (strpos($_SERVER['SERVER_PROTOCOL'], 'HTTPS') !== false ? 'https' : 'http') . '://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $url		= strtr($url, array('index.php' => '/payments/webtopay.php'));
    $urlIndex	= strtr($url, array('/payments/webtopay.php' => 'index.php'));
	
    $w2pData	= fn_get_payment_method_data($_SESSION['cart']['payment_id']);

	try {
		$payment_info = array(
			'projectid'		=> $w2pData['params']['project_id'],
			'sign_password' => $w2pData['params']['sign'],
			'orderid'		=> $_order_id,
			'lang'			=> ($language === 'LT') ? 'LIT' : 'ENG',
			'amount'		=> $price,
			'currency'		=> $currency,
			'accepturl'		=> "{$urlIndex}?dispatch=payment_notification.return&payment=webtopay&orderId={$_order_id}",//"{$url}&answer=accept&cartId={$_order_id}", //$current_location/$index_script?dispatch=payment_notification.return&payment=paypal&order_id=$order_id //http://localhost/cscart/?dispatch=payment_notification.return&payment=webtopay&orderid=1
			'cancelurl'		=> "{$urlIndex}?dispatch=payment_notification.cancel&payment=webtopay&orderId={$_order_id}", //$current_location/$index_script?dispatch=payment_notification.cancel&payment=paypal&order_id=$order_id   {$urlIndex}&dispatch=checkout.checkout  {$urlIndex}?dispatch=checkout.cancel&payment=webtopay&orderId={$_order_id}
			'callbackurl'	=> "{$urlIndex}?dispatch=payment_notification.callback&payment=webtopay&orderId={$_order_id}",
			'payment'		=> '',
			'country'		=> $order_info['b_country'],
			'logo'			=> '',
//"{$urlIndex}?dispatch=payment_notification.cancel&payment=webtopay&orderId={$_order_id}",
			'p_firstname'	=> $order_info['b_firstname'],
			'p_lastname'	=> $order_info['b_lastname'],
			'p_email'		=> $order_info['email'],
			'p_street'		=> $order_info['b_address'],
			'p_city'		=> $order_info['b_city'],
			'p_state'		=> $order_info['b_state'],
			'p_zip'			=> $order_info['b_zipcode'],
			'p_countrycode' => $order_info['b_country'],

			'test'			=> $w2pData['params']['test'],
		);
		
		
        WebToPay::redirectToPayment($payment_info);
    } catch (WebToPayException $e) {
        exit(get_class($e).': '.$e->getMessage());
    }
	fn_start_payment($_order_id, false, $payment_info);
}
