<?php
/*
 * Created on Apr 2, 2009
 */

$ghotiAssetBase = '/';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $scriptDir = rtrim($scriptDir, '/');
    if ($scriptDir !== '' && $scriptDir !== '.') {
        $ghotiAssetBase = $scriptDir . '/';
    }
}
$ghotiAsset = function($path) use ($ghotiAssetBase) {
    $version = '';
    $file = __DIR__ . '/' . $path;
    if (is_file($file)) {
        $version = '?v=' . filemtime($file);
    }
    return $ghotiAssetBase . $path . $version;
};
?>

<script type="text/javascript">
<?php ghoti_async_emit_js(); ?>
</script>
<?/*Third party libs*/?>
<script type="text/javascript" src="<?php echo $ghotiAsset('lib/jquery-4.0.0.js'); ?>"></script>

<?/*Main ghoti javascript*/?>
<script type="text/javascript" src="<?php echo $ghotiAsset('ghoti.js'); ?>"></script>

<?/*Module javascript*/?>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/banners/banners.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/comments/comments.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/links/links.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/login/login.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/analytics/analytics.js'); ?>"></script>

<?/*Main ghoti stylesheet*/?>
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('css/ghoti/ghoti.css'); ?>" />
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/analytics/analytics.css'); ?>" />
