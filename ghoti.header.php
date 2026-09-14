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

<link rel="icon" type="image/x-icon" href="<?php echo $ghotiAsset('favicon.ico'); ?>" />
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo $ghotiAsset('gfx/ghoti-favicon.png'); ?>" />

<script type="text/javascript">
<?php ghoti_async_emit_js(); ?>
</script>
<?/*Third party libs*/?>
<script type="text/javascript" src="<?php echo $ghotiAsset('lib/jquery-4.0.0.js'); ?>"></script>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-7052039184749025" crossorigin="anonymous"></script>
<?/*Main ghoti javascript*/?>
<script type="text/javascript" src="<?php echo $ghotiAsset('ghoti.js'); ?>"></script>

<?/*Module javascript*/?>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/banners/banners.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/comments/comments.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/links/links.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/login/login.js'); ?>"></script>
<?/* Filename avoids "analytics.js" - Firefox ETP / uBlock Origin block that
     path via the Disconnect tracker list, even though this file just draws
     admin-only SVG charts and contains no tracking code. */?>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/analytics/charts.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/analytics/apachelog.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/gallery/gallery.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/filemanager/filemanager.js'); ?>"></script>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/mail/mail.js'); ?>"></script>
<?php if(ghoti::$enableVhosts){ ?>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/vhosts/vhosts.js'); ?>"></script>
<?php } ?>
<?php if(ghoti::$enableStore){ ?>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/store/store.js'); ?>"></script>
<?php } ?>
<?php if(ghoti::$enableBpong){ ?>
<script type="text/javascript" src="<?php echo $ghotiAsset('mod/bpong/bpong.js'); ?>"></script>
<?php } ?>

<?/*Main ghoti stylesheet*/?>
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('css/ghoti/ghoti.css'); ?>" />
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/analytics/analytics.css'); ?>" />
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/analytics/apachelog.css'); ?>" />
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/gallery/gallery.css'); ?>" />
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/filemanager/filemanager.css'); ?>" />
<?php if(ghoti::$enableVhosts){ ?>
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/vhosts/vhosts.css'); ?>" />
<?php } ?>
<?php if(ghoti::$enableStore){ ?>
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/store/store.css'); ?>" />
<?php } ?>
<?php if(ghoti::$enableBpong){ ?>
<link rel="stylesheet" type="text/css" href="<?php echo $ghotiAsset('mod/bpong/bpong.css'); ?>" />
<?php } ?>

<link rel="stylesheet" href="<?php echo $ghotiAsset('css/ghoti/privacy.css'); ?>" />
