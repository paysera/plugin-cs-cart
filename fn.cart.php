<?php
/***************************************************************************
*                                                                          *
*   (c) 2004 Vladimir V. Kalynyak, Alexey V. Vinokurov, Ilya M. Shalnev    *
*                                                                          *
* This  is  commercial  software,  only  users  who have purchased a valid *
* license  and  accept  to the terms of the  License Agreement can install *
* and use this program.                                                    *
*                                                                          *
****************************************************************************
* PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
* "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
****************************************************************************/


if ( !defined('AREA') ) { die('Access denied'); }

//
// Get product description to show it in the cart
//
function fn_get_cart_product_data($hash, &$product, $skip_promotion, &$cart, &$auth, $promotion_amount = 0)
{
	if (!empty($product['product_id'])) {
		
		$fields = array(
			'?:products.product_id',
			'?:products.company_id',
			"GROUP_CONCAT(IF(?:products_categories.link_type = 'M', CONCAT(?:products_categories.category_id, 'M'), ?:products_categories.category_id)) as category_ids",
			'?:products.product_code',
			'?:products.weight',
			'?:products.tracking',
			'?:product_descriptions.product',
			'?:product_descriptions.short_description',
			'?:products.is_edp',
			'?:products.edp_shipping',
			'?:products.shipping_freight',
			'?:products.free_shipping',
			'?:products.zero_price_action',
			'?:products.tax_ids',
			'?:products.qty_step',
			'?:products.list_qty_count',
			'?:products.max_qty',
			'?:products.min_qty',
			'?:products.amount as in_stock',
			'?:products.shipping_params',
			'?:companies.status as company_status',
			'?:companies.company as company_name'			
		);

		$join  = db_quote("LEFT JOIN ?:product_descriptions ON ?:product_descriptions.product_id = ?:products.product_id AND ?:product_descriptions.lang_code = ?s", CART_LANGUAGE);

		$_p_statuses = array('A', 'H');
		$_c_statuses = array('A', 'H');
		$avail_cond = (AREA == 'C') ? " AND (" . fn_find_array_in_set($auth['usergroup_ids'], '?:categories.usergroup_ids', true) . ")" : '';
		$avail_cond .= (AREA == 'C') ? " AND (" . fn_find_array_in_set($auth['usergroup_ids'], '?:products.usergroup_ids', true) . ")" : '';
		$avail_cond .= (AREA == 'C' && !(isset($auth['area']) && $auth['area'] == 'A')) ? db_quote(' AND ?:categories.status IN (?a) AND ?:products.status IN (?a)', $_c_statuses, $_p_statuses) : '';
		$avail_cond .= (AREA == 'C') ? fn_get_localizations_condition('?:products.localization') : '';
	
		$join .= " INNER JOIN ?:products_categories ON ?:products_categories.product_id = ?:products.product_id INNER JOIN ?:categories ON ?:categories.category_id = ?:products_categories.category_id $avail_cond";
		$join .= " LEFT JOIN ?:companies ON ?:companies.company_id = ?:products.company_id";

		fn_set_hook('pre_get_cart_product_data', $hash, $product, $skip_promotion, $cart, $auth, $promotion_amount, $fields, $join);
		
		$_pdata = db_get_row("SELECT " . implode(', ', $fields) . " FROM ?:products ?p WHERE ?:products.product_id = ?i GROUP BY ?:products.product_id", $join, $product['product_id']);

		// delete product from cart if supplier or vendor was disabled.
		if (empty($_pdata) || (!empty($_pdata['company_id']) && !defined('ORDER_MANAGEMENT') && ($_pdata['company_status'] != 'A' || ((PRODUCT_TYPE == 'PROFESSIONAL' || PRODUCT_TYPE == 'COMMUNITY') && Registry::get('settings.Suppliers.enable_suppliers') != 'Y') ))) {
			unset($cart['products'][$hash]);
			return false;
		}

		if (!empty($_pdata['category_ids'])) {
			list($_pdata['category_ids'], $_pdata['main_category']) = fn_convert_categories($_pdata['category_ids']);
		} else {
			$_pdata['category_ids'] = array();
		}

		$_pdata['options_count'] = db_get_field("SELECT COUNT(*) FROM ?:product_options WHERE product_id = ?i AND status = 'A'", $product['product_id']);

		$amount = !empty($product['amount_total']) ? $product['amount_total'] : $product['amount'];
		$_pdata['price'] = fn_get_product_price($product['product_id'], $amount, $auth);

		$_pdata['base_price'] = (isset($product['stored_price']) && $product['stored_price'] == 'Y') ? $product['price'] : $_pdata['price'];

		fn_set_hook('get_cart_product_data', $product['product_id'], $_pdata, $product, $auth, $cart);

		$product['stored_price'] = empty($product['stored_price']) ? 'N' : $product['stored_price'];
		$product['stored_discount'] = empty($product['stored_discount']) ? 'N' : $product['stored_discount'];
		$product['product_options'] = empty($product['product_options']) ? array() : $product['product_options'];

		if (empty($_pdata['product_id'])) { // FIXME - for deleted products for OM
			unset($cart['products'][$hash]);
			return array();
		}

		if (!empty($_pdata['options_count']) && empty($product['product_options'])) {
			$cart['products'][$hash]['product_options'] = fn_get_default_product_options($product['product_id']);
		}

		if (Registry::get('settings.General.inventory_tracking') == 'Y' && !empty($_pdata['tracking']) && $_pdata['tracking'] == 'O' && !empty($product['selectable_cart_id'])) {
			$_pdata['in_stock'] = db_get_field("SELECT amount FROM ?:product_options_inventory WHERE combination_hash = ?i", $product['selectable_cart_id']);
		}

		if (fn_check_amount_in_stock($product['product_id'], $product['amount'], $product['product_options'], $hash, $_pdata['is_edp'], !empty($product['original_amount']) ? $product['original_amount'] : 0, $cart) == false) {
			unset($cart['products'][$hash]);
			$out_of_stock = true;
			return false;
		}
		
		$exceptions = fn_get_product_exceptions($product['product_id'], true);
		if (!isset($product['options_type']) || !isset($product['exceptions_type'])) {
			$product = array_merge($product, db_get_row('SELECT options_type, exceptions_type FROM ?:products WHERE product_id = ?i', $product['product_id']));
		}

		if (!fn_is_allowed_options_exceptions($exceptions, $product['product_options'], $product['options_type'], $product['exceptions_type']) && !defined('GET_OPTIONS')) {
			fn_set_notification('E', fn_get_lang_var('notice'), str_replace('[product]', $_pdata['product'], fn_get_lang_var('product_options_forbidden_combination')));
			unset($cart['products'][$hash]);
			
			return false;
		}
		
		if (isset($product['extra']['custom_files'])) {
			$_pdata['extra']['custom_files'] = $product['extra']['custom_files'];
		}

		$_pdata['calculation'] = array();

		if (isset($product['extra']['exclude_from_calculate'])) {
			$_pdata['exclude_from_calculate'] = $product['extra']['exclude_from_calculate'];
			$_pdata['aoc'] = !empty($product['extra']['aoc']);
			$_pdata['price'] = 0;
		} else {
			if ($product['stored_price'] == 'Y') {
				$_pdata['price'] = $product['price'];
			}
		}

		$product['price'] = ($_pdata['zero_price_action'] == 'A' && isset($product['custom_user_price'])) ? $product['custom_user_price'] : floatval($_pdata['price']);
		$cart['products'][$hash]['price'] = $product['price'];
		
		$_pdata['original_price'] = $product['price'];

		if ($product['stored_price'] != 'Y' && !isset($product['extra']['exclude_from_calculate'])) {
			$_tmp = $product['price'];
			$product['price'] = fn_apply_options_modifiers($product['product_options'], $product['price'], 'P', array(), array('product_data' => $product));
			$product['modifiers_price'] = $_pdata['modifiers_price'] = $product['price'] - $_tmp; // modifiers
		} else {
			$product['modifiers_price'] = $_pdata['modifiers_price'] = 0;
		}

		if (isset($product['modifiers_price']) && $_pdata['zero_price_action'] == 'A') {
			$_pdata['base_price'] = $product['price'] - $product['modifiers_price'];
		}

		$_pdata['weight'] = fn_apply_options_modifiers($product['product_options'], $_pdata['weight'], 'W', array(), array('product_data' => $product));
		$_pdata['amount'] = $product['amount'];
		$_pdata['price'] = $_pdata['original_price'] = fn_format_price($product['price']);

		$_pdata['stored_price'] = $product['stored_price'];

		if ($cart['options_style'] == 'F') {
			$_pdata['product_options'] = fn_get_selected_product_options($product['product_id'], $product['product_options'], CART_LANGUAGE);
		} elseif ($cart['options_style'] == 'I') {
			$_pdata['product_options'] = fn_get_selected_product_options_info($product['product_options'], CART_LANGUAGE);
		} else {
			$_pdata['product_options'] = $product['product_options'];
		}
		
		fn_set_hook('get_cart_product_data_post_options', $product['product_id'], $_pdata, $product);

		if (($_pdata['free_shipping'] != 'Y' || AREA == 'A') && ($_pdata['is_edp'] != 'Y' || ($_pdata['is_edp'] == 'Y' && $_pdata['edp_shipping'] == 'Y'))) {
			$cart['shipping_required'] = true;
		}

		$cart['products'][$hash]['is_edp'] = (!empty($_pdata['is_edp']) && $_pdata['is_edp'] == 'Y') ? 'Y' : 'N';
		$cart['products'][$hash]['edp_shipping'] = (!empty($_pdata['edp_shipping']) && $_pdata['edp_shipping'] == 'Y') ? 'Y' : 'N';

		if (empty($cart['products'][$hash]['extra']['parent'])) { // count only products without parent
			if ($skip_promotion == true && !empty($promotion_amount)){
				$cart['amount'] += $promotion_amount;
			}else{
				$cart['amount'] += $product['amount'];
			}
		}

		if ($skip_promotion == false) {
			if (empty($cart['order_id'])) {
				fn_promotion_apply('catalog', $_pdata, $auth);
			} else {
				if (isset($product['discount'])) {
					$_pdata['discount'] = $product['discount'];
					$_pdata['price'] -= $product['discount'];

					if ($_pdata['price'] < 0) {
						$_pdata['discount'] += $_pdata['price'];
						$_pdata['price'] = 0;
					}
				}
			}

			// apply discount to the product
			if (!empty($_pdata['discount'])) {
				$cart['use_discount'] = true;
			}
		}

		if (!empty($product['object_id'])) {
			$_pdata['object_id'] = $product['object_id'];
		}

		$_pdata['shipping_params'] = empty($_pdata['shipping_params']) ? array() : unserialize($_pdata['shipping_params']);

		$_pdata['stored_discount'] = $product['stored_discount'];
		$cart['products'][$hash]['modifiers_price'] = $product['modifiers_price'];

		$_pdata['subtotal'] = $_pdata['price'] * $product['amount'];
		$cart['original_subtotal'] += $_pdata['original_price'] * $product['amount'];
		$cart['subtotal'] += $_pdata['subtotal'];

		return $_pdata;
	}

	return array();
}

/**
 * Update cart products data 
 *
 * @param array $cart Array of cart content and user information necessary for purchase
 * @param array $cart_products Array of new data for products information update
 * @return boolean Always true
 */
function fn_update_cart_data(&$cart, &$cart_products)
{
	foreach ($cart_products as $k => $v) {
		if (isset($cart['products'][$k])) {
			if (!isset($v['base_price'])) {
				$cart['products'][$k]['base_price'] = $v['base_price'] = $cart['products'][$k]['stored_price'] != 'Y' ? $v['price'] : $cart['products'][$k]['price'];
			} else {
				if ($cart['products'][$k]['stored_price'] == 'Y') {
					$cart_products[$k]['base_price'] = $cart['products'][$k]['price'];
				}
			}

			$cart['products'][$k]['base_price'] = $cart['products'][$k]['stored_price'] != 'Y' ? $v['base_price'] : $cart['products'][$k]['price'];
			$cart['products'][$k]['price'] = $cart['products'][$k]['stored_price'] != 'Y' ? $v['price'] : $cart['products'][$k]['price'];
			if (isset($v['discount'])) {
				$cart['products'][$k]['discount'] = $v['discount'];
			}
			if (isset($v['promotions'])) {
				$cart['products'][$k]['promotions'] = $v['promotions'];
			}
		}
	}

	return true;
}

/**
 * Get all available payment methods for current area
 * 
 * @param array &$auth customer data
 * @param string $lang_code 2-letter language code
 * @return array found payment methods
 */
function fn_get_payment_methods(&$auth, $lang_code = CART_LANGUAGE)
{
	$condition = '';
	if (AREA == 'C') {
		$condition .= " AND (" . fn_find_array_in_set($auth['usergroup_ids'], '?:payments.usergroup_ids', true) . ")";
		$condition .= " AND ?:payments.status = 'A' ";
		$condition .= fn_get_localizations_condition('?:payments.localization');
	}

	$payment_methods = db_get_hash_array("SELECT ?:payments.payment_id, ?:payments.a_surcharge, ?:payments.p_surcharge, ?:payments.payment_category, ?:payment_descriptions.*, ?:payment_processors.processor, ?:payment_processors.type AS processor_type FROM ?:payments LEFT JOIN ?:payment_descriptions ON ?:payments.payment_id = ?:payment_descriptions.payment_id AND ?:payment_descriptions.lang_code = ?s LEFT JOIN ?:payment_processors ON ?:payment_processors.processor_id = ?:payments.processor_id WHERE 1 $condition ORDER BY ?:payments.position", 'payment_id', $lang_code);

	fn_set_hook('get_payment_methods', $payment_methods, $auth);

	return $payment_methods;
}

/**
 * Gets payment methods names list
 *
 * @param boolean $is_active Flag determines if only the active methods should be returned; default value is false
 * @param string $lang_code 2-letter language code
 * @return array Array of payment method names with payment_ids as keys
 */
function fn_get_simple_payment_methods($is_active = true, $lang_code = CART_LANGUAGE)
{
	$condition = '';
	if ($is_active) {
		$condition .= " AND status = 'A'";
	}
	return db_get_hash_single_array("SELECT ?:payments.payment_id, ?:payment_descriptions.payment FROM ?:payments LEFT JOIN ?:payment_descriptions ON ?:payments.payment_id = ?:payment_descriptions.payment_id AND ?:payment_descriptions.lang_code = ?s WHERE 1 $condition ORDER BY ?:payments.position, ?:payment_descriptions.payment", array('payment_id', 'payment'), $lang_code);
}

/**
 * Get payment method data
 * 
 * @param type $payment_id payment ID
 * @param type $lang_code 2-letter language code
 * @return array payment information
 */
function fn_get_payment_method_data($payment_id, $lang_code = CART_LANGUAGE)
{
	$payment = db_get_row("SELECT ?:payments.*, ?:payment_descriptions.*, ?:payment_processors.processor, ?:payment_processors.type AS processor_type, ?:payments.params FROM ?:payments LEFT JOIN ?:payment_processors ON ?:payment_processors.processor_id = ?:payments.processor_id LEFT JOIN ?:payment_descriptions ON ?:payments.payment_id = ?:payment_descriptions.payment_id AND ?:payment_descriptions.lang_code = ?s WHERE ?:payments.payment_id = ?i", $lang_code, $payment_id);

	$payment['params'] = (!empty($payment['params'])) ? unserialize($payment['params']) : '';
	$payment['tax_ids'] = !empty($payment['tax_ids']) ? fn_explode(',', $payment['tax_ids']) : array();
	$payment['image'] = fn_get_image_pairs($payment_id, 'payment', 'M', true, true, DESCR_SL);

	fn_set_hook('summary_get_payment_method', $payment_id, $payment);

	return $payment;
}

//
// Update product amount
//
// returns true if inventory successfully updated and false if amount
// is negative is allow_negative_amount option set to false

function fn_update_product_amount($product_id, $amount, $product_options, $sign)
{
	if (Registry::get('settings.General.inventory_tracking') != 'Y') {
		return true;
	}

	$tracking = db_get_field("SELECT tracking FROM ?:products WHERE product_id = ?i", $product_id);

	if ($tracking == 'D') {
		return true;
	}

	if ($tracking == 'B') {
		$product = db_get_row("SELECT amount, product_code FROM ?:products WHERE product_id = ?i", $product_id);
		$current_amount = $product['amount'];
		$product_code = $product['product_code'];
	} else {
		$cart_id = fn_generate_cart_id($product_id, array('product_options' => $product_options), true);
		$product = db_get_row("SELECT amount, product_code FROM ?:product_options_inventory WHERE combination_hash = ?i", $cart_id);
		$current_amount = empty($product['amount']) ? 0 : $product['amount'];
		
		if (empty($product['product_code'])) {
			$product_code = db_get_field("SELECT product_code FROM ?:products WHERE product_id = ?i", $product_id);
		} else {
			$product_code = $product['product_code'];
		}
	}

	if ($sign == '-') {
		$new_amount = $current_amount - $amount;

		// Notify administrator about inventory low stock
		if ($new_amount <= Registry::get('settings.General.low_stock_threshold')) {
			// Log product low-stock
			fn_log_event('products', 'low_stock', array (
				'product_id' => $product_id
			));

			$company_id = fn_get_company_id('products', 'product_id', $product_id);
			$company_placement_info = fn_get_company_placement_info($company_id);
			$lang_code = !empty($company_placement_info['lang_code']) ? $company_placement_info['lang_code'] : Registry::get('settings.Appearance.admin_default_language');
			$selected_product_options = ($tracking == 'O') ? fn_get_selected_product_options_info($product_options, $lang_code) : '';

			Registry::get('view_mail')->assign('product_options', $selected_product_options);
			Registry::get('view_mail')->assign('new_amount', $new_amount);
			Registry::get('view_mail')->assign('product_id', $product_id);
			Registry::get('view_mail')->assign('product_code', $product_code);
			Registry::get('view_mail')->assign('product', db_get_field("SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s", $product_id, $lang_code));

			Registry::get('view_mail')->assign('company_placement_info', $company_placement_info);

			fn_send_mail($company_placement_info['company_orders_department'], Registry::get('settings.Company.company_orders_department'), 'orders/low_stock_subj.tpl', 'orders/low_stock.tpl', '', $lang_code);
		}

		if ($new_amount < 0 && Registry::get('settings.General.allow_negative_amount') != 'Y') {
			return false;
		}
	} else {
		$new_amount = $current_amount + $amount;
	}

	fn_set_hook('update_product_amount', $new_amount, $product_id, $cart_id, $tracking);

	if ($tracking == 'B') {
		db_query("UPDATE ?:products SET amount = ?i WHERE product_id = ?i", $new_amount, $product_id);
	} else {
		db_query("UPDATE ?:product_options_inventory SET amount = ?i WHERE combination_hash = ?i", $new_amount, $cart_id);
	}

	if (($current_amount <= 0) && ($new_amount > 0)) {
		fn_send_product_notifications($product_id);
	}

	return true;
}

/**
 * Places an order
 *
 * @param array $cart Array of the cart contents and user information necessary for purchase
 * @param array $auth Array of user authentication data (e.g. uid, usergroup_ids, etc.)
 * @param string $action Current action. Can be empty or "save"
 * @return int order_id in case of success, otherwise False
 */
function fn_place_order(&$cart, &$auth, $action = '', $parent_order_id = 0)
{
	$allow = true;

	fn_set_hook('pre_place_order', $cart, $allow);

	if ($allow == true && !fn_cart_is_empty($cart)) {
		$ip = fn_get_ip();
		$__order_status = STATUS_INCOMPLETED_ORDER;
		$order = fn_check_table_fields($cart, 'orders');
		$order = fn_array_merge($order, fn_check_table_fields($cart['user_data'], 'orders'));

		// filter hidden fields, which were hidden to checkout
		fn_filter_hidden_profile_fields($order, 'O');

		$profile_fields = fn_get_profile_fields('O');

		// If the contact information fields were disabled, fill the information from the billing/shipping
		$order = fn_fill_contact_info_from_address($order);

		$order['user_id'] = $auth['user_id'];
		$order['timestamp'] = TIME;
		$order['lang_code'] = CART_LANGUAGE;
		$order['tax_exempt'] = $auth['tax_exempt'];
		$order['status'] = STATUS_INCOMPLETED_ORDER; // incomplete by default to increase inventory
		$order['ip_address'] = $ip['host'];

		if (defined('CART_LOCALIZATION')) {
			$order['localization_id'] = CART_LOCALIZATION;
		}

		if (!empty($cart['payment_surcharge'])) {
			$cart['total'] += $cart['payment_surcharge'];
			$order['total'] = $cart['total'];
		}

		$cart['companies'] = fn_get_products_companies($cart['products']);

		$order['is_parent_order'] = 'N';

		
		
		if (PRODUCT_TYPE == 'ULTIMATE' && defined('COMPANY_ID')) {
			$order['company_id'] = COMPANY_ID;
		}
		
		$order['promotions'] = serialize(!empty($cart['promotions']) ? $cart['promotions'] : array());
		if (!empty($cart['promotions'])) {
			$order['promotion_ids'] = implode(',', array_keys($cart['promotions']));
		}

		$order['shipping_ids'] = !empty($cart['shipping']) ? fn_create_set(array_keys($cart['shipping'])) : '';

		if (!empty($cart['payment_info'])) {
			$ccards = fn_get_static_data_section('C', true);
			if (!empty($cart['payment_info']['card']) && !empty($ccards[$cart['payment_info']['card']])) {
				// Check if cvv2 number required and unset it if not
				if ($ccards[$cart['payment_info']['card']]['param_2'] != 'Y') {
					unset($cart['payment_info']['cvv2']);
				}
				// Check if start date exists and required and convert it to string
				if ($ccards[$cart['payment_info']['card']]['param_3'] != 'Y') {
					
					unset($cart['payment_info']['start_year'], $cart['payment_info']['start_month']);
				}
				// Check if issue number required
				if ($ccards[$cart['payment_info']['card']]['param_4'] != 'Y') {
					unset($cart['payment_info']['issue_number']);
				}
			}
		}

		// We're editing existing order
		if (!empty($order['order_id']) && $order['is_parent_order'] != 'Y') {

			$_tmp = db_get_row("SELECT status, ip_address, details, timestamp, payment_id, lang_code, repaid FROM ?:orders WHERE order_id = ?i", $order['order_id']);
			$order['ip_address'] = $_tmp['ip_address']; // Leave original customers IP address
			$order['details'] = $_tmp['details']; // Leave order details
			$order['timestamp'] = $_tmp['timestamp']; // Leave the original date
			$order['lang_code'] = $_tmp['lang_code']; // Leave the original language
			$order['repaid'] = $_tmp['repaid'];

			if ($action == 'save') {

				$payment_info = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'P'", $order['order_id']);
				if (!empty($payment_info) && $cart['payment_id'] == $_tmp['payment_id']) {
					$payment_info = unserialize(fn_decrypt_text($payment_info));
				} else {
					$payment_info = array();
				}

				$cart['payment_info'] = array_merge($payment_info, $cart['payment_info']);

				$__order_status = $_tmp['status']; // Get the original order status
			}

			fn_change_order_status($order['order_id'], STATUS_INCOMPLETED_ORDER, $_tmp['status'], fn_get_notification_rules(array(), false)); // incomplete the order to increase inventory amount.

			db_query("DELETE FROM ?:orders WHERE order_id = ?i", $order['order_id']);
			db_query("DELETE FROM ?:order_details WHERE order_id = ?i", $order['order_id']);
			db_query("DELETE FROM ?:profile_fields_data WHERE object_id = ?i AND object_type = 'O'", $order['order_id']);
			db_query("DELETE FROM ?:order_data WHERE order_id = ?i AND type IN ('T', 'C', 'P')", $order['order_id']);

			fn_set_hook('edit_place_order', $order['order_id']);
		}

		if (!empty($cart['rewrite_order_id'])) {
			$order['order_id'] = array_shift($cart['rewrite_order_id']);
		}

		$order_id = db_query("INSERT INTO ?:orders ?e", $order);

		// Log order creation/update
		$log_action = !empty($order['order_id']) ? 'update' : 'create';
		fn_log_event('orders', $log_action, array(
			'order_id' => $order_id
		));

		fn_store_profile_fields($cart['user_data'], $order_id, 'O');

		$order['order_id'] = $order_id;
		// If customer is not logged in, store order ids in the session
		if (empty($auth['user_id'])) {
			$auth['order_ids'][] = $order_id;
		}
		// Add order details data
		if (!empty($order_id)) {
			if (!empty($cart['products'])) {
				foreach ((array)$cart['products'] as $k => $v) {
					$product_code = '';
					$extra = empty($v['extra']) ? array() : $v['extra'];
					$v['discount'] = empty($v['discount']) ? 0 : $v['discount'];

					$extra['product'] = empty($v['product']) ? fn_get_product_name($v['product_id']) : $v['product'];

					$extra['company_id'] = !empty($v['company_id']) ? $v['company_id'] : 0;

					if (isset($v['is_edp'])) {
						$extra['is_edp'] = $v['is_edp'];
					}
					if (isset($v['edp_shipping'])) {
						$extra['edp_shipping'] = $v['edp_shipping'];
					}
					if (isset($v['discount'])) {
						$extra['discount'] = $v['discount'];
					}
					if (isset($v['base_price'])) {
						$extra['base_price'] = floatval($v['base_price']);
					}
					if (!empty($v['promotions'])) {
						$extra['promotions'] = $v['promotions'];
					}
					if (!empty($v['stored_price'])) {
						$extra['stored_price'] = $v['stored_price'];
					}

					if (!empty($v['product_options'])) {
						$_options = fn_get_product_options($v['product_id']);
						if (!empty($_options)) {
							foreach ($_options as $option_id => $option) {
								if (!isset($v['product_options'][$option_id])) {
									$v['product_options'][$option_id] = '';
								}
							}
						}
						
						$extra['product_options'] = $v['product_options'];
						$cart_id = fn_generate_cart_id($v['product_id'], array('product_options' => $v['product_options']), true);
						$tracking = db_get_field("SELECT tracking FROM ?:products WHERE product_id = ?i", $v['product_id']);
						
						if ($tracking == 'O') {
							$product_code = db_get_field("SELECT product_code FROM ?:product_options_inventory WHERE combination_hash = ?i", $cart_id);
						}

						$extra['product_options_value'] = fn_get_selected_product_options_info($v['product_options']);
					} else {
						$v['product_options'] = array();
					}

					if (empty($product_code)) {
						$product_code = db_get_field("SELECT product_code FROM ?:products WHERE product_id = ?i", $v['product_id']);
					}

					// Check the cart custom files
					if (isset($extra['custom_files'])) {
						$dir_path = DIR_CUSTOM_FILES . 'order_data/' . $order_id;
						$sess_dir_path = DIR_CUSTOM_FILES . 'sess_data';

						fn_mkdir($dir_path);
						
						foreach ($extra['custom_files'] as $option_id => $files) {
							if (is_array($files)) {
								foreach ($files as $file_id => $file) {
									$file['path'] = $sess_dir_path . '/' . fn_basename($file['path']);
									
									fn_copy($file['path'], $dir_path . '/' . $file['file']);
									fn_rm($file['path']);
									fn_rm($file['path'] . '_thumb');
									
									$extra['custom_files'][$option_id][$file_id]['path'] = $dir_path . '/' . $file['file'];
								}
							}
						}
					}

					$order_details = array (
						'item_id' => $k,
						'order_id' => $order_id,
						'product_id' => $v['product_id'],
						'product_code' => $product_code,
						'price' => (!empty($v['stored_price']) && $v['stored_price'] == 'Y') ? $v['price'] - $v['discount'] : $v['price'],
						'amount' => $v['amount'],
						'extra' => serialize($extra)
					);

					db_query("INSERT INTO ?:order_details ?e", $order_details);
					
					// Increase product popularity
					$_data = array (
						'product_id' => $v['product_id'],
						'bought' => 1,
						'total' => POPULARITY_BUY
					);
					
					db_query("INSERT INTO ?:product_popularity ?e ON DUPLICATE KEY UPDATE bought = bought + 1, total = total + ?i", $_data, POPULARITY_BUY);
				}
			}

			// Save shipping information
			if (!empty($cart['shipping'])) {
				// Get carriers and tracking number
				$data = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'L'", $order_id);
				if (!empty($data)) {
					$data = unserialize($data);
					foreach ($cart['shipping'] as $sh_id => $_d) {
						if (!empty($data[$sh_id]['carrier'])) {
							$cart['shipping'][$sh_id]['carrier'] = $data[$sh_id]['carrier'];
						}

						if (!empty($data[$sh_id]['tracking_number'])) {
							$cart['shipping'][$sh_id]['tracking_number'] = $data[$sh_id]['tracking_number'];
						}
					}
				}

				fn_apply_stored_shipping_rates($cart, $order_id);

				$_data = array (
					'order_id' => $order_id,
					'type' => 'L', //shipping information
					'data' => serialize($cart['shipping'])
				);
				db_query("REPLACE INTO ?:order_data ?e", $_data);
			}

			// Save taxes
			if (!empty($cart['taxes'])) {
				$_data = array (
					'order_id' => $order_id,
					'type' => 'T', //taxes information
					'data' => serialize($cart['taxes']),
				);
				db_query("REPLACE INTO ?:order_data ?e", $_data);
			}

			// Save payment information
			if (!empty($cart['payment_info'])) {
				//Remove credit card data
				$payment_info = $cart['payment_info'];
				$_data = array (
					'order_id' => $order_id,
					'type' => 'P', //payment information
					'data' => fn_encrypt_text(serialize($payment_info)),
				);
				db_query("REPLACE INTO ?:order_data ?e", $_data);
			}

			// Save coupons information
			if (!empty($cart['coupons'])) {
				$_data = array (
					'order_id' => $order_id,
					'type' => 'C', //coupons
					'data' => serialize($cart['coupons']),
				);
				db_query("REPLACE INTO ?:order_data ?e", $_data);
			}

			// Save secondary currency (for order notifications from payments with feedback requests) 
			$_data = array (
				'order_id' => $order_id,
				'type' => 'R', //secondary currency
				'data' => serialize(CART_SECONDARY_CURRENCY),
			);
			db_query("REPLACE INTO ?:order_data ?e", $_data);

			//
			// Place the order_id to new_orders table for all admin profiles
			//
			if (!$parent_order_id) {
				$condition = " AND user_type = 'A'";
				if (PRODUCT_TYPE == 'MULTIVENDOR') {
					$condition = empty($order['company_id']) ? "AND user_type = 'A' AND company_id = 0 " : "AND user_type = 'V' " . fn_get_company_condition('?:users.company_id', true, $order['company_id'], true);
				}
				$admins = db_get_fields("SELECT user_id FROM ?:users WHERE 1 $condition");
				foreach ($admins as $k => $v) {
					db_query("REPLACE INTO ?:new_orders (order_id, user_id) VALUES (?i, ?i)", $order_id, $v);
				}
			}

			$auto_process_free_orders = true;

			fn_set_hook('place_order', $order_id, $action, $__order_status, $cart, $auth, $auto_process_free_orders);

			// If order total is zero, just save the order without any processing procedures
			if (floatval($cart['total']) == 0 && $auto_process_free_orders) {
				$action = 'save';
				$__order_status = 'P';
			}
			
			list($is_processor_script, ) = fn_check_processor_script($cart['payment_id'], $action, true);

			if (!$is_processor_script && $__order_status == STATUS_INCOMPLETED_ORDER) {
				$__order_status = 'O';
			}

			// Set new order status
			fn_change_order_status($order_id, $__order_status, '', (($is_processor_script || $__order_status == STATUS_PARENT_ORDER) ? fn_get_notification_rules(array(), true) : fn_get_notification_rules(array())), true);

			$cart['processed_order_id'] = array();
			$cart['processed_order_id'][] = $order_id;



			return array($order_id, $action != 'save');
		}
	}

	return array(false, false);
}

