/*
 * store.js - the browser half of the store module.
 *
 * Storefront, cart, checkout and the PayPal buttons, plus the admin product and
 * settings forms. Follows the other modules' shape: x_<endpoint>() calls through
 * the ghoti async layer, and the server answers with rendered HTML that goes
 * into #ghotiContent via printPage().
 *
 * The PayPal SDK is loaded on demand, the first time a checkout is opened, so
 * no visitor reading an ordinary page pays for a payment script they will never
 * use. It is the only third-party script this app loads at runtime.
 *
 * Nothing here computes or sends a price. The buttons ask the server to create
 * a PayPal order and to capture it; both amounts are the server's.
 */

var GHOTI_STORE_SDK = null; //a promise once the PayPal SDK has begun loading
var GHOTI_STORE_SUBSCRIPTION_SDK = null;
var GHOTI_STORE_CRYPTO_TIMER = null;
var GHOTI_STORE_STRIPE_SCRIPT = null;
var GHOTI_STORE_SQUARE_SCRIPT = null;
var GHOTI_STORE_SQUARE_CARD = null;

function showStore(category){
	storeStopCryptoPoll();
	x_showStore(category || 'all', function(html){
		printPage(html);
	});
}

function storeShowCart(code){
	storeStopCryptoPoll();
	x_storeShowCart(code === undefined ? null : code, function(html){
		printPage(html);
	});
}

function storeAddToCart(productId, button){
	var input = button ? button.closest('.ghotiStoreBuy').querySelector('input') : document.getElementById('storeQty-' + productId);
	if(input && !input.reportValidity()){ return; }
	if(button){ button.disabled = true; }
	var quantity = input ? parseInt(input.value, 10) : 1;
	if(!(quantity > 0)){ quantity = 1; }
	x_storeAddToCart(productId, quantity, function(result){
		if(button){ button.disabled = false; }
		if(!result || !result.ok){
			pageFeedBack((result && result.error) || 'That item could not be added.');
			return;
		}
		document.querySelectorAll('.ghotiStoreCartSummary, #ghotiStoreCartSummary').forEach(function(summary){ summary.textContent = result.summary; });
		pageFeedBack(result.name + ' added to your cart.');
	});
}

function storeSetCartQuantity(productId, quantity){
	x_storeSetCartQuantity(productId, parseInt(quantity, 10) || 0, function(result){
		if(!result || !result.ok){
			pageFeedBack((result && result.error) || 'The cart could not be updated.');
			return;
		}
		printPage(result.html);
	});
}

function storeShowCheckout(){
	storeStopCryptoPoll();
	x_storeShowCheckout(function(html){
		printPage(html);
		storeMountPaypal();
		storeMountSquare();
	});
}

function storeShowSubscription(productId){
	storeStopCryptoPoll();
	x_storeShowSubscription(productId, function(html){
		printPage(html);
		storeMountSubscriptionPaypal();
	});
}

/* ---------------- payment ---------------- */

//Load the PayPal SDK once, for the merchant and currency this store is
//configured with. Resolves to window.paypal.
function storeLoadPaypal(){
	if(GHOTI_STORE_SDK){ return GHOTI_STORE_SDK; }
	GHOTI_STORE_SDK = new Promise(function(resolve, reject){
		x_storePaypalConfig(function(config){
			if(!config || !config.ok){
				reject(new Error((config && config.error) || 'This store is not connected to PayPal yet.'));
				return;
			}
			if(window.paypal){ resolve(window.paypal); return; }
			var script = document.createElement('script');
			script.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(config.clientId)
				+ '&currency=' + encodeURIComponent(config.currency)
				+ '&intent=capture&components=buttons&disable-funding=credit';
			script.onload = function(){
				if(window.paypal){ resolve(window.paypal); }
				else { reject(new Error('The PayPal button script did not load.')); }
			};
			script.onerror = function(){ reject(new Error('The PayPal button script could not be loaded.')); };
			document.head.appendChild(script);
		});
	});
	//A failed load must not be cached: the admin may be mid-way through saving
	//credentials, and the next attempt should try again rather than replay the error.
	GHOTI_STORE_SDK.catch(function(){ GHOTI_STORE_SDK = null; });
	return GHOTI_STORE_SDK;
}

function storePayStatus(message, isError){
	var node = document.getElementById('ghotiStorePayStatus');
	if(!node){ return; }
	node.textContent = message || '';
	node.classList.toggle('is-error', !!isError);
}

//Read the checkout form. Validation is the server's job - this only collects.
function storeCheckoutFields(){
	function value(id){
		var node = document.getElementById(id);
		return node ? node.value : '';
	}
	return {
		name: value('storeName'),
		email: value('storeEmail'),
		address1: value('storeAddress1'),
		address2: value('storeAddress2'),
		city: value('storeCity'),
		region: value('storeRegion'),
		postcode: value('storePostcode'),
		country: value('storeCountry'),
		note: value('storeNote'),
		serviceDetails: Array.prototype.reduce.call(document.querySelectorAll('[data-store-service]'), function(details, field){
			details[field.dataset.storeService] = field.value;
			return details;
		}, {})
	};
}

