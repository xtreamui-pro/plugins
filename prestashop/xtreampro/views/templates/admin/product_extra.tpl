{**
 * Xtream UI Pro settings of one product (back office product page, tab "Modules").
 *}
<div class="panel product-tab" id="product-xtreampro">
  <h3>{l s='Xtream UI Pro' d='Modules.Xtreampro.Admin'}</h3>
  <input type="hidden" name="xtreampro_form" value="1">

  <div class="form-group">
    <label class="form-control-label" for="xtreampro_kind">{l s='Sold as' d='Modules.Xtreampro.Admin'}</label>
    <select name="xtreampro_kind" id="xtreampro_kind" class="form-control custom-select">
      <option value=""{if $xp_kind == ''} selected="selected"{/if}>{l s='Not an Xtream UI Pro product' d='Modules.Xtreampro.Admin'}</option>
      <option value="line"{if $xp_kind == 'line'} selected="selected"{/if}>{l s='IPTV line' d='Modules.Xtreampro.Admin'}</option>
      <option value="reseller"{if $xp_kind == 'reseller'} selected="selected"{/if}>{l s='Sub-reseller account (credits)' d='Modules.Xtreampro.Admin'}</option>
    </select>
    <small class="form-text">{l s='IPTV line: every unit bought creates one line. Sub-reseller account: the customer gets one reseller account (made on the first purchase) and every unit hands over the credits below.' d='Modules.Xtreampro.Admin'}</small>
  </div>

  <div class="form-group">
    <label class="form-control-label" for="xtreampro_package_id">{l s='Package' d='Modules.Xtreampro.Admin'}</label>
    <select name="xtreampro_package_id" id="xtreampro_package_id" class="form-control custom-select">
      <option value="0">{l s='None' d='Modules.Xtreampro.Admin'}</option>
      {foreach from=$xp_packages key=pkg_id item=pkg_label}
        <option value="{$pkg_id|intval}"{if $xp_package_id == $pkg_id} selected="selected"{/if}>{$pkg_label|escape:'html':'UTF-8'}</option>
      {/foreach}
    </select>
    <small class="form-text">{l s='IPTV lines only: the package of the line (the list comes from your panel). Ignored for sub-reseller products.' d='Modules.Xtreampro.Admin'}</small>
    {if $xp_packages_error}
      <small class="form-text text-danger">{$xp_packages_error|escape:'html':'UTF-8'}</small>
    {/if}
  </div>

  <div class="form-group">
    <label class="form-control-label" for="xtreampro_trial">{l s='Trial line' d='Modules.Xtreampro.Admin'}</label>
    <select name="xtreampro_trial" id="xtreampro_trial" class="form-control custom-select">
      <option value="0"{if !$xp_trial} selected="selected"{/if}>{l s='No' d='Modules.Xtreampro.Admin'}</option>
      <option value="1"{if $xp_trial} selected="selected"{/if}>{l s='Yes' d='Modules.Xtreampro.Admin'}</option>
    </select>
    <small class="form-text">{l s='IPTV lines only: create the line as a trial (the package must allow it). Ignored for sub-reseller products.' d='Modules.Xtreampro.Admin'}</small>
  </div>

  <div class="form-group">
    <label class="form-control-label" for="xtreampro_credits">{l s='Credits' d='Modules.Xtreampro.Admin'}</label>
    <input type="number" min="0" step="1" name="xtreampro_credits" id="xtreampro_credits" class="form-control" value="{$xp_credits|intval}">
    <small class="form-text">{l s='Sub-reseller products only: credits handed to the reseller account of the customer for every unit bought (taken from your balance). 0 = create the account without credits.' d='Modules.Xtreampro.Admin'}</small>
  </div>
</div>