/**
 * Order payment processing
 *
 * @param array $payment payment data
 * @param int $order_id order ID
 * @param bool $force_notification force user notification (true - notify, false - do not notify, order status properties will be skipped)
 * @return bool True on success, false otherwise
 */
function fn_start_payment($order_id, $force_notification = array(), $payment_info)
{
	$order_info = fn_get_order_info($order_id);

	if (!empty($order_info['payment_info']) && !empty($payment_info)) {
		$order_info['payment_info'] = $payment_info;
	}

	list($is_processor_script, $processor_data) = fn_check_processor_script($order_info['payment_id'], '');
	if ($is_processor_script) {
		set_time_limit(300);
		$idata = array (
			'order_id' => $order_id,
			'type' => 'S',
			'data' => TIME,
		);
		db_query("REPLACE INTO ?:order_data ?e", $idata);


		$index_script = INDEX_SCRIPT;
		$mode = MODE;

		include(DIR_PAYMENT_FILES . $processor_data['processor_script']);

		return fn_finish_payment($order_id, $pp_response, $force_notification);
	}

	return false;
}

/**
 * Finish order paymnent
 *
 * @param int $order_id order ID
 * @param array $pp_response payment response
 * @param bool $force_notification force user notification (true - notify, false - do not notify, order status properties will be skipped)
 */
function fn_finish_payment($order_id, $pp_response, $force_notification = array())
{
	
	// Change order status
	$valid_id = db_get_field("SELECT order_id FROM ?:order_data WHERE order_id = ?i AND type = 'S'", $order_id);

	if (!empty($valid_id)) {
		db_query("DELETE FROM ?:order_data WHERE order_id = ?i AND type = 'S'", $order_id);

		fn_update_order_payment_info($order_id, $pp_response);

		if ($pp_response['order_status'] == 'N' && !empty($_SESSION['cart']['placement_action']) && $_SESSION['cart']['placement_action'] == 'repay') {
			$pp_response['order_status'] = 'I';
		}

		fn_set_hook('finish_payment', $order_id, $pp_response, $force_notification);
		//die("awdsf");
	}
	fn_change_order_status($order_id, $pp_response['order_status'], '', $force_notification);
}

//
// Store cart content in the customer's profile
//
function fn_save_cart_content(&$cart, $user_id, $type = 'C', $user_type = 'R')
{
	if (empty($user_id)) {
		if (fn_get_session_data('cu_id')) {
			$user_id = fn_get_session_data('cu_id');
		} else {
			$user_id = fn_crc32(uniqid(TIME));
			fn_set_session_data('cu_id', $user_id, COOKIE_ALIVE_TIME);
		}
		$user_type = 'U';
	}

	if (!empty($user_id)) {
		$condition = db_quote("user_id = ?i AND type = ?s AND user_type = ?s", $user_id, $type, $user_type);
		
		if (PRODUCT_TYPE == 'ULTIMATE') {
			$condition .= fn_get_company_condition('?:user_session_products.company_id');
		}
		
		db_query("DELETE FROM ?:user_session_products WHERE " . $condition);
		if (!empty($cart['products']) && is_array($cart['products'])) {
			$_cart_prods = $cart['products'];
			foreach ($_cart_prods as $_item_id => $_prod) {
				$_cart_prods[$_item_id]['user_id'] = $user_id;
				$_cart_prods[$_item_id]['timestamp'] = TIME;
				$_cart_prods[$_item_id]['type'] = $type;
				$_cart_prods[$_item_id]['user_type'] = $user_type;
				$_cart_prods[$_item_id]['item_id'] = $_item_id;
				$_cart_prods[$_item_id]['item_type'] = 'P';
				$_cart_prods[$_item_id]['extra'] = serialize($_prod);
				$_cart_prods[$_item_id]['amount'] = empty($_cart_prods[$_item_id]['amount']) ? 1 : $_cart_prods[$_item_id]['amount'];
				$_cart_prods[$_item_id]['session_id'] = Session::get_id();
				$ip = fn_get_ip();
				$_cart_prods[$_item_id]['ip_address'] = $ip['host'];
				
				if (PRODUCT_TYPE == 'ULTIMATE' && defined('COMPANY_ID')) {
					$_cart_prods[$_item_id]['company_id'] = COMPANY_ID;
				}
				
				if (!empty($_cart_prods[$_item_id])) {
					db_query('REPLACE INTO ?:user_session_products ?e', $_cart_prods[$_item_id]);
				}
			}
		}

		fn_set_hook('save_cart', $cart, $user_id, $type);

	}
	return true;
}

/**
 * Extract cart content from the customer's profile.
 * $type : C - cart, W - wishlist
 *
 * @param array $cart
 * @param integer $user_id
 * @param char $type
 *
 * @return void
 */
function fn_extract_cart_content(&$cart, $user_id, $type = 'C', $user_type = 'R')
{
	$auth = & $_SESSION['auth'];
	$old_session_id = '';

	// Restore cart content
	if (!empty($user_id)) {
		$item_types = fn_get_cart_content_item_types('X');
		$condition = db_quote("user_id = ?i AND type = ?s AND user_type = ?s AND item_type IN (?a)", $user_id, $type, $user_type, $item_types);

		fn_set_hook('pre_extract_cart', $cart, $condition, $item_types);

		$_prods = db_get_hash_array("SELECT * FROM ?:user_session_products WHERE " . $condition, 'item_id');
		if (!empty($_prods) && is_array($_prods)) {
			$cart['products'] = empty($cart['products']) ? array() : $cart['products'];
			foreach ($_prods as $_item_id => $_prod) {
				$old_session_id = $_prod['session_id'];
				$_prod_extra = unserialize($_prod['extra']);
				unset($_prod['extra']);
				$cart['products'][$_item_id] = empty($cart['products'][$_item_id]) ? fn_array_merge($_prod, $_prod_extra, true) : $cart['products'][$_item_id];
			}
		}
	}

	fn_set_hook('extract_cart', $cart, $user_id, $type, $user_type);

	if ($type == 'C') {
		fn_calculate_cart_content($cart, $auth, 'S', false, 'I');
	}
}
/**
 * get cart content item types
 *
 * @param char $action
 * V - for View mode
 * X - for eXtract mode
 * @return array
 */
function fn_get_cart_content_item_types($action = 'V')
{
	$item_types = array('P');

	fn_set_hook('get_cart_item_types', $item_types, $action);

	return $item_types;
}

/**
 * Generate title string for order details page
 * 
 * @param int $order_id order identifier
 * @return string
 */
function fn_get_order_name($order_id)
{
	$total = db_get_field("SELECT total FROM ?:orders WHERE order_id = ?i", $order_id);
	if ($total == '') {
		return false;
	}

	if (Registry::get('settings.General.alternative_currency') == "Y") {
		$result = fn_format_price_by_currency($total, CART_PRIMARY_CURRENCY);
		if (CART_SECONDARY_CURRENCY != CART_PRIMARY_CURRENCY) {
			$result .= ' (' . fn_format_price_by_currency($total) . ')';
		}
	} else {
		$result = fn_format_price_by_currency($total);
	}

	return $order_id . ' - ' . $result;
}

/**
 * Gets order paid statuses
 * 
 * @return array Available paid statuses
 */
function fn_get_order_paid_statuses()
{
	$paid_statuses = db_get_fields('SELECT status FROM ?:status_data WHERE type = ?s AND param = ?s AND value = ?s', 'O', 'inventory', 'D');

	/**
	 * Get order paid statuses (at the end of fn_get_order_paid_statuses())
	 *
	 * @param array $paid_statuses List of order paid statuses
	 */
	fn_set_hook('get_order_paid_statuses_post', $paid_statuses);

	return $paid_statuses;
}

function fn_format_price_by_currency($price, $currency_code = CART_SECONDARY_CURRENCY)
{
	$currencies = Registry::get('currencies');
	$currency = $currencies[$currency_code];
	$result = fn_format_rate_value($price, 'F', $currency['decimals'], $currency['decimals_separator'], $currency['thousands_separator'], $currency['coefficient']);
	if ($currency['after'] == 'Y') {
		$result .= ' ' . $currency['symbol'];
	} else {
		$result = $currency['symbol'] . $result;
	}

	return $result;
}