function storeMountPaypal(){
	var container = document.getElementById('ghotiStorePaypal');
	if(!container){ return; }
	storePayStatus('Loading the PayPal button…');
	storeLoadPaypal().then(function(paypal){
		container.replaceChildren();
		storePayStatus('');
		paypal.Buttons({
			style: { layout: 'vertical', shape: 'rect', label: 'paypal' },

			//createOrder sends the buyer's details and nothing else. The server
			//prices the cart, records a pending order, and returns PayPal's id.
			createOrder: function(){
				var form = document.getElementById('ghotiStoreCheckoutForm');
				if(form && !form.reportValidity()){ return Promise.reject(new Error('Check your checkout details.')); }
				storePayStatus('Preparing your order…');
				return new Promise(function(resolve, reject){
					x_storeBeginCheckout(storeCheckoutFields(), function(result){
						if(!result || !result.ok){
							storePayStatus((result && result.error) || 'This order could not be started.', true);
							reject(new Error('begin-checkout-failed'));
							return;
						}
						storePayStatus('Order ' + result.reference + ' is ready. Complete the payment in the PayPal window.');
						resolve(result.paypalOrderId);
					});
				});
			},

			onApprove: function(data){
				storePayStatus('Confirming your payment…');
				return new Promise(function(resolve){
					x_storeCaptureOrder(data.orderID, function(result){
						if(!result || !result.ok){
							storePayStatus((result && result.error) || 'The payment could not be confirmed. Do not pay again; contact us first.', true);
							resolve();
							return;
						}
						printPage(result.html);
						//The receipt is on screen; only now does anything reach a
						//supplier, and nothing on this page waits for it.
						if(result.submitQueued && typeof x_storeSubmitQueued === 'function'){
							x_storeSubmitQueued(function(){});
						}
						resolve();
					});
				});
			},

			onCancel: function(){
				storePayStatus('Payment cancelled. Your cart is still here.');
			},

			onError: function(){
				//PayPal's own error object is not shown: it is for developers,
				//and at this point the buyer needs to know only whether to retry.
				storePayStatus('PayPal reported a problem with this payment. Nothing has been charged.', true);
			}
		}).render(container);
	}).catch(function(error){
		storePayStatus(error.message || 'PayPal is unavailable right now.', true);
	});
}

function storeStopCryptoPoll(){
	if(GHOTI_STORE_CRYPTO_TIMER){ window.clearTimeout(GHOTI_STORE_CRYPTO_TIMER); }
	GHOTI_STORE_CRYPTO_TIMER = null;
}

function storeBeginCryptoPayment(button){
	var form = document.getElementById('ghotiStoreCheckoutForm');
	if(form && !form.reportValidity()){ return; }
	var currency = document.getElementById('storeCryptoCurrency');
	if(!currency){ return; }
	if(button){ button.disabled = true; }
	storePayStatus('Creating a deposit address…');
	x_storeBeginCryptoCheckout(storeCheckoutFields(), currency.value, function(result){
		if(button){ button.disabled = false; }
		if(!result || !result.ok){
			storePayStatus((result && result.error) || 'The crypto payment could not be started.', true);
			return;
		}
		printPage(result.html);
		if(!result.final){ storeScheduleCryptoPoll(result.orderId); }
	});
}

function storeRefreshCryptoPayment(orderId, button, automatic){
	storeStopCryptoPoll();
	if(button){ button.disabled = true; }
	storePayStatus('Checking network confirmations…');
	x_storeRefreshCryptoPayment(orderId, function(result){
		if(button){ button.disabled = false; }
		if(!result || !result.ok){
			storePayStatus((result && result.error) || 'The payment status could not be checked.', true);
			//A temporary provider outage should not strand an otherwise valid page.
			if(automatic){ storeScheduleCryptoPoll(orderId); }
			return;
		}
		printPage(result.html);
		if(!result.final){ storeScheduleCryptoPoll(orderId); }
		if(result.final && result.submitQueued && typeof x_storeSubmitQueued === 'function'){
			x_storeSubmitQueued(function(){});
		}
	});
}

function storeScheduleCryptoPoll(orderId){
	storeStopCryptoPoll();
	var panel = document.getElementById('ghotiStoreCryptoPayment');
	if(!panel || panel.dataset.final === '1'){ return; }
	GHOTI_STORE_CRYPTO_TIMER = window.setTimeout(function(){
		storeRefreshCryptoPayment(orderId, null, true);
	}, 12000);
}

function storeCopyCrypto(id, button){
	var node = document.getElementById(id);
	if(!node){ return; }
	var value = node.textContent || '';
	if(navigator.clipboard && navigator.clipboard.writeText){
		navigator.clipboard.writeText(value).then(function(){
			if(button){ button.textContent = 'Copied'; window.setTimeout(function(){ button.textContent = 'Copy'; }, 1500); }
		}, function(){ pageFeedBack('Copy failed. Select the value manually.'); });
	}else{ pageFeedBack('Select and copy the value manually.'); }
}

function storeLoadPaymentScript(url, globalName, cached){
	if(window[globalName]){ return Promise.resolve(window[globalName]); }
	if(cached){ return cached; }
	return new Promise(function(resolve,reject){
		var script=document.createElement('script'); script.src=url; script.async=true;
		script.onload=function(){ window[globalName] ? resolve(window[globalName]) : reject(new Error('The payment library did not initialize.')); };
		script.onerror=function(){ reject(new Error('The payment library could not be loaded.')); };
		document.head.appendChild(script);
	});
}

function storeBeginStripePayment(button){
	var form=document.getElementById('ghotiStoreCheckoutForm'); if(form && !form.reportValidity()){ return; }
	button.disabled=true; storePayStatus('Preparing Stripe checkout…');
	x_storeBeginStripeCheckout(storeCheckoutFields(),function(result){
		button.disabled=false;
		if(!result || !result.ok){ storePayStatus((result&&result.error)||'Stripe checkout could not be started.',true); return; }
		printPage(result.html);
		GHOTI_STORE_STRIPE_SCRIPT=storeLoadPaymentScript('https://js.stripe.com/v3/','Stripe',GHOTI_STORE_STRIPE_SCRIPT);
		GHOTI_STORE_STRIPE_SCRIPT.then(function(Stripe){
			var stripe=Stripe(result.publishableKey), elements=stripe.elements({clientSecret:result.clientSecret});
			var payment=elements.create('payment'); payment.mount('#ghotiStoreStripeElement');
			var payButton=document.getElementById('ghotiStoreStripeButton');
			payButton.addEventListener('click',function(){
				payButton.disabled=true; storePayStatus('Confirming with Stripe…');
				stripe.confirmPayment({elements:elements,confirmParams:{return_url:window.location.href},redirect:'if_required'}).then(function(answer){
					if(answer.error){ payButton.disabled=false; storePayStatus(answer.error.message||'Stripe could not complete the payment.',true); return; }
					storeRefreshProcessorPayment(result.orderId,null);
				});
			});
		}).catch(function(error){ storePayStatus(error.message||'Stripe is unavailable.',true); });
	});
}

