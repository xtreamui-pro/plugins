<?php
    if(!defined("CORE_FOLDER")) return false;
    /** @var XtreamPro $module */
    $v    = $module->dashboard_view();
    $lang = $module->lang;
    $e    = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $t    = function ($k) use ($lang, $e) { return $e($lang[$k] ?? $k); };
?>
<div class="hizmetblok" id="block_module_details_con">
    <div class="block_module_details-title formcon">
        <h4><?php echo $t($v['reseller'] ? 'reseller-title' : 'line-title'); ?></h4>
    </div>
    <div class="clear"></div>

<?php if ($v['error'] !== ''): ?>
    <p class="red"><?php echo $e(sprintf($lang['load-error'] ?? '%s', $v['error'])); ?></p>
<?php else: ?>
    <table width="100%" class="table table-condensed">
        <tbody>
            <tr><th align="left"><?php echo $t('status'); ?></th><td><?php echo $e($v['status']); ?></td></tr>
<?php if ($v['reseller']): ?>
            <tr><th align="left"><?php echo $t('credit-balance'); ?></th><td><?php echo $e($v['credits']); ?></td></tr>
<?php else: ?>
            <tr><th align="left"><?php echo $t('expires'); ?></th><td><?php echo $e($v['expiry']); ?></td></tr>
            <tr><th align="left"><?php echo $t('max-connections'); ?></th><td><?php echo $e($v['max_connections']); ?></td></tr>
            <tr><th align="left"><?php echo $t('server-url'); ?></th><td><code><?php echo $e($v['server_url']); ?></code></td></tr>
<?php endif; ?>
            <tr><th align="left"><?php echo $t('username'); ?></th><td><code><?php echo $e($v['username']); ?></code></td></tr>
            <tr><th align="left"><?php echo $t('password'); ?></th><td><code><?php echo $e($v['password']); ?></code></td></tr>
<?php if (!$v['reseller'] && $v['playlist_url'] !== ''): ?>
            <tr><th align="left"><?php echo $t('m3u-playlist'); ?></th><td><input type="text" readonly style="width:100%" value="<?php echo $e($v['playlist_url']); ?>"></td></tr>
<?php endif; ?>
<?php if (!$v['reseller'] && $v['player_url'] !== ''): ?>
            <tr><th align="left"><?php echo $t('web-player'); ?></th><td><a href="<?php echo $e($v['player_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $e($v['player_url']); ?></a></td></tr>
<?php endif; ?>
        </tbody>
    </table>
<?php if (!$v['reseller']): ?>
    <p class="kinfo"><?php echo $t('apps-hint'); ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
