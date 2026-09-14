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

function showStore(category){
	x_showStore(category || 'all', function(html){
		printPage(html);
	});
}

function storeShowCart(){
	x_storeShowCart(function(html){
		printPage(html);
	});
}

function storeAddToCart(productId){
	var input = document.getElementById('storeQty-' + productId);
	var quantity = input ? parseInt(input.value, 10) : 1;
	if(!(quantity > 0)){ quantity = 1; }
	x_storeAddToCart(productId, quantity, function(result){
		if(!result || !result.ok){
			pageFeedBack((result && result.error) || 'That item could not be added.');
			return;
		}
		var summary = document.getElementById('ghotiStoreCartSummary');
		if(summary){ summary.textContent = result.summary; }
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
	x_storeShowCheckout(function(html){
		printPage(html);
		storeMountPaypal();
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
		note: value('storeNote')
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
function storeEditProduct(productId){
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

	var isNew = !product;
	var price = product ? storeCentsToText(product.priceCents) : '0.00';
	var html = ''
		+ '<form class="ghotiForm" action="#" onsubmit="storeSaveProduct(); return false;">'
		+ '<h3>' + (isNew ? 'Add product' : 'Edit product') + '</h3>'
		+ '<input type="hidden" id="storeProductId" value="' + (product ? product.productId : 0) + '" />'
		+ '<div class="ghotiFormGrid">'
		+ storeField('storeProductName', 'Name', product ? product.name : '', 'text', 'maxlength="120" required="required"')
		+ storeField('storeProductSku', 'SKU', product ? product.sku : '', 'text', 'maxlength="60" required="required"')
		+ storeField('storeProductPrice', 'Price', price, 'text', 'maxlength="12" required="required"')
		+ storeField('storeProductCategory', 'Category', product ? product.category : 'default', 'text', 'maxlength="40"')
		+ '<label class="ghotiField"><span>Kind</span><select id="storeProductKind" onchange="storeToggleDownloadField();">'
		+ '<option value="physical"' + (product && product.kind === 'digital' ? '' : ' selected="selected"') + '>Physical &mdash; needs shipping</option>'
		+ '<option value="digital"' + (product && product.kind === 'digital' ? ' selected="selected"' : '') + '>Digital &mdash; delivered as a download</option>'
		+ '</select></label>'
		+ storeField('storeProductSort', 'Sort order', product ? product.sortOrder : 0, 'number', 'min="0" max="99999" step="1"')
		+ storeField('storeProductImage', 'Image URL', product ? product.imageUrl : '', 'text', 'maxlength="2048" placeholder="files/shop/mug.jpg"')
		+ '<label class="ghotiField" id="storeDownloadField"><span>File to deliver <i>(path under files/store/)</i></span>'
		+ '<input type="text" id="storeProductDownload" maxlength="255" value="' + storeAttr(product ? product.downloadPath : '') + '" placeholder="guide.pdf" /></label>'
		+ '</div>'
		+ '<label class="ghotiField ghotiFieldWide"><span>Description</span><textarea id="storeProductDescription" rows="4" maxlength="2000">'
		+ storeAttr(product ? product.description : '') + '</textarea></label>'
		+ '<label class="ghotiInlineChoice"><input type="checkbox" id="storeProductActive"' + (!product || product.active ? ' checked="checked"' : '') + ' /> Show in the store</label>'
		+ '<div class="ghotiFormActions"><button type="submit" class="ghotiButton">Save product</button>'
		+ '<button type="button" class="ghotiButton ghotiButtonSecondary" onclick="storeCloseProduct();">Cancel</button></div>'
		+ '</form>';

	panel.innerHTML = html;
	panel.hidden = false;
	storeToggleDownloadField();
	panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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
		category: value('storeProductCategory'),
		kind: value('storeProductKind'),
		sortOrder: value('storeProductSort'),
		imageUrl: value('storeProductImage'),
		downloadPath: value('storeProductDownload'),
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
		currency: value('store-currency'),
		shipping: value('store-shipping'),
		shippingNote: value('store-shippingNote'),
		downloadHours: value('store-downloadHours'),
		downloadLimit: value('store-downloadLimit')
	}, function(result){
		if(result === true){
			//Drop any SDK loaded for the previous credentials or currency.
			GHOTI_STORE_SDK = null;
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