function storeMountSquare(){
	var panel=document.getElementById('ghotiStoreSquare'); if(!panel){ return; }
	var url=panel.dataset.env==='live'?'https://web.squarecdn.com/v1/square.js':'https://sandbox.web.squarecdn.com/v1/square.js';
	GHOTI_STORE_SQUARE_SCRIPT=storeLoadPaymentScript(url,'Square',GHOTI_STORE_SQUARE_SCRIPT);
	GHOTI_STORE_SQUARE_SCRIPT.then(function(Square){ return Square.payments(panel.dataset.appId,panel.dataset.locationId).card(); })
		.then(function(card){ GHOTI_STORE_SQUARE_CARD=card; return card.attach('#ghotiStoreSquareCard'); })
		.then(function(){ var button=document.getElementById('ghotiStoreSquareButton'); if(button){ button.disabled=false; } })
		.catch(function(error){ storePayStatus(error.message||'Square is unavailable.',true); });
}

function storePayWithSquare(button){
	var form=document.getElementById('ghotiStoreCheckoutForm'); if(form && !form.reportValidity()){ return; }
	var panel=document.getElementById('ghotiStoreSquare'); if(!panel || !GHOTI_STORE_SQUARE_CARD){ return; }
	var fields=storeCheckoutFields(), names=fields.name.trim().split(/\s+/);
	button.disabled=true; storePayStatus('Securing your card with Square…');
	GHOTI_STORE_SQUARE_CARD.tokenize({amount:panel.dataset.amount,currencyCode:panel.dataset.currency,intent:'CHARGE',customerInitiated:true,sellerKeyedIn:false,
		billingContact:{givenName:names.shift()||'',familyName:names.join(' '),email:fields.email,addressLines:[fields.address1,fields.address2].filter(Boolean),city:fields.city,state:fields.region,postalCode:fields.postcode,countryCode:fields.country.toUpperCase()}})
		.then(function(tokenResult){
			if(tokenResult.status!=='OK'){ throw new Error((tokenResult.errors&&tokenResult.errors[0]&&tokenResult.errors[0].message)||'Square could not tokenize the card.'); }
			storePayStatus('Completing your Square payment…');
			x_storeBeginSquareCheckout(fields,tokenResult.token,function(result){
				if(!result||!result.ok){ button.disabled=false; storePayStatus((result&&result.error)||'Square could not complete the payment.',true); return; }
				printPage(result.html); if(result.submitQueued&&typeof x_storeSubmitQueued==='function'){ x_storeSubmitQueued(function(){}); }
			});
		}).catch(function(error){ button.disabled=false; storePayStatus(error.message||'Square could not tokenize the card.',true); });
}

function storeRefreshProcessorPayment(orderId,button){
	if(button){ button.disabled=true; } storePayStatus('Checking payment status…');
	x_storeRefreshProcessorPayment(orderId,function(result){
		if(button){ button.disabled=false; }
		if(!result||!result.ok){ storePayStatus((result&&result.error)||'The payment could not be verified.',true); return; }
		printPage(result.html); if(result.submitQueued&&typeof x_storeSubmitQueued==='function'){ x_storeSubmitQueued(function(){}); }
	});
}

//Subscriptions need PayPal's subscription intent and vault mode. A separate
//namespace lets this SDK coexist with the Orders SDK when both were loaded in
//the same page session.
function storeLoadSubscriptionPaypal(){
	if(GHOTI_STORE_SUBSCRIPTION_SDK){ return GHOTI_STORE_SUBSCRIPTION_SDK; }
	GHOTI_STORE_SUBSCRIPTION_SDK = new Promise(function(resolve, reject){
		x_storePaypalConfig(function(config){
			if(!config || !config.ok){ reject(new Error((config && config.error) || 'This store is not connected to PayPal yet.')); return; }
			if(window.paypalSubscriptions){ resolve(window.paypalSubscriptions); return; }
			var script = document.createElement('script');
			script.setAttribute('data-namespace', 'paypalSubscriptions');
			script.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(config.clientId)
				+ '&currency=' + encodeURIComponent(config.currency) + '&intent=subscription&vault=true&components=buttons';
			script.onload = function(){
				if(window.paypalSubscriptions){ resolve(window.paypalSubscriptions); }
				else { reject(new Error('The PayPal subscription button did not load.')); }
			};
			script.onerror = function(){ reject(new Error('The PayPal subscription button could not be loaded.')); };
			document.head.appendChild(script);
		});
	});
	GHOTI_STORE_SUBSCRIPTION_SDK.catch(function(){ GHOTI_STORE_SUBSCRIPTION_SDK = null; });
	return GHOTI_STORE_SUBSCRIPTION_SDK;
}

function storeMountSubscriptionPaypal(){
	var root = document.getElementById('ghotiStoreSubscription');
	var container = document.getElementById('ghotiStoreSubscriptionPaypal');
	if(!root || !container){ return; }
	storePayStatus('Loading the PayPal subscription button…');
	storeLoadSubscriptionPaypal().then(function(paypal){
		container.replaceChildren();
		storePayStatus('');
		paypal.Buttons({
			style: { layout: 'vertical', shape: 'rect', label: 'subscribe' },
			createSubscription: function(data, actions){
				var form = document.getElementById('ghotiStoreSubscriptionForm');
				if(form && !form.reportValidity()){ return Promise.reject(new Error('Check your subscription details.')); }
				return actions.subscription.create({plan_id: root.dataset.planId});
			},
			onApprove: function(data){
				storePayStatus('Confirming your subscription…');
				return new Promise(function(resolve){
					x_storeConfirmSubscription(root.dataset.productId, data.subscriptionID, {
						name: document.getElementById('storeSubscriptionName').value,
						email: document.getElementById('storeSubscriptionEmail').value,
						serviceDetails: document.getElementById('storeSubscriptionDetails').value
					}, function(result){
						if(!result || !result.ok){ storePayStatus((result && result.error) || 'The subscription could not be confirmed.', true); resolve(); return; }
						printPage(result.html);
						resolve();
					});
				});
			},
			onCancel: function(){ storePayStatus('Subscription cancelled. Nothing has been started.'); },
			onError: function(){ storePayStatus('PayPal reported a problem with this subscription.', true); }
		}).render(container);
	}).catch(function(error){ storePayStatus(error.message || 'PayPal is unavailable right now.', true); });
}

