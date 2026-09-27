<?php
// CLI-only fixture: render an isolated catalogue/admin page using fake data.
// Usage: php tests/store-preview.php > /tmp/store-preview.html
if(PHP_SAPI !== 'cli'){ http_response_code(404); exit; }
ob_start();
require __DIR__.'/store.php';
ob_end_clean();
$db = new StoreDbFake();
$_SESSION['storeObj']->storedb = $db;
$_SESSION['storeCart'] = array();
$products = array(
	array('name'=>'Everyday ceramic mug','sku'=>'MUG','priceCents'=>2400,'category'=>'home','featured'=>true,'badge'=>'Everyday favourite','deliveryNote'=>'Packed with care · Ships in 2–3 days','description'=>'A generous ceramic mug for slow mornings and long conversations. Dishwasher and microwave safe.','kind'=>'physical'),
	array('name'=>'The studio tee','sku'=>'TEE','priceCents'=>3200,'category'=>'apparel','fulfilment'=>'spring','externalUrl'=>'https://studio.creator-spring.com/listing/studio-tee','badge'=>'Made to order','description'=>'Soft cotton, a relaxed fit, and your choice of colours. Choose a size on Spring.','kind'=>'physical'),
	array('name'=>'A field guide to creating','sku'=>'GUIDE','priceCents'=>1200,'compareAtCents'=>1800,'category'=>'digital','description'=>'Make room for your next idea. Thirty prompts, a printable planner, and a little encouragement.','deliveryNote'=>'PDF · Yours to keep','kind'=>'digital','downloadPath'=>'guide.pdf'),
	array('name'=>'Weekend canvas tote','sku'=>'TOTE','priceCents'=>2600,'category'=>'apparel','description'=>'An everyday carry-all with room for the good stuff.','kind'=>'physical'),
);
$db->products = array();
foreach($products as $index => $product){
	$id = $index + 1;
	$db->products[$id] = array_merge(array('productId'=>$id,'name'=>'','sku'=>'','description'=>'','priceCents'=>0,'kind'=>'physical','category'=>'default','imageUrl'=>'','downloadPath'=>'','active'=>true,'sortOrder'=>0,'createdAt'=>$id,'fulfilment'=>'self','dropProvider'=>'','dropProductId'=>'','dropVariantId'=>'','externalUrl'=>'','featured'=>false,'compareAtCents'=>0,'badge'=>'','deliveryNote'=>''), $product);
}
$theme = 'default'; $view = 'catalog';
foreach($argv as $argument){
	if(strpos($argument, '--theme=') === 0){ $theme = substr($argument, 8); }
	if(strpos($argument, '--view=') === 0){ $view = substr($argument, 7); }
}
$themeFiles = array('default'=>array(), 'prosimii'=>array('css/prosimii/prosimii-modern.css'), 'smurfius'=>array('css/smurfius/style.css'), 'ghoticms'=>array('css/ghoticms/style.css'), 'cyber'=>array('css/cyber/cyber.css'), 'ironhide'=>array('css/ironhide/ironhide.css', 'css/ironhide/store.css'), 'mahogany'=>array('css/mahogany/mahogany.css', 'css/mahogany/store.css'), 'spore'=>array('css/spore/spore.css'), 'veil'=>array('css/veil/veil.css'));
if(!isset($themeFiles[$theme]) || !in_array($view, array('catalog', 'cart', 'checkout', 'promotions'), true)){ throw new RuntimeException('Unknown preview theme or view'); }
$db->settings['commerceConfig'] = storeValidateCommerce(array('freeShipping'=>'60', 'pointsPerUnit'=>2, 'loyaltyThreshold'=>500, 'loyaltyPercent'=>5, 'coupons'=>array(array('code'=>'WELCOME', 'type'=>'percent', 'value'=>10, 'minimum'=>'0', 'start'=>'', 'end'=>'', 'active'=>1))));
storeTestSignOut();
$bodyClass = $theme === 'prosimii' ? 'prosimii-modern' : ($theme === 'default' ? '' : $theme.'-theme');
$contentClass = array('mahogany'=>'mahogany-content', 'ironhide'=>'ironhide-content', 'spore'=>'spore-reading', 'veil'=>'veil-document', 'prosimii'=>'prosimii-content')[$theme] ?? '';
$ui = storeUi();
$method = new ReflectionMethod(storeui::class, 'renderProductAdmin');
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Store preview — fixture data</title>
<style><?php readfile(__DIR__.'/../css/ghoti/ghoti.css'); readfile(__DIR__.'/../mod/store/store.css'); ?>
body{margin:0;padding:24px;font-family:system-ui,sans-serif;background:#eee;color:#171717}main{max-width:1250px;margin:auto}#ghotiStoreManager{margin-top:36px;padding:24px;background:var(--store-surface);border-radius:18px}.preview-only{font-size:12px;color:#777}.preview-narrow{max-width:370px;margin:32px auto}@media(prefers-color-scheme:dark){body{background:#111;color:#eee}}@media(max-width:600px){body{padding:12px}#ghotiStoreManager{padding:12px}}</style><style><?php foreach($themeFiles[$theme] as $file){ readfile(__DIR__.'/../'.$file); } ?></style>
<body class="<?php echo htmlspecialchars($bodyClass, ENT_QUOTES); ?>"><main><section class="<?php echo htmlspecialchars($contentClass, ENT_QUOTES); ?>"><div id="ghotiContent"><p class="preview-only">LOCAL PREVIEW · FIXTURE PRODUCTS</p>
<?php if($view === 'catalog'){ echo $ui->renderStorefront(array_values($db->products)); ?>
<section id="ghotiStoreManager"><h1>Store management</h1><?php echo $method->invoke($ui, $db->settings); ?></section>
<div class="preview-narrow"><?php echo $ui->renderStorefront(array($db->products[2]), 'apparel', true); ?></div>
<?php }else{
	$_SESSION['storeCart'] = array(1=>1, 3=>1, 4=>1); $_SESSION['storeCoupon'] = 'WELCOME';
	if($view === 'cart'){ echo storeShowCart(); }
	elseif($view === 'checkout'){ echo storeShowCheckout(); }
	else { storeTestSignIn(true); echo showStoreManager('promotions'); }
} ?></div></section></main><script><?php readfile(__DIR__.'/../mod/store/store.js'); ?>
function pageFeedBack(message){ window.lastFeedback = message; }
function x_storeAddToCart(id, qty, callback){ window.lastCart = {id:id, qty:qty}; callback({ok:true, name:'Fixture product', summary:qty+' items'}); }
function x_saveStoreProduct(product, callback){window.lastSaved = product; callback('Fixture only: nothing saved');}
</script><?php if(in_array('--test', $argv, true)){ ?><script><?php readfile(__DIR__.'/'.($view === 'catalog' ? 'store-browser.js' : 'store-commerce-browser.js')); ?></script><?php } ?>
<script>
window.addEventListener('load', function(){
 document.body.dataset.overflow = String(document.documentElement.scrollWidth > innerWidth + 1);
 var shop = document.querySelector('.ghotiStore, #ghotiStoreManager');
 document.body.dataset.surface = getComputedStyle(shop).getPropertyValue('--store-surface').trim();
});
</script></body></html>