//
// Get order info
//
function fn_get_order_info($order_id, $native_language = false, $format_info = true, $get_edp_files = false, $skip_static_values = false)
{
	if (!empty($order_id)) {
		$condition = fn_get_company_condition('?:orders.company_id');
		$order = db_get_row("SELECT * FROM ?:orders WHERE ?:orders.order_id = ?i $condition", $order_id);

		if (!empty($order)) {
			$lang_code = ($native_language == true) ? $order['lang_code'] : CART_LANGUAGE;

			$order['payment_method'] = fn_get_payment_method_data($order['payment_id'], $lang_code);
			// Get additional profile fields
			$additional_fields = db_get_hash_single_array(
				"SELECT field_id, value FROM ?:profile_fields_data "
				. "WHERE object_id = ?i AND object_type = 'O'",
				array('field_id', 'value'), $order_id
			);
			$order['fields'] = $additional_fields;

			$order['items'] = db_get_hash_array(
				"SELECT ?:order_details.*, ?:product_descriptions.product, ?:products.status as product_status FROM ?:order_details "
				. "LEFT JOIN ?:product_descriptions ON ?:order_details.product_id = ?:product_descriptions.product_id AND ?:product_descriptions.lang_code = ?s "
				. "LEFT JOIN ?:products ON ?:order_details.product_id = ?:products.product_id "
				. "WHERE ?:order_details.order_id = ?i ORDER BY ?:product_descriptions.product",
				'item_id', $lang_code, $order_id
			);

			$order['promotions'] = unserialize($order['promotions']);
			if (!empty($order['promotions'])) { // collect additional data
				$params = array (
					'promotion_id' => array_keys($order['promotions']),
				);
				list($promotions) = fn_get_promotions($params);
				foreach ($promotions as $pr_id => $p) {
					$order['promotions'][$pr_id]['name'] = $p['name'];
					$order['promotions'][$pr_id]['short_description'] = $p['short_description'];
				}
			}

			// Get additional data
			$additional_data = db_get_hash_single_array("SELECT type, data FROM ?:order_data WHERE order_id = ?i", array('type', 'data'), $order_id);

			$order['taxes'] = array();
			$order['tax_subtotal'] = 0;
			$order['display_shipping_cost'] = $order['shipping_cost'];

			// Replace country, state and title values with their descriptions
			$order_company_id = isset($order['company_id']) ? $order['company_id'] : ''; // company_id will be rewritten by user field, so need to save it.
			fn_add_user_data_descriptions($order, $lang_code);
			$order['company_id'] = $order_company_id;
			
			$order['need_shipping'] = false;
			$deps = array();

			
			// Get shipments common information
			if (Registry::get('settings.General.use_shipments') == 'Y') {
				$order['shipment_ids'] = db_get_fields(
					"SELECT sh.shipment_id FROM ?:shipments AS sh LEFT JOIN ?:shipment_items AS s_items ON (sh.shipment_id = s_items.shipment_id) "
					. "WHERE s_items.order_id = ?i GROUP BY s_items.shipment_id",
					$order_id
				);
				
				$_products = db_get_array("SELECT item_id, SUM(amount) AS amount FROM ?:shipment_items WHERE order_id = ?i GROUP BY item_id", $order_id);
				$shipped_products = array();
				
				if (!empty($_products)) {
					foreach ($_products as $_product) {
						$shipped_products[$_product['item_id']] = $_product['amount'];
					}
				}
				unset($_products);
			}
			
			foreach ($order['items'] as $k => $v) {
				//Check for product existance
				if (empty($v['product'])) {
					$order['items'][$k]['deleted_product'] = true;
				} else {
					$order['items'][$k]['deleted_product'] = false;
				}

				$order['items'][$k]['discount'] = 0;

				$v['extra'] = @unserialize($v['extra']);
				if ($order['items'][$k]['deleted_product'] == true && !empty($v['extra']['product'])) {
					$order['items'][$k]['product'] = $v['extra']['product'];
				}

				$order['items'][$k]['company_id'] = empty($v['extra']['company_id']) ? 0 : $v['extra']['company_id'];

				if (!empty($v['extra']['discount']) && floatval($v['extra']['discount'])) {
					$order['items'][$k]['discount'] = $v['extra']['discount'];
					$order['use_discount'] = true;
				}

				if (!empty($v['extra']['promotions'])) {
					$order['items'][$k]['promotions'] = $v['extra']['promotions'];
				}

				if (isset($v['extra']['base_price'])) {
					$order['items'][$k]['base_price'] = floatval($v['extra']['base_price']);
				} else {
					$order['items'][$k]['base_price'] = $v['price'];
				}
				$order['items'][$k]['original_price'] = $order['items'][$k]['base_price'];

				// Form hash key for this product
				$order['items'][$k]['cart_id'] = $v['item_id'];
				$deps['P_'.$order['items'][$k]['cart_id']] = $k;

				// Unserialize and collect product options information
				if (!empty($v['extra']['product_options'])) {
					if ($format_info == true) {
						if ($order['items'][$k]['deleted_product'] == true && !empty($v['extra']['product_options_value'])) {
							$order['items'][$k]['product_options'] = $v['extra']['product_options_value'];
						} else {
							$order['items'][$k]['product_options'] = fn_get_selected_product_options_info($v['extra']['product_options'], $lang_code);
						}
					}

					if (empty($v['extra']['stored_price']) || (!empty($v['extra']['stored_price']) && $v['extra']['stored_price'] != 'Y')) { // apply modifiers if this is not the custom price
						$order['items'][$k]['original_price'] = fn_apply_options_modifiers($v['extra']['product_options'], $order['items'][$k]['base_price'], 'P', ($skip_static_values == false && !empty($v['extra']['product_options_value'])) ? $v['extra']['product_options_value'] : array(), array('product_data' => $v));
					}
				}

				$order['items'][$k]['extra'] = $v['extra'];
				$order['items'][$k]['tax_value'] = 0;
				$order['items'][$k]['display_subtotal'] = $order['items'][$k]['subtotal'] = ($v['price'] * $v['amount']);

				// Get information about edp
				if ($get_edp_files == true && $order['items'][$k]['extra']['is_edp'] == 'Y') {
					$order['items'][$k]['files'] = db_get_array(
						"SELECT ?:product_files.file_id, ?:product_files.activation_type, ?:product_files.max_downloads, "
						. "?:product_file_descriptions.file_name, ?:product_file_ekeys.active, ?:product_file_ekeys.downloads, "
						. "?:product_file_ekeys.ekey, ?:product_file_ekeys.ttl FROM ?:product_files "
						. "LEFT JOIN ?:product_file_descriptions ON ?:product_file_descriptions.file_id = ?:product_files.file_id "
						. "AND ?:product_file_descriptions.lang_code = ?s "
						. "LEFT JOIN ?:product_file_ekeys ON ?:product_file_ekeys.file_id = ?:product_files.file_id "
						. "AND ?:product_file_ekeys.order_id = ?i WHERE ?:product_files.product_id = ?i", 
						$lang_code, $order_id, $v['product_id']
					);
				}
				
				// Get shipments information
				if (Registry::get('settings.General.use_shipments') == 'Y') {
					if (isset($shipped_products[$k])) {
						$order['items'][$k]['shipped_amount'] = $shipped_products[$k];
						$order['items'][$k]['shipment_amount'] = $v['amount'] - $shipped_products[$k];

					} else {
						$order['items'][$k]['shipped_amount'] = 0;
						$order['items'][$k]['shipment_amount'] = $v['amount'];
					}
					
					if ($order['items'][$k]['shipped_amount'] < $order['items'][$k]['amount']) {
						$order['need_shipment'] = true;
					}
				}
				
				// Check if the order needs the shipping method
				if (!($v['extra']['is_edp'] == 'Y' && (!isset($v['extra']['edp_shipping']) || $v['extra']['edp_shipping'] != 'Y'))) {
					$order['need_shipping'] = true;
				}

				// Adds flag that defines if product page is available
				$order['items'][$k]['is_accessible'] = fn_is_accessible_product($v);
			}

			if (Registry::get('settings.Suppliers.enable_suppliers') == 'Y') {
				$order['companies'] = fn_get_products_companies($order['items']);
				$order['have_suppliers'] = fn_check_companies_have_suppliers($order['companies']);
			} elseif (PRODUCT_TYPE == 'MULTIVENDOR') {
				$order['have_suppliers'] = empty($order['company_id']) ? 'N' : 'Y';
			}

			// Unserialize and collect taxes information
			if (!empty($additional_data['T'])) {
				$order['taxes'] = unserialize($additional_data['T']);
				if (is_array($order['taxes'])) {
					foreach ($order['taxes'] as  $tax_id => $tax_data) {
						foreach ($tax_data['applies'] as $_id => $value) {
							if (strpos($_id, 'P_') !== false && isset($deps[$_id])) {
								$order['items'][$deps[$_id]]['tax_value'] += $value;
								if ($tax_data['price_includes_tax'] != 'Y') {
									$order['items'][$deps[$_id]]['subtotal'] += $value;
									$order['items'][$deps[$_id]]['display_subtotal'] += (Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y') ? $value : 0;
									$order['tax_subtotal'] += $value;
								}
							}
							if (strpos($_id, 'S_') !== false && Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y') {
								if ($tax_data['price_includes_tax'] != 'Y') {
									$order['display_shipping_cost'] += $value;
								}
							}
						}
					}
				} else {
					$order['taxes'] = array();
				}
			}

			if (!empty($additional_data['C'])) {
				$order['coupons'] = unserialize($additional_data['C']);
			}

			if (!empty($additional_data['R'])) {
				$order['secondary_currency'] = unserialize($additional_data['R']);
			}

			// Recalculate subtotal
			$order['subtotal'] = $order['display_subtotal'] = 0;
			foreach ($order['items'] as $v) {
				$order['subtotal'] += $v['subtotal'];
				$order['display_subtotal'] += $v['display_subtotal'];
			}

			// Unserialize and collect payment information
			if (!empty($additional_data['P'])) {
				$order['payment_info'] = unserialize(fn_decrypt_text($additional_data['P']));
			}

			if (empty($order['payment_info']) || !is_array($order['payment_info'])) {
				$order['payment_info'] = array();
			}

			// Get shipping information
			if (!empty($additional_data['L'])) {
				$order['shipping'] = unserialize($additional_data['L']);
				foreach ($order['shipping'] as $ship_id => $v) {
					$shipping_name = fn_get_shipping_name($ship_id, $lang_code);
					if ($shipping_name) {
						$order['shipping'][$ship_id]['shipping'] = $shipping_name;
					}
				}
			}

			$order['doc_ids'] = db_get_hash_single_array("SELECT type, doc_id FROM ?:order_docs WHERE order_id = ?i", array('type', 'doc_id'), $order_id);
		}

		fn_set_hook('get_order_info', $order, $additional_data);

		return $order;
	}

	return false;
}

/**
 * Checks if product is currently accessible for viewing
 *
 * @param array $product Product data
 * @return boolean Flag that defines if product is accessible
 */
function fn_is_accessible_product($product)
{
	$result = false;

	$status = db_get_field('SELECT status FROM ?:products WHERE product_id = ?i', $product['product_id']);
	if (!empty($status) && $status != "D") {
		$result = true;
	}

	/**
	 * Changes result of product accessability checking
	 * @param array $product Product data
	 * @param boolean $result Flag that defines if product is accessible
	 */
	fn_set_hook('is_accessible_product_post', $product, $result);

	return $result;
}

//
// Get order short info
//
function fn_get_order_short_info($order_id)
{
	if (!empty($order_id)) {
		$order = db_get_row("SELECT total, status, firstname, lastname, timestamp FROM ?:orders WHERE order_id = ?i", $order_id);

		return $order;
	}

	return false;
}

/**
 * Change order status
 *
 * @param int $order_id Order identifier
 * @param string $status_to New order status (one char)
 * @param string $status_from Old order status (one char)
 * @param array $force_notification Array with notification rules
 * @param boolean $place_order True, if this function have been called inside of fn_place_order function.
 * @return boolean
 */
function fn_change_order_status($order_id, $status_to, $status_from = '', $force_notification = array(), $place_order = false)
{
	$order_info = fn_get_order_info($order_id, true);

	if (defined('CART_LOCALIZATION') && $order_info['localization_id'] && CART_LOCALIZATION != $order_info['localization_id']) {
		Registry::get('view')->assign('localization', fn_get_localization_data(CART_LOCALIZATION));
	}

	$order_statuses = fn_get_statuses(STATUSES_ORDER, false, true);

	if (empty($status_from)) {
		$status_from = $order_info['status'];
	}

	if (empty($order_info) || empty($status_to) || $status_from == $status_to) {
		return false;
	}



	$_updated_ids = array();
	$_error = false;

	foreach ($order_info['items'] as $k => $v) {

		// Generate ekey if EDP is ordered
		if (!empty($v['extra']['is_edp']) && $v['extra']['is_edp'] == 'Y') {
			continue; // don't track inventory
		}

		// Update product amount if inventory tracking is enabled
		if (Registry::get('settings.General.inventory_tracking') == 'Y') {
			if ($order_statuses[$status_to]['inventory'] == 'D' && $order_statuses[$status_from]['inventory'] == 'I') {
				// decrease amount
				if (fn_update_product_amount($v['product_id'], $v['amount'], @$v['extra']['product_options'], '-') == false) {
					$status_to = 'B'; //backorder
					$_error = true;
					$msg = str_replace('[product]', fn_get_product_name($v['product_id']) . ' #' . $v['product_id'], fn_get_lang_var('low_stock_subj'));
					fn_set_notification('W', fn_get_lang_var('warning'), $msg);

					break;
				} else {
					$_updated_ids[] = $k;
				}
			} elseif ($order_statuses[$status_to]['inventory'] == 'I' && $order_statuses[$status_from]['inventory'] == 'D') {
				// increase amount
				fn_update_product_amount($v['product_id'], $v['amount'], @$v['extra']['product_options'], '+');
			}
		}
	}

	if ($_error) {
		if (!empty($_updated_ids)) {
			foreach ($_updated_ids as $id) {
				// increase amount
				fn_update_product_amount($order_info['items'][$id]['product_id'], $order_info['items'][$id]['amount'], @$order_info['items'][$id]['extra']['product_options'], '+');
			}
			unset($_updated_ids);
		}
		
		if ($status_from == $status_to) {
			return false;
		}
	}

	fn_set_hook('change_order_status', $status_to, $status_from, $order_info, $force_notification, $order_statuses, $place_order);

	if ($status_from == $status_to) {
		if (!empty($_updated_ids)) {
			foreach ($_updated_ids as $id) {
				// increase amount
				fn_update_product_amount($order_info['items'][$id]['product_id'], $order_info['items'][$id]['amount'], @$order_info['items'][$id]['extra']['product_options'], '+');
			}
			unset($_updated_ids);
		}

		return false;
	}
	
	fn_promotion_post_processing($status_to, $status_from, $order_info, $force_notification);

	// Log order status change
	fn_log_event('orders', 'status', array (
		'order_id' => $order_id,
		'status_from' => $status_from,
		'status_to' => $status_to,
	));

	if (!empty($order_statuses[$status_to]['appearance_type']) && ($order_statuses[$status_to]['appearance_type'] == 'I' || $order_statuses[$status_to]['appearance_type'] == 'C') && !db_get_field("SELECT doc_id FROM ?:order_docs WHERE type = ?s AND order_id = ?i", $order_statuses[$status_to]['appearance_type'], $order_id)) {
		$_data = array (
			'order_id' => $order_id,
			'type' => $order_statuses[$status_to]['appearance_type']
		);
		$order_info['doc_ids'][$order_statuses[$status_to]['appearance_type']] = db_query("INSERT INTO ?:order_docs ?e", $_data);
	}

	// Check if we need to remove CC info
	if (!empty($order_statuses[$status_to]['remove_cc_info']) && $order_statuses[$status_to]['remove_cc_info'] == 'Y' && !empty($order_info['payment_info'])) {
		fn_cleanup_payment_info($order_id, $order_info['payment_info'], true);
	}

	$edp_data = fn_generate_ekeys_for_edp(array('status_from' => $status_from, 'status_to' => $status_to), $order_info);
	$order_info['status'] = $status_to;

	fn_order_notification($order_info, $edp_data, $force_notification);

	db_query("UPDATE ?:orders SET status = ?s WHERE order_id = ?i", $status_to, $order_id);
	
	return true;
}

/**
 * Function delete order
 *
 * @param int $order_id
 */
function fn_delete_order($order_id)
{
	if (defined('COMPANY_ID') && PRODUCT_TYPE != 'ULTIMATE') {
		fn_company_access_denied_notification();
		return false;
	}

	// Log order deletion
	fn_log_event('orders', 'delete', array (
		'order_id' => $order_id,
	));

	fn_change_order_status($order_id, STATUS_INCOMPLETED_ORDER, '', fn_get_notification_rules(array(), false)); // incomplete to increase inventory

	fn_set_hook('delete_order', $order_id);

	db_query("DELETE FROM ?:new_orders WHERE order_id = ?i", $order_id);
	db_query("DELETE FROM ?:order_data WHERE order_id = ?i", $order_id);
	db_query("DELETE FROM ?:order_details WHERE order_id = ?i", $order_id);
	db_query("DELETE FROM ?:orders WHERE order_id = ?i", $order_id);
	db_query("DELETE FROM ?:product_file_ekeys WHERE order_id = ?i", $order_id);
	db_query("DELETE FROM ?:profile_fields_data WHERE object_id = ?i AND object_type='O'", $order_id);
	db_query("DELETE FROM ?:order_docs WHERE order_id = ?i", $order_id);

	// Delete shipments
	$shipment_ids = db_get_fields('SELECT shipment_id FROM ?:shipment_items WHERE order_id = ?i GROUP BY shipment_id', $order_id);
	db_query('DELETE FROM ?:shipments WHERE shipment_id IN (?a)', $shipment_ids);
	db_query('DELETE FROM ?:shipment_items WHERE order_id = ?i', $order_id);
}

/**
 * Function generate edp ekeys for email notification
 *
 * @param array $statuses order statuses
 * @param array $order_info order information
 * @param array $active_files array with file download statuses
 * @return array $edp_data
 */

function fn_generate_ekeys_for_edp($statuses, $order_info, $active_files = array())
{
	$edp_data = array();
	$order_statuses = fn_get_statuses(STATUSES_ORDER, false, true);

	foreach ($order_info['items'] as $v) {

		// Generate ekey if EDP is ordered
		if (!empty($v['extra']['is_edp']) && $v['extra']['is_edp'] == 'Y') {

			$activations = db_get_hash_single_array("SELECT activation_type, file_id FROM ?:product_files WHERE product_id = ?i", array('file_id', 'activation_type'), $v['product_id']);

			foreach ($activations as $file_id => $activation_type) {

				$send_notification = false;

				// Check if ekey already was generated for this file
				$_ekey = db_get_row("SELECT ekey, active, file_id, product_id, order_id, ekey FROM ?:product_file_ekeys WHERE file_id = ?i AND order_id = ?i", $file_id, $order_info['order_id']);
				if (!empty($_ekey)) {
					// If order status changed to "Processed"
					if (($activation_type == 'P') && !empty($statuses)) {
						if ($order_statuses[$statuses['status_to']]['inventory'] == 'D' && substr_count('O', $statuses['status_to']) == 0 && ($order_statuses[$statuses['status_from']]['inventory'] != 'D' || substr_count('O', $statuses['status_from']) > 0)) {
							$active_files[$v['product_id']][$file_id] = 'Y';
						} elseif (($order_statuses[$statuses['status_to']]['inventory'] != 'D' && substr_count('O', $statuses['status_from']) == 0 || substr_count('O', $statuses['status_to']) > 0) && $order_statuses[$statuses['status_from']]['inventory'] == 'D') {
							$active_files[$v['product_id']][$file_id] = 'N';
						}
					}

					if (!empty($active_files[$v['product_id']][$file_id])) {
						db_query('UPDATE ?:product_file_ekeys SET ?u WHERE file_id = ?i AND product_id = ?i AND order_id = ?i', array('active' => $active_files[$v['product_id']][$file_id]), $_ekey['file_id'], $_ekey['product_id'], $_ekey['order_id']);

						if ($active_files[$v['product_id']][$file_id] == 'Y' && $_ekey['active'] !== 'Y') {
							$edp_data[$v['product_id']]['files'][$file_id] = $_ekey;
						}
					}

				} else {
					$_data = array (
						'file_id' => $file_id,
						'product_id' => $v['product_id'],
						'ekey' => md5(uniqid(rand())),
						'ttl' => (TIME + (Registry::get('settings.General.edp_key_ttl') * 60 * 60)),
						'order_id' => $order_info['order_id'],
						'activation' => $activation_type
					);

					// Activate the file if type is "Immediately" or "After full payment" and order statuses is from "paid" group
					if ($activation_type == 'I' || !empty($active_files[$v['product_id']][$file_id]) && $active_files[$v['product_id']][$file_id] == 'Y' || ($activation_type == 'P' && $order_statuses[$statuses['status_to']]['inventory'] == 'D' && substr_count('O', $statuses['status_to']) == 0 && ($order_statuses[$statuses['status_from']]['inventory'] != 'D' || substr_count('O', $statuses['status_from']) > 0 ))) {
						$_data['active'] = 'Y';
						$edp_data[$v['product_id']]['files'][$file_id] = $_data;
					}

					db_query('REPLACE INTO ?:product_file_ekeys ?e', $_data);
				}

				if (!empty($edp_data[$v['product_id']]['files'][$file_id])) {
					$edp_data[$v['product_id']]['files'][$file_id]['file_size'] = db_get_field("SELECT file_size FROM ?:product_files WHERE file_id = ?i", $file_id);
					$edp_data[$v['product_id']]['files'][$file_id]['file_name'] = db_get_field("SELECT file_name FROM ?:product_file_descriptions WHERE file_id = ?i AND lang_code = ?s", $file_id, CART_LANGUAGE);
				}
			}
		}
	}

	return $edp_data;
}

/**
 * Updates order payment information
 * 
 * @param int $order_id 
 * @param array $pp_response Response from payment processor
 * @return boolean true
 */
function fn_update_order_payment_info($order_id, $pp_response)
{
	if (empty($order_id) || empty($pp_response) || !is_array($pp_response)) {
		return false;
	}

	$payment_info = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'P'", $order_id);
	if (!empty($payment_info)) {
		$payment_info = unserialize(fn_decrypt_text($payment_info));
	} else {
		$payment_info = array();
	}


	foreach ($pp_response as $k => $v) {
		$payment_info[$k] = $v;
	}

	$data = array (
		'data' => fn_encrypt_text(serialize($payment_info)),
		'order_id' => $order_id,
		'type' => 'P'
	);

	db_query("REPLACE INTO ?:order_data ?e", $data);
	
	$child_orders_ids = db_get_fields("SELECT order_id FROM ?:orders WHERE parent_order_id = ?i", $order_id);
	if (!empty($child_orders_ids)) {
		foreach ($child_orders_ids as $child_id) {
			fn_update_order_payment_info($child_id, $pp_response);
		}
	}

	return true;
}

//
// Get all shippings list
//
function fn_get_shippings($simple, $lang_code = CART_LANGUAGE)
{
	$conditions = '1';

	if (AREA == 'C') {
		$conditions .= " AND (" . fn_find_array_in_set($_SESSION['auth']['usergroup_ids'], 'a.usergroup_ids', true) . ")";
		$conditions .= " AND a.status = 'A'";
		$conditions .= fn_get_localizations_condition('a.localization');
	}

	if ($simple == true) {
		return db_get_hash_single_array("SELECT a.shipping_id, b.shipping FROM ?:shippings as a LEFT JOIN ?:shipping_descriptions as b ON a.shipping_id = b.shipping_id AND b.lang_code = ?s WHERE ?p ORDER BY a.position", array('shipping_id', 'shipping'), $lang_code, $conditions);
	} else {
		return db_get_array("SELECT a.shipping_id, a.min_weight, a.max_weight, a.position, a.status, b.shipping, b.delivery_time, a.usergroup_ids FROM ?:shippings as a LEFT JOIN ?:shipping_descriptions as b ON a.shipping_id = b.shipping_id AND b.lang_code = ?s WHERE ?p ORDER BY a.position", $lang_code, $conditions);
	}
}

/**
 * Gets all available rates for specific shipping
 * 
 * @param int $shipping_id Shipping identifier
 * @return array Array of rates if shipping identifier is not null; false otherwise
 */
function fn_get_shipping_rates($shipping_id)
{
	if (!empty($shipping_id)) {
		return db_get_array("SELECT rate_id, ?:shipping_rates.destination_id, destination FROM ?:shipping_rates LEFT JOIN ?:destination_descriptions ON ?:destination_descriptions.destination_id = ?:shipping_rates.destination_id AND ?:destination_descriptions.lang_code = ?s WHERE shipping_id = ?i", CART_LANGUAGE, $shipping_id);
	} else {
		return false;
	}
}

/**
 * Gets shipping name
 * 
 * @param int $shipping_id shipping identifier
 * @param string $lang_code 2-letter language code (e.g. 'EN', 'RU', etc.)
 * @return string Shipping name if shipping identifier is not null; false otherwise
 */
function fn_get_shipping_name($shipping_id, $lang_code = CART_LANGUAGE)
{
	if (!empty($shipping_id)) {
		return db_get_field("SELECT shipping FROM ?:shipping_descriptions WHERE shipping_id = ?i AND lang_code = ?s", $shipping_id, $lang_code);
	}

	return false;
}

//
// Get all taxes list
//
function fn_get_taxes($lang_code = '')
{
	if (empty($lang_code)) {
		$lang_code = CART_LANGUAGE;
	}

	return db_get_hash_array("SELECT a.*, b.tax FROM ?:taxes as a LEFT JOIN ?:tax_descriptions as b ON b.tax_id = a.tax_id AND b.lang_code = ?s ORDER BY a.priority", 'tax_id', $lang_code);
}

//
// Get tax name
//
function fn_get_tax_name($tax_id = 0, $lang_code = CART_LANGUAGE, $as_array = false)
{
	if (!empty($tax_id)) {
		if (!is_array($tax_id) && strpos($tax_id, ',') !== false) {
			$tax_id = explode(',', $tax_id);
		}
		if (is_array($tax_id) || $as_array == true) {
			return db_get_hash_single_array("SELECT tax_id, tax FROM ?:tax_descriptions WHERE tax_id IN (?n) AND lang_code = ?s", array('tax_id', 'tax'), $tax_id, $lang_code);
		} else {
			return db_get_field("SELECT tax FROM ?:tax_descriptions WHERE tax_id = ?i AND lang_code = ?s", $tax_id, $lang_code);
		}
	}

	return false;
}

//
// Get all rates for specific tax
//
function fn_get_tax_rates($tax_id, $destination_id = 0)
{
	if (empty($tax_id)) {
		return false;
	}
	return db_get_array("SELECT * FROM ?:tax_rates WHERE tax_id = ?i AND destination_id = ?i", $tax_id, $destination_id);
}

//
// Get selected taxes
//
function fn_get_set_taxes($taxes_set)
{
	if (empty($taxes_set)) {
		return false;
	}

	return db_get_hash_array("SELECT tax_id, address_type, priority, price_includes_tax, regnumber FROM ?:taxes WHERE tax_id IN (?n) AND status = 'A' ORDER BY priority", 'tax_id', explode(',', $taxes_set));
}

function fn_add_exclude_products(&$cart, &$auth)
{
	$subtotal = 0;
	$original_subtotal = 0;

	if (isset($cart['products']) && is_array($cart['products'])) {
		foreach($cart['products'] as $cart_id => $product) {
			if (empty($product['product_id'])) {
				continue;
			}

			if (isset($product['extra']['exclude_from_calculate'])) {
				if (empty($cart['order_id']) && !isset($cart['company_id'])) {
					unset($cart['products'][$cart_id]);
				}
			} else {
				if (!isset($product['product_options'])) {
					$product['product_options'] = array();
				}
				
				$product_subtotal = fn_apply_options_modifiers($product['product_options'], $product['price'], 'P', array(), array('product_data' => $product)) * $product['amount'];
				$original_subtotal += $product_subtotal;
				$subtotal += $product_subtotal - ((isset($product['discount'])) ? $product['discount'] : 0);
			}
		}
	}

	fn_set_hook('exclude_products_from_calculation', $cart, $auth, $original_subtotal, $subtotal);

}

//
// Calculate cart content
//
// options style:
// F - full
// S - skip selection
// I - info
// calculate_shipping:
// A - calculate all available methods
// E - calculate selected methods only (from cart[shipping])
// S - skip calculation

// Products prices definition
// base_price - price without options modifiers
// original_price - price without discounts (with options modifiers)
// price - price includes discount and taxes
// original_subtotal - original_price * product qty
// subtotal - price * product qty
// discount - discount for this product
// display_price - the displayed price (price does not use in the calculaton)
// display_subtotal - the displayed subtotal (price does not use in the calculaton)

// Cart prices definition
// shipping_cost - total shipping cost
// subtotal - sum (price * amount) of all products
// original_subtotal - sum (original_price * amount) of all products
// tax_subtotal - sum of all the tax values
// display_subtotal - the displayed subtotal (does not use in the calculaton)
// subtotal_discount - the order discount
// discount - sum of all products discounts (except subtotal_discount)
// total - order total

function fn_calculate_cart_content(&$cart, $auth, $calculate_shipping = 'A', $calculate_taxes = true, $options_style = 'F', $apply_cart_promotions = true)
{
	$shipping_rates = array();
	$cart_products = array();
	$cart['subtotal'] = $cart['display_subtotal'] = $cart['original_subtotal'] = $cart['amount'] = $cart['total'] = $cart['discount'] = $cart['tax_subtotal'] = 0;
	$cart['use_discount'] = false;
	$cart['shipping_required'] = false;
	$cart['shipping_failed'] = $cart['company_shipping_failed'] = false;
	$cart['stored_taxes'] = empty($cart['stored_taxes']) ? 'N': $cart['stored_taxes'];
	$cart['display_shipping_cost'] = $cart['shipping_cost'] = 0;
	$cart['coupons'] = empty($cart['coupons']) ? array() : $cart['coupons'];
	$cart['recalculate'] = isset($cart['recalculate']) ? $cart['recalculate'] : false;
	$cart['free_shipping'] = array();
	$cart['options_style'] = $options_style;

	fn_add_exclude_products($cart, $auth);

	if (isset($cart['products']) && is_array($cart['products'])) {

		$amount_totals = array();
		if (Registry::get('settings.General.disregard_options_for_discounts') == 'Y') {
			foreach ($cart['products'] as $k => $v) {
				if (!empty($amount_totals[$v['product_id']])) {
					$amount_totals[$v['product_id']] += $v['amount'];
				} else {
					$amount_totals[$v['product_id']] = $v['amount'];
				}
			}
		}

		// Collect product data
		foreach ($cart['products'] as $k => $v) {
			$cart['products'][$k]['amount_total'] = isset($amount_totals[$v['product_id']]) ? $amount_totals[$v['product_id']] : $v['amount'];

			$_cproduct = fn_get_cart_product_data($k, $cart['products'][$k], false, $cart, $auth);
			if (empty($_cproduct)) { // FIXME - for deleted products for OM
				unset($cart['products'][$k]);
				continue;
			}

			$cart_products[$k] = $_cproduct;
		}

		fn_set_hook('calculate_cart_items', $cart, $cart_products, $auth);
		
		// Apply cart promotions
		if ($apply_cart_promotions == true && $cart['subtotal'] >= 0 && empty($cart['order_id'])) {
			fn_promotion_apply('cart', $cart, $auth, $cart_products);
		}
		

		if (Registry::get('settings.Shippings.disable_shipping') == 'Y') {
			$cart['shipping_required'] = false;
		}

		// Apply shipping fee
		if ($calculate_shipping != 'S' && $cart['shipping_required'] == true) {

			if (defined('CACHED_SHIPPING_RATES') && $cart['recalculate'] == false && isset($cart['shipping_language']) && $cart['shipping_language'] == CART_LANGUAGE) {
				$shipping_rates = $_SESSION['shipping_rates'];
			} else {
				$shipping_rates = fn_calculate_shipping_rates($cart, $cart_products, $auth, ($calculate_shipping == 'E'));
			}
			
			if (!empty($cart['shipping']) || !empty($cart['calculate_shipping'])) {
				fn_apply_cart_shipping_rates($cart, $cart_products, $auth, $shipping_rates);
				fn_apply_stored_shipping_rates($cart);
			}
			
		} else {
			if (!empty($cart['shipping'])) {
				$cart['chosen_shipping'] = $cart['shipping'];
			}
			$cart['shipping'] = $shipping_rates = array();
			$cart['shipping_cost'] = 0;
		}

		$cart['display_shipping_cost'] = $cart['shipping_cost'];

		fn_set_hook('calculate_cart_taxes_pre', $cart, $cart_products, $shipping_rates, $calculate_taxes, $auth);

		// Calculate taxes
		if (($cart['subtotal'] + $cart['display_shipping_cost']) > 0 && $calculate_taxes == true && $auth['tax_exempt'] != 'Y') {
			fn_calculate_taxes($cart, $cart_products, $shipping_rates, $auth);
		} elseif ($cart['stored_taxes'] != 'Y') {
			$cart['taxes'] = $cart['tax_summary'] = array();
		}

		fn_set_hook('calculate_cart_taxes_post', $cart, $cart_products, $shipping_rates, $calculate_taxes, $auth);

		$cart['subtotal'] = $cart['display_subtotal'] = 0;

		fn_update_cart_data($cart, $cart_products);

		// Calculate totals
		foreach ($cart_products as $k => $v) {
			$_tax = (!empty($cart_products[$k]['tax_summary']) ? ($cart_products[$k]['tax_summary']['added'] / $v['amount']) : 0);
			$cart_products[$k]['display_price'] = $cart_products[$k]['price'] + (Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y' ? $_tax : 0);
			$cart_products[$k]['subtotal'] = $cart_products[$k]['price'] * $v['amount'];

			$cart_products[$k]['display_subtotal'] = $cart_products[$k]['display_price'] * $v['amount'];

			$cart['subtotal'] += $cart_products[$k]['subtotal'];
			$cart['display_subtotal'] += $cart_products[$k]['display_subtotal'];
			$cart['products'][$k]['display_price'] = $cart_products[$k]['display_price'];

			$cart['tax_subtotal'] += (!empty($cart_products[$k]['tax_summary']) ? ($cart_products[$k]['tax_summary']['added']) : 0);

			$cart['total'] += ($cart_products[$k]['price'] - 0) * $v['amount'];

			if (!empty($v['discount'])) {
				$cart['discount'] += $v['discount'] * $v['amount'];
			}
		}

		if (Registry::get('settings.General.tax_calculation') == 'subtotal') {
			$cart['tax_subtotal'] += (!empty($cart['tax_summary']['added']) ? ($cart['tax_summary']['added']) : 0);
		}

		$cart['subtotal'] = fn_format_price($cart['subtotal']);
		$cart['display_subtotal'] = fn_format_price($cart['display_subtotal']);

		$cart['total'] += $cart['tax_subtotal'];

		$cart['total'] = fn_format_price($cart['total'] + $cart['shipping_cost']);

		if (!empty($cart['subtotal_discount'])) {
			$cart['total'] -= ($cart['subtotal_discount'] < $cart['total']) ? $cart['subtotal_discount'] : $cart['total'];
		}
	}

	if (fn_check_suppliers_functionality ()) {
		$cart['companies'] = fn_get_products_companies($cart_products);
		$cart['have_suppliers'] = fn_check_companies_have_suppliers($cart['companies']);
		
		if (($calculate_shipping != 'S' && $cart['shipping_required'] == true) && (defined('ESTIMATION') || !empty($cart['shipping']) || !empty($cart['calculate_shipping']))) {
			fn_companies_apply_cart_shipping_rates($cart, $cart_products, $auth, $shipping_rates, false);
		}
	}

	fn_set_hook('calculate_cart', $cart, $cart_products, $auth, $calculate_shipping, $calculate_taxes, $apply_cart_promotions);

	$cart['recalculate'] = false;

	return array (
		$cart_products,
		$shipping_rates
	);
}

function fn_cart_is_empty($cart)
{
	$result = true;

	if (!empty($cart['products'])) {
		foreach ($cart['products'] as $v) {
			if (!isset($v['extra']['exclude_from_calculate']) && empty($v['extra']['parent'])) {
				$result = false;
				break;
			}
		}
	}

	fn_set_hook('is_cart_empty', $cart, $result);

	return $result;
}

/**
 * Calculate total cost of products in cart
 *
 * @param array $cart cart information
 * @param array $cart_products cart products
 * @param char $type S - cost for shipping, A - all, C - all, exception excluded from calculation
 * @return int products cost
 */
function fn_get_products_cost($cart, $cart_products, $type = 'S')
{
	$cost = 0;

	if (is_array($cart_products)) {
		foreach ($cart_products as $k => $v) {
			if ($type == 'S') {
				if (($v['is_edp'] == 'Y' && $v['edp_shipping'] != 'Y') || $v['free_shipping'] == 'Y' || fn_exclude_from_shipping_calculate($cart['products'][$k])) {
					continue;
				}
			} elseif ($type == 'C') {
				if (isset($v['exclude_from_calculate'])) {
					continue;
				}
			}
			if (isset($v['price'])) {
				$cost += $v['subtotal'];
			}
		}
	}

	return $cost;
}

/**
 * Calculate total weight of products in cart
 *
 * @param array $cart cart information
 * @param array $cart_products cart products
 * @param char $type S - weight for shipping, A - all, C - all, exception excluded from calculation
 * @return int products weight
 */
function fn_get_products_weight($cart, $cart_products, $type = 'S')
{
	$weight = 0;

	if (is_array($cart_products)) {
		foreach ($cart_products as $k => $v) {
			if ($type == 'S') {
				if (($v['is_edp'] == 'Y' && $v['edp_shipping'] != 'Y') || $v['free_shipping'] == 'Y' || fn_exclude_from_shipping_calculate($cart['products'][$k])) {
					continue;
				}
			} elseif ($type == 'C') {
				if (isset($v['exclude_from_calculate'])) {
					continue;
				}
			}

			if (isset($v['weight'])) {
				$weight += ($v['weight'] * $v['amount']);
			}
		}
	}

	return !empty($weight) ? sprintf("%.2f", $weight) : '0.01';
}

/**
 * Calculate total quantity of products in cart
 *
 * @param array $cart cart information
 * @param array $cart_products cart products
 * @param char $type S - quantity for shipping, A - all, C - all, exception excluded from calculation
 * @return int products quantity
 */
function fn_get_products_amount($cart, $cart_products, $type = 'S')
{
	$amount = 0;

	foreach ($cart_products as $k => $v) {
		if ($type == 'S') {
			if (($v['is_edp'] == 'Y' && $v['edp_shipping'] != 'Y') || $v['free_shipping'] == 'Y' || fn_exclude_from_shipping_calculate($cart['products'][$k])) {
				continue;
			}
		} elseif ($type == 'C') {
			if (isset($v['exclude_from_calculate'])) {
				continue;
			}
		}

		$amount += $v['amount'];
	}

	return $amount;
}

/**
 * Divide the products into a separate packages
 *
 * @param array $cart cart information
 * @param array $cart_products cart products
 * @return array products packages
 */
function fn_get_products_packages($cart, $cart_products)
{
	// Implode the same products but with the different options to one package
	$package_groups = array(
		'personal' => array(),
		'global' => array(
			'products' => array(),
			'amount' => 0,
		),
	);
	foreach ($cart_products as $cart_id => $product) {
		if (empty($product['shipping_params']) || (empty($product['shipping_params']['min_items_in_box']) && empty($product['shipping_params']['max_items_in_box']))) {
			if (!(($product['is_edp'] == 'Y' && $product['edp_shipping'] != 'Y') || $product['free_shipping'] == 'Y' || fn_exclude_from_shipping_calculate($cart['products'][$cart_id]))) {
				$package_groups['global']['products'][$cart_id] = $product['amount'];
				$package_groups['global']['amount'] += $product['amount'];
			}
			
		} else {
			if (!isset($package_groups['personal'][$product['product_id']])) {
				$package_groups['personal'][$product['product_id']] = array(
					'shipping_params' => $product['shipping_params'],
					'amount' => 0,
					'products' => array(),
				);
			}
			
			if (!(($product['is_edp'] == 'Y' && $product['edp_shipping'] != 'Y') || $product['free_shipping'] == 'Y' || fn_exclude_from_shipping_calculate($cart['products'][$cart_id]))) {
				$package_groups['personal'][$product['product_id']]['amount'] += $product['amount'];
				$package_groups['personal'][$product['product_id']]['products'][$cart_id] = $product['amount'];
			}
		}
	}
	
	// Divide the products into a separate packages
	$packages = array();
	
	if (!empty($package_groups['personal'])) {
		foreach ($package_groups['personal'] as $product_id => $package_products) {
			while ($package_products['amount'] > 0) {
				if (!empty($package_products['shipping_params']['min_items_in_box']) && $package_products['amount'] < $package_products['shipping_params']['min_items_in_box']) {
					list($_data, $package_size) = fn_get_package_by_amount($package_products['amount'], $package_products['products']);
					foreach ($_data as $cart_id => $amount) {
						$package_groups['global']['products'][$cart_id] = isset($package_groups['global']['products'][$cart_id]) ? $package_groups['global']['products'][$cart_id] : 0;
						$package_groups['global']['products'][$cart_id] += $amount;
						$package_groups['global']['amount'] += $amount;
					}
				} elseif (empty($package_products['shipping_params']['max_items_in_box'])) {
					list($_data, $package_size) = fn_get_package_by_amount($package_products['amount'], $package_products['products']);
					
					$packages[] = array(
						'shipping_params' => $package_products['shipping_params'],
						'products' => $_data,
						'amount' => $package_size,
					);
				} else {
					list($_data, $package_size) = fn_get_package_by_amount($package_products['shipping_params']['max_items_in_box'], $package_products['products']);
					$packages[] = array(
						'shipping_params' => $package_products['shipping_params'],
						'products' => $_data,
						'amount' => $package_size,
					);
				}
				
				// Decrease the current product amount in the global package groups
				foreach ($_data as $cart_id => $amount) {
					$package_products['products'][$cart_id] -= $amount;
				}
				$package_products['amount'] -= $package_size;
			}
		}
	}
	
	if (!empty($package_groups['global']['products'])) {
		$packages[] = $package_groups['global'];
	}
	
	// Calculate the package additional info (weight, cost)
	foreach ($packages as $package_id => &$package) {
		$package['weight'] = 0;
		$package['cost'] = 0;
		
		foreach ($package['products'] as $cart_id => $amount) {
			$package['weight'] += $cart_products[$cart_id]['weight'] * $amount;
			$package['cost'] += $cart_products[$cart_id]['price'] * $amount;
		}
		
		if ($package['weight'] == 0) {
			$package['weight'] = 0.1;
		}
	}
	
	return $packages;
}

function fn_get_package_by_amount($amount, $products)
{
	$return = array();
	$size = 0;
	
	foreach ($products as $cart_id => $product_amount) {
		if ($product_amount < $amount) {
			if ($product_amount == 0) {
				continue;
			}
			$return[$cart_id] = $product_amount;
			$amount -= $product_amount;
			$size += $product_amount;
		} else {
			if ($amount == 0) {
				continue;
			}
			$return[$cart_id] = $amount;
			$size += $amount;
			$amount = 0;
		}
		
		if ($amount <= 0) {
			break;
		}
	}
	
	return array($return, $size);
}

// Get Payment processor data
function fn_get_processor_data($payment_id)
{
	$pdata = db_get_row("SELECT processor_id, params FROM ?:payments WHERE payment_id = ?i", $payment_id);
	if (empty($pdata)) {
		return false;
	}

	$processor_data = db_get_row("SELECT * FROM ?:payment_processors WHERE processor_id = ?i", $pdata['processor_id']);
	$processor_data['params'] = unserialize($pdata['params']);

	$processor_data['currencies'] = (!empty($processor_data['currencies'])) ? explode(',', $processor_data['currencies']) : array();

	return $processor_data;
}


/**
 * Get processor data by processor script
 *
 * @param string $processor_script name of processor script
 * @return (array) processor data
 */
function fn_get_processor_data_by_name($processor_script)
{
	$processor_data = db_get_row("SELECT * FROM ?:payment_processors WHERE processor_script = ?s", $processor_script);

	return $processor_data;
}

/**
 * Get payment method by processor_id
 *
 * @param string $processor_id
 * @param string $lang_code
 * @return (array) payment methods which use this processor
 */
function fn_get_payment_by_processor($processor_id, $lang_code = CART_LANGUAGE)
{
	$payment_methods = db_get_hash_array("SELECT ?:payments.payment_id, ?:payments.a_surcharge, ?:payments.p_surcharge, ?:payments.payment_category, ?:payment_descriptions.*, ?:payment_processors.type AS processor_type, ?:payments.status FROM ?:payments LEFT JOIN ?:payment_descriptions ON ?:payments.payment_id = ?:payment_descriptions.payment_id AND ?:payment_descriptions.lang_code = ?s LEFT JOIN ?:payment_processors ON ?:payment_processors.processor_id = ?:payments.processor_id WHERE ?:payments.processor_id = ?i ORDER BY ?:payments.position", 'payment_id', $lang_code, $processor_id);

	return $payment_methods;
}

//
// Calculate shipping rate
//
function fn_calculate_shipping_rate($package, $rate_value)
{
	$rate_value = unserialize($rate_value);

	$base_cost = $package['C'];
	$shipping_cost = 0;

	foreach ($package as $type => $amount) {
		if (isset($rate_value[$type]) && is_array($rate_value[$type])) {
			$__rval = array_reverse($rate_value[$type], true);
			foreach ($__rval as $__amnt => $__data) {
				if ($__amnt < $amount) {
					/*if (!empty($__data['per_unit']) && $__data['per_unit'] == 'Y') {
					$__data['value'] = (($__data['type'] == 'F') ? $__data['value'] : ($base_cost * $__data['value'])/100) * $package[$type];
					}*/

					$shipping_cost += (($__data['type'] == 'F') ? $__data['value'] : ($base_cost * $__data['value'])/100) * ((!empty($__data['per_unit']) && $__data['per_unit'] == 'Y') ? $package[$type] : 1);
					break;
				}
			}
		}
	}

	fn_set_hook('calculate_shipping_rate', $shipping_cost, $package, $rate_value);
	
	return fn_format_price($shipping_cost);
}

//
// Calculate shipping rates based on cart data and user info
//
function fn_calculate_shipping_rates(&$cart, &$cart_products, $auth, $calculate_selected = false)
{
	$shipping_rates = array();

	$condition = '';
	if ($calculate_selected == true) {
		$shipping_ids = !empty($cart['shipping']) ? array_keys($cart['shipping']) : array();
		if (!empty($shipping_ids)) {
			$condition = db_quote(" AND a.shipping_id IN (?n)", $shipping_ids);
		} else {
			return array();
		}
	}

	$condition .= fn_get_localizations_condition('a.localization');

	$location = fn_get_customer_location($auth, $cart);
	$destination_id = fn_get_available_destination($location);


	$package_infos = fn_prepare_package_info($cart, $cart_products);
	
	if (PRODUCT_TYPE == 'ULTIMATE' && defined('COMPANY_ID')) {
		// Get all available shippings. Auto sharing functionality will not allow to select "not shared" shippings for selected company.
		$shippings = db_get_fields('SELECT shipping_id FROM ?:shippings WHERE status = ?s', 'A');
		$company_shippings[COMPANY_ID] = implode(',', $shippings);
	} else {
		$company_shippings = db_get_hash_single_array("SELECT company_id, shippings FROM ?:companies WHERE company_id IN (?a)", array('company_id', 'shippings'), array_keys($package_infos));
	}
	
	foreach ($package_infos as $o_id => $package_info) {
		
		$c = fn_get_company_condition('a.company_id', false, $o_id, false, true);
		
		if (!empty($company_shippings[$o_id])) {
			if (trim($c)) {
				$c = "$c OR ";
			}
			$c .= db_quote('a.shipping_id IN (?n)', explode(',', $company_shippings[$o_id]));
			$c = "($c)";
		}
		if (trim($c)) {
			$c = " AND $c";
		}
		
		//TODO select companies shippings
		fn_set_hook('calculate_shipping_rates', $c, $o_id, $package_info);

		if (AREA == 'C') {
			$condition .= " AND (" . fn_find_array_in_set($auth['usergroup_ids'], 'a.usergroup_ids', true) . ")";
		}

		$shipping_methods = db_get_hash_array(
			"SELECT a.shipping_id, a.rate_calculation, a.service_id, a.params, a.position, b.shipping as name, b.delivery_time "
			. "FROM ?:shippings as a LEFT JOIN ?:shipping_descriptions as b ON a.shipping_id = b.shipping_id AND b.lang_code = ?s "
			. "WHERE (a.min_weight <= ?d AND (a.max_weight >= ?d OR a.max_weight = 0.00)) AND a.status = 'A' ?p ?p "
			. "ORDER BY a.position", 
			'shipping_id', CART_LANGUAGE, $package_info['W'], $package_info['W'], $condition, $c
		);
		
		if (empty($shipping_methods)) {
			continue;
		}

		$found_rates = array();

		if (function_exists('curl_multi_init') && fn_check_curl()) {
			$allow_multithreading = true;
			$h_curl_multi = curl_multi_init();
			$threads = array();
		} else {
			$allow_multithreading = false;
		}
		
		$base_package = $package_info;
		$base_general_package = array();
		
		// Find the general product package (if exists)
		if (!empty($package_info['packages'])) {
			foreach ($package_info['packages'] as $package_id => $package) {
				if (empty($package['shipping_params'])) {
					$base_general_package = $package;
					unset($base_package['packages'][$package_id]);
					
					break;
				}
			}
		}
		
		foreach ($shipping_methods as $k => $method) {
			if (!empty($method['params'])) {
				$method['params'] = unserialize($method['params']);
			}
			
			// Prepare package for the current shipping method
			$package_info = $base_package;
			$general_package = $base_general_package;
			
			if (!empty($method['params']['max_weight_of_box']) && !empty($general_package['products'])) {
				$package = array();
				while (count($general_package['products']) > 0) {
					if (empty($package)) {
						$package = array(
							'products' => array(),
							'amount' => 0,
							'weight' => 0,
							'cost' => 0,
						);
					}
					
					foreach ($general_package['products'] as $cart_id => $amount) {
						// Check, if the product have weight more than package weight. Pack it into a personal package
						if ($cart_products[$cart_id]['weight'] >= $method['params']['max_weight_of_box'] && empty($package['products'])) {
							$package = array(
								'products' => array(
									$cart_id => 1,
								),
								'amount' => 1,
								'weight' => $cart_products[$cart_id]['weight'],
								'cost' => $cart_products[$cart_id]['subtotal'],
							);
							
							$general_package['products'][$cart_id]--;
							
							if ($general_package['products'][$cart_id] == 0) {
								unset($general_package['products'][$cart_id]);
							}
							
							break;
						}
						
						if ($cart_products[$cart_id]['weight'] <= $method['params']['max_weight_of_box'] && (($cart_products[$cart_id]['weight'] + $package['weight']) <= $method['params']['max_weight_of_box'])) {
							while ($general_package['products'][$cart_id] > 0) {
								if (($cart_products[$cart_id]['weight'] + $package['weight']) <= $method['params']['max_weight_of_box']) {
									isset($package['products'][$cart_id]) ? $package['products'][$cart_id]++ : $package['products'][$cart_id] = 1;
									$package['weight'] += $cart_products[$cart_id]['weight'];
									$package['amount']++;
									$package['cost'] += $cart_products[$cart_id]['subtotal'];
								} else {
									break;
								}
								$general_package['products'][$cart_id]--;
							}
							
							if ($general_package['products'][$cart_id] == 0) {
								unset($general_package['products'][$cart_id]);
							}
						}
					}
					
					if ($package['weight'] == 0) {
						$package['weight'] = 0.1;
					}
					
					$package_info['packages'][] = $package;
					$package = array();
				}
				
			} else {
				if (!empty($general_package['products'])) {
					$package_info['packages'][] = $general_package;
				}
			}
			
			if (!empty($package_info['has_free_shipping'])) {
				// Paskage does not need the shipping rate
				$found_rates[$method['shipping_id']] = 0;
				
			} elseif ($method['rate_calculation'] == 'M') {
				// Manual rate calculation
				if ($destination_id !== false) {
					$rate_data = db_get_row("SELECT rate_id, rate_value FROM ?:shipping_rates WHERE shipping_id = ?i AND destination_id = ?i", $method['shipping_id'], $destination_id);

					if (!empty($rate_data)) {
						$found_rates[$method['shipping_id']] = fn_calculate_shipping_rate($package_info, $rate_data['rate_value']);
					}
				}

			} else {
				// Realtime rate calculation
				
				$charge = db_get_field("SELECT rate_value FROM ?:shipping_rates WHERE shipping_id = ?i AND destination_id = 0", $method['shipping_id']);
				$rate_data = fn_calculate_realtime_shipping_rate($method['service_id'], $location, $package_info, $auth, $method['shipping_id'], $allow_multithreading, array(), $o_id);

				if ($rate_data !== false) {
					if ($allow_multithreading && false === array_key_exists('cost', $rate_data)) {
						$threads[$k] = $rate_data;
						$threads[$k][3] = $method['shipping_id'];
						$threads[$k][4] = $charge;
						curl_multi_add_handle($h_curl_multi, $threads[$k][0]);
					} else {
						$found_rates[$method['shipping_id']] = $rate_data['cost'];
						$found_rates[$method['shipping_id']] += fn_calculate_shipping_rate($package_info, $charge);
					}
				}
			}
			
			// Save prepeared packages for the current shipping method
			$shipping_packages[$method['shipping_id']] = $package_info;
		}

		if (false === empty($threads)) {

			// Launch the jobs pool
			// FIXME: we must use fn_http(s)_request instead of this code!
			
			do {
				$status = curl_multi_exec($h_curl_multi, $active);
				$info = curl_multi_info_read($h_curl_multi);
			} while ($status === CURLM_CALL_MULTI_PERFORM || $active);

			foreach ($threads as $k => $thread) {
				$res[$k] = curl_multi_getcontent($threads[$k][0]);
				$request_info = curl_getinfo($threads[$k][0]);
				
				curl_close($threads[$k][0]);

				if (!isset($threads[$k][2])) {
					$threads[$k][2] = array();
				} elseif (!is_array($threads[$k][2])) {
					$threads[$k][2] = array($threads[$k][2]);
				}
				array_unshift($threads[$k][2], '200 OK', $res[$k]);

				$rate_data = call_user_func_array($threads[$k][1], $threads[$k][2]);

				if ($rate_data !== false) {
					$found_rates[$threads[$k][3]] = $rate_data['cost'];
					$found_rates[$threads[$k][3]] += fn_calculate_shipping_rate($package_info, $threads[$k][4]);
				}
				
				// Prepare log info. FIXME: use fn_http(s)_request instead of this code!
				(strpos($request_info['url'], '?') != false) ? list($url, $data) = explode('?', $request_info['url']) : $url = $request_info['url'];
				$_data = array();
				if (!empty($data)) {
					$data = explode('&', $data);
					foreach ($data as $part) {
						list($key, $value) = explode('=', $part);
						$_data[$key] = urldecode($value);
					}
				}

				unset($data);

				fn_log_event('requests', 'http', array(
					'url' => $url,
					'data' => var_export($_data, true),
					'response' => $res[$k],
				));
			}

			curl_multi_close($h_curl_multi);
		}

		$shipping_freight = 0;
		foreach ($cart_products as $v) {
			if (($v['is_edp'] != 'Y' || ($v['is_edp'] == 'Y' && $v['edp_shipping'] == 'Y')) && $v['free_shipping'] != 'Y') {
				$shipping_freight += ($v['shipping_freight'] * $v['amount']);
			}
		}
		
		foreach ($found_rates as $shipping_id => $rate_value) {
			if (!isset($shipping_rates[$shipping_id])) {
				$shipping_rates[$shipping_id]['name'] = $shipping_methods[$shipping_id]['name'];
				$shipping_rates[$shipping_id]['delivery_time'] = $shipping_methods[$shipping_id]['delivery_time'];
				$shipping_rates[$shipping_id]['position'] = $shipping_methods[$shipping_id]['position'];
			}

			$shipping_rates[$shipping_id]['rates'][$o_id] = $rate_value + $shipping_freight;
			
			if (!empty($shipping_packages[$shipping_id])) {
				$shipping_rates[$shipping_id]['packages_info'] = $shipping_packages[$shipping_id];
			}
		}
	}
	
	$shipping_rates = fn_sort_array_by_key($shipping_rates, 'position', SORT_ASC);
	
	// Unset the unnecessary packages info from the manual calculated shipping methods
	if (!empty($shipping_rates)) {
		foreach ($shipping_rates as $shipping_id => $rate) {
			if (isset($shipping_methods[$shipping_id]) && $shipping_methods[$shipping_id]['rate_calculation'] == 'M') {
				unset($shipping_rates[$shipping_id]['packages_info']['packages']);
			}
		}
	}

	// Set cart language
	$cart['shipping_language'] = CART_LANGUAGE;
	
	return $shipping_rates;
}

//
// Returns customer location or default location
//
function fn_get_customer_location($auth, $cart, $billing = false)
{

	$s_info = array();
	$prefix = 's';
	if ($billing == true) {
		$prefix = 'b';
	}

	$fields = array (
		'country',
		'state',
		'city',
		'zipcode',
		'address',
		'address_2',
	);

	$u_info = (!empty($cart['user_data'])) ? $cart['user_data'] : ((empty($cart['user_data']) && !empty($auth['user_id'])) ? fn_get_user_info($auth['user_id'], true, $cart['profile_id']) : array());

	// Fill basic fields
	foreach ($fields as $field) {
		$s_info[$field] = !empty($u_info[$prefix . '_' . $field]) ? $u_info[$prefix . '_' . $field] : Registry::get("settings.General.default_$field");
	}

	// Add phone
	$s_info['phone'] = !empty($u_info['phone']) ? $u_info['phone'] : Registry::get('settings.General.default_phone');

	// Add residential address flag
	$s_info['address_type'] = (!empty($u_info['s_address_type'])) ? $u_info['s_address_type'] : 'residential';

	// Get First and Last names
	$u_info['firstname'] = !empty($u_info['firstname']) ? $u_info['firstname'] : 'John';
	$u_info['lastname'] = !empty($u_info['lastname']) ? $u_info['lastname'] : 'Doe';

	if ($prefix == 'b') {
		$s_info['firstname'] = (!empty($u_info['b_firstname'])) ? $u_info['b_firstname'] : $u_info['firstname'];
		$s_info['lastname'] = (!empty($u_info['b_lastname'])) ? $u_info['b_lastname'] : $u_info['lastname'];
	} else {
		$s_info['firstname'] = (!empty($u_info['s_firstname'])) ? $u_info['s_firstname'] : (!empty($u_info['b_firstname']) ? $u_info['b_firstname'] : $u_info['firstname']);
		$s_info['lastname'] = (!empty($u_info['s_lastname'])) ? $u_info['s_lastname'] : (!empty($u_info['b_lastname']) ? $u_info['b_lastname'] : $u_info['lastname']);
	}

	// Get country/state descriptions
	$avail_country = db_get_field("SELECT COUNT(*) FROM ?:countries WHERE code = ?s AND status = 'A'", $s_info['country']);
	if (empty($avail_country)) {
		return array();
	}

	$avail_state = db_get_field("SELECT COUNT(*) FROM ?:states WHERE country_code = ?s AND code = ?s AND status = 'A'", $s_info['country'], $s_info['state']);
	if (empty($avail_state)) {
		$s_info['state'] = '';
	}
	
	return $s_info;
}

/**
 * Calculate products and shipping taxes
 *
 * @param array $cart cart data
 * @param array $cart_products products data
 * @param array $shipping_rates
 * @param array $auth auth data
 * @return boolean always false
 */
function fn_calculate_taxes(&$cart, &$cart_products, &$shipping_rates, $auth)
{
	$calculated_data = array();
	
	if (Registry::get('settings.General.tax_calculation') == 'unit_price') {
		// Tax calculation method based on UNIT PRICE
		
		// Calculate product taxes
		foreach ($cart_products as $k => $product) {
			$taxes = fn_get_product_taxes($k, $cart, $cart_products);
			
			if (empty($taxes)) {
				continue;
			}

			if (isset($product['subtotal'])) {
				if ($product['price'] == $product['subtotal'] && $product['amount'] != 1) {
					$price = fn_format_price($product['price']);
				} else {
					$price = fn_format_price($product['subtotal'] / $product['amount']);
				}
				
				$calculated_data['P_' . $k] = fn_calculate_tax_rates($taxes, $price, $product['amount'], $auth, $cart);

				$cart_products[$k]['tax_summary'] = array('included' => 0, 'added' => 0, 'total' => 0); // tax summary for 1 unit of product

				// Apply taxes to product subtotal
				if (!empty($calculated_data['P_' . $k])) {
					foreach ($calculated_data['P_' . $k] as $_k => $v) {
						$cart_products[$k]['taxes'][$_k] = $v;
						if ($taxes[$_k]['price_includes_tax'] != 'Y') {
							$cart_products[$k]['tax_summary']['added'] += $v['tax_subtotal'];
						} else {
							$cart_products[$k]['tax_summary']['included'] += $v['tax_subtotal'];
						}
					}
					$cart_products[$k]['tax_summary']['total'] = $cart_products[$k]['tax_summary']['added'] + $cart_products[$k]['tax_summary']['included'];
				}
			}
		}
		
		// Calculate shipping taxes
		if (!empty($shipping_rates)) {
			foreach ($shipping_rates as $shipping_id => $shipping) {
				$taxes = fn_get_shipping_taxes($shipping_id, $shipping_rates, $cart);
				
				if (!empty($taxes)) {

					$shipping_rates[$shipping_id]['taxes'] = array();
					
					$calculate_rate = true;
					
					if (!empty($cart['shipping'][$shipping_id])) {
						foreach ($cart['shipping'][$shipping_id]['rates'] as $k => $v) {
							$calculated_data['S_' . $shipping_id . '_' . $k] = fn_calculate_tax_rates($taxes, $v, 1, $auth, $cart);
							
							if (!empty($calculated_data['S_' . $shipping_id . '_' . $k])) {
								foreach ($calculated_data['S_' . $shipping_id . '_' . $k] as $__k => $__v) {
									if ($taxes[$__k]['price_includes_tax'] != 'Y') {
										$cart['display_shipping_cost'] += Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y' ? $__v['tax_subtotal'] : 0;
										$cart['tax_subtotal'] += $__v['tax_subtotal'];
									}
									

									if ($cart['stored_taxes'] == 'Y') {
										$cart['taxes'][$__k]['applies']['S_' . $shipping_id . '_' . $k] = $__v['tax_subtotal'];
									}
								}
								
								$shipping_rates[$shipping_id]['taxes']['S_' . $shipping_id . '_' . $k] = $calculated_data['S_' . $shipping_id . '_' . $k];
								$calculate_rate = false;
							}
						}
					}

					if ($calculate_rate) {
						foreach ($shipping['rates'] as $k => $v) {
							if (isset($shipping['rates'][$k])) {
								$cur_shipping_rates = fn_calculate_tax_rates($taxes, $v, 1, $auth, $cart);
								if (!empty($cur_shipping_rates)) {
									$shipping_rates[$shipping_id]['taxes']['S_' . $shipping_id . '_' . $k] = $cur_shipping_rates;
								}
							}
						}
					}
				}
			}

			foreach ($shipping_rates as $shipping_id => $shipping) {
				// Calculate taxes for each shipping rate
				$taxes = fn_get_shipping_taxes($shipping_id, $shipping_rates, $cart);

				$shipping_rates[$shipping_id]['taxed_price'] = 0; 
				unset($shipping_rates[$shipping_id]['inc_tax']);
				
				if (!empty($taxes)) {
					$shipping_rates[$shipping_id]['taxes'] = array();
					
					foreach ($shipping['rates'] as $k => $v) {
						$tax = fn_calculate_tax_rates($taxes, fn_format_price($v), 1, $auth, $cart);
						
						$shipping_rates[$shipping_id]['taxes']['S_' . $shipping_id . '_' . $k] = $tax;
						
						if (!empty($tax) && Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y') {
							foreach ($tax as $_id => $_tax) {
								if ($_tax['price_includes_tax'] != 'Y') {
									$shipping_rates[$shipping_id]['taxed_price'] += $_tax['tax_subtotal'];
								}
							}
							$shipping_rates[$shipping_id]['inc_tax'] = true;
						}
					}
					
					if (!empty($shipping_rates[$shipping_id]['rates']) && $shipping_rates[$shipping_id]['taxed_price'] > 0) {
						$shipping_rates[$shipping_id]['taxed_price'] += array_sum($shipping_rates[$shipping_id]['rates']);
					}
				}
			}
		}
		
	} else {
		// Tax calculation method based on SUBTOTAL
		
		// Calculate discounted subtotal
		if (!isset($cart['subtotal_discount'])) {
			$cart['subtotal_discount'] = 0;
		}
		$discounted_subtotal = $cart['subtotal'] - $cart['subtotal_discount'];
		
		if ($discounted_subtotal < 0) {
			$discounted_subtotal = 0;
		}

		// Get discount distribution coefficient (DDC) between taxes
		if ($cart['subtotal'] > 0) {
			$ddc = $discounted_subtotal / $cart['subtotal'];
		} else {
			$ddc = 1;
		}
		
		//
		// Group subtotal by taxes
		//
		$subtotal = array();
		
		// Get products taxes
		foreach ($cart_products as $k => $product) {
			$taxes = fn_get_product_taxes($k, $cart, $cart_products);
			
			if (!empty($taxes)) {
				foreach ($taxes as $tax_id => $tax) {
					if (empty($subtotal[$tax_id])) {
						$subtotal[$tax_id] = fn_init_tax_subtotals($tax);
					}
					
					$_subtotal = ($product['price'] == $product['subtotal'] && $product['amount'] != 1) ? fn_format_price($product['price'] * $product['amount']) : $product['subtotal'];
					
					$subtotal[$tax_id]['subtotal'] += $_subtotal;
					$subtotal[$tax_id]['applies']['P'] += $_subtotal;
					$subtotal[$tax_id]['applies']['items']['P'][$k] = true;
					
					if (isset($product['company_id'])) {
						if (!isset($subtotal[$tax_id]['companies'][$product['company_id']])) {
							$subtotal[$tax_id]['companies'][$product['company_id']]['products'] = 0;
						}
						$subtotal[$tax_id]['companies'][$product['company_id']]['products'] += $_subtotal;
						$priority_stack['products'][$product['company_id']] = -1;
						$applied_taxes['products'][$product['company_id']] = 0;
					}
				}
			}
		}

		// Get shipping taxes
		if (!empty($shipping_rates)) {
			foreach ($shipping_rates as $shipping_id => $shipping) {
				// Calculate taxes for each shipping rate
				$taxes = fn_get_shipping_taxes($shipping_id, $shipping_rates, $cart);

				$shipping_rates[$shipping_id]['taxed_price'] = 0; 
				unset($shipping_rates[$shipping_id]['inc_tax']);
				
				// Display shipping with taxes at cart/checkout page
				if (!empty($taxes)) {
					$shipping_rates[$shipping_id]['taxes'] = array();
					
					foreach ($shipping['rates'] as $k => $v) {
						$tax = fn_calculate_tax_rates($taxes, fn_format_price($v), 1, $auth, $cart);
						$shipping_rates[$shipping_id]['taxes']['S_' . $shipping_id . '_' . $k] = $tax;
						
						if (!empty($tax) && Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y') {
							foreach ($tax as $_id => $_tax) {
								if ($_tax['price_includes_tax'] != 'Y') {
									$shipping_rates[$shipping_id]['taxed_price'] += $_tax['tax_subtotal'];
								}
							}
							$shipping_rates[$shipping_id]['inc_tax'] = true;
						}
					}
					
					if (!empty($shipping_rates[$shipping_id]['rates']) && $shipping_rates[$shipping_id]['taxed_price'] > 0) {
						$shipping_rates[$shipping_id]['taxed_price'] += array_sum($shipping_rates[$shipping_id]['rates']);
					}
				}
				
				if (!isset($cart['shipping'][$shipping_id])) {
					continue;
				}

				// Add shipping taxes to "tax" array
				if (!empty($taxes)) {
					foreach ($taxes as $tax_id => $tax) {
						if (empty($subtotal[$tax_id])) {
							$subtotal[$tax_id] = fn_init_tax_subtotals($tax);
						}
						
						$subtotal[$tax_id]['subtotal'] += array_sum($cart['shipping'][$shipping_id]['rates']);
						$subtotal[$tax_id]['applies']['S'] += array_sum($cart['shipping'][$shipping_id]['rates']);
						$subtotal[$tax_id]['applies']['items']['S'][$shipping_id] = true;

						foreach ($cart['shipping'][$shipping_id]['rates'] as $company_id => $rate) {
							if (!isset($subtotal[$tax_id]['companies'][$company_id]['shippings'])) {
								$subtotal[$tax_id]['companies'][$company_id]['shippings'] = 0;
							}

							$subtotal[$tax_id]['companies'][$company_id]['shippings'] += $rate;
							$priority_stack['shippings'][$company_id] = -1;
							$applied_taxes['shippings'][$company_id] = 0;
						}
					}
				}
			}
		}
		
		if (!empty($subtotal)) {
			$subtotal = fn_sort_array_by_key($subtotal, 'priority');
		}

		// Apply DDC and calculate tax rates
		$calculated_taxes = array();
		
		if (empty($priority_stack)) {
			$priority_stack['products'][0] = -1;
			$priority_stack['shippings'][0] = -1;
			$applied_taxes['products'][0] = 0;
			$applied_taxes['shippings'][0] = 0;
		}
		
		foreach ($subtotal as $tax_id => $_st) {
			if (empty($_st['tax_id'])) {
				$_st['tax_id'] = $tax_id;
			}
			
			$product_tax = fn_calculate_tax_rates(array($_st), fn_format_price($_st['applies']['P'] * $ddc), 1, $auth, $cart);
			$shipping_tax = fn_calculate_tax_rates(array($_st), fn_format_price($_st['applies']['S']), 1, $auth, $cart);
			
			if (empty($product_tax) && empty($shipping_tax)) {
				continue;
			}
			
			if (empty($_st['companies'])) {
				$_st['companies'][0]['products'] = $_st['applies']['P'];
				$_st['companies'][0]['shippings'] = $_st['applies']['S'];
			}
			
			foreach ($_st['companies'] as $company_id => $applies) {
				$apply_tax_stack = array(
					'products' => 0,
					'shippings' => 0,
				);
				
				if (!isset($priority_stack['products'][$company_id])) {
					$priority_stack['products'][$company_id] = -1;
				}
				if (!isset($priority_stack['shippings'][$company_id])) {
					$priority_stack['shippings'][$company_id] = -1;
				}
				
				if ($priority_stack['products'][$company_id] < 0 && !empty($applies['products'])) {
					$priority_stack['products'][$company_id] = $_st['priority'];

				} elseif (!empty($applies['products']) && $priority_stack['products'][$company_id] != $_st['priority']) {
					$apply_tax_stack['products'] = $applied_taxes['products'][$company_id];
					$priority_stack['products'][$company_id] = $_st['priority'];
				}

				if ($priority_stack['shippings'][$company_id] < 0 && !empty($applies['shippings'])) {
					$priority_stack['shippings'][$company_id] = $_st['priority'];

				} elseif (!empty($applies['shippings']) && $priority_stack['shippings'][$company_id] != $_st['priority']) {
					$apply_tax_stack['shippings'] = $applied_taxes['shippings'][$company_id];
					$priority_stack['shippings'][$company_id] = $_st['priority'];
				}
				
				if (empty($calculated_data[$tax_id])) {
					$calculated_data[$tax_id] = empty($product_tax) ? reset($shipping_tax) : reset($product_tax);
				}

				if (!empty($applies['products'])) {
					$products_tax = fn_calculate_tax_rates(array($_st), fn_format_price($applies['products'] * $ddc + $apply_tax_stack['products']), 1, $auth, $cart);
				} else {
					$products_tax[$tax_id]['tax_subtotal'] = 0;
				}

				if (!empty($applies['shippings'])) {
					$shippings_tax = fn_calculate_tax_rates(array($_st), fn_format_price($applies['shippings'] + $apply_tax_stack['shippings']), 1, $auth, $cart);
				} else {
					$shippings_tax[$tax_id]['tax_subtotal'] = 0;
				}
				
				if (!isset($applied_taxes['products'][$company_id])) {
					$applied_taxes['products'][$company_id] = 0;
				}
				if (!isset($applied_taxes['shippings'][$company_id])) {
					$applied_taxes['shippings'][$company_id] = 0;
				}
				
				if ($_st['price_includes_tax'] != 'Y') {
					$applied_taxes['products'][$company_id] += $products_tax[$tax_id]['tax_subtotal'];
					$applied_taxes['shippings'][$company_id] += $shippings_tax[$tax_id]['tax_subtotal'];
				}

				if (!isset($calculated_data[$tax_id]['applies']['P'])) {
					$calculated_data[$tax_id]['applies']['P'] = 0;
				}
				if (!isset($calculated_data[$tax_id]['applies']['S'])) {
					$calculated_data[$tax_id]['applies']['S'] = 0;
				}
				$calculated_data[$tax_id]['applies']['P'] += $products_tax[$tax_id]['tax_subtotal'];
				$calculated_data[$tax_id]['applies']['S'] += $shippings_tax[$tax_id]['tax_subtotal'];
				$calculated_data[$tax_id]['tax_subtotal'] = $calculated_data[$tax_id]['applies']['P'] + $calculated_data[$tax_id]['applies']['S'];
			}
		}
	}
	
	/**
	 * Calculate payment taxes (running before applying products and shipping taxes to cart)
	 *
	 * @param array $calculated_data payment data taxes
	 * @param array $cart cart data
	 * @param array $cart_products products data
	 * @param array $shipping_rates
	 * @param array $auth auth data
	 */
	fn_set_hook('calculate_payment_taxes_post', $calculated_data, $cart, $cart_products, $shipping_rates, $auth);

	fn_apply_calculated_taxes($calculated_data, $cart);

	return false;
}

/**
 * Calculate payment surcharge taxes, calculated separately from products and shipping taxes
 * becuase payment surcharge is calculated based on cart totals.
 *
 * @param array $cart cart data
 * @param array $auth auth data
 * @return boolean always false
 */
function fn_calculate_payment_taxes(&$cart, $auth)
{
	if (PRODUCT_TYPE == 'MULTIVENDOR') {
		if (Registry::get('settings.Suppliers.include_payment_surcharge') == 'Y' && fn_take_payment_surcharge_from_vendor($cart['products'])) {
			return;
		}
	}
	$calculated_data = array();

	if (Registry::get('settings.General.tax_calculation') == 'unit_price') {
		// Tax calculation method based on UNIT PRICE

		if (!empty($cart['payment_id']) && !empty($cart['payment_surcharge'])) {
			$payment_id = $cart['payment_id'];
			$taxes = fn_get_payment_taxes($payment_id, $cart);

			if (!empty($taxes)) {
				$calculated_data['PS_' . $payment_id] = fn_calculate_tax_rates($taxes, fn_format_price($cart['payment_surcharge']), 1, $auth, $cart);

				if (!empty($calculated_data['PS_' . $payment_id])) {
					foreach ($calculated_data['PS_' . $payment_id] as $__k => $__v) {
						if ($taxes[$__k]['price_includes_tax'] != 'Y') {
							if (Registry::get('settings.Appearance.cart_prices_w_taxes') == 'Y') {
								$cart['payment_surcharge'] += $__v['tax_subtotal'];
							}
						}
					}
					$calculate_rate = false;
				}
			}
		}

	} else {
		if (!empty($cart['payment_id']) && !empty($cart['payment_surcharge'])) {
			$taxes = fn_get_payment_taxes($cart['payment_id'], $cart);
			$priority = 0;
			$calc_surcharge = $cart['payment_surcharge'];
			$taxed_surcharge =  $cart['payment_surcharge'];
			if (!empty($taxes)) {
				foreach ($taxes as $tax_id => $tax) {
					if ($tax['priority'] > $priority) {
						$calc_surcharge = $taxed_surcharge;
					}

					$calculated_tax = fn_calculate_tax_rates(array($tax), fn_format_price($calc_surcharge), 1, $auth, $cart);
					if (empty($calculated_tax[$tax_id])) {
						continue;
					}
					$calculated_data[$tax_id] = fn_init_tax_subtotals($calculated_tax[$tax_id]);

					$calculated_data[$tax_id]['tax_subtotal'] = $calculated_tax[$tax_id]['tax_subtotal'];
					$calculated_data[$tax_id]['applies']['PS'] = $calculated_tax[$tax_id]['tax_subtotal'];
					$calculated_data[$tax_id]['applies']['items']['PS'][$cart['payment_id']] = true;
					$taxed_surcharge += $calculated_tax[$tax_id]['tax_subtotal'];
				}
			}
		}

	}

	/**
	 * Calculate payment taxes (running before applying payment taxes to cart)
	 *
	 * @param array $calculated_data payment data taxes
	 * @param array $cart cart data
	 * @param array $auth auth data
	 */
	fn_set_hook('calculate_payment_taxes_post', $calculated_data, $cart, $auth);

	fn_apply_payment_taxes($calculated_data, $cart);

	return false;
}

/**
 * Apply payment surcharge taxes to cart, payment surcharge taxes calculated and applied 
 * separately from products and shipping taxes
 * cart taxes are supposed to keep shippings and products taxes
 *
 * @param array $calculated_data payment data taxes
 * @param array $cart cart data
 * @return boolean always true
 */
function fn_apply_payment_taxes($calculated_data, &$cart)
{
	$tax_added = 0;

	if (empty($cart['taxes'])) {
		$cart['taxes'] = array();
		$cart['tax_subtotal'] = 0;
	}
	if (!empty($calculated_data)) {
		if (Registry::get('settings.General.tax_calculation') == 'unit_price') {
			// Based on the unit price
			foreach ($calculated_data as $id => $_taxes) {
				if (empty($_taxes)) {
					continue;
				}
				foreach ($_taxes as $k => $v) {
					if (empty($cart['taxes'][$k])) {
						$cart['taxes'][$k] = $v;
						$cart['taxes'][$k]['tax_subtotal'] = 0;
					}
					$cart['taxes'][$k]['applies'][$id] = $v['tax_subtotal'];
					$cart['taxes'][$k]['tax_subtotal'] += $v['tax_subtotal'];

					if ($v['price_includes_tax'] == 'N') {
						if (Registry::get('settings.Appearance.cart_prices_w_taxes') != 'Y') {
							$tax_added += $v['tax_subtotal'];
						}
						$cart['tax_subtotal'] += $v['tax_subtotal'];
					}
				}
			}
		} else {
			if (empty($cart['tax_summary'])) {
				// Based on the order subtotal
				$cart['tax_summary'] = array(
					'included' => 0,
					'added' => 0,
					'total' => 0
				);
			}

			foreach ($calculated_data as $tax_id => $v) {
				if (!empty($cart['taxes'][$tax_id])) {
					$cart['taxes'][$tax_id]['applies']['PS'] =  $v['applies']['PS'];
					$cart['taxes'][$tax_id]['applies']['items']['PS'] =  $v['applies']['items']['PS'];
					$cart['taxes'][$tax_id]['tax_subtotal'] += $v['tax_subtotal'];
				} else {
					$cart['taxes'][$tax_id] = $v;
				}
				
				if ($v['price_includes_tax'] == 'Y') {
					$cart['tax_summary']['included'] += $v['tax_subtotal'];
				} else {
					$cart['tax_summary']['added'] += $v['tax_subtotal'];
					$tax_added += $v['tax_subtotal'];
				}
				
				$cart['tax_summary']['total'] += $v['tax_subtotal'];
			}
		}
	}
	if (!empty($tax_added)) {
		$cart['total'] = fn_format_price($cart['total'] + $tax_added);
	}

	/**
	 * Apply payment taxes (running after fn_apply_payment_taxes function)
	 *
	 * @param array $calculated_data payment data taxes
	 * @param array $cart cart data
	 */
	fn_set_hook('apply_payment_taxes_post', $calculated_data, $cart);

	return true;
}

/**
 * Init taxes array: add additional params to tax array for calculation
 *
 * @param array $tax base tax array
 * @return array array with inited params
 */
function fn_init_tax_subtotals($tax)
{
	$tax['subtotal'] = $tax['applies']['P'] = $tax['applies']['S'] = 0;
	$tax['applies']['items']['P'] = $tax['applies']['items']['S'] = array();

	/**
	 * Init tax subtotals (running after fn_init_tax_subtotals function)
	 *
	 * @param array $tax tax array
	 */
	fn_set_hook('init_tax_subtotals_post', $tax);
	return $tax;
}

function fn_get_product_taxes($idx, $cart, $cart_products)
{
	if ($cart['stored_taxes'] == 'Y') {
		$_idx = '';
		if (isset($cart['products'][$idx]['original_product_data']['cart_id'])) {
			$_idx = $cart['products'][$idx]['original_product_data']['cart_id'];
		}
		
		$taxes = array();
		foreach ((array)$cart['taxes'] as $_k => $_v) {
			$tax = array();
			if (isset($_v['applies']['P_'.$idx]) || isset($_v['applies']['items']['P'][$idx]) || isset($_v['applies']['P_'.$_idx]) || isset($_v['applies']['items']['P'][$_idx])) {
				$taxes[$_k] = $_v;
			}
		}
	}
	if ($cart['stored_taxes'] != 'Y' || empty($taxes)) {
		$taxes = fn_get_set_taxes($cart_products[$idx]['tax_ids']);
	}
	
	return $taxes;
}

/**
 * Get payment taxes
 *
 * @param integer $payment_id payment method id
 * @param array $cart cart data
 * @return array array with taxes
 */
function fn_get_payment_taxes($payment_id, $cart)
{
	// get current tax ids
	$tax_ids = db_get_field("SELECT tax_ids FROM ?:payments WHERE payment_id = ?i", $payment_id);
	if (!empty($tax_ids)) {
		$taxes = fn_get_set_taxes($tax_ids);

		// apply new rates if exists
		if ($cart['stored_taxes'] == 'Y' && !empty($cart['stored_taxes_data'])) {

			foreach ((array)$cart['stored_taxes_data'] as $_k => $_v) {
				
				if (!empty($taxes[$_k]) && (!empty($_v['applies']['PS_'.$payment_id]) || !empty($_v['applies']['items']['PS'][$payment_id]))) {
					if (!empty($_v['rate_value']) && !empty($_v['rate_type'])) {
						$taxes[$_k]['rate_value'] = $_v['rate_value'];
						$taxes[$_k]['rate_type'] = $_v['rate_type'];
					}
				}
			}
		}

	}
	
	/**
	 * Init payment taxes (running after fn_get_payment_taxes function)
	 *
	 * @param integer $payment_id payment method id
	 * @param array $cart cart data
	 * @param array $taxes array with taxes
	 */
	fn_set_hook('get_payment_taxes_post', $payment_id, $cart, $taxes);
	return $taxes;
}

function fn_get_shipping_taxes($shipping_id, $shipping_rates, $cart)
{
	$tax_ids = array();
	if (defined('ORDER_MANAGEMENT')) {
		$_taxes = db_get_hash_single_array("SELECT tax_ids, shipping_id FROM ?:shippings WHERE shipping_id IN (?n)", array('shipping_id', 'tax_ids'), array_keys($shipping_rates));
		
		if (!empty($_taxes)) {
			foreach ($_taxes as $_ship => $_tax) {
				if (!empty($_tax)) {
					$_tids = explode(',', $_tax);
					foreach ($_tids as $_tid) {
						$tax_ids[$_ship][$_tid] = $_tax;
					}
				}
			}
		}
	}
	
	if ($cart['stored_taxes'] == 'Y') {
		$taxes = array();

		foreach ((array)$cart['taxes'] as $_k => $_v) {
			isset($_v['applies']['items']['S'][$shipping_id]) ? $exists = true : $exists = false;
			foreach ($_v['applies'] as $aid => $av) {
				if (strpos($aid, 'S_' . $shipping_id . '_') !== false) {
					$exists = true;

				}
			}
			if ($exists == true || (!empty($tax_ids[$shipping_id]) && !empty($tax_ids[$shipping_id][$_k]))) {
				$taxes[$_k] = $_v;
				$taxes[$_k]['applies'] = array();
			}
		}
	} else {
		$taxes = array();
		$tax_ids = db_get_field("SELECT tax_ids FROM ?:shippings WHERE shipping_id = ?i", $shipping_id);
		if (!empty($tax_ids)) {
			$taxes = db_get_hash_array("SELECT tax_id, address_type, priority, price_includes_tax, regnumber FROM ?:taxes WHERE tax_id IN (?n) AND status = 'A' ORDER BY priority", 'tax_id', explode(',', $tax_ids));
		}
	}
	
	return $taxes;
}


/**
 * Apply calculated products and shipping taxes to cart
 * cart taxes are supposed to be empty
 *
 * @param array $calculated_data payment data taxes
 * @param array $cart cart data
 * @return boolean always true
 */
function fn_apply_calculated_taxes($calculated_data, &$cart)
{
	if ($cart['stored_taxes'] == 'Y') {
		// save taxes to prevent payment taxes loss
		$cart['stored_taxes_data'] = $cart['taxes'];
	}

	if (!empty($calculated_data)) {
		if (Registry::get('settings.General.tax_calculation') == 'unit_price') {
			// Based on the unit price
			$taxes_data = array();
			foreach ($calculated_data as $id => $_taxes) {
				if (empty($_taxes)) {
					continue;
				}
				foreach ($_taxes as $k => $v) {
					if (empty($taxes_data[$k])) {
						$taxes_data[$k] = $v;
						$taxes_data[$k]['tax_subtotal'] = 0;
					}
					$taxes_data[$k]['applies'][$id] = $v['tax_subtotal'];
					$taxes_data[$k]['tax_subtotal'] += $v['tax_subtotal'];
				}
			}
			
			$cart['taxes'] = $taxes_data;
			
		} else {
			// Based on the order subtotal
			$cart['taxes'] = array();
			$cart['tax_subtotal'] = 0;
			$cart['tax_summary'] = array(
				'included' => 0,
				'added' => 0,
				'total' => 0
			);
			
			foreach ($calculated_data as $tax_id => $v) {
				$cart['taxes'][$tax_id] = $v;
				
				if ($v['price_includes_tax'] == 'Y') {
					$cart['tax_summary']['included'] += $v['tax_subtotal'];
				} else {
					$cart['tax_summary']['added'] += $v['tax_subtotal'];
				}
				
				$cart['tax_summary']['total'] += $v['tax_subtotal'];
			}
		}
		
	} else { // FIXME!!! Test on order management
		$cart['taxes'] = array();
		$cart['tax_summary'] = array();
	}

	/**
	 * Apply products and shipping taxes (running after fn_apply_calculated_taxes function)
	 *
	 * @param array $calculated_data payment data taxes
	 * @param array $cart cart data
	 */
	fn_set_hook('apply_calculated_taxes_post', $calculated_data, $cart);
	
	return true;
}

function fn_format_rate_value($rate_value, $rate_type, $decimals='2', $dec_point='.', $thousands_sep=',', $coefficient = '')
{
	if (!empty($coefficient) && @$rate_type != 'P') {
		$rate_value = $rate_value / floatval($coefficient);
	}

	if (empty($rate_type)) {
		$rate_type = 'F';
	}

	fn_set_hook('format_rate_value', $rate_value, $rate_type, $decimals, $dec_point, $thousands_sep, $coefficient);

	$value = number_format(fn_format_price($rate_value, '', $decimals), $decimals, $dec_point, $thousands_sep);
	if ($rate_type == 'F') { // Flat rate
		return $value;
	}
	elseif ($rate_type == 'P') { // Percent rate
		return $value.'%';
	}

	return $rate_value;

}

function fn_check_amount_in_stock($product_id, $amount, $product_options, $cart_id, $is_edp, $original_amount, &$cart, $update_id = 0)
{
	fn_set_hook('check_amount_in_stock', $product_id, $amount, $product_options, $cart_id, $is_edp, $original_amount, $cart);

	// If the product is EDP don't track the inventory
	if ($is_edp == 'Y') {
		return 1;
	}

	$product = db_get_row("SELECT ?:products.tracking, ?:products.amount, ?:products.min_qty, ?:products.max_qty, ?:products.qty_step, ?:products.list_qty_count, ?:product_descriptions.product FROM ?:products LEFT JOIN ?:product_descriptions ON ?:product_descriptions.product_id = ?:products.product_id AND lang_code = ?s WHERE ?:products.product_id = ?i", CART_LANGUAGE, $product_id);

	if (isset($product['tracking']) && Registry::get('settings.General.inventory_tracking') == 'Y' && $product['tracking'] != 'D') {
		// Track amount for ordinary product
		if ($product['tracking'] == 'B') {
			$current_amount = $product['amount'];

		// Track amount for product with options
		} elseif ($product['tracking'] == 'O') {
			$selectable_cart_id = fn_generate_cart_id($product_id, array('product_options' => $product_options), true);
			$current_amount = db_get_field("SELECT amount FROM ?:product_options_inventory WHERE combination_hash = ?i", $selectable_cart_id);
			$current_amount = intval($current_amount);
		}

		if (!empty($cart['products']) && is_array($cart['products'])) {
			$product_not_in_cart = true;
			foreach ($cart['products'] as $k => $v) {
				if ($k != $cart_id){ // Check if the product with the same selectable options already exists ( for tracking = O)
					if (isset ($product['tracking']) && ($product['tracking'] == 'B' && $v['product_id'] == $product_id) || ($product['tracking'] == 'O' && @$v['selectable_cart_id'] == $selectable_cart_id)) {
						$current_amount -= $v['amount'];
					}
				} else {
					$product_not_in_cart = false;
				}
			}

			if ($product['tracking'] == 'B' && !empty($update_id) && $product_not_in_cart && !empty($cart['products'][$update_id])) {
				$current_amount += $cart['products'][$update_id]['amount'];
			}

			if ($product['tracking'] == 'O') {
				// Store cart_id for selectable options in cart variable, so if the same product is added to
				// the cart with the same selectable options, but different text options,
				// the total amount will be tracked anyway as it is the one product
				if (!empty($selectable_cart_id) && isset($cart['products'][$cart_id])) {
					$cart['products'][$cart_id]['selectable_cart_id'] = $selectable_cart_id;
				}
			}
		}
	}

	$min_qty = 1;

	if (!empty($product['min_qty']) && $product['min_qty'] > $min_qty) {
		$min_qty = $product['min_qty'];
	}

	if (!empty($product['qty_step']) && $product['qty_step'] > $min_qty) {
		$min_qty = $product['qty_step'];
	}

    // Step parity check
    if (!empty($product['qty_step']) && $amount % $product['qty_step']) {
        $amount = fn_ceil_to_step($amount, $product['qty_step']);
        $amount_corrected = true;
        fn_set_notification('W', fn_get_lang_var('important'), str_replace('[product]', $product['product'], fn_get_lang_var('text_cart_amount_changed')));
    }
	
	if (isset($current_amount) && $current_amount >= 0 && $current_amount - $amount < 0 && Registry::get('settings.General.allow_negative_amount') != 'Y') {
		// For order edit: add original amount to existent amount
		$current_amount += $original_amount;

		if ($current_amount > 0 && $current_amount - $amount < 0 && Registry::get('settings.General.allow_negative_amount') != 'Y') {
			if (!defined('ORDER_MANAGEMENT')) {
				fn_set_notification('W', fn_get_lang_var('important'), str_replace('[product]', $product['product'], fn_get_lang_var('text_cart_amount_corrected')));
				$amount = fn_ceil_to_step($current_amount, $product['qty_step']);
			} else {
				fn_set_notification('W', fn_get_lang_var('warning'), fn_get_lang_var('text_cart_not_enough_inventory'));
			}
		} elseif ($current_amount - $amount < 0 && Registry::get('settings.General.allow_negative_amount') != 'Y') {
			fn_set_notification('E', fn_get_lang_var('notice'), str_replace('[product]', $product['product'], fn_get_lang_var('text_cart_zero_inventory')));
			return false;
		} elseif ($current_amount <= 0 && $amount <= 0 && Registry::get('settings.General.allow_negative_amount') != 'Y') {
			fn_set_notification('E', fn_get_lang_var('notice'), str_replace('[product]', $product['product'], fn_get_lang_var('text_cart_zero_inventory_and_removed')));
			return false;
		}
	}

	$min_qty = fn_ceil_to_step($min_qty, $product['qty_step']);
    if ($amount < $min_qty || (isset($current_amount) && $amount > $current_amount && Registry::get('settings.General.allow_negative_amount') != 'Y' && Registry::get('settings.General.inventory_tracking') == 'Y') && isset($product_not_in_cart) && !$product_not_in_cart) {
		if (($current_amount < $min_qty || $current_amount == 0) && Registry::get('settings.General.allow_negative_amount') != 'Y' && Registry::get('settings.General.inventory_tracking') == 'Y') {
			fn_set_notification('W', fn_get_lang_var('warning'), fn_get_lang_var('text_cart_not_enough_inventory'));
			if (!defined('ORDER_MANAGEMENT')) {
				$amount = false;
			}
		} elseif ($amount > $current_amount && Registry::get('settings.General.allow_negative_amount') != 'Y' && Registry::get('settings.General.inventory_tracking') == 'Y') {
			fn_set_notification('W', fn_get_lang_var('warning'), fn_get_lang_var('text_cart_not_enough_inventory'));
			if (!defined('ORDER_MANAGEMENT')) {
				$amount = fn_floor_to_step($current_amount, $product['qty_step']);
			}
		} elseif ($amount < $min_qty) {
			fn_set_notification('W', fn_get_lang_var('notice'), str_replace(array('[product]' , '[quantity]'), array($product['product'] , $min_qty), fn_get_lang_var('text_cart_min_qty')));
			if (!defined('ORDER_MANAGEMENT')) {
				$amount = $min_qty;
			}
		}
	}

	$max_qty = fn_floor_to_step($product['max_qty'], $product['qty_step']);
    if (!empty($max_qty) && $amount > $max_qty) {
		fn_set_notification('W', fn_get_lang_var('notice'), str_replace(array('[product]' , '[quantity]'), array($product['product'], $max_qty), fn_get_lang_var('text_cart_max_qty')));
		if (!defined('ORDER_MANAGEMENT')) {
			$amount = $max_qty;
		}
	}
	
	fn_set_hook('post_check_amount_in_stock', $product_id, $amount, $product_options, $cart_id, $is_edp, $original_amount, $cart);

	return empty($amount) ? false : $amount;
}

//
// Calculate unique product id in the cart
//
function fn_generate_cart_id($product_id, $extra, $only_selectable = false)
{
	$_cid = array();

	if (!empty($extra['product_options']) && is_array($extra['product_options'])) {
		foreach ($extra['product_options'] as $k => $v) {
			Registry::set('runtime.skip_sharing_selection', true);
			if ($only_selectable == true && ((string)intval($v) != $v || db_get_field("SELECT inventory FROM ?:product_options WHERE option_id = ?i", $k) != 'Y')) {
				continue;
			}
			Registry::set('runtime.skip_sharing_selection', false);
			$_cid[] = $v;
		}
	}

	if (isset($extra['exclude_from_calculate'])) {
		$_cid[] = $extra['exclude_from_calculate'];
	}

	fn_set_hook('generate_cart_id', $_cid, $extra, $only_selectable);

	natsort($_cid);
	array_unshift($_cid, $product_id);
	$cart_id = fn_crc32(implode('_', $_cid));

	return $cart_id;
}


//
// Normalize product amount
//
function fn_normalize_amount($amount = '1')
{
	$amount = abs(intval($amount));

	return empty($amount) ? 0 : $amount;
}

function fn_order_placement_routines($order_id, $force_notification = array(), $clear_cart = true, $action = '')
{
	$order_info = fn_get_order_info($order_id, true);
	$display_notification = true;

	fn_set_hook('placement_routines', $order_id, $order_info, $force_notification, $clear_cart, $action, $display_notification);

	if (!empty($_SESSION['cart']['placement_action'])) {
		if (empty($action)) {
			$action = $_SESSION['cart']['placement_action'];
		}
		unset($_SESSION['cart']['placement_action']);
	}

	if (AREA == 'C' && !empty($order_info['user_id'])) {
		$__fake = '';
		fn_save_cart_content($__fake, $order_info['user_id']);
	}

	$edp_data = fn_generate_ekeys_for_edp(array(), $order_info);
	fn_order_notification($order_info, $edp_data, $force_notification);

	$_error = false;

	if ($action == 'save') {
		if ($display_notification) {
			fn_set_notification('N', fn_get_lang_var('congratulations'), fn_get_lang_var('text_order_saved_successfully'));
		}
	} else {
		if ($order_info['status'] == STATUS_PARENT_ORDER) {
			$child_orders = db_get_hash_single_array("SELECT order_id, status FROM ?:orders WHERE parent_order_id = ?i", array('order_id', 'status'), $order_id);
			$status = reset($child_orders);
			$child_orders = array_keys($child_orders);
		} else {
			$status = $order_info['status'];
		}
		if (in_array($status, fn_get_order_paid_statuses())) {
			if ($action == 'repay') {
				fn_set_notification('N', fn_get_lang_var('congratulations'), fn_get_lang_var('text_order_repayed_successfully'));
			} else {
				fn_set_notification('N', fn_get_lang_var('order_placed'), fn_get_lang_var('text_order_placed_successfully'));
			}
		} elseif ($status == 'B') {
			fn_set_notification('W', fn_get_lang_var('important'), fn_get_lang_var('text_order_backordered'));
		} else {
			if (AREA == 'A' || $action == 'repay') {
				if ($status != 'I') {
					$_payment_info = db_get_field("SELECT data FROM ?:order_data WHERE order_id = ?i AND type = 'P'", $order_id);
					if (!empty($_payment_info)) {
						$_payment_info = unserialize(fn_decrypt_text($_payment_info));
						$_msg = !empty($_payment_info['reason_text']) ? $_payment_info['reason_text'] : '';
						$_msg .= empty($_msg) ? fn_get_lang_var('text_order_placed_error') : '';
						fn_set_notification('E', '', $_msg);
					}
				}
			} else {
				$_error = true;
				if (!empty($child_orders)) {
					array_unshift($child_orders, $order_id);
				} else {
					$child_orders = array();
					$child_orders[] = $order_id;
				}
				$_SESSION['cart'][($status == 'N' ? 'processed_order_id' : 'failed_order_id')] = $child_orders;
			}
			if ($status == 'N' || ($action == 'repay' && $status == 'I')) {
				fn_set_notification('W', fn_get_lang_var('important'), fn_get_lang_var('text_transaction_cancelled'));
			}
		}
	}

	// Empty cart
	if ($clear_cart == true && $_error == false) {
		$_SESSION['cart'] = array(
			'user_data' => !empty($_SESSION['cart']['user_data']) ? $_SESSION['cart']['user_data'] : array(), 
			'profile_id' => !empty($_SESSION['cart']['profile_id']) ? $_SESSION['cart']['profile_id'] : 0, 
			'user_id' => !empty($_SESSION['cart']['user_id']) ? $_SESSION['cart']['user_id'] : 0,
		);
		$_SESSION['shipping_rates'] = array();
		unset($_SESSION['shipping_hash']);
		
		db_query('DELETE FROM ?:user_session_products WHERE session_id = ?s AND type = ?s', Session::get_id(), 'C');
	}

	fn_set_hook('order_placement_routines', $order_id, $force_notification, $order_info, $_error);

	if (AREA == 'A' || $action == 'repay') {
		fn_redirect("orders.details?order_id=$order_id", true);
	} else {
		fn_redirect("checkout." . ($_error == true ? (Registry::get('settings.General.checkout_style') != 'multi_page' ? "checkout" : "summary") : "complete?order_id=$order_id"), true);
	}
}

//
// Calculate difference
//
function fn_less_zero($first_arg, $second_arg = 0, $zero = false)
{
	if (!empty($second_arg)) {
		if ($first_arg - $second_arg > 0) {
			return $first_arg - $second_arg;
		} else {
			return 0;
		}
	} else {
		if (empty($zero)) {
			return $first_arg;
		} else {
			return 0;
		}
	}
}

//
// Check if product was added to cart
//
function fn_check_add_product_to_cart($cart, $product, $product_id)
{
	$result = true;

	/**
	 * Change parmetres of checking if product can be added to cart (run before fn_check_add_product_to_cart func)
	 *
	 * @param array $cart Array of the cart contents and user information necessary for purchase
	 * @param array $product Params with that product is adding to cart
	 * @param int $product_id Identifier of adding product
	 * @param boolean $result Flag determines if product can be added to cart
	 */
	fn_set_hook('check_add_to_cart_pre', $cart, $product, $product_id, $result);

	
	if ((PRODUCT_TYPE == 'ULTIMATE' && defined('COMPANY_ID')) || (PRODUCT_TYPE == 'MULTIVENDOR' && isset($cart['company_id']))) {
		$product_company_id = db_get_field('SELECT company_id FROM ?:products WHERE product_id = ?i', $product_id);
	}
			
	if (PRODUCT_TYPE == 'ULTIMATE' && defined('COMPANY_ID')) {
		if ($product_company_id != COMPANY_ID && fn_ult_is_shared_product($product_id, COMPANY_ID) != 'Y') {
			$result = false;
		}
	}

	if (PRODUCT_TYPE == 'MULTIVENDOR' && isset($cart['company_id'])) {
		if ($product_company_id != $cart['company_id']) {
			$result = false;
		}
	}
	

	/**
	 * Change parmetres of checking if product can be added to cart (run before fn_check_add_product_to_cart func)
	 *
	 * @param array $cart Array of the cart contents and user information necessary for purchase
	 * @param array $product Params with that product is adding to cart
	 * @param int $product_id Identifier of adding product
	 * @param boolean $result Flag determines if product can be added to cart
	 */
	fn_set_hook('check_add_to_cart_post', $cart, $product, $product_id, $result);

	return $result;
}

//
// Add product to cart
//
// @param array $product_data array with data for the product to add)(product_id, price, amount, product_options, is_edp)
// @return mixed cart ID for the product if addition is successful and false otherwise
//
function fn_add_product_to_cart($product_data, &$cart, &$auth, $update = false)
{
	$ids = array();
	if (!empty($product_data) && is_array($product_data)) {
		if (!defined('GET_OPTIONS')) {
			list($product_data, $cart) = fn_add_product_options_files($product_data, $cart, $auth, $update);
		}

		fn_set_hook('pre_add_to_cart', $product_data, $cart, $auth, $update);

		foreach ($product_data as $key => $data) {
			if (empty($key)) {
				continue;
			}
			if (empty($data['amount'])) {
				continue;
			}

			$data['stored_price'] = (!empty($data['stored_price']) && AREA != 'C') ? $data['stored_price'] : 'N';

			if (empty($data['extra'])) {
				$data['extra'] = array();
			}

			$product_id = (!empty($data['product_id'])) ? intval($data['product_id']) : intval($key);
			if (!fn_check_add_product_to_cart($cart, $data, $product_id)) {
				continue;
			}

			// Check if product options exist
			if (!isset($data['product_options'])) {
				$data['product_options'] = fn_get_default_product_options($product_id);
			}

			// Generate cart id
			$data['extra']['product_options'] = $data['product_options'];

			$_id = fn_generate_cart_id($product_id, $data['extra'], false);

			if (isset($ids[$_id]) && $key == $_id) {
				continue;
			}

			if (isset($data['extra']['exclude_from_calculate'])) {
				if (!empty($cart['products'][$key]) && !empty($cart['products'][$key]['extra']['aoc'])) {
					$cart['saved_product_options'][$cart['products'][$key]['extra']['saved_options_key']] = $data['product_options'];
				}
				if (isset($cart['deleted_exclude_products'][$data['extra']['exclude_from_calculate']][$_id])) {
					continue;
				}
			}
			$amount = fn_normalize_amount(@$data['amount']);
			
			if (!isset($data['extra']['exclude_from_calculate'])) {
				if ($data['stored_price'] != 'Y') {
					$allow_add = true;
					// Check if the product price with options modifiers equals to zero
					$price = fn_get_product_price($product_id, $amount, $auth);
					$zero_price_action = db_get_field("SELECT zero_price_action FROM ?:products WHERE product_id = ?i", $product_id);
					if (!floatval($price) && $zero_price_action == 'A') {
						if (isset($cart['products'][$key]['custom_user_price'])) {
							$price = $cart['products'][$key]['custom_user_price'];
						} else {
							$custom_user_price = empty($data['price']) ? 0 : $data['price'];
						}
					}
					$price = fn_apply_options_modifiers($data['product_options'], $price, 'P', array(), array('product_data' => $data));
					if (!floatval($price)) {
						$data['price'] = isset($data['price']) ? fn_parse_price($data['price']) : 0;

						if (($zero_price_action == 'R' || ($zero_price_action == 'A' && floatval($data['price']) < 0)) && AREA == 'C') {
							if ($zero_price_action == 'A') {
								fn_set_notification('E', fn_get_lang_var('error'), fn_get_lang_var('incorrect_price_warning'));
							}
							$allow_add = false;
						}

						$price = empty($data['price']) ? 0 : $data['price'];
					}

					/**
					 * Recalculates price and checks if product can be added with the current price
					 *
					 * @param array $data Adding product data
					 * @param float $price Calculated product price
					 * @param boolean $allow_add Flag that determines if product can be added to cart
					 */
					fn_set_hook('add_product_to_cart_check_price', $data, $price, $allow_add);

					if (!$allow_add) {
						continue;
					}

				} else {
					$price = empty($data['price']) ? 0 : $data['price'];
				}
			} else {
				$price = 0;
			}

			$_data = db_get_row('SELECT is_edp, options_type, tracking, unlimited_download FROM ?:products WHERE product_id = ?i', $product_id);
			if (isset($_data['is_edp'])) {
				$data['is_edp'] = $_data['is_edp'];
			}
			if (isset($_data['options_type'])) {
				$data['options_type'] = $_data['options_type'];
			}
			if (isset($_data['tracking'])) {
				$data['tracking'] = $_data['tracking'];
			}
			if (isset($_data['unlimited_download'])) {
				$data['extra']['unlimited_download'] = $_data['unlimited_download'];
			}

			// Check the sequential options
			if (!empty($data['tracking']) && $data['tracking'] == 'O' && $data['options_type'] == 'S') {
				$inventory_options = db_get_fields("SELECT a.option_id FROM ?:product_options as a LEFT JOIN ?:product_global_option_links as c ON c.option_id = a.option_id WHERE (a.product_id = ?i OR c.product_id = ?i) AND a.status = 'A' AND a.inventory = 'Y'", $product_id, $product_id);
				
				$sequential_completed = true;
				if (!empty($inventory_options)) {
					foreach ($inventory_options as $option_id) {
						if (!isset($data['product_options'][$option_id]) || empty($data['product_options'][$option_id])) {
							$sequential_completed = false;
							break;
						}
					}
				}
				
				if (!$sequential_completed) {
					fn_set_notification('E', fn_get_lang_var('error'), fn_get_lang_var('select_all_product_options'));
					// Even if customer tried to add the product from the catalog page, we will redirect he/she to the detailed product page to give an ability to complete a purchase
					$redirect_url = fn_url('products.view?product_id=' . $product_id . '&combination=' . fn_get_options_combination($data['product_options']));
					$_REQUEST['redirect_url'] = $redirect_url; //FIXME: Very very very BAD style to use the global variables in the functions!!!
					
					return false;
				}
			}
			
			if (!isset($cart['products'][$_id])) { // If product doesn't exists in the cart
				$amount = empty($data['original_amount']) ? fn_check_amount_in_stock($product_id, $amount, $data['product_options'], $_id, $data['is_edp'], 0, $cart, $update == true ? $key : 0) : $data['original_amount'];
				
				if ($amount === false) {
					continue;
				}

				$cart['products'][$_id]['product_id'] = $product_id;
				$cart['products'][$_id]['amount'] = $amount;
				$cart['products'][$_id]['product_options'] = $data['product_options'];
				$cart['products'][$_id]['price'] = $price;
				if (!empty($zero_price_action) && $zero_price_action == 'A') {
					if (isset($custom_user_price)) {
						$cart['products'][$_id]['custom_user_price'] = $custom_user_price;
					} elseif (isset($cart['products'][$key]['custom_user_price'])) {
						$cart['products'][$_id]['custom_user_price'] = $cart['products'][$key]['custom_user_price'];
					}
				}
				$cart['products'][$_id]['stored_price'] = $data['stored_price'];

				// add image for minicart
				$cart['products'][$_id]['main_pair'] = fn_get_cart_product_icon($product_id, $data);

				fn_define_original_amount($product_id, $_id, $cart['products'][$_id], $data);

				if ($update == true && $key != $_id) {
					unset($cart['products'][$key]);
				}

			} else { // If product is already exist in the cart

				$_initial_amount = empty($cart['products'][$_id]['original_amount']) ? $cart['products'][$_id]['amount'] : $cart['products'][$_id]['original_amount'];

				// If ID changed (options were changed), summ the total amount of old and new products
				if ($update == true && $key != $_id) {
					$amount += $_initial_amount;
					unset($cart['products'][$key]);
				}

				$cart['products'][$_id]['amount'] = fn_check_amount_in_stock($product_id, (($update == true) ? 0 : $_initial_amount) + $amount, $data['product_options'], $_id, (!empty($data['is_edp']) && $data['is_edp'] == 'Y' ? 'Y' : 'N'), 0, $cart, $update == true ? $key : 0);
			}

			$cart['products'][$_id]['extra'] = (empty($data['extra'])) ? array() : $data['extra'];
			$cart['products'][$_id]['stored_discount'] = @$data['stored_discount'];
			if (defined('ORDER_MANAGEMENT')) {
				$cart['products'][$_id]['discount'] = @$data['discount'];
			}

			// Increase product popularity
			if (empty($_SESSION['products_popularity']['added'][$product_id])) {
				$_data = array (
					'product_id' => $product_id,
					'added' => 1,
					'total' => POPULARITY_ADD_TO_CART
				);
				
				db_query("INSERT INTO ?:product_popularity ?e ON DUPLICATE KEY UPDATE added = added + 1, total = total + ?i", $_data, POPULARITY_ADD_TO_CART);
				
				$_SESSION['products_popularity']['added'][$product_id] = true;
			}
			
			$company_id = db_get_field("SELECT company_id FROM ?:products WHERE product_id = ?i", $product_id);
			$cart['products'][$_id]['company_id'] = $company_id;
			
			if (!empty($data['saved_object_id'])) {
				$cart['products'][$_id]['object_id'] = $data['saved_object_id'];
			}
			
			fn_set_hook('add_to_cart', $cart, $product_id, $_id);

			$ids[$_id] = $product_id;
		}

		/**
		 * Change product data after adding product to cart
		 *
		 * @param array $product_data Product data
		 * @param array $cart Cart data
		 * @param array $auth Auth data
		 * @param bool $update Flag the determains if cart data are updated
		 */
		fn_set_hook('post_add_to_cart', $product_data, $cart, $auth, $update);

		$cart['recalculate'] = true;
		return $ids;

	} else {
		return false;
	}
}

function fn_form_cart($order_id, &$cart, &$auth)
{
	$order_info = fn_get_order_info($order_id, false, false);

	if (empty($order_info)) {
		return false;
	}

	// Fill the cart
	foreach ($order_info['items'] as $_id => $item) {
		$_item = array (
			$item['product_id'] => array (
				'amount' => $item['amount'],
				'product_options' => @$item['extra']['product_options'],
				'price' => $item['original_price'],
				'stored_discount' => 'Y',
				'stored_price' => 'Y',
				'discount' => @$item['extra']['discount'],
				'original_amount' => $item['amount'], // the original amount, that stored in order
				'original_product_data' => array ( // the original cart ID and amount, that stored in order
					'cart_id' => $_id,
					'amount' => $item['amount'],
				),
			),
		);
		if (isset($item['extra'])) {
			$_item[$item['product_id']]['extra'] = $item['extra'];
		}
		fn_add_product_to_cart($_item, $cart, $auth);
	}

    // FIXME: Workaround for the add-ons that do not add a product to cart unless the parent product is already added.
    if (count($order_info['items']) > count($cart['products'])) {
        foreach ($order_info['items'] as $_id => $item) {
            if (empty($cart['products'][$_id])) {
                $_item = array (
                    $item['product_id'] => array (
                        'amount' => $item['amount'],
                        'product_options' => @$item['extra']['product_options'],
                        'price' => $item['original_price'],
                        'stored_discount' => 'Y',
                        'stored_price' => 'Y',
                        'discount' => @$item['extra']['discount'],
                        'original_amount' => $item['amount'], // the original amount, that stored in order
                        'original_product_data' => array ( // the original cart ID and amount, that stored in order
                            'cart_id' => $_id,
                            'amount' => $item['amount'],
                        ),
                    ),
                );
                if (isset($item['extra'])) {
                    $_item[$item['product_id']]['extra'] = $item['extra'];
                }
                fn_add_product_to_cart($_item, $cart, $auth);
            }
        }
    }

	// Restore custom files
	$dir_path = DIR_CUSTOM_FILES . 'order_data/' . $order_id;
	
	if (is_dir($dir_path)) {
		fn_mkdir(DIR_CUSTOM_FILES . 'sess_data');
		fn_copy($dir_path, DIR_CUSTOM_FILES . 'sess_data');
	}

	$cart['payment_id'] = $order_info['payment_id'];
	$cart['stored_taxes'] = 'Y';
	$cart['stored_discount'] = 'Y';
	$cart['taxes'] = $order_info['taxes'];
	$cart['promotions'] = !empty($order_info['promotions']) ? $order_info['promotions'] : array();

	$cart['shipping'] = (!empty($order_info['shipping'])) ? $order_info['shipping'] : array();
	$cart['stored_shipping'] = array();
	foreach ($cart['shipping'] as $sh_id => $v) {
		if (!empty($v['rates'])) {
			$cart['stored_shipping'][$sh_id] = array_sum($v['rates']);
		}
	}

	$cart['notes'] = $order_info['notes'];
	$cart['payment_info'] = @$order_info['payment_info'];

	// Add order discount
	if (floatval($order_info['subtotal_discount'])) {
		$cart['stored_subtotal_discount'] = 'Y';
		$cart['subtotal_discount'] = $cart['original_subtotal_discount'] = fn_format_price($order_info['subtotal_discount']);
	}

	// Fill the cart with the coupons
	if (!empty($order_info['coupons'])) {
		$cart['coupons'] = $order_info['coupons'];
	}

	// Set the customer if exists
	$_data = array();
	if (!empty($order_info['user_id'])) {
		$_data = db_get_row("SELECT user_id, user_login as login FROM ?:users WHERE user_id = ?i", $order_info['user_id']);
	}
	$auth = fn_fill_auth($_data, array(), false, 'C');
	$auth['tax_exempt'] = $order_info['tax_exempt'];

	// Fill customer info
	$cart['user_data'] = fn_check_table_fields($order_info, 'user_profiles');
	$cart['user_data'] = fn_array_merge(fn_check_table_fields($order_info, 'users'), $cart['user_data']);
	if (!empty($order_info['fields'])) {
		$cart['user_data']['fields'] = $order_info['fields'];
	}
	fn_add_user_data_descriptions($cart['user_data']);

	fn_set_hook('form_cart', $order_info, $cart);

}

//
// Calculate taxes for products or shippings
//
function fn_calculate_tax_rates($taxes, $price, $amount, $auth, &$cart)
{
	static $destination_id;
	static $tax_description;
	static $user_data;

	$taxed_price = $price;

	if (!empty($cart['user_data'])) {
		$profile_fields = fn_get_profile_fields('O', $auth);
		$billing_population = fn_check_profile_fields_population($cart['user_data'], 'B', $profile_fields);
		$shipping_population = fn_check_profile_fields_population($cart['user_data'], 'S', $profile_fields);
	}

	if (empty($auth['user_id']) && (empty($cart['user_data']) || fn_is_empty($cart['user_data']) || $billing_population != true || $shipping_population != true) && defined('CHECKOUT') && Registry::get('settings.Appearance.taxes_using_default_address') !== 'Y' && !defined('ESTIMATION')) {
		return false;
	}

	if ((empty($destination_id) || $user_data != @$cart['user_data'])) {
		// Get billing location
		$location = fn_get_customer_location($auth, $cart, true);
		$destination_id['B'] = fn_get_available_destination($location);

		// Get shipping location
		$location = fn_get_customer_location($auth, $cart);
		$destination_id['S'] = fn_get_available_destination($location);
	}

	if (!empty($cart['user_data'])) {
		$user_data = $cart['user_data'];
	}
	$_tax = 0;
	$previous_priority = -1;
	$previous_price = '';

	foreach ($taxes as $key => $tax) {
		if (empty($tax['tax_id'])) {
			$tax['tax_id'] = $key;
		}

		if (empty($tax['priority'])) {
			$tax['priority'] = 0;
		}

		$_is_zero = floatval($taxed_price);
		if (empty($_is_zero)) {
			continue;
		}

		if (!empty($cart['stored_taxes']) && $cart['stored_taxes'] == 'Y' && (!empty($tax['rate_type']) || isset($cart['taxes'][$tax['tax_id']]['rate_value']))) {
			$rate = array (
				'rate_value' => isset($cart['taxes'][$tax['tax_id']]['rate_value']) ? $cart['taxes'][$tax['tax_id']]['rate_value'] : $tax['rate_value'],
				'rate_type' => isset($cart['taxes'][$tax['tax_id']]['rate_type']) ? $cart['taxes'][$tax['tax_id']]['rate_type'] : $tax['rate_type']
			);
			
		} else {
			if (!isset($destination_id[$tax['address_type']])) {
				continue;
			}

			$rate = db_get_row("SELECT destination_id, apply_to, rate_value, rate_type FROM ?:tax_rates WHERE tax_id = ?i AND destination_id = ?i", $tax['tax_id'], $destination_id[$tax['address_type']]);
			if (!@floatval($rate['rate_value'])) {
				continue;
			}
		}


		$base_price = ($tax['priority'] == $previous_priority) ? $previous_price : $taxed_price;

		if ($rate['rate_type'] == 'P') { // Percent dependence
			// If tax is included into the price
			if ($tax['price_includes_tax'] == 'Y') {
				$_tax = fn_format_price($base_price - $base_price / ( 1 + ($rate['rate_value'] / 100)));
				// If tax is NOT included into the price
			} else {
				$_tax = fn_format_price($base_price * ($rate['rate_value'] / 100));
				$taxed_price += $_tax;
			}

		} else {
			$_tax = fn_format_price($rate['rate_value']);
			// If tax is NOT included into the price
			if ($tax['price_includes_tax'] != 'Y') {
				$taxed_price += $_tax;
			}
		}

		$previous_priority = $tax['priority'];
		$previous_price = $base_price;

		if (empty($tax_description[$tax['tax_id']])) {
			$tax_description[$tax['tax_id']] = db_get_field("SELECT tax FROM ?:tax_descriptions WHERE tax_id = ?i AND lang_code = ?s", $tax['tax_id'], CART_LANGUAGE);
		}

		$taxes_data[$tax['tax_id']] = array (
			'rate_type' => $rate['rate_type'],
			'rate_value' => $rate['rate_value'],
			'price_includes_tax' => $tax['price_includes_tax'],
			'regnumber' => @$tax['regnumber'],
			'priority' => @$tax['priority'],
			'tax_subtotal' => fn_format_price($_tax * $amount),
			'description' => $tax_description[$tax['tax_id']],
		);
	}

	return empty($taxes_data) ? false : $taxes_data;
}

function fn_get_predefined_statuses($type)
{
	$statuses = array(
		'profiles' => array(
			'A' => fn_get_lang_var('active'),
			'P' => fn_get_lang_var('pending'),
			'F' => fn_get_lang_var('available'),
			'D' => fn_get_lang_var('declined')
		),
		'usergroups' => array(
			'A' => fn_get_lang_var('active'),
			'P' => fn_get_lang_var('pending'),
			'F' => fn_get_lang_var('available'),
			'D' => fn_get_lang_var('declined'),
		),
	);

	fn_set_hook('get_predefined_statuses', $type, $statuses);

	return $statuses[$type];
}

//
// Get order status data
//
function fn_get_status_data($status, $type = STATUSES_ORDER, $object_id = 0, $lang_code = CART_LANGUAGE)
{
	$data = db_get_row("SELECT * FROM ?:status_descriptions WHERE status = ?s AND type = ?s AND lang_code = ?s", $status, $type, $lang_code);

	fn_set_hook('get_status_data', $data, $status, $type, $object_id, $lang_code);

	return $data;
}

//
//Get order payment data
//
function fn_get_payment_data($payment_id, $object_id = 0, $lang_code = CART_LANGUAGE)
{	
	$data = db_get_row("SELECT * FROM ?:payment_descriptions WHERE payment_id = ?i AND lang_code = ?s", $payment_id, $lang_code);
	
	fn_set_hook('get_payment_data', $data, $payment_id, $object_id, $lang_code);
	
	return $data;
}

//
// Get all order statuses
//
function fn_get_statuses($type = STATUSES_ORDER, $simple = false, $additional_statuses = false, $exclude_parent = false, $lang_code = CART_LANGUAGE)
{
	if ($simple) {
		$statuses = db_get_hash_single_array("SELECT a.status, b.description FROM ?:statuses as a LEFT JOIN ?:status_descriptions as b ON b.status = a.status AND b.type = a.type AND b.lang_code = ?s WHERE a.type = ?s", array('status', 'description'), $lang_code, $type);
		if ($type == STATUSES_ORDER && !empty($additional_statuses)) {
			$statuses['N'] = fn_get_lang_var('incompleted', $lang_code);
			if (empty($exclude_parent)) {
				$statuses[STATUS_PARENT_ORDER] = fn_get_lang_var('parent_order', $lang_code);
			}
		}
	} else {
		$statuses = db_get_hash_array("SELECT a.status, b.description FROM ?:statuses as a LEFT JOIN ?:status_descriptions as b ON b.status = a.status AND b.type = a.type AND b.lang_code = ?s WHERE a.type = ?s", 'status', $lang_code, $type);
		foreach ($statuses as $status => $data) {
			$statuses[$status] = fn_array_merge($statuses[$status], fn_get_status_params($status, $type));
		}
		if ($type == STATUSES_ORDER && !empty($additional_statuses)) {
			$statuses[STATUS_INCOMPLETED_ORDER] = array (
				'status' => STATUS_INCOMPLETED_ORDER,
				'description' => fn_get_lang_var('incompleted', $lang_code),
				'inventory' => 'I',
				'type' => STATUSES_ORDER,
			);
			if (empty($exclude_parent)) {
				$statuses[STATUS_PARENT_ORDER] = array (
					'status' => STATUS_PARENT_ORDER,
					'description' => fn_get_lang_var('parent_order', $lang_code),
					'inventory' => 'I',
					'type' => STATUSES_ORDER,
				);
			}
		}
	}

	return $statuses;
}

function fn_get_status_params($status, $type = STATUSES_ORDER)
{

	return db_get_hash_single_array("SELECT param, value FROM ?:status_data WHERE status = ?s AND type = ?s", array('param', 'value'), $status, $type);
}

/**
 * Gets parameter value of the status
 * 
 * @param string $status Status code
 * @param string $param Parameter name
 * @param string $type Status type (order type defualt)
 * @return string Parameter value
 */
function fn_get_status_param_value($status, $param, $type = STATUSES_ORDER)
{

	return db_get_field("SELECT value FROM ?:status_data WHERE status = ?s AND param = ?s AND type = ?s", $status, $param, $type);
}

//
// Delete product from the cart
//
function fn_delete_cart_product(&$cart, $cart_id, $full_erase = true)
{
	fn_set_hook('delete_cart_product', $cart, $cart_id, $full_erase);

	if (!empty($cart_id) && !empty($cart['products'][$cart_id])) {
		// Decrease product popularity
		$product_id = $cart['products'][$cart_id]['product_id'];
		
		$_data = array (
			'product_id' => $product_id,
			'deleted' => 1,
			'total' => 0
		);
		
		db_query("INSERT INTO ?:product_popularity ?e ON DUPLICATE KEY UPDATE deleted = deleted + 1, total = total - ?i", $_data, POPULARITY_DELETE_FROM_CART);
		
		unset($_SESSION['products_popularity']['added'][$product_id]);
		
		// Delete saved product files
		if (isset($cart['products'][$cart_id]['extra']['custom_files'])) {
			foreach ($cart['products'][$cart_id]['extra']['custom_files'] as $option_id => $images) {
				if (!empty($images)) {
					foreach ($images as $image) {
						@unlink($image['path']);
						@unlink($image['path'] . '_thumb');
					}
				}
			}
		}
		
		unset($cart['products'][$cart_id]);
		$cart['recalculate'] = true;
	}

	return true;
}

//
// Checks whether this order used the current payment and calls the payment_cc_complete.php file
//
function fn_check_payment_script($script_name, $order_id, &$processor_data = null)
{
	$payment_id = db_get_field("SELECT payment_id FROM ?:orders WHERE order_id = ?i", $order_id);
	$processor_data = fn_get_processor_data($payment_id);
	if ($processor_data['processor_script'] == $script_name) {
		return true;
	}
	return false;
}

//
// This function calculates product prices without taxes and with taxes
//
function fn_get_taxed_and_clean_prices(&$product, &$auth)
{
	$tax_value = 0;
	$included_tax = false;

	if (empty($product) || empty($product['product_id']) || empty($product['tax_ids'])) {
		return false;
	}
	if (isset($product['subtotal'])) {
		$tx_price =  $product['subtotal'];
	} elseif (empty($product['price'])) {
		$tx_price = 0;
	} elseif (isset($product['discounted_price'])) {
		$tx_price = $product['discounted_price'];
	} else {
		$tx_price = $product['price'];
	}

	$product_taxes = fn_get_set_taxes($product['tax_ids']);

	$calculated_data = fn_calculate_tax_rates($product_taxes, $tx_price, 1, $auth, $_SESSION['cart']);
	// Apply taxes to product subtotal
	if (!empty($calculated_data)) {
		foreach ($calculated_data as $_k => $v) {
			$tax_value += $v['tax_subtotal'];
			if ($v['price_includes_tax'] != 'Y') {
				$included_tax = true;
				$tx_price += $v['tax_subtotal'];
			}
		}
	}

	$product['clean_price'] = $tx_price - $tax_value;
	$product['taxed_price'] = $tx_price;
	$product['taxes'] = $calculated_data;
	$product['included_tax'] = $included_tax;

	return true;
}

function fn_clear_cart(&$cart, $complete = false, $clear_all = false)
{
	fn_set_hook('clear_cart', $cart, $complete, $clear_all);
	
	// Decrease products popularity
	if (!empty($cart['products'])) {
		$pids = array();
		
		foreach ($cart['products'] as $product) {
			$pids[] = $product['product_id'];
			unset($_SESSION['products_popularity']['added'][$product['product_id']]);
		}
		
		db_query("UPDATE ?:product_popularity SET deleted = deleted + 1, total = total - ?i WHERE product_id IN (?n)", POPULARITY_DELETE_FROM_CART, $pids);
	}
	
	if ($clear_all) {
		$cart = array();
	} else {
		$cart = array (
			'products' => array(),
			'recalculate' => false,
			'user_data' => !empty($cart['user_data']) && $complete == false ? $cart['user_data'] : array(),
		);
	}
	
	return true;
}

function fn_apply_cart_shipping_rates(&$cart, &$cart_products, &$auth, &$shipping_rates)
{
	$cart['shipping_failed'] = true;

	if (!fn_is_empty($shipping_rates)) {

		// Delete all free shippings
		foreach ($shipping_rates as $k => $v) {
			if (!empty($v['free_shipping']) && !in_array($k, $cart['free_shipping'])) {
				if (!empty($v['original_rates'])) {
					$shipping_rates[$k]['rates'] = $v['original_rates'];
					unset($shipping_rates[$k]['free_shipping']);
				} else {
					unset($shipping_rates[$k]);
				}
			}
		}

		// Set free shipping rates
		if (!empty($cart['free_shipping'])) {
			foreach ($cart['free_shipping'] as $sh_id) {
				if (isset($shipping_rates[$sh_id])) {
					if (empty($shipping_rates[$sh_id]['added_manually'])) {
						if (empty($shipping_rates[$sh_id]['original_rates'])) { // save original rates
							$shipping_rates[$sh_id]['original_rates'] = $shipping_rates[$sh_id]['rates'];
						}
						foreach ($shipping_rates[$sh_id]['rates'] as $_k => $_v) { // null rates
							$shipping_rates[$sh_id]['rates'][$_k] = 0;
						}
					}
				} else {
					$name = db_get_row("SELECT b.shipping as name, b.delivery_time FROM ?:shippings as a LEFT JOIN ?:shipping_descriptions as b ON a.shipping_id = b.shipping_id AND b.lang_code = ?s WHERE a.shipping_id = ?i AND a.status = 'A'", CART_LANGUAGE, $sh_id);
					if (!empty($name)) {
						$shipping_rates[$sh_id] = $name;
						$shipping_rates[$sh_id]['rates'] = array(0);
						$shipping_rates[$sh_id]['added_manually'] = true;
					}
				}

				if (isset($shipping_rates[$sh_id])) {
					$shipping_rates[$sh_id]['free_shipping'] = true;
				}
			}
			
			$positions = db_get_hash_array('SELECT position, shipping_id FROM ?:shippings WHERE shipping_id IN (?a)', 'shipping_id', array_keys($shipping_rates));
			foreach ($positions as $shipping_id => $position) {
				$shipping_rates[$shipping_id]['position'] = $position['position'];
			}
			
			$shipping_rates = fn_sort_array_by_key($shipping_rates, 'position', SORT_ASC);
		}

		// Delete not existent rates
		if (!empty($cart['shipping'])) {
			foreach ($cart['shipping'] as $sh_id => $v) {
				foreach ($v['rates'] as $o_id => $r) {
					if (!isset($shipping_rates[$sh_id]['rates'][$o_id]) && empty($shipping_rates[$sh_id]['added_manually'])) {
						unset($cart['shipping'][$sh_id]);
					}
				}
			}
		}

		if (fn_check_suppliers_functionality()) {
			fn_companies_apply_cart_shipping_rates($cart, $cart_products, $auth, $shipping_rates);
		} else {
			if (isset($cart['shipping']) && false != reset($cart['shipping']) && isset($shipping_rates[key($cart['shipping'])])) {
				$k = key($cart['shipping']);
				$first_method = $shipping_rates[$k];

				// enables to select the last chosen shipping method on checkout
			} elseif (isset($cart['chosen_shipping']) && false != reset($cart['chosen_shipping']) && isset($shipping_rates[key($cart['chosen_shipping'])])) {
				$cart['shipping'] = $cart['chosen_shipping'];
				$k = key($cart['chosen_shipping']);
				$first_method = $shipping_rates[$k];
			} else {
				$cart['shipping'] = array();
				$first_method = reset($shipping_rates);
				$k = key($shipping_rates);
			}
			
			$cart['shipping_cost'] = reset($first_method['rates']);
			$cart['shipping'] = fn_array_merge(isset($cart['shipping']) ? $cart['shipping'] : array(), array(
				$k => array(
					'shipping' => $first_method['name'],
					'rates' => $first_method['rates'],
					'packages_info' => isset($first_method['packages_info']) ? $first_method['packages_info'] : array(),
				)
			));
		}

		if (!empty($cart['shipping'])) {
			$cart['shipping_failed'] = false;
		}
		
		fn_set_hook('apply_cart_shipping_rates', $cart, $cart_products, $auth, $shipping_rates);
	}
}

function fn_external_discounts($product)
{
	$discounts = 0;

	fn_set_hook('get_external_discounts', $product, $discounts);

	return $discounts;
}

// FIX-EVENT - must be revbuilt to check edp, free, etc
function fn_exclude_from_shipping_calculate($product)
{
	$exclude = false;

	fn_set_hook('exclude_from_shipping_calculation', $product, $exclude);

	return $exclude;
}
//
// This function is used to find out the total shipping cost. Used in payments, quickbooks
//

function fn_order_shipping_cost($order_info)
{
	$cost = (floatval($order_info['shipping_cost'])) ? $order_info['shipping_cost'] : 0;

	if (floatval($order_info['shipping_cost'])) {
		foreach($order_info['taxes'] as $tax) {
			if ($tax['price_includes_tax'] == 'N') {
				foreach ($tax['applies'] as $_id => $value) {
					if (strpos($_id, 'S_') !== false) {
						$cost += $value;
					}
				}
			}
		}
	}

	return $cost ? fn_format_price($cost) : 0;
}

//
// Cleanup payment information
//
function fn_cleanup_payment_info($order_id = '', $payment_info, $silent = false)
{

	if ($silent == false) {
		fn_set_progress('echo', fn_get_lang_var('processing_order') . '&nbsp;<b>#'.$order_id.'</b>...');
	}

	if (!is_array($payment_info)) {
		$info = @unserialize(fn_decrypt_text($payment_info));
	} else {
		$info = $payment_info;
	}

	if (!empty($info['cvv2'])) {
		$info['cvv2'] = 'XXX';
	}
	if (!empty($info['card_number'])) {
		$info['card_number'] = substr_replace($info['card_number'], str_repeat('X', strlen($info['card_number']) - 4), 0, strlen($info['card_number']) - 4);
	}

	foreach (array('start_month', 'start_year', 'expiry_month', 'expiry_year') as $v) {
		if (!empty($info[$v])) {
			$info[$v] = 'XX';
		}
	}

	$_data = fn_encrypt_text(serialize($info));
	if (!empty($order_id)) {
		db_query("UPDATE ?:order_data SET data = ?s WHERE order_id = ?i AND type = 'P'", $_data, $order_id);
	} else {
		return $_data;
	}
}

//
// Checks if order can be placed
//
function fn_allow_place_order(&$cart)
{
	$total = Registry::get('settings.General.min_order_amount_type') == "S" ? $cart['total'] : $cart['subtotal'];

	fn_set_hook('allow_place_order', $total, $cart);

	$cart['amount_failed'] = (Registry::get('settings.General.min_order_amount') > $total && floatval($total));

	if (!empty($cart['amount_failed']) || !empty($cart['shipping_failed']) || !empty($cart['company_shipping_failed'])) {
		return false;
	}

	return true;
}

/**
 * Returns orders
 *
 * @param array $params array with search params
 * @param int $items_per_page
 * @param bool $get_totals
 * @param string $lang_code
 * @return array
 */

function fn_get_orders($params, $items_per_page = 0, $get_totals = false, $lang_code = CART_LANGUAGE)
{
	// Init filter
	$params = fn_init_view('orders', $params);

	// Set default values to input params
	$params['page'] = empty($params['page']) ? 1 : $params['page']; // default page is 1

	if (AREA != 'C') {
		$params['include_incompleted'] = empty($params['include_incompleted']) ? false : $params['include_incompleted']; // default incomplited orders should not be displayed
		if (!empty($params['status']) && (is_array($params['status']) && in_array(STATUS_INCOMPLETED_ORDER, $params['status']) || !is_array($params['status']) && $params['status'] == STATUS_INCOMPLETED_ORDER)) {
			$params['include_incompleted'] = true;
		}
	} else {
		$params['include_incompleted'] = false;
	}

	// Define fields that should be retrieved
	$fields = array (
		"distinct ?:orders.order_id",
		"?:orders.user_id",
		"?:orders.is_parent_order",
		"?:orders.parent_order_id",
		"?:orders.company_id",
		"?:orders.timestamp",
		"?:orders.firstname",
		"?:orders.lastname",
		"?:orders.email",
		"?:orders.status",
		"?:orders.total",
		"invoice_docs.doc_id as invoice_id",
		"memo_docs.doc_id as credit_memo_id"
	);

	// Define sort fields
	$sortings = array (
		'order_id' => "?:orders.order_id",
		'status' => "?:orders.status",
		'customer' => array("?:orders.lastname", "?:orders.firstname"),
		'email' => "?:orders.email",
		'date' => array("?:orders.timestamp", "?:orders.order_id"),
		'total' => "?:orders.total",
	);

	$directions = array (
		'asc' => 'asc',
		'desc' => 'desc'
	);

	fn_set_hook('pre_get_orders', $params, $fields, $sortings, $items_per_page, $get_totals, $lang_code);

	if (empty($params['sort_order']) || empty($directions[$params['sort_order']])) {
		$params['sort_order'] = 'desc';
	}

	if (empty($params['sort_by']) || empty($sortings[$params['sort_by']])) {
		$params['sort_by'] = 'date';
	}

	$sorting = (is_array($sortings[$params['sort_by']]) ? implode(' ' . $directions[$params['sort_order']] . ', ', $sortings[$params['sort_by']]): $sortings[$params['sort_by']]) . ' ' . $directions[$params['sort_order']];

	// Reverse sorting (for usage in view)
	$params['sort_order'] = $params['sort_order'] == 'asc' ? 'desc' : 'asc';

	if (isset($params['compact']) && $params['compact'] == 'Y') {
		$union_condition = ' OR ';
	} else {
		$union_condition = ' AND ';
	}

	$condition = $_condition = $join = $group = '';
	
	$condition .= " AND ?:orders.is_parent_order != 'Y' ";
	$condition .= fn_get_company_condition('?:orders.company_id');

	if (isset($params['cname']) && fn_string_not_empty($params['cname'])) {
		$arr = fn_explode(' ', $params['cname']);
		foreach ($arr as $k => $v) {
			if (!fn_string_not_empty($v)) {
				unset($arr[$k]);
			}
		}
		if (sizeof($arr) == 2) {
			$_condition .= db_quote(" $union_condition ?:orders.firstname LIKE ?l AND ?:orders.lastname LIKE ?l", "%" . array_shift($arr) . "%", "%" . array_shift($arr) . "%");
		} else {
			$_condition .= db_quote(" $union_condition (?:orders.firstname LIKE ?l OR ?:orders.lastname LIKE ?l)", "%" . trim($params['cname']) . "%", "%" . trim($params['cname']) . "%");
		}
	}

	if (isset($params['company_id']) && $params['company_id'] != '') {
		$condition .= db_quote(' AND ?:orders.company_id = ?i ', $params['company_id']);
	}

	if (!empty($params['tax_exempt'])) {
		$condition .= db_quote(" AND ?:orders.tax_exempt = ?s", $params['tax_exempt']);
	}

	if (isset($params['email']) && fn_string_not_empty($params['email'])) {
		$_condition .= db_quote(" $union_condition ?:orders.email LIKE ?l", "%" . trim($params['email']) . "%");
	}

	if (!empty($params['user_id'])){
		$condition .= db_quote(' AND ?:orders.user_id IN (?n)', $params['user_id']);
	}

	if (isset($params['total_from']) && fn_is_numeric($params['total_from'])) {
		$condition .= db_quote(" AND ?:orders.total >= ?d", fn_convert_price($params['total_from']));
	}

	if (!empty($params['total_to']) && fn_is_numeric($params['total_to'])) {
		$condition .= db_quote(" AND ?:orders.total <= ?d", fn_convert_price($params['total_to']));
	}

	if (!empty($params['status'])) {
		$condition .= db_quote(' AND ?:orders.status IN (?a)', $params['status']);
	}

	if (empty($params['include_incompleted'])) {
		$condition .= db_quote(' AND ?:orders.status != ?s', STATUS_INCOMPLETED_ORDER);
	}

	if (!empty($params['order_id'])) {
		$_condition .= db_quote($union_condition . ' ?:orders.order_id IN (?n)', (!is_array($params['order_id']) && (strpos($params['order_id'], ',') !== false) ? explode(',', $params['order_id']) : $params['order_id']));
	}

	if (!empty($params['p_ids']) || !empty($params['product_view_id'])) {
		$arr = (strpos($params['p_ids'], ',') !== false || !is_array($params['p_ids'])) ? explode(',', $params['p_ids']) : $params['p_ids'];

		if (empty($params['product_view_id'])) {
			$condition .= db_quote(" AND ?:order_details.product_id IN (?n)", $arr);
		} else {
			$condition .= db_quote(" AND ?:order_details.product_id IN (?n)", db_get_fields(fn_get_products(array('view_id' => $params['product_view_id'], 'get_query' => true))));
		}

		$join .= " LEFT JOIN ?:order_details ON ?:order_details.order_id = ?:orders.order_id";
		$group .=  " GROUP BY ?:orders.order_id ";
	}

	if (!empty($params['admin_user_id'])) {
		$condition .= db_quote(" AND ?:new_orders.user_id = ?i", $params['admin_user_id']);
		$join .= " LEFT JOIN ?:new_orders ON ?:new_orders.order_id = ?:orders.order_id";
	}

	$docs_conditions = array();
	if (!empty($params['invoice_id']) || !empty($params['has_invoice'])) {
		if (!empty($params['has_invoice'])) {
			$docs_conditions[] = "invoice_docs.doc_id IS NOT NULL";
		} elseif (!empty($params['invoice_id'])) {
			$docs_conditions[] = db_quote("invoice_docs.doc_id = ?i", $params['invoice_id']);
		}
	}
	$join .= " LEFT JOIN ?:order_docs as invoice_docs ON invoice_docs.order_id = ?:orders.order_id AND invoice_docs.type = 'I'";

	if (!empty($params['credit_memo_id']) || !empty($params['has_credit_memo'])) {
		if (!empty($params['has_credit_memo'])) {
			$docs_conditions[] = "memo_docs.doc_id IS NOT NULL";
		} elseif (!empty($params['credit_memo_id'])) {
			$docs_conditions[] = db_quote("memo_docs.doc_id = ?i", $params['credit_memo_id']);
		}
	}
	$join .= " LEFT JOIN ?:order_docs as memo_docs ON memo_docs.order_id = ?:orders.order_id AND memo_docs.type = 'C'";

	if (!empty($docs_conditions)) {
		$condition .= ' AND (' . implode(' OR ', $docs_conditions) . ')';
	}

	if (!empty($params['shippings'])) {
		$set_conditions = array();
		foreach ($params['shippings'] as $v) {
			$set_conditions[] = db_quote("FIND_IN_SET(?s, ?:orders.shipping_ids)", $v);
		}
		$condition .= ' AND (' . implode(' OR ', $set_conditions) . ')';
	}

	if (!empty($params['payments'])) {
		$condition .= db_quote(" AND ?:orders.payment_id IN (?a)", $params['payments']);
	}

	if (!empty($params['period']) && $params['period'] != 'A') {
		list($params['time_from'], $params['time_to']) = fn_create_periods($params);

		$condition .= db_quote(" AND (?:orders.timestamp >= ?i AND ?:orders.timestamp <= ?i)", $params['time_from'], $params['time_to']);
	}
	
	if (!empty($params['custom_files']) && $params['custom_files'] == 'Y') {
		$condition .= db_quote(" AND ?:order_details.extra LIKE ?l", '%custom_files%');
		
		if (empty($params['p_ids']) && empty($params['product_view_id'])) {
			$join .= " LEFT JOIN ?:order_details ON ?:order_details.order_id = ?:orders.order_id";
		}
	}
	
	if (!empty($params['company_name'])) {
		$fields[] = '?:companies.company as company_name';
		$join .= " LEFT JOIN ?:companies ON ?:companies.company_id = ?:orders.company_id";
	}
	
	if (!empty($_condition)) {
		$condition .= ' AND (' . ($union_condition == ' OR ' ? '0 ' : '1 ') . $_condition . ')';
	}
	
	fn_set_hook('get_orders', $params, $fields, $sortings, $condition, $join, $group);

	// Used for Extended search
	if (!empty($params['get_conditions'])) {
		return array($fields, $join, $condition);
	}

	$limit = '';
	if (!empty($items_per_page)) {
		$total = db_get_field("SELECT COUNT(DISTINCT (?:orders.order_id)) FROM ?:orders $join WHERE 1 $condition");
		$limit = fn_paginate($params['page'], $total, $items_per_page);
	}

	$orders = db_get_array('SELECT ' . implode(', ', $fields) . " FROM ?:orders $join WHERE 1 $condition $group ORDER BY $sorting $limit");

	if (!empty($params['check_for_suppliers'])) {
		if (Registry::get('settings.Suppliers.enable_suppliers') == 'Y') {
			foreach ($orders as &$order) {
				$order['items'] = db_get_hash_array("SELECT ?:order_details.* FROM ?:order_details WHERE ?:order_details.order_id = ?i", 'item_id', $order['order_id']);
				foreach ($order['items'] as $k => &$v) {
					$v = @unserialize($v['extra']);
					$v['company_id'] = empty($v['company_id']) ? 0 : $v['company_id'];
				}
				$order['companies'] = fn_get_products_companies($order['items']);
				$order['have_suppliers'] = fn_check_companies_have_suppliers($order['companies']);
			}
		} elseif (PRODUCT_TYPE == 'MULTIVENDOR') {
			foreach ($orders as &$order) {
				$order['have_suppliers'] = empty($order['company_id'])? 'N' : 'Y';
			}
		}
	}

	if ($get_totals == true) {
		$paid_statuses = array('P', 'C');
		fn_set_hook('get_orders_totals', $paid_statuses, $join, $condition, $group);
		$totals = array (
			'gross_total' => db_get_field("SELECT sum(t.total) FROM ( SELECT total FROM ?:orders $join WHERE 1 $condition $group) as t"),
			'totally_paid' => db_get_field("SELECT sum(t.total) FROM ( SELECT total FROM ?:orders $join WHERE ?:orders.status IN (?a) $condition $group) as t", $paid_statuses),
		);
	}

	fn_view_process_results('orders', $orders, $params, $items_per_page);

	return array($orders, $params, ($get_totals == true ? $totals : array()));
}

/**
 * Calculate shipping rates using real-time shipping processors
 *
 * @param int $service_id shipping service ID
 * @param array $location customer location
 * @param array $package_info package information (weight, subtotal, qty)
 * @param array $auth customer session information
 * @param array $substitution_settings settings what can replace default shipping origination
 * @return mixed array with rates if calculated, false otherwise
 */
function fn_calculate_realtime_shipping_rate($service_id, $location, $package_info, &$auth, $shipping_id, $allow_multithreading = false, $new_settings = array(), $company_id = 0)
{
	static $shipping_settings = array();

	$code = fn_get_shipping_service_data($service_id);

	if (empty($code)) {
		return false;
	}
	
	if (empty($shipping_settings)) {
		$shipping_settings = CSettings::instance()->get_values('Shippings');
	}
	if (!empty($new_settings)) {
		$shipping_settings[$code['module']] = $new_settings;
	} else {
		$shipping_settings[$code['module']] = fn_get_shipping_params($shipping_id);
	}

	include_once(DIR_LIB . 'xmldocument/xmldocument.php');
	include_once(DIR_SHIPPING_FILES . $code['module'] . '.php');

	$func = 'fn_get_' . $code['module'] . '_rates';
	$weight = fn_expand_weight($package_info['W']);

	return $func($code['code'], $weight, $location, $auth, $shipping_settings, $package_info, $package_info['origination'], $service_id, $allow_multithreading, $company_id);
}

/**
 * Gets shipping method parameters by identifier
 * 
 * @param int $shipping_id Shipping identifier
 * @return array Shipping parameters
 */
function fn_get_shipping_params($shipping_id)
{
	$params = array();
	if ($shipping_id) {
		$params = db_get_field("SELECT params FROM ?:shippings WHERE shipping_id = ?i", $shipping_id);
		$params = unserialize($params);
	}
	return $params;
}

/**
 * Gets shipping service data by identifier
 * 
 * @param int $service_id Shipping service identifier
 * @return array Shipping service data
 */
function fn_get_shipping_service_data($service_id)
{
	static $services = array();

	if (!isset($services[$service_id])) {

		$service = db_get_row("SELECT code, module FROM ?:shipping_services WHERE service_id = ?i AND status = 'A'", $service_id);

		if (empty($service)) {
			$services[$service_id] = false;
			return false;
		}

		$services[$service_id] = $service;
	}

	return $services[$service_id];
}

/**
 * Convert weight to pounds/ounces
 *
 * @param float $weight weight
 * @return array converted data
 */
function fn_expand_weight($weight)
{
	$full_ounces = ceil(round($weight * Registry::get('settings.General.weight_symbol_grams') / 28.35, 3));
	$full_pounds = sprintf("%.1f", $full_ounces/16);
	$pounds = floor($full_ounces/16);
	$ounces = $full_ounces - $pounds * 16;

	return array (
		'full_ounces' => $full_ounces,
		'full_pounds' => $full_pounds,
		'pounds' => $pounds,
		'ounces' => $ounces,
		'plain' => $weight,
	);
}

/**
 * Generate unique ID to cache rates calculation results
 *
 * @param mixed parameters to generate unique ID from
 * @return mixed array with rates if calculated, false otherwise
 */
function fn_generate_cached_rate_id()
{
	return md5(serialize(func_get_args()));
}

/**
 * Send order notification
 *
 * @param array $order_info order information
 * @param array $edp_data information about downloadable products
 * @param mixed $force_notification user notification flag (true/false), if not set, will be retrieved from status parameters
 * @return array structured data
 */
function fn_order_notification(&$order_info, $edp_data = array(), $force_notification = array())
{
	static $notified = array();

	$send_order_notification = true;

	if ((!empty($notified[$order_info['order_id']][$order_info['status']]) && $notified[$order_info['order_id']][$order_info['status']]) || $order_info['status'] == STATUS_INCOMPLETED_ORDER || $order_info['status'] == STATUS_PARENT_ORDER) {
		$send_order_notification = false;
	}
	
	fn_set_hook('send_order_notification', $order_info, $edp_data, $force_notification, $notified, $send_order_notification);

	if (!$send_order_notification) {
		return true;
	}
	
	if (!is_array($force_notification)) {
		$force_notification = fn_get_notification_rules($force_notification, !$force_notification);	
	}

	$order_statuses = fn_get_statuses(STATUSES_ORDER, false, true);
	$status_params = $order_statuses[$order_info['status']];

	$notify_user = isset($force_notification['C']) ? $force_notification['C'] : (!empty($status_params['notify']) && $status_params['notify'] == 'Y' ? true : false);
	$notify_department = isset($force_notification['A']) ? $force_notification['A'] : (!empty($status_params['notify_department']) && $status_params['notify_department'] == 'Y' ? true : false);
	$notify_supplier = isset($force_notification['S']) ? $force_notification['S'] : (!empty($status_params['notify_supplier']) && $status_params['notify_supplier'] == 'Y' ? true : false);

	$company_id = null;
	if (PRODUCT_TYPE == 'MULTIVENDOR' || PRODUCT_TYPE == 'ULTIMATE') {
		$company_id = $order_info['company_id'];
	}
	$company = fn_get_company_placement_info($company_id, $order_info['lang_code']);
	Registry::get('view_mail')->assign('company_placement_info', $company);
	Registry::get('view_mail')->assign('manifest', fn_get_manifest('customer', $order_info['lang_code'], $company_id));

	if ($notify_user == true || $notify_department == true || $notify_supplier == true) {

		$notified[$order_info['order_id']][$order_info['status']] = true;

		Registry::get('view_mail')->assign('order_info', $order_info);
		Registry::get('view_mail')->assign('order_status', fn_get_status_data($order_info['status'], STATUSES_ORDER, $order_info['order_id'], $order_info['lang_code']));
		Registry::get('view_mail')->assign('payment_method', fn_get_payment_data((!empty($order_info['payment_method']['payment_id']) ? $order_info['payment_method']['payment_id'] : 0), $order_info['order_id'], $order_info['lang_code']));
		Registry::get('view_mail')->assign('status_settings', $order_statuses[$order_info['status']]);
		Registry::get('view_mail')->assign('profile_fields', fn_get_profile_fields('I', '', $order_info['lang_code']));

		// restore secondary currency
		if (!empty($order_info['secondary_currency']) && Registry::get("currencies.{$order_info['secondary_currency']}")) {
			Registry::get('view_mail')->assign('secondary_currency', $order_info['secondary_currency']);
		}

		// Notify customer
		if ($notify_user == true) {
			Registry::get('view_mail')->assign('manifest', fn_get_manifest('customer', $order_info['lang_code'], $company_id));

			fn_send_mail($order_info['email'], array('email' => $company['company_orders_department'], 'name' => $company['company_name']), 'orders/order_notification_subj.tpl', 'orders/order_notification.tpl', '', $order_info['lang_code'], '', true, $order_info['company_id']);

			if (!empty($edp_data)) {
				Registry::get('view_mail')->assign('edp_data', $edp_data);
				fn_send_mail($order_info['email'], array('email' => $company['company_orders_department'], 'name' => $company['company_name']), 'orders/edp_access_subj.tpl', 'orders/edp_access.tpl', '', $order_info['lang_code'], '', true, $order_info['company_id']);
			}
		}

		// Notify supplier or vendor
		if ($notify_supplier == true) {
			if (PRODUCT_TYPE == 'PROFESSIONAL') {

				fn_companies_suppliers_order_notification($order_info, $order_statuses, $force_notification);

			} elseif (PRODUCT_TYPE == 'MULTIVENDOR' && !empty($company_id)) {

				Registry::get('view_mail')->assign('manifest', fn_get_manifest('customer', $company['lang_code'], $company_id));
				Registry::get('view_mail')->assign('company_placement_info', fn_get_company_placement_info($company_id, $company['lang_code']));

				// Translate descriptions to admin language
				fn_translate_products($order_info['items'], '', fn_get_company_language($company_id), true);
				Registry::get('view_mail')->assign('payment_method', fn_get_payment_data($order_info['payment_method']['payment_id'], $order_info['order_id'], fn_get_company_language($company_id)));
				Registry::get('view_mail')->assign('order_info', $order_info);
				Registry::get('view_mail')->assign('order_status', fn_get_status_data($order_info['status'], STATUSES_ORDER, $order_info['order_id'], $company['lang_code']));
				Registry::get('view_mail')->assign('profile_fields', fn_get_profile_fields('I', '', $company['lang_code']));

				fn_send_mail($company['company_orders_department'], Registry::get('settings.Company.company_orders_department'), 'orders/order_notification_subj.tpl', 'orders/order_notification.tpl', '', $company['lang_code'], $order_info['email'], true, $order_info['company_id']);
			}
		}

		// Notify order department
		if ($notify_department == true) {
			Registry::get('view_mail')->assign('manifest', fn_get_manifest('customer', Registry::get('settings.Appearance.admin_default_language'), $company_id));
			Registry::get('view_mail')->assign('company_placement_info', fn_get_company_placement_info($company_id, Registry::get('settings.Appearance.admin_default_language')));
			// Translate descriptions to admin language
			fn_translate_products($order_info['items'], '', Registry::get('settings.Appearance.admin_default_language'), true);
			if (!empty($order_info['payment_method']['payment_id'])) {
				Registry::get('view_mail')->assign('payment_method', fn_get_payment_data($order_info['payment_method']['payment_id'], $order_info['order_id'], Registry::get('settings.Appearance.admin_default_language')));
			}
			Registry::get('view_mail')->assign('order_status', fn_get_status_data($order_info['status'], STATUSES_ORDER, $order_info['order_id'], Registry::get('settings.Appearance.admin_default_language')));
			Registry::get('view_mail')->assign('order_info', $order_info);
			Registry::get('view_mail')->assign('profile_fields', fn_get_profile_fields('I', '', Registry::get('settings.Appearance.admin_default_language')));

			fn_send_mail(Registry::get('settings.Company.company_orders_department'), Registry::get('settings.Company.company_orders_department'), 'orders/order_notification_subj.tpl', 'orders/order_notification.tpl', '', Registry::get('settings.Appearance.admin_default_language'), $order_info['email'], true, $order_info['company_id']);
		}

	}

	if (!empty($edp_data) && !$notify_user) {
		// Send out download links for EDP with "Immediately" Activation mode

		// TRUE if the EDP download links e-mail has already been sent. Used to avoid sending duplicate e-mails.
		$download_email_sent = false;
		foreach ($edp_data as $edp_item) {
			foreach ($edp_item['files'] as $file) {
				if (!empty($file['activation']) && $file['activation'] == 'I' && !$download_email_sent) {
					Registry::get('view_mail')->assign('edp_data', $edp_data);
					Registry::get('view_mail')->assign('order_info', $order_info);
					fn_send_mail($order_info['email'], array('email' => $company['company_orders_department'], 'name' => $company['company_name']), 'orders/edp_access_subj.tpl', 'orders/edp_access.tpl', '', $order_info['lang_code']);
					$download_email_sent = true;
					break;
				}
			}
		}
	}

	fn_set_hook('order_notification', $order_info, $order_statuses, $force_notification);
}

function fn_prepare_package_info(&$cart, &$cart_products)
{
	$package_infos = array();
	
	$groupped_products = array();

	foreach ($cart['products'] as $k => $v) {
		if (!isset($v['company_id'])) {
			// for old saved carts
			$cart['products'][$k]['company_id'] = $v['company_id'] = $cart_products[$k]['company_id'] = 0;
		}
		
		if (PRODUCT_TYPE == 'ULTIMATE') {
			$_company_id = defined('COMPANY_ID') ? COMPANY_ID : 0;
		} else {
			$_company_id = $v['company_id'];
		}
		$groupped_products[$_company_id][$k] = $cart_products[$k];
		
		if ($cart_products[$k]['free_shipping'] == 'Y' || ($cart_products[$k]['is_edp'] == 'Y' && $cart_products[$k]['edp_shipping'] != 'Y')) {
			$package_infos[$_company_id]['has_free_shipping'] = true;
		} else {
			$package_infos[$_company_id]['need_shipping'] = true;
		}
	}
	
	foreach ($groupped_products as $_cid => $products) {
		// Leave this code to back compability
		$package_infos[$_cid]['C'] = fn_get_products_cost($cart, $products);
		$package_infos[$_cid]['W'] = fn_get_products_weight($cart, $products);
		$package_infos[$_cid]['I'] = fn_get_products_amount($cart, $products);
		
		$package_infos[$_cid]['packages'] = fn_get_products_packages($cart, $products);
		
		if (empty($package_infos[$_cid]['origination'])) {
			if (!$_cid || PRODUCT_TYPE == 'ULTIMATE') {
				$package_infos[$_cid]['origination'] = array(
					'name' => Registry::get('settings.Company.company_name'),
					'address' => Registry::get('settings.Company.company_address'),
					'city' => Registry::get('settings.Company.company_city'),
					'country' => Registry::get('settings.Company.company_country'),
					'state' => Registry::get('settings.Company.company_state'),
					'zipcode' => Registry::get('settings.Company.company_zipcode'),
					'phone' => Registry::get('settings.Company.company_phone'),
					'fax' => Registry::get('settings.Company.company_fax'),
				);
			} else {
				$supplier_data = fn_get_company_data($_cid);
				$package_infos[$_cid]['origination'] = array(
					'name' => !empty($supplier_data['company']) ? $supplier_data['company'] : '',
					'phone' => !empty($supplier_data['phone']) ? $supplier_data['phone'] : '',
					'fax' => !empty($supplier_data['fax']) ? $supplier_data['fax'] : '',
					'country' => !empty($supplier_data['country']) ? $supplier_data['country'] : '',
					'state' => !empty($supplier_data['state']) ? $supplier_data['state'] : '',
					'zipcode' => !empty($supplier_data['zipcode']) ? $supplier_data['zipcode'] : '',
					'city' => !empty($supplier_data['city']) ? $supplier_data['city'] : '',
					'address' => !empty($supplier_data['address']) ? $supplier_data['address'] : '',
				);
			}
		}
		
		if (!empty($package_infos[$_cid]['need_shipping'])) {
			unset($package_infos[$_cid]['has_free_shipping'], $package_infos[$_cid]['need_shipping']);
		}
	}	
	
	fn_set_hook('prepare_package_info', $cart, $cart_products, $package_infos);
	
	return $package_infos;
}

/**
 *
 * @param int $payment_id payment ID
 * @param string $action action 
 * @return array (boolean, string) 
 */
function fn_check_processor_script($payment_id, $action, $additional_params = false)
{
	
	if ($additional_params) {
		if ($action == 'save' || (!empty($_REQUEST['skip_payment']) && AREA == 'C')){
			return array(false, '');
		}
	}	
	
	$payment = fn_get_payment_method_data((int)$payment_id);

	if (!empty($payment['processor_id'])) {
		$processor_data = fn_get_processor_data($payment['payment_id']);
		if (!empty($processor_data['processor_script']) && file_exists(DIR_PAYMENT_FILES . $processor_data['processor_script'])) {
			return array(true, $processor_data);
		}
	}

	return array(false, '');
}

/**
 * Check if store can use processor script
 *
 * @param string $processor name of processor script
 * @return bool 
 */
function fn_check_prosessor_status($processor)
{
	$is_active = false;

	$processor = fn_get_processor_data_by_name($processor . '.php');
	$payments = fn_get_payment_by_processor($processor['processor_id']);
	
	if (!empty($payments)) {
		foreach ($payments as $payment) {
			if ($payment['status'] == 'A') {
				$is_active = true;
			}
		}
	}

	return $is_active;
}

function fn_add_product_options_files($product_data, &$cart, &$auth, $update = false, $location = 'cart')
{
	// Check if products have cusom images
	if (!$update) {
		$uploaded_data = fn_filter_uploaded_data('product_data');
	} else {
		$uploaded_data = fn_filter_uploaded_data('cart_products');
	}
	
	$dir_path = DIR_CUSTOM_FILES . 'sess_data';
	
	// Check for the already uploaded files
	if (!empty($product_data['custom_files']['uploaded'])) {
		foreach ($product_data['custom_files']['uploaded'] as $file_id => $file_data) {
			if (file_exists($dir_path . '/' . fn_basename($file_data['path']))) {
				$id = $file_data['product_id'] . $file_data['option_id'] . $file_id;
				$uploaded_data[$id] = array(
					'name' => $file_data['name'],
					'path' => $dir_path . '/' . fn_basename($file_data['path']),
				);
				
				$product_data['custom_files'][$id] = $file_data['product_id'] . '_' . $file_data['option_id'];
			}
		}
	}
	
	if (!empty($uploaded_data) && !empty($product_data['custom_files'])) {
		$files_data = array();
		
		foreach ($uploaded_data as $key => $file) {
			$file_info = fn_pathinfo($file['name']);
			$file['extension'] = empty($file_info['extension']) ? '' : $file_info['extension'];
			$file['is_image'] = fn_get_image_extension($file['type']);
			
			$_data = explode('_', $product_data['custom_files'][$key]);
			$product_id = empty($_data[0]) ? 0 : $_data[0];
			$option_id = empty($_data[1]) ? 0 : $_data[1];
			$file_id = str_replace($option_id . $product_id, '', $key);
			
			if (empty($file_id)) {
				$files_data[$product_id][$option_id][] = $file;
			} else {
				$files_data[$product_id][$option_id][$file_id] = $file;
			}
		}

		if (!fn_mkdir($dir_path)) {
			// Unable to create a directory
			fn_set_notification('E', fn_get_lang_var('error'), str_replace('[directory]', DIR_CUSTOM_FILES, fn_get_lang_var('text_cannot_write_directory')));
		}
	}

	unset($product_data['custom_files']);

	foreach ($product_data as $key => $data) {
		$product_id = (!empty($data['product_id'])) ? $data['product_id'] : $key;
		
		// Check if product has cusom images
		if ($update || isset($files_data[$key])) {
			$hash = $key;
		} else {
			$hash = $product_id;
		}
		
		if (!empty($files_data[$hash]) && is_array($files_data[$hash])) {
			$_options = fn_get_product_options($product_id);
			
			foreach ($files_data[$hash] as $option_id => $files) {
				foreach ($files as $file_id => $file) {
					// Check for the allowed extensions
					if (!empty($_options[$option_id]['allowed_extensions'])) {
						if ((empty($file['extension']) && !empty($_options[$option_id]['allowed_extensions'])) || !preg_match("/\b" . $file['extension'] . "\b/i", $_options[$option_id]['allowed_extensions'])) {
							$message = fn_get_lang_var('text_forbidden_uploaded_file_extension');
							$message = str_replace('[ext]', $file['extension'], $message);
							$message = str_replace('[exts]', $_options[$option_id]['allowed_extensions'], $message);
							
							fn_set_notification('E', fn_get_lang_var('error'), $file['name'] . ': ' . $message);
							unset($files_data[$hash][$option_id][$file_id]);
							continue;
						}
					}
					
					// Check for the max file size
					
					if (!empty($_options[$option_id]['max_file_size'])) {
						if (empty($file['size'])) {
							$file['size'] = filesize($file['path']);
						}
						
						if ($file['size'] > $_options[$option_id]['max_file_size'] * 1024) {
							fn_set_notification('E', fn_get_lang_var('error'), str_replace('[size]', $_options[$option_id]['max_file_size'] . ' kb', $file['name'] . ': ' . fn_get_lang_var('text_forbidden_uploaded_file_size')));
							unset($files_data[$hash][$option_id][$file_id]);
							continue;
						}
					}
					
					$_file_path = tempnam($dir_path, 'file_');
					if (!fn_copy($file['path'], $_file_path)) {
						fn_set_notification('E', fn_get_lang_var('error'), str_replace('[file]', $file['name'], fn_get_lang_var('text_cannot_create_file')));
						
						unset($files_data[$hash][$option_id][$file_id]);
						continue;
					}
					
					$file['path'] = $_file_path;
					$file['file'] = fn_basename($file['path']);
					
					if ($file['is_image']) {
						$file['thumbnail'] = 'image.custom_image&image=' . $file['file'] . '&type=T';
						$file['detailed'] = 'image.custom_image&image=' . $file['file'] . '&type=D';
					}
					
					$file['location'] = $location;
					
					if ($update) {
						$cart['products'][$key]['extra']['custom_files'][$option_id][] = $file;
					} else {
						$data['extra']['custom_files'][$option_id][] = $file;
						
					}
				}
				
				if ($update) {
					if (!empty($cart['products'][$key]['product_options'][$option_id])) {
						$cart['products'][$key]['product_options'][$option_id] = md5(serialize($cart['products'][$key]['extra']['custom_files'][$option_id]));
					}
				} else {
					if (!empty($data['extra']['custom_files'][$option_id])) {
						$data['product_options'][$option_id] = md5(serialize($data['extra']['custom_files'][$option_id]));
					}
				}
			}
			
			// Check the required options
			if (empty($data['extra']['parent'])) {
				foreach ($_options as $option) {
					if ($option['option_type'] == 'F' && $option['required'] == 'Y' && !$update) {
						if (empty($data['product_options'][$option['option_id']])) {
							fn_set_notification('E', fn_get_lang_var('error'), fn_get_lang_var('product_cannot_be_added'));
							
							unset($product_data[$key]);
							return array($product_data, $cart);
						}
					}
				}
			}
			
		} else {
			if (empty($data['extra']['parent'])) {
				$_options = fn_get_product_options($product_id);
				
				foreach ($_options as $option) {
					if ($option['option_type'] == 'F' && $option['required'] == 'Y' && empty($cart['products'][$hash]['extra']['custom_files'][$option['option_id']]) && empty($data['extra']['custom_files'][$option['option_id']])) {
						fn_set_notification('E', fn_get_lang_var('error'), fn_get_lang_var('product_cannot_be_added'));
						
						unset($product_data[$key]);
						return array($product_data, $cart);
					}
				}
			}
		}
		
		if (isset($cart['products'][$key]['extra']['custom_files'])) {
			foreach ($cart['products'][$key]['extra']['custom_files'] as $option_id => $files) {
				foreach ($files as $file) {
					$data['extra']['custom_files'][$option_id][] = $file;
				}
				
				$data['product_options'][$option_id] = md5(serialize($files));
			}
		}
		
		$product_data[$key] = $data;
	}
	
	return array($product_data, $cart);
}

/**
 *   save stored taxes for products
 * @param array $cart cart
 * @param int $update_id   key of $cart['products'] to be updated
 * @param int $new_id  new key
 * @param bool $consider_existing  whether consider or not existing key
 */
function fn_update_stored_cart_taxes(&$cart, $update_id, $new_id, $consider_existing = false)
{
	if (!empty($cart['taxes']) && is_array($cart['taxes'])) {
		foreach ($cart['taxes'] as $t_id => $s_tax) {
			if (!empty($s_tax['applies']) && is_array($s_tax['applies'])) {
				$compare_key = 'P_' . $update_id;
				$new_key = 'P_' . $new_id;
				if (array_key_exists($compare_key, $s_tax['applies'])) {
					$cart['taxes'][$t_id]['applies'][$new_key] = (isset($s_tax['applies'][$new_key]) && $consider_existing ? $s_tax['applies'][$new_key] : 0) + $s_tax['applies'][$compare_key];
					unset($cart['taxes'][$t_id]['applies'][$compare_key]);
				}
			}
		}
	}
}

function fn_define_original_amount($product_id, $cart_id, &$product, $prev_product)
{
	if (!empty($prev_product['original_product_data']) && !empty($prev_product['original_product_data']['amount'])) {
		$tracking = db_get_field("SELECT tracking FROM ?:products WHERE product_id = ?i", $product_id);
		if ($tracking != 'O' || $tracking == 'O' && $prev_product['original_product_data']['cart_id'] == $cart_id) {
			$product['original_amount'] = $prev_product['original_product_data']['amount'];
		}
		$product['original_product_data'] = $prev_product['original_product_data'];
	} elseif (!empty($prev_product['original_amount'])) {
		$product['original_amount'] = $prev_product['original_amount'];
	}
}

function fn_get_shipments_info($params, $items_per_page = SHIPMENTS_PER_PAGE)
{
	// Init view params
	$params = fn_init_view('shipments', $params);
	
	// Set default values to input params
	$params['page'] = empty($params['page']) ? 1 : $params['page']; // default page is 1
	
	$fields_list = array(
		'?:shipments.shipment_id',
		'?:shipments.timestamp AS shipment_timestamp',
		'?:shipments.comments',
		'?:shipment_items.order_id',
		'?:orders.timestamp AS order_timestamp',
		'?:orders.s_firstname',
		'?:orders.s_lastname',
	);
	
	$joins = array(
		'LEFT JOIN ?:shipment_items ON (?:shipments.shipment_id = ?:shipment_items.shipment_id)',
		'LEFT JOIN ?:orders ON (?:shipment_items.order_id = ?:orders.order_id)',
	);

	$condition = '';
	if (PRODUCT_TYPE == 'MULTIVENDOR' && defined('COMPANY_ID') && COMPANY_ID) {
		$joins[] = 'LEFT JOIN ?:companies ON (?:companies.company_id = ?:orders.company_id)';
		$condition = db_quote(' AND ?:companies.company_id = ?i', COMPANY_ID);
	}
	
	$group = array(
		'?:shipments.shipment_id',
	);
	
	// Define sort fields
	$sortings = array (
		'id' => "?:shipments.shipment_id",
		'order_id' => "?:orders.order_id",
		'shipment_date' => "?:shipments.timestamp",
		'order_date' => "?:orders.timestamp",
		'customer' => array("?:orders.s_lastname", "?:orders.s_firstname"),
	);

	$directions = array (
		'asc' => 'asc',
		'desc' => 'desc'
	);

	if (empty($params['sort_order']) || empty($directions[$params['sort_order']])) {
		$params['sort_order'] = 'desc';
	}

	if (empty($params['sort_by']) || empty($sortings[$params['sort_by']])) {
		$params['sort_by'] = 'id';
	}

	$sorting = (is_array($sortings[$params['sort_by']]) ? implode(' ' . $directions[$params['sort_order']] . ', ', $sortings[$params['sort_by']]) : $sortings[$params['sort_by']]) . " " . $directions[$params['sort_order']];

	// Reverse sorting (for usage in view)
	$params['sort_order'] = $params['sort_order'] == 'asc' ? 'desc' : 'asc';
	
	if (isset($params['advanced_info']) && $params['advanced_info']) {
		$fields_list[] = '?:shipping_descriptions.shipping AS shipping';
		$fields_list[] = '?:shipments.tracking_number';
		$fields_list[] = '?:shipments.carrier';
		
		$joins[] = ' LEFT JOIN ?:shippings ON (?:shipments.shipping_id = ?:shippings.shipping_id)';
		$joins[] = ' LEFT JOIN ?:shipping_descriptions ON (?:shippings.shipping_id = ?:shipping_descriptions.shipping_id)';
		
		$condition .= db_quote(' AND ?:shipping_descriptions.lang_code = ?s', DESCR_SL);
	}
	
	if (!empty($params['order_id'])) {
		$condition .= db_quote(' AND ?:shipment_items.order_id = ?i', $params['order_id']);
	}
	
	if (!empty($params['shipment_id'])) {
		$condition .= db_quote(' AND ?:shipments.shipment_id = ?i', $params['shipment_id']);
	}
	
	if (isset($params['cname']) && fn_string_not_empty($params['cname'])) {
		$arr = fn_explode(' ', $params['cname']);
		foreach ($arr as $k => $v) {
			if (!fn_string_not_empty($v)) {
				unset($arr[$k]);
			}
		}
		if (sizeof($arr) == 2) {
			$condition .= db_quote(" AND ?:orders.firstname LIKE ?l AND ?:orders.lastname LIKE ?l", "%".array_shift($arr)."%", "%".array_shift($arr)."%");
		} else {
			$condition .= db_quote(" AND (?:orders.firstname LIKE ?l OR ?:orders.lastname LIKE ?l)", "%".trim($params['cname'])."%", "%".trim($params['cname'])."%");
		}
	}

	if (!empty($params['p_ids']) || !empty($params['product_view_id'])) {
		$arr = (strpos($params['p_ids'], ',') !== false || !is_array($params['p_ids'])) ? explode(',', $params['p_ids']) : $params['p_ids'];

		if (empty($params['product_view_id'])) {
			$condition .= db_quote(" AND ?:shipment_items.product_id IN (?n)", $arr);
		} else {
			$condition .= db_quote(" AND ?:shipment_items.product_id IN (?n)", db_get_fields(fn_get_products(array('view_id' => $params['product_view_id'], 'get_query' => true)), ','));
		}

		$joins[] = "LEFT JOIN ?:order_details ON ?:order_details.order_id = ?:orders.order_id";
	}
	
	if (!empty($params['shipment_period']) && $params['shipment_period'] != 'A') {
		$params['time_from'] = $params['shipment_time_from'];
		$params['time_to'] = $params['shipment_time_to'];
		$params['period'] = $params['shipment_period'];
		
		list($params['shipment_time_from'], $params['shipment_time_to']) = fn_create_periods($params);

		$condition .= db_quote(" AND (?:shipments.timestamp >= ?i AND ?:shipments.timestamp <= ?i)", $params['shipment_time_from'], $params['shipment_time_to']);
	}
	
	if (!empty($params['order_period']) && $params['order_period'] != 'A') {
		$params['time_from'] = $params['order_time_from'];
		$params['time_to'] = $params['order_time_to'];
		$params['period'] = $params['order_period'];
		
		list($params['order_time_from'], $params['order_time_to']) = fn_create_periods($params);

		$condition .= db_quote(" AND (?:orders.timestamp >= ?i AND ?:orders.timestamp <= ?i)", $params['order_time_from'], $params['order_time_to']);
	}
	
	fn_set_hook('get_shipments', $params, $fields_list, $joins, $condition, $group);
	
	$fields_list = implode(', ', $fields_list);
	$joins = implode(' ', $joins);
	$group = implode(', ', $group);
	
	if (!empty($group)) {
		$group = ' GROUP BY ' . $group;
	}
	
	$limit = '';
	if (!empty($items_per_page)) {
		$total = db_get_field("SELECT COUNT(DISTINCT(?:shipments.shipment_id)) FROM ?:shipments $joins WHERE 1 $condition");
		$limit = fn_paginate($params['page'], $total, $items_per_page);
	}
	
	$shipments = db_get_array("SELECT $fields_list FROM ?:shipments $joins WHERE 1 $condition $group ORDER BY $sorting $limit");
	
	if (isset($params['advanced_info']) && $params['advanced_info'] && !empty($shipments)) {
		foreach ($shipments as $id => $shipment) {
			$items = db_get_array('SELECT item_id, amount FROM ?:shipment_items WHERE shipment_id = ?i', $shipment['shipment_id']);
			if (!empty($items)) {
				foreach ($items as $item) {
					$shipments[$id]['items'][$item['item_id']] = $item['amount'];
				}
			}
		}
	}

	fn_view_process_results('shipments_info', $shipments, $params, $items_per_page);

	return array($shipments, $params, $total);
}

/**
 * Deletes shipping method by identifier
 * 
 * @param int $shipping_id Shipping identifier
 * @return bool Always true
 */
function fn_delete_shipping($shipping_id)
{
	db_query("DELETE FROM ?:shipping_rates WHERE shipping_id = ?i", $shipping_id);
	db_query("DELETE FROM ?:shipping_descriptions WHERE shipping_id = ?i", $shipping_id);
	db_query("DELETE FROM ?:shippings WHERE shipping_id = ?i", $shipping_id);
	
	fn_set_hook('delete_shipping', $shipping_id);

	return true;
}

function fn_purge_undeliverable_products(&$cart)
{
	foreach ((array)$cart['products'] as $k => $v) {
		if (isset($v['shipping_failed']) && $v['shipping_failed']) {
			unset($cart['products'][$k]);
		}
	}
}

function fn_apply_stored_shipping_rates(&$cart, $order_id = 0)
{
	if (!empty($cart['stored_shipping'])) {
		$total_cost = 0;
		foreach ($cart['shipping'] as $sh_id => $method) {
			if (isset($cart['stored_shipping'][$sh_id])) {
				$piece = fn_format_price($cart['stored_shipping'][$sh_id] / count($method['rates']));
				foreach ($method['rates'] as $k => $v) {
					$cart['shipping'][$sh_id]['rates'][$k] = $piece;
					$total_cost += $piece;
				}
				if (($sum = array_sum($cart['shipping'][$sh_id]['rates'])) != $cart['stored_shipping'][$sh_id]) {
					$deviation = $cart['stored_shipping'][$sh_id] - $sum;
					$value = reset($cart['shipping'][$sh_id]['rates']);
					$key = key($cart['shipping'][$sh_id]['rates']);
					$cart['shipping'][$sh_id]['rates'][$key] = $value + $deviation;
					$total_cost += $deviation;
				}
			} else {
				if (!empty($method['rates'])) {
					$total_cost += array_sum($method['rates']);
				}
			}
		}
		if (!empty($order_id)) {
			db_query("UPDATE ?:orders SET shipping_cost = ?i WHERE order_id = ?i", $total_cost, $order_id);
		}
		$cart['shipping_cost'] = $total_cost;
	}
}

function fn_checkout_update_shipping(&$cart, $shipping_ids)
{
	$cart['shipping'] = array();
	$parsed_data = array();
	foreach ($shipping_ids as $k => $shipping_id) {
		if (strpos($k, ',') !== false) {
			$parsed_data = fn_array_merge($parsed_data, fn_array_combine(fn_explode(',', $k), $shipping_id));
		} else {
			$parsed_data[$k] = $shipping_id;
		}
	}
	
	foreach ($parsed_data as $k => $shipping_id) {
		if (empty($cart['shipping'][$shipping_id])) {
			$cart['shipping'][$shipping_id] = array();
			if (!empty($_SESSION['shipping_rates'][$shipping_id]['name'])) {
				$cart['shipping'][$shipping_id]['shipping'] = $_SESSION['shipping_rates'][$shipping_id]['name'];
			}
		}

		if (isset($_SESSION['shipping_rates'][$shipping_id]['rates'][$k])) {
			$cart['shipping'][$shipping_id]['rates'][$k] = $_SESSION['shipping_rates'][$shipping_id]['rates'][$k];
		} else {
			$cart['shipping'][$shipping_id]['rates'] = $_SESSION['shipping_rates'][$shipping_id]['rates'];
		}
		
	}
	
	$cart['chosen_shipping'] = $cart['shipping'];

	return true;
}

/**
 * Applies surcharge of selected payment to cart total
 * 
 * @param array $cart Array of the cart contents and user information necessary for purchase
 * @param array $auth Array of user authentication data (e.g. uid, usergroup_ids, etc.)
 * @param string $lang_code 2-letter language code (e.g. 'EN', 'RU', etc.)
 * @return bool Always true
 */
function fn_update_payment_surcharge(&$cart, $auth, $lang_code = CART_LANGUAGE)
{
	$cart['payment_surcharge'] = 0;
	if (!empty($cart['payment_id'])) {
		$_data = db_get_row("SELECT a_surcharge, p_surcharge FROM ?:payments WHERE payment_id = ?i", $cart['payment_id']);

		if (!empty($_data)) {
			if (floatval($_data['a_surcharge'])) {
				$cart['payment_surcharge'] += $_data['a_surcharge'];
			}
			if (floatval($_data['p_surcharge'])) {
				$cart['payment_surcharge'] += fn_format_price($cart['total'] * $_data['p_surcharge'] / 100);
			}
		}
	}

	if (!empty($cart['payment_surcharge'])) {
		$cart['payment_surcharge_title'] = db_get_field("SELECT surcharge_title FROM ?:payment_descriptions WHERE payment_id = ?i AND lang_code = ?s", $cart['payment_id'], $lang_code);

		// apply tax
		fn_calculate_payment_taxes($cart, $auth);
	}

	return true;
}

function fn_get_cart_product_icon($product_id, $product_data = array())
{
	if (!empty($product_data['product_options'])) {
		$combination_hash = fn_generate_cart_id($product_id, array('product_options' => $product_data['product_options']), true);
		$image = fn_get_image_pairs($combination_hash, 'product_option', 'M', true, true);
		if (!empty($image)) {
			return $image;
		}
	}

	return fn_get_image_pairs($product_id, 'product', 'M', true, true);
}

function fn_prepare_checkout_payment_methods(&$cart, &$auth)
{
	static $payment_methods, $payment_groups;

	//Get payment methods
	if (empty($payment_methods)) {
		$payment_methods = fn_get_payment_methods($auth);
	}

	// Check if payment method has surcharge rates
	foreach ($payment_methods as $k => $v) {
		$payment_methods[$k]['surcharge_value'] = 0;
		if (floatval($v['a_surcharge'])) {
			$payment_methods[$k]['surcharge_value'] += $v['a_surcharge'];
		}
		if (floatval($v['p_surcharge']) && !empty($cart['total'])) {
			$payment_methods[$k]['surcharge_value'] += fn_format_price($cart['total'] * $v['p_surcharge'] / 100);
		}

		$payment_groups[$v['payment_category']][$k] = $payment_methods[$k];
	}

	/*
	 * We couldn't use Google checkout button at last checkout step.
	 * According to "Google Checkout Program Policies" button should not be accessible through the default checkout functional
	 * https://checkout.google.com/seller/policies.html
	 * 
	 * 4. Google Checkout buttons and Buy Now buttons
	 * ...
	 * b. Ensure 1:1 and adjacent button placement
	 * ...
	 * You must separate the Google Checkout flow from your existing checkout process.
	 * If buyers initiate your existing checkout process, they must not see a Google Checkout or Buy Now button.
	 * 
	 */

	if (!empty($payment_groups)) {
		foreach ($payment_groups as $tab_id => $payments) {
			foreach ($payments as $payment_id => $payment_data) {
				if ($payment_data['processor_type'] == 'C') {
					$payment_data = fn_get_payment_method_data($payment_id);
					if ($payment_data['processor'] == 'Google checkout') {
						unset($payment_groups[$tab_id][$payment_id]);

						if (empty($payment_groups[$tab_id])) {
							unset($payment_groups[$tab_id]);
						}
					}
				}
			}
		}
		
		ksort($payment_groups);
	}

	fn_set_hook('prepare_checkout_payment_methods', $cart, $auth, $payment_groups);
	
	return $payment_groups;
}

function fn_update_order_customer_info($data, $order_id)
{
	$order_info = fn_get_order_info($order_id);
	$new_order_info = array();
	$need_update = false;

	if (empty($order_info)) {
		return false;
	}

	foreach ($data as $k => $v) {
		if ($data[$k] != $order_info[$k]) {
			$need_update = true;
			$new_order_info[$k] = $v;
		}
	}

	if ($need_update) {
		db_query("UPDATE ?:orders SET ?u WHERE order_id = ?i", $new_order_info, $order_id);
	}

	return true;
}

/**
 * Returns all available shippings for root/vendor company
 * 
 * @param int $company_id Company identifier
 * @return array List of shippings
 */
function fn_get_available_shippings($company_id = null)
{
	$condition = '';
	if ($company_id != null && PRODUCT_TYPE != 'ULTIMATE') {
		$company_shippings = db_get_field('SELECT shippings FROM ?:companies WHERE company_id = ?i', $company_id);
		$condition .= db_quote('AND (a.company_id = ?i ', $company_id);

		if (!empty($company_shippings)) {
			$condition .= db_quote(' OR a.shipping_id IN (?n)', explode(',', $company_shippings));
		}

		$condition .= ')';
	}

	$res = db_get_hash_array("SELECT a.shipping_id, a.company_id, a.min_weight, a.max_weight, a.position, a.status, b.shipping, b.delivery_time, a.usergroup_ids, c.company as company_name FROM ?:shippings as a LEFT JOIN ?:shipping_descriptions as b ON a.shipping_id = b.shipping_id AND b.lang_code = ?s LEFT JOIN ?:companies c ON c.company_id = a.company_id WHERE 1 $condition ORDER BY a.position", 'shipping_id', DESCR_SL);
	return $res;
}

?>