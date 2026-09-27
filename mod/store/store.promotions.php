<?php
/* Pure promotion rules. Prices and eligibility are computed on the server;
 * orders snapshot the result so later rule changes cannot rewrite a purchase. */
function storeCommerceDefaults(){
	return array('freeShippingCents' => 0, 'pointsPerUnit' => 0, 'loyaltyThreshold' => 500, 'loyaltyPercent' => 5, 'coupons' => array());
}

function storeCouponCode($value){
	if(!is_string($value) || strlen($value) > 32){ throw new Exception('Use a discount code of up to 32 letters, numbers, or hyphens.'); }
	$code = strtoupper(ghoti_validate()->text($value, 32, false, 'discount code'));
	if($code !== '' && !preg_match('/^[A-Z0-9-]+$/D', $code)){ throw new Exception('Use only letters, numbers, or hyphens in the discount code.'); }
	return $code;
}

function storePromotionDate($value){
	if($value === ''){ return ''; }
	if(!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)){ throw new Exception('Enter promotion dates as YYYY-MM-DD.'); }
	$parts = explode('-', $value);
	if(!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])){ throw new Exception('Enter a valid promotion date.'); }
	return $value;
}

function storeValidateCommerce($input){
	if(!is_array($input)){ throw new Exception('Enter the promotion settings.'); }
	$v = ghoti_validate();
	$config = storeCommerceDefaults();
	$config['freeShippingCents'] = storePriceToCents($input['freeShipping'] ?? '0');
	if($config['freeShippingCents'] < 0){ throw new Exception('Enter a valid free-shipping threshold.'); }
	$config['pointsPerUnit'] = $v->intInRange($input['pointsPerUnit'] ?? 0, 0, 100, 'points per currency unit');
	$config['loyaltyThreshold'] = $v->intInRange($input['loyaltyThreshold'] ?? 500, 1, 1000000, 'loyalty threshold');
	$config['loyaltyPercent'] = $v->intInRange($input['loyaltyPercent'] ?? 5, 1, 50, 'loyalty percentage');
	$coupons = $input['coupons'] ?? array();
	if(!is_array($coupons) || count($coupons) > 50){ throw new Exception('Keep at most 50 discount codes.'); }
	$seen = array();
	foreach($coupons as $coupon){
		if(!is_array($coupon)){ throw new Exception('Enter valid discount-code details.'); }
		$code = storeCouponCode($coupon['code'] ?? '');
		if($code === '' || isset($seen[$code])){ throw new Exception('Each discount code must be non-empty and unique.'); }
		$seen[$code] = true;
		$type = $coupon['type'] ?? '';
		if(!in_array($type, array('percent', 'fixed'), true)){ throw new Exception('Choose percentage or fixed discount.'); }
		$value = $type === 'percent' ? $v->intInRange($coupon['value'] ?? '', 1, 90, 'discount percentage') : storePriceToCents($coupon['value'] ?? '');
		$minimum = storePriceToCents($coupon['minimum'] ?? '0');
		if($value <= 0 || $minimum < 0){ throw new Exception('Enter valid discount and minimum amounts.'); }
		$start = storePromotionDate($coupon['start'] ?? '');
		$end = storePromotionDate($coupon['end'] ?? '');
		if($start !== '' && $end !== '' && $end < $start){ throw new Exception('The end date must follow the start date.'); }
		$config['coupons'][] = array('code' => $code, 'type' => $type, 'value' => $value, 'minimumCents' => $minimum,
			'start' => $start, 'end' => $end, 'active' => $v->boolInt($coupon['active'] ?? 0));
	}
	return $config;
}

function storeCalculatePromotions($subtotal, $shipping, $config, $code, $points, $signedIn, $today){
	$config = array_merge(storeCommerceDefaults(), $config);
	$discount = 0;
	$label = '';
	$error = '';
	if($code !== ''){
		$error = 'That code is unavailable, expired, or its minimum spend has not been reached.';
		foreach($config['coupons'] as $coupon){
			if($coupon['code'] !== $code || empty($coupon['active']) || $subtotal < $coupon['minimumCents']
				|| ($coupon['start'] !== '' && $today < $coupon['start']) || ($coupon['end'] !== '' && $today > $coupon['end'])){ continue; }
			$discount = $coupon['type'] === 'percent' ? intdiv($subtotal * $coupon['value'], 100) : $coupon['value'];
			$label = $code;
			$error = '';
			break;
		}
	}
	// Give the better offer, never silently stack two promotions. Leave at least
	// one cent payable for PayPal; free orders require a different checkout flow.
	if($signedIn && $config['pointsPerUnit'] > 0 && $points >= $config['loyaltyThreshold']){
		$memberDiscount = intdiv($subtotal * $config['loyaltyPercent'], 100);
		if($memberDiscount > $discount){ $discount = $memberDiscount; $label = 'Member reward'; }
	}
	$discount = min(max(0, $subtotal - 1), max(0, $discount));
	$net = $subtotal - $discount;
	$threshold = (int)$config['freeShippingCents'];
	if($threshold > 0 && $net >= $threshold){ $shipping = 0; }
	return array('discountCents' => $discount, 'discountLabel' => $discount > 0 ? $label : '', 'couponError' => $error,
		'shippingCents' => $shipping, 'totalCents' => $net + $shipping,
		'loyaltyPoints' => $signedIn ? intdiv($net * (int)$config['pointsPerUnit'], 100) : 0,
		'pointsBalance' => $points, 'signedIn' => $signedIn, 'commerce' => $config,
		'freeShippingRemaining' => $threshold > 0 ? max(0, $threshold - $net) : 0);
}
