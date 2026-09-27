// Fixture-only RPC stubs verify the rendered forms against production store.js.
(function(){
	var checks = 0;
	function check(ok, label){ if(!ok){ throw new Error(label); } checks++; }
	try{
		var promotions = document.querySelector('[data-store-coupons]');
		if(promotions){
			var form = promotions.closest('form'), saved;
			window.x_saveStorePromotions = function(payload, callback){ saved = payload; callback('Fixture only'); };
			storeAddCoupon(form.querySelector('button[onclick^="storeAddCoupon"]'));
			check(promotions.children.length === 2, 'Add a discount-code row');
			var row = promotions.lastElementChild;
			row.querySelector('[data-coupon-field="code"]').value = 'FIXED';
			row.querySelector('[data-coupon-field="type"]').value = 'fixed';
			row.querySelector('[data-coupon-field="value"]').value = '7.50';
			row.querySelector('[data-coupon-field="start"]').value = '2026-09-01';
			row.querySelector('[data-coupon-field="active"]').checked = false;
			storeSavePromotions(form);
			check(saved.coupons.length === 2 && saved.coupons[0].code === 'WELCOME', 'Saving preserves existing codes');
			check(saved.coupons[1].type === 'fixed' && saved.coupons[1].value === '7.50', 'Fixed amount transmitted unchanged');
			check(saved.coupons[1].start === '2026-09-01' && saved.coupons[1].active === 0, 'Date and disabled state transmitted');
			check(saved.pointsPerUnit === '2' && saved.freeShipping === '60.00', 'Reward settings transmitted');
			check(!form.querySelector('[type="submit"]').disabled, 'Save button restored after error');
			row.querySelector('button').click();
			check(promotions.children.length === 1, 'Remove code before saving');
		}else if(document.getElementById('ghotiStoreCart')){
			var requested;
			window.x_storeShowCart = function(code, callback){ requested = code; callback('Fixture only'); };
			window.printPage = function(){};
			var coupon = document.querySelector('.ghotiStoreCoupon');
			coupon.elements.code.value = 'SAVE20';
			storeApplyCoupon(coupon);
			check(requested === 'SAVE20', 'Apply sends code to cart RPC');
			coupon.querySelector('button[type="button"]').click();
			check(requested === '', 'Clear explicitly removes code');
			storeShowCart();
			check(requested === null, 'Opening cart preserves server code');
		}else if(document.getElementById('ghotiStoreCheckout')){
			check(document.querySelector('.ghotiStoreSummary').textContent.includes('WELCOME'), 'Checkout includes applied discount');
			check(document.querySelector('.ghotiStoreBenefits').textContent.includes('Sign in'), 'Guest checkout explains rewards eligibility');
			check(!document.getElementById('ghotiStoreCheckoutForm').checkValidity(), 'Empty required fields invalid');
			check(document.getElementById('storeCryptoCurrency').querySelector('option[value="btc"]'), 'Checkout offers configured Bitcoin payment');
			check(document.querySelector('[onclick^="storeBeginCryptoPayment"]'), 'Checkout renders the crypto payment action');
			check(document.querySelector('[onclick^="storeBeginStripePayment"]'), 'Checkout renders the Stripe payment action');
			check(document.getElementById('ghotiStoreSquareCard') && document.getElementById('ghotiStoreSquareButton'), 'Checkout renders the Square card mount');
		}else if(document.getElementById('ghotiStoreSubscription')){
			var subscription = document.getElementById('ghotiStoreSubscription');
			check(subscription.dataset.productId === '6' && subscription.dataset.planId === 'P-1234567890', 'Subscription carries server-selected product and plan');
			check(subscription.textContent.includes('per month'), 'Subscription billing term displayed');
			check(!document.getElementById('ghotiStoreSubscriptionForm').checkValidity(), 'Empty subscription details invalid');
			check(document.getElementById('storeSubscriptionDetails').required, 'Required setup answer rendered');
		}else if(document.getElementById('ghotiStoreSettingsForm')){
			check(document.getElementById('store-cryptoEnabled').checked, 'Saved crypto checkout state rendered');
			check(document.getElementById('store-cryptoCurrencies').value.includes('btc'), 'Configured crypto allow-list rendered');
			check(document.getElementById('store-cryptoApiKey').value === '', 'Crypto API key was not rendered back into the form');
			check(document.getElementById('store-stripeEnabled').checked && document.getElementById('store-stripeSecretKey').value === '', 'Stripe settings or secret handling rendered incorrectly');
			check(document.getElementById('store-squareEnabled').checked && document.getElementById('store-squareAccessToken').value === '', 'Square settings or token handling rendered incorrectly');
		}
		document.body.dataset.testResult = 'PASS: ' + checks + ' commerce browser assertions';
	}catch(error){ document.body.dataset.testResult = 'FAIL: ' + error.message; }
})();
