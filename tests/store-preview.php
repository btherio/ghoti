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
$ui = storeUi();
$method = new ReflectionMethod(storeui::class, 'renderProductAdmin');
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Store preview — fixture data</title>
<style><?php readfile(__DIR__.'/../css/ghoti/ghoti.css'); readfile(__DIR__.'/../mod/store/store.css'); ?>
body{margin:0;padding:24px;font-family:system-ui,sans-serif;background:#eee;color:#171717}main{max-width:1250px;margin:auto}#ghotiStoreManager{margin-top:36px;padding:24px;background:var(--store-surface);border-radius:18px}.preview-only{font-size:12px;color:#777}.preview-narrow{max-width:370px;margin:32px auto}@media(prefers-color-scheme:dark){body{background:#111;color:#eee}}@media(max-width:600px){body{padding:12px}#ghotiStoreManager{padding:12px}}</style>
<main><p class="preview-only">LOCAL PREVIEW · FIXTURE PRODUCTS</p>
<?php echo $ui->renderStorefront(array_values($db->products)); ?>
<section id="ghotiStoreManager"><h1>Store management</h1><?php echo $method->invoke($ui, $db->settings); ?></section>
<div class="preview-narrow"><?php echo $ui->renderStorefront(array($db->products[2]), 'apparel', true); ?></div>
</main><script><?php readfile(__DIR__.'/../mod/store/store.js'); ?>
function pageFeedBack(message){ window.lastFeedback = message; }
function x_storeAddToCart(id, qty, callback){ window.lastCart = {id:id, qty:qty}; callback({ok:true, name:'Fixture product', summary:qty+' items'}); }
function x_saveStoreProduct(product, callback){window.lastSaved = product; callback('Fixture only: nothing saved');}
</script><?php if(in_array('--test', $argv, true)){ ?><script><?php readfile(__DIR__.'/store-browser.js'); ?></script><?php } ?></html>