/* ---------------- admin ---------------- */

function showStoreManager(tab){
	x_showStoreManager(tab || 'products', function(html){
		printPage(html);
	});
}

function storeProductData(){
	var node = document.getElementById('ghotiStoreProducts');
	if(!node){ return []; }
	try{ return JSON.parse(node.textContent || node.innerText); }
	catch(e){ return []; }
}

//The product editor is built here rather than server-side because it opens over
//the list without a round trip; the list already carries every field as JSON.
function storeEditProduct(productId, duplicate){
	var panel = document.getElementById('ghotiStoreProductForm');
	if(!panel){ return; }
	var product = null;
	storeProductData().forEach(function(row){
		if(row.productId === productId){ product = row; }
	});
	if(productId && !product){
		pageFeedBack('That product could not be found. Reload the page.');
		return;
	}

	if(duplicate && product){
		product = Object.assign({}, product, {productId: 0, sku: '', name: product.name.slice(0, 113) + ' (copy)', active: false});
	}
	var isNew = !product || duplicate;
	var price = product ? storeCentsToText(product.priceCents) : '0.00';
	var html = ''
		+ '<form class="ghotiForm" action="#" onsubmit="storeSaveProduct(); return false;">'
		+ '<h3>' + (isNew ? 'Add product' : 'Edit product') + '</h3>'
		+ '<input type="hidden" id="storeProductId" value="' + (product ? product.productId : 0) + '" />'
		+ '<div class="ghotiFormGrid">'
		+ storeField('storeProductName', 'Name', product ? product.name : '', 'text', 'maxlength="120" required="required"')
		+ storeField('storeProductSku', 'SKU', product ? product.sku : '', 'text', 'maxlength="60" required="required"')
		+ storeField('storeProductPrice', 'Price', price, 'text', 'maxlength="12" required="required"')
		+ storeField('storeProductCompareAt', 'Original price (optional)', product && product.compareAtCents ? storeCentsToText(product.compareAtCents) : '', 'text', 'maxlength="12" placeholder="Higher price before a sale"')
		+ storeField('storeProductBadge', 'Product badge (optional)', product ? product.badge : '', 'text', 'maxlength="32" placeholder="New arrival, Limited edition…"')
		+ storeField('storeProductDelivery', 'Delivery note (optional)', product ? product.deliveryNote : '', 'text', 'maxlength="160" placeholder="Made to order · Ships in 5–7 days"')
		+ storeField('storeProductCategory', 'Category', product ? product.category : 'default', 'text', 'maxlength="40"')
		+ '<label class="ghotiField"><span>Kind</span><select id="storeProductKind" onchange="storeToggleDownloadField();">'
		+ '<option value="physical"' + (!product || product.kind === 'physical' ? ' selected="selected"' : '') + '>Physical &mdash; needs shipping</option>'
		+ '<option value="digital"' + (product && product.kind === 'digital' ? ' selected="selected"' : '') + '>Digital &mdash; delivered as a download</option>'
		+ '<option value="service"' + (product && product.kind === 'service' ? ' selected="selected"' : '') + '>Service &mdash; provisioned for the customer</option>'
		+ '</select></label>'
		+ storeField('storeProductSort', 'Sort order', product ? product.sortOrder : 0, 'number', 'min="0" max="99999" step="1"')
		+ storeField('storeProductImage', 'Image URL', product ? product.imageUrl : '', 'text', 'maxlength="2048" placeholder="files/shop/mug.jpg"')
		+ '<label class="ghotiField" id="storeDownloadField"><span>File to deliver <i>(path under files/store/)</i></span>'
		+ '<input type="text" id="storeProductDownload" maxlength="255" value="' + storeAttr(product ? product.downloadPath : '') + '" placeholder="guide.pdf" /></label>'
		+ '<label class="ghotiField storeServiceField"><span>Billing</span><select id="storeProductBilling" onchange="storeToggleDownloadField();">'
		+ '<option value="one_time"' + (!product || product.billingType !== 'subscription' ? ' selected="selected"' : '') + '>One-time purchase</option>'
		+ '<option value="subscription"' + (product && product.billingType === 'subscription' ? ' selected="selected"' : '') + '>Recurring PayPal subscription</option></select></label>'
		+ storeField('storeProductServiceTerm', 'Service / billing term', product ? product.serviceTerm : '', 'text', 'maxlength="80" placeholder="per month, 1 year, one-time setup…"').replace('class="ghotiField"', 'class="ghotiField storeServiceField"')
		+ storeField('storeProductPlanId', 'PayPal plan ID', product ? product.paypalPlanId : '', 'text', 'maxlength="80" placeholder="P-…"').replace('class="ghotiField"', 'class="ghotiField storeServiceField storeSubscriptionField"')
		+ storeField('storeProductServicePrompt', 'Setup question', product ? product.servicePrompt : '', 'text', 'maxlength="160" placeholder="Which domain should we configure?"').replace('class="ghotiField"', 'class="ghotiField storeServiceField"')
		+ storeFulfilmentFields(product)
		+ '</div>'
		+ '<label class="ghotiInlineChoice storeServiceField"><input type="checkbox" id="storeProductServiceRequired"' + (product && product.serviceRequired ? ' checked="checked"' : '') + ' /> Require an answer to the setup question</label>'
		+ '<label class="ghotiField ghotiFieldWide"><span>Description</span><textarea id="storeProductDescription" rows="4" maxlength="2000">'
		+ storeAttr(product ? product.description : '') + '</textarea></label>'
		+ '<label class="ghotiInlineChoice"><input type="checkbox" id="storeProductFeatured"' + (product && product.featured ? ' checked="checked"' : '') + ' /> Featured — show first in the collection</label>'
		+ '<label class="ghotiInlineChoice"><input type="checkbox" id="storeProductActive"' + (!product || product.active ? ' checked="checked"' : '') + ' /> Show in the store</label>'
		+ '<div class="ghotiFormActions"><button type="submit" class="ghotiButton">Save product</button>'
		+ '<button type="button" class="ghotiButton ghotiButtonSecondary" onclick="storeCloseProduct();">Cancel</button></div>'
		+ '</form>';

	panel.innerHTML = html;
	panel.hidden = false;
	storeToggleDownloadField();
	storeToggleDropshipFields();
	panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

//Who fulfils this product, and the supplier ids if it is not you. The provider
//list comes from the server's driver registry, so a new supplier shows up here
//without this file changing.
function storeProviderData(){
	var node = document.getElementById('ghotiStoreProviders');
	if(!node){ return {}; }
	try{ return JSON.parse(node.textContent || node.innerText); }
	catch(e){ return {}; }
}

function storeFulfilmentFields(product){
	var providers = storeProviderData();
	var current = product ? (product.fulfilment || 'self') : 'self';
	var chosen = product ? product.dropProvider : '';
	var options = '<option value=""' + (chosen ? '' : ' selected="selected"') + '>Choose a supplier</option>';
	Object.keys(providers).forEach(function(id){
		options += '<option value="' + storeAttr(id) + '"' + (chosen === id ? ' selected="selected"' : '') + '>'
			+ storeAttr(providers[id].label) + '</option>';
	});

	return ''
		+ '<label class="ghotiField" id="storeFulfilmentField"><span>Fulfilled by</span><select id="storeProductFulfilment" onchange="storeToggleDropshipFields();">'
		+ '<option value="self"' + (current === 'self' ? ' selected="selected"' : '') + '>You &mdash; you ship it</option>'
		+ '<option value="dropship"' + (current === 'dropship' ? ' selected="selected"' : '') + '>A supplier &mdash; sent to them when paid</option>'
		+ '<option value="spring"' + (current === 'spring' ? ' selected="selected"' : '') + '>Spring / Teespring — checkout and fulfilment on Spring</option>'
		+ '</select></label>'
		+ '<label class="ghotiField" id="storeSpringField"><span>Spring product URL</span><input type="url" id="storeProductExternal" maxlength="2048" value="' + storeAttr(product ? product.externalUrl : '') + '" placeholder="https://your-store.creator-spring.com/listing/your-product" /><small>Customers choose options and pay on Spring. The local price is a starting price; keep it in sync with your listing.</small></label>'
		+ '<label class="ghotiField storeDropField"><span>Supplier</span><select id="storeProductProvider" onchange="storeToggleDropshipFields();">'
		+ options + '</select></label>'
		+ '<label class="ghotiField storeDropField"><span id="storeProductVariantLabel">Supplier variant id</span>'
		+ '<input type="text" id="storeProductVariant" maxlength="64" value="' + storeAttr(product ? product.dropVariantId : '') + '" /></label>'
		+ '<label class="ghotiField storeDropField" id="storeProductProductIdField"><span id="storeProductProductIdLabel">Supplier product id</span>'
		+ '<input type="text" id="storeProductSupplierId" maxlength="64" value="' + storeAttr(product ? product.dropProductId : '') + '" /></label>';
}

function storeToggleDropshipFields(){
	var route = document.getElementById('storeProductFulfilment');
	var provider = document.getElementById('storeProductProvider');
	var kind = document.getElementById('storeProductKind');
	if(!route){ return; }
	var isService = kind && kind.value === 'service';
	var routeField = document.getElementById('storeFulfilmentField');
	if(routeField){ routeField.hidden = isService; }
	if(isService){ route.value = 'self'; }
	var isDrop = route.value === 'dropship';
	var springField = document.getElementById('storeSpringField');
	if(springField){ springField.hidden = route.value !== 'spring'; }
	var springUrl = document.getElementById('storeProductExternal');
	if(springUrl){
		springUrl.disabled = route.value !== 'spring';
		springUrl.required = route.value === 'spring';
	}
	var downloadField = document.getElementById('storeDownloadField');
	if(downloadField){ downloadField.hidden = !kind || kind.value !== 'digital' || route.value === 'spring'; }
	Array.prototype.forEach.call(document.querySelectorAll('.storeDropField'), function(field){
		field.hidden = !isDrop;
	});
	//A download has no supplier; the two routes are mutually exclusive.
	if(isDrop && kind && kind.value !== 'physical'){
		route.value = 'self';
		storeToggleDropshipFields();
		pageFeedBack('Only a physical product can be sent to a supplier.');
		return;
	}
	if(!isDrop || !provider){ return; }

	//Label the id fields the way the chosen supplier names them, and hide the
	//product id for suppliers that do not use one.
	var providers = storeProviderData();
	var mapping = providers[provider.value] ? providers[provider.value].mapping : null;
	var variantLabel = document.getElementById('storeProductVariantLabel');
	var productField = document.getElementById('storeProductProductIdField');
	var productLabel = document.getElementById('storeProductProductIdLabel');
	if(variantLabel){ variantLabel.textContent = mapping && mapping.variant ? mapping.variant : 'Supplier variant id'; }
	if(productField){ productField.hidden = !(mapping && mapping.product); }
	if(productLabel && mapping && mapping.product){ productLabel.textContent = mapping.product; }
}

function storeField(id, label, value, type, attrs){
	return '<label class="ghotiField"><span>' + label + '</span><input type="' + type + '" id="' + id + '" value="'
		+ storeAttr(value) + '" ' + (attrs || '') + ' /></label>';
}

//Values go into an attribute or a textarea, so quotes and angle brackets have
//to be neutralised: product copy is admin-entered, not trusted markup.
function storeAttr(value){
	return String(value === null || value === undefined ? '' : value)
		.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function storeCentsToText(cents){
	cents = parseInt(cents, 10) || 0;
	var sign = cents < 0 ? '-' : '';
	cents = Math.abs(cents);
	return sign + Math.floor(cents / 100) + '.' + String(cents % 100).padStart(2, '0');
}

function storeToggleDownloadField(){
	var kind = document.getElementById('storeProductKind');
	var field = document.getElementById('storeDownloadField');
	if(!kind || !field){ return; }
	field.hidden = kind.value !== 'digital';
	var service = kind.value === 'service';
	document.querySelectorAll('.storeServiceField').forEach(function(node){ node.hidden = !service; });
	var billing = document.getElementById('storeProductBilling');
	document.querySelectorAll('.storeSubscriptionField').forEach(function(node){ node.hidden = !service || !billing || billing.value !== 'subscription'; });
	storeToggleDropshipFields();
}

function storeCloseProduct(){
	var panel = document.getElementById('ghotiStoreProductForm');
	if(panel){ panel.hidden = true; panel.replaceChildren(); }
}

function storeSaveProduct(){
	function value(id){
		var node = document.getElementById(id);
		return node ? node.value : '';
	}
	var checkbox = document.getElementById('storeProductActive');
	x_saveStoreProduct({
		productId: value('storeProductId'),
		name: value('storeProductName'),
		sku: value('storeProductSku'),
		price: value('storeProductPrice'),
		compareAtPrice: value('storeProductCompareAt'),
		badge: value('storeProductBadge'),
		deliveryNote: value('storeProductDelivery'),
		serviceTerm: value('storeProductServiceTerm'),
		servicePrompt: value('storeProductServicePrompt'),
		serviceRequired: document.getElementById('storeProductServiceRequired') && document.getElementById('storeProductServiceRequired').checked ? 1 : 0,
		billingType: value('storeProductBilling'),
		paypalPlanId: value('storeProductPlanId'),
		externalUrl: value('storeProductExternal'),
		featured: document.getElementById('storeProductFeatured').checked ? 1 : 0,
		category: value('storeProductCategory'),
		kind: value('storeProductKind'),
		sortOrder: value('storeProductSort'),
		imageUrl: value('storeProductImage'),
		downloadPath: value('storeProductDownload'),
		fulfilment: value('storeProductFulfilment'),
		dropProvider: value('storeProductProvider'),
		dropVariantId: value('storeProductVariant'),
		dropProductId: value('storeProductSupplierId'),
		description: value('storeProductDescription'),
		active: checkbox && checkbox.checked ? 1 : 0
	}, function(result){
		if(result === true){
			pageFeedBack('Product saved.');
			showStoreManager('products');
			return;
		}
		pageFeedBack(result || 'The product could not be saved.');
	});
}

function storeDeleteProduct(productId){
	if(!confirm('Delete this product? Past orders keep their own copy of it.')){ return; }
	x_deleteStoreProduct(productId, function(result){
		if(result === true){
			pageFeedBack('Product deleted.');
			showStoreManager('products');
			return;
		}
		pageFeedBack(result || 'The product could not be deleted.');
	});
}

function storeSaveSettings(){
	function value(id){
		var node = document.getElementById(id);
		return node ? node.value : '';
	}
	x_saveStoreSettings({
		paypalClientId: value('store-clientId'),
		paypalSecret: value('store-secret'),
		paypalEnv: value('store-env'),
		cryptoEnabled: !!(document.getElementById('store-cryptoEnabled') && document.getElementById('store-cryptoEnabled').checked),
		cryptoApiKey: value('store-cryptoApiKey'),
		cryptoCurrencies: value('store-cryptoCurrencies'),
		stripeEnabled: !!(document.getElementById('store-stripeEnabled') && document.getElementById('store-stripeEnabled').checked),
		stripePublishableKey: value('store-stripePublishableKey'), stripeSecretKey: value('store-stripeSecretKey'),
		squareEnabled: !!(document.getElementById('store-squareEnabled') && document.getElementById('store-squareEnabled').checked),
		squareApplicationId: value('store-squareApplicationId'), squareLocationId: value('store-squareLocationId'),
		squareAccessToken: value('store-squareAccessToken'), squareEnv: value('store-squareEnv'),
		currency: value('store-currency'),
		shipping: value('store-shipping'),
		shippingNote: value('store-shippingNote'),
		downloadHours: value('store-downloadHours'),
		downloadLimit: value('store-downloadLimit')
	}, function(result){
		if(result === true){
			//Drop any SDK loaded for the previous credentials or currency.
			GHOTI_STORE_SDK = null;
			GHOTI_STORE_SUBSCRIPTION_SDK = null;
			GHOTI_STORE_STRIPE_SCRIPT = null;
			GHOTI_STORE_SQUARE_SCRIPT = null;
			GHOTI_STORE_SQUARE_CARD = null;
			pageFeedBack('Store settings saved.');
			showStoreManager('settings');
			return;
		}
		pageFeedBack(result || 'The settings could not be saved.');
	});
}

function storeShowOrder(orderId){
	x_showStoreOrder(orderId, function(html){
		var panel = document.getElementById('ghotiStoreOrderDetail');
		if(!panel){ return; }
		panel.innerHTML = html;
		panel.hidden = false;
		panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	});
}

function storeRefreshCryptoOrder(orderId){
	x_storeRefreshCryptoPayment(orderId, function(result){
		if(result && result.ok){
			if(result.submitQueued && typeof x_storeSubmitQueued === 'function'){ x_storeSubmitQueued(function(){}); }
			pageFeedBack(result.final ? 'Crypto payment status updated.' : 'Payment is still awaiting confirmation.');
			showStoreManager('orders');
			return;
		}
		pageFeedBack((result && result.error) || 'The crypto payment status could not be refreshed.');
	});
}

function storeRefreshProcessorOrder(orderId){
	x_storeRefreshProcessorPayment(orderId,function(result){
		if(result&&result.ok){
			if(result.submitQueued&&typeof x_storeSubmitQueued==='function'){ x_storeSubmitQueued(function(){}); }
			pageFeedBack(result.final?'Payment status updated.':'Payment is still pending.'); showStoreManager('orders'); return;
		}
		pageFeedBack((result&&result.error)||'The payment status could not be refreshed.');
	});
}

function storeCloseOrder(){
	var panel = document.getElementById('ghotiStoreOrderDetail');
	if(panel){ panel.hidden = true; panel.replaceChildren(); }
}

function storeSetOrderStatus(orderId, status){
	var question = status === 'shipped'
		? 'Mark this order shipped? The customer is not e-mailed automatically.'
		: 'Cancel this order? Nothing is refunded automatically.';
	if(!confirm(question)){ return; }
	x_setStoreOrderStatus(orderId, status, function(result){
		if(result === true){
			pageFeedBack('Order updated.');
			showStoreManager('orders');
			return;
		}
		pageFeedBack(result || 'The order could not be updated.');
	});
}

function storeSetOrderServiceStatus(orderId, status){
	var question = status === 'fulfilled' ? 'Mark this service ready?' : 'Reopen this service setup?';
	if(!confirm(question)){ return; }
	x_setStoreOrderServiceStatus(orderId, status, function(result){
		if(result === true){ pageFeedBack('Service status updated.'); showStoreManager('orders'); return; }
		pageFeedBack(result || 'The service status could not be updated.');
	});
}

function storeRefreshSubscription(subscriptionId){
	x_refreshStoreSubscription(subscriptionId, function(result){
		if(result === true){ pageFeedBack('Subscription status refreshed from PayPal.'); showStoreManager('subscriptions'); return; }
		pageFeedBack(result || 'The subscription status could not be refreshed.');
	});
}

function storeSetSubscriptionServiceStatus(subscriptionId, status){
	var question = status === 'fulfilled' ? 'Mark this subscribed service ready?' : 'Reopen this service setup?';
	if(!confirm(question)){ return; }
	x_setStoreSubscriptionServiceStatus(subscriptionId, status, function(result){
		if(result === true){ pageFeedBack('Subscription setup status updated.'); showStoreManager('subscriptions'); return; }
		pageFeedBack(result || 'The setup status could not be updated.');
	});
}

/* ---------------- dropshipping ---------------- */

function storeSaveDropship(){
	function value(id){
		var node = document.getElementById(id);
		return node ? node.value : '';
	}
	function checked(id){
		var node = document.getElementById(id);
		return node && node.checked ? 1 : 0;
	}
	//Collect every drop-<provider>-<field> input the server rendered, so this
	//function does not need to know which suppliers exist.
	var providers = {};
	Array.prototype.forEach.call(document.querySelectorAll('[id^="drop-"]'), function(input){
		var parts = input.id.split('-');
		if(parts.length !== 3){ return; }
		if(!providers[parts[1]]){ providers[parts[1]] = {}; }
		providers[parts[1]][parts[2]] = input.value;
	});

	x_saveStoreDropshipSettings({
		enabled: checked('drop-enabled'),
		autoSubmit: checked('drop-autoSubmit'),
		providers: providers
	}, function(result){
		if(result === true){
			pageFeedBack('Dropshipping settings saved.');
			showStoreManager('dropship');
			return;
		}
		pageFeedBack(result || 'The settings could not be saved.');
	});
}

function storeDrainQueue(){
	x_storeSubmitQueued(function(result){
		if(!result || !result.ok){
			pageFeedBack((result && result.error) || 'The queue could not be processed.');
			return;
		}
		pageFeedBack(result.sent > 0 ? (result.sent + ' order(s) sent to suppliers.') : 'Nothing was sent. Check the queue for the reason.');
		showStoreManager('dropship');
	});
}

function storeRetryFulfilment(fulfilmentId){
	x_storeRetryFulfilment(fulfilmentId, function(result){
		if(result === true){
			pageFeedBack('Sent to the supplier.');
			showStoreManager('orders');
			return;
		}
		pageFeedBack(result || 'The supplier did not accept this order.');
	});
}

function storeRefreshFulfilment(fulfilmentId){
	x_storeRefreshFulfilment(fulfilmentId, function(result){
		if(result === true){
			pageFeedBack('Supplier status updated.');
			showStoreManager('orders');
			return;
		}
		pageFeedBack(result || 'The supplier status could not be read.');
	});
}

// Controls are scoped to the nearest catalogue, so independent shortcodes work together.
function storeFilterCatalog(control){
	var root = control.closest('.ghotiStoreCatalog');
	if(!root){ return; }
	var query = root.querySelector('[data-store-search]').value.trim().toLocaleLowerCase();
	var kind = root.querySelector('[data-store-kind]').value;
	var sort = root.querySelector('[data-store-sort]').value;
	var category = root.querySelector('[data-store-category][aria-pressed="true"]').dataset.storeCategory;
	var grid = root.querySelector('.ghotiStoreGrid');
	var cards = Array.from(grid.children);
	var visible = 0;
	cards.forEach(function(card){
		var data = card.dataset;
		var match = (!query || data.search.toLocaleLowerCase().includes(query))
			&& (category === 'all' || data.category === category)
			&& (kind === 'all' || (kind === 'sale' ? data.sale === '1' : kind === 'saved' ? storeSavedIds().includes(Number(data.productId)) : kind === 'spring' ? data.spring === '1' : data.kind === kind));
		card.hidden = !match;
		if(match){ visible++; }
	});
	cards.sort(function(a, b){
		var x = a.dataset, y = b.dataset, order = 0;
		if(sort === 'price-asc'){ order = Number(x.price) - Number(y.price); }
		else if(sort === 'price-desc'){ order = Number(y.price) - Number(x.price); }
		else if(sort === 'newest'){ order = Number(y.created) - Number(x.created); }
		else if(sort === 'name'){ order = x.name.localeCompare(y.name); }
		else { order = Number(y.featured) - Number(x.featured); }
		return order || Number(x.index) - Number(y.index);
	}).forEach(function(card){ grid.appendChild(card); });
	root.querySelector('.ghotiStoreResultCount').textContent = visible + ' product' + (visible === 1 ? '' : 's');
	root.querySelector('.ghotiStoreNoResults').hidden = visible > 0;
}

function storeSelectCategory(button){
	button.closest('.ghotiStoreChips').querySelectorAll('button').forEach(function(other){
		other.setAttribute('aria-pressed', String(other === button));
	});
	storeFilterCatalog(button);
}

function storeResetFilters(button){
	var root = button.closest('.ghotiStoreCatalog');
	root.querySelector('[data-store-search]').value = '';
	root.querySelector('[data-store-kind]').value = 'all';
	root.querySelector('[data-store-sort]').value = 'featured';
	storeSelectCategory(root.querySelector('[data-store-category="all"]'));
	root.querySelector('[data-store-search]').focus();
}

function storeFilterProducts(input){
	var query = input.value.trim().toLocaleLowerCase();
	var rows = document.querySelectorAll('#ghotiStoreManager [data-store-admin-product]');
	var count = 0;
	rows.forEach(function(row){
		row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
		if(!row.hidden){ count++; }
	});
	var status = document.getElementById('storeAdminResults');
	if(status){ status.textContent = count + ' product' + (count === 1 ? '' : 's'); }
}

/* Saved favourites stay on this browser; no account or tracking request needed. */
var GHOTI_STORE_SAVED = null;
function storeSavedIds(){
	if(GHOTI_STORE_SAVED !== null){ return GHOTI_STORE_SAVED; }
	try{
		var ids = JSON.parse(localStorage.getItem('ghoti-store-favourites') || '[]');
		GHOTI_STORE_SAVED = Array.isArray(ids) ? ids.filter(function(id){ return Number.isSafeInteger(id) && id > 0; }).slice(0, 500) : [];
	}catch(error){ GHOTI_STORE_SAVED = []; }
	return GHOTI_STORE_SAVED;
}
function storeSyncSaved(){
	var ids = storeSavedIds();
	document.querySelectorAll('[data-store-save]').forEach(function(button){
		var saved = ids.includes(Number(button.dataset.storeSave));
		var label = saved ? '♥ Saved favourite' : '♡ Save favourite';
		button.setAttribute('aria-pressed', String(saved));
		if(button.textContent !== label){ button.textContent = label; }
	});
}
function storeToggleSaved(button){
	var id = Number(button.dataset.storeSave), ids = storeSavedIds();
	var index = ids.indexOf(id);
	if(index >= 0){ ids.splice(index, 1); }
	else if(ids.length < 500){ ids.push(id); }
	else { pageFeedBack('You can save up to 500 favourites. Remove one first.'); return; }
	try{ localStorage.setItem('ghoti-store-favourites', JSON.stringify(ids)); }
	catch(error){ pageFeedBack('Browser storage is unavailable. Favourites will last until you reload this page.'); }
	storeSyncSaved();
	document.querySelectorAll('.ghotiStoreCatalog [data-store-kind]').forEach(function(control){
		if(control.value === 'saved'){ storeFilterCatalog(control); }
	});
}
function storeApplyCoupon(form){
	storeShowCart(form.elements.code.value);
}
function storeAddCoupon(button){
	var form = button.closest('form'), list = form.querySelector('[data-store-coupons]');
	if(list.children.length >= 50){ pageFeedBack('Keep at most 50 codes.'); return; }
	list.appendChild(document.getElementById('storeCouponTemplate').content.cloneNode(true));
	list.lastElementChild.querySelector('input').focus();
}
function storeSavePromotions(form){
	var data = {};
	['freeShipping', 'pointsPerUnit', 'loyaltyThreshold', 'loyaltyPercent'].forEach(function(name){ data[name] = form.elements[name].value; });
	data.coupons = Array.from(form.querySelectorAll('[data-store-coupon]')).map(function(row){
		var coupon = {};
		row.querySelectorAll('[data-coupon-field]').forEach(function(input){ coupon[input.dataset.couponField] = input.type === 'checkbox' ? (input.checked ? 1 : 0) : input.value; });
		return coupon;
	});
	var submit = form.querySelector('[type="submit"]');
	submit.disabled = true;
	x_saveStorePromotions(data, function(result){
		submit.disabled = false;
		pageFeedBack(result === true ? 'Promotions saved.' : (result || 'Promotions could not be saved.'));
		if(result === true){ showStoreManager('promotions'); }
	});
}
function storeWatchCatalogs(){
	storeSyncSaved();
	new MutationObserver(function(records){
		if(records.some(function(record){ return Array.from(record.addedNodes).some(function(node){ return node.nodeType === 1 && (node.matches('[data-store-save]') || node.querySelector('[data-store-save]')); }); })){
			storeSyncSaved();
		}
	}).observe(document.body, {childList:true, subtree:true});
}
if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', storeWatchCatalogs); }
else { storeWatchCatalogs(); }
window.addEventListener('storage', function(event){
	if(event.key === 'ghoti-store-favourites'){
		GHOTI_STORE_SAVED = null;
		storeSyncSaved();
		document.querySelectorAll('[data-store-kind]').forEach(function(control){ if(control.value === 'saved'){ storeFilterCatalog(control); } });
	}
});
