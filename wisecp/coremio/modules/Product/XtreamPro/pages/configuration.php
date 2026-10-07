<?php if(!defined("CORE_FOLDER")) return false;
    /** @var XtreamPro $module  (also passed as variables: $m_name, $area_link, $lang, $api_url, $has_key) */
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $t = function ($k) use ($lang, $e) { return $e($lang[$k] ?? $k); };
?>
<h3>Xtream UI Pro</h3>
<form action="<?php echo $e($area_link); ?>" method="post" id="xtreamProForm" autocomplete="off">
    <input type="hidden" name="operation" value="module_controller">
    <input type="hidden" name="module" value="<?php echo $e($m_name); ?>">
    <input type="hidden" name="controller" value="save">

    <div class="formcon">
        <div class="yuzde30"><?php echo $t('api-url'); ?></div>
        <div class="yuzde70">
            <input type="text" name="api_url" value="<?php echo $e($api_url); ?>" placeholder="https://api.example.com" spellcheck="false">
            <span class="kinfo"><?php echo $t('api-url-desc'); ?></span>
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30"><?php echo $t('api-key'); ?></div>
        <div class="yuzde70">
            <input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo $has_key ? '********' : ''; ?>">
            <span class="kinfo"><?php echo $has_key ? $t('api-key-saved') : $t('api-key-desc'); ?></span>
        </div>
    </div>

    <div class="clear"></div>
    <br>

    <div style="float:right;" class="guncellebtn yuzde30"><a id="xtreamProForm_submit" href="javascript:void(0);" class="yesilbtn gonderbtn"><?php echo $t('save'); ?></a></div>
</form>

<form action="<?php echo $e($area_link); ?>" method="post" id="xtreamProTest">
    <input type="hidden" name="operation" value="module_controller">
    <input type="hidden" name="module" value="<?php echo $e($m_name); ?>">
    <input type="hidden" name="controller" value="test-connection">
    <a id="xtreamProTest_submit" href="javascript:void(0);" class="lbtn"><?php echo $t('test-connection'); ?></a>
</form>

<script type="text/javascript">
    $(document).ready(function(){
        $("#xtreamProForm_submit").click(function(){
            MioAjaxElement($(this),{waiting_text:waiting_text, progress_text:progress_text, result:"xtreamPro_handler"});
        });
        $("#xtreamProTest_submit").click(function(){
            MioAjaxElement($(this),{waiting_text:waiting_text, progress_text:progress_text, result:"xtreamPro_handler"});
        });
    });
    function xtreamPro_handler(result){
        if(result != ''){
            var solve = getJson(result);
            if(solve !== false){
                if(solve.status == "error"){
                    if(solve.message != undefined && solve.message != '')
                        alert_error(solve.message,{timer:5000});
                }else if(solve.status == "successful")
                    alert_success(solve.message,{timer:2500});
            }else
                console.log(result);
        }
    }
</script>
