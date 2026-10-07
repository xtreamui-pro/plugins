<?php
    // Older loaders: the admin's service detail (same shape as the sample module).
    $options        = $order["options"];
    $buttons        = method_exists($module,"adminArea_buttons_output") ? $module->adminArea_buttons_output() : '';
?>

<?php if($buttons): ?>
    <div class="formcon"><?php echo $buttons; ?></div>
    <div class="clear"></div>
<?php endif; ?>

<?php
    if(method_exists($module,"adminArea_service_fields") && $config_options = $module->adminArea_service_fields())
        $module->config_options_output($config_options,'creation_info');
?>
