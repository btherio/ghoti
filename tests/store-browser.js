// Runs inside the CLI-generated preview, with production JS and a fake RPC transport.
(function(){
	var checks = 0;
	function check(condition, message){ if(!condition){ throw new Error(message); } checks++; }
	try{
		var root = document.querySelector('.ghotiStoreCatalog');
		var second = document.querySelectorAll('.ghotiStoreCatalog')[1];
		function visible(){ return Array.from(root.querySelectorAll('.ghotiStoreCard')).filter(function(card){return !card.hidden;}); }
		var search = root.querySelector('[data-store-search]');
		search.value = 'ceramic'; storeFilterCatalog(search);
		check(visible().length === 1 && visible()[0].dataset.name === 'Everyday ceramic mug', 'Search by name');
		check(!second.querySelector('.ghotiStoreCard').hidden, 'Multiple shortcodes remain independent');
		search.value = 'PRINTABLE'; storeFilterCatalog(search);
		check(visible().length === 1 && visible()[0].dataset.kind === 'digital', 'Case-insensitive description search');
		search.value = 'nothing-matches'; storeFilterCatalog(search);
		check(visible().length === 0 && !root.querySelector('.ghotiStoreNoResults').hidden, 'No-results state');
		check(getComputedStyle(root.querySelector('.ghotiStoreCard')).display === 'none', 'Hidden cards respect CSS');
		storeResetFilters(root.querySelector('.ghotiStoreNoResults button'));
		check(visible().length === 4 && root.querySelector('.ghotiStoreNoResults').hidden, 'Reset restores collection');
		var kind = root.querySelector('[data-store-kind]'); kind.value = 'spring'; storeFilterCatalog(kind);
		check(visible().length === 1 && visible()[0].dataset.spring === '1', 'Spring filter');
		check(!visible()[0].querySelector('input[type=number]'), 'No local quantities on Spring card');
		kind.value = 'all'; storeFilterCatalog(kind);
		storeSelectCategory(root.querySelector('[data-store-category="apparel"]'));
		check(visible().length === 2, 'Category filter');
		storeResetFilters(root.querySelector('.ghotiStoreNoResults button'));
		var sort = root.querySelector('[data-store-sort]'); sort.value = 'price-asc'; storeFilterCatalog(sort);
		check(visible().map(function(card){return Number(card.dataset.price);}).join(',') === '1200,2400,2600,3200', 'Ascending prices');
		sort.value = 'newest'; storeFilterCatalog(sort);
		check(visible()[0].dataset.name === 'Weekend canvas tote', 'Newest sort');
		sort.value = 'featured'; storeFilterCatalog(sort);
		check(visible()[0].dataset.featured === '1', 'Featured sort');
		var button = visible()[0].querySelector('.ghotiStoreBuy button');
		button.closest('.ghotiStoreBuy').querySelector('input').value = 3;
		storeAddToCart(1, button);
		check(window.lastCart.qty === 3 && !button.disabled, 'Card quantity and button state');
		check(Array.from(document.querySelectorAll('.ghotiStoreCartSummary')).every(function(node){return node.textContent === '3 items';}), 'All cart summaries update');
		storeEditProduct(2);
		check(document.getElementById('storeProductFulfilment').value === 'spring' && !document.getElementById('storeSpringField').hidden, 'Spring editor selection');
		check(document.getElementById('storeProductExternal').value.includes('creator-spring.com'), 'Saved Spring URL displayed');
		check(Array.from(document.querySelectorAll('.storeDropField')).every(function(node){return node.hidden;}), 'Spring hides supplier IDs');
		storeSaveProduct();
		check(window.lastSaved.externalUrl.includes('creator-spring.com') && window.lastSaved.fulfilment === 'spring', 'Spring form payload');
		storeEditProduct(2, true);
		check(document.getElementById('storeProductId').value === '0' && document.getElementById('storeProductSku').value === '' && !document.getElementById('storeProductActive').checked, 'Duplicate creates hidden draft with fresh SKU');
		check(document.getElementById('storeProductExternal').value.includes('creator-spring.com'), 'Duplicate keeps mapping');
		storeEditProduct(0);
		check(document.getElementById('storeSpringField').hidden && document.getElementById('storeDownloadField').hidden, 'New physical product hides unrelated fields');
		var route = document.getElementById('storeProductFulfilment'); route.value = 'dropship'; storeToggleDropshipFields();
		check(document.getElementById('storeProductProvider').options.length > 1, 'Supplier choices available');
		route.value = 'spring'; storeToggleDropshipFields();
		var productKind = document.getElementById('storeProductKind'); productKind.value = 'digital'; storeToggleDownloadField();
		check(document.getElementById('storeDownloadField').hidden && !document.getElementById('storeSpringField').hidden, 'Spring digital uses hosted URL');
		check(document.getElementById('storeProductExternal').required && !document.getElementById('storeProductExternal').disabled, 'Spring URL is required');
		route.value = 'self'; storeToggleDropshipFields();
		check(!document.getElementById('storeDownloadField').hidden && document.getElementById('storeSpringField').hidden, 'Local digital uses download path');
		check(document.getElementById('storeProductExternal').disabled, 'Hidden Spring URL cannot block local product validation');
		storeCloseProduct();
		var adminSearch = document.querySelector('.ghotiStoreAdminSearch input'); adminSearch.value = 'Spring'; storeFilterProducts(adminSearch);
		check(Array.from(document.querySelectorAll('[data-store-admin-product]')).filter(function(row){return !row.hidden;}).length === 1, 'Admin search includes provider');
		adminSearch.value = ''; storeFilterProducts(adminSearch);
		check(document.documentElement.scrollWidth <= window.innerWidth + 1, 'Page has horizontal overflow');
		document.body.dataset.testResult = 'PASS: ' + checks + ' browser assertions';
	}catch(error){ document.body.dataset.testResult = 'FAIL: ' + error.message; }
})();
