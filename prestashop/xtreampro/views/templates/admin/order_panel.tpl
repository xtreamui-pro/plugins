{**
 * Xtream UI Pro panel of the back office order page.
 * Every value is escaped here; the buttons post to the module's hidden admin controller.
 *}
<div class="card mt-2" id="xtreampro-order-panel">
  <div class="card-header">
    <h3 class="card-header-title">{l s='Xtream UI Pro' d='Modules.Xtreampro.Admin'}</h3>
  </div>
  <div class="card-body">
    {if $xp_flash}
      <div class="alert alert-info" role="alert"><p class="alert-text">{$xp_flash|escape:'html':'UTF-8'}</p></div>
    {/if}

    {if $xp_rows}
      <table class="table">
        <thead>
          <tr>
            <th>{l s='Item' d='Modules.Xtreampro.Admin'}</th>
            <th>{l s='Status' d='Modules.Xtreampro.Admin'}</th>
            <th>{l s='Username' d='Modules.Xtreampro.Admin'}</th>
            <th>{l s='Panel id' d='Modules.Xtreampro.Admin'}</th>
            <th>{l s='Message' d='Modules.Xtreampro.Admin'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$xp_rows item=row}
            <tr>
              <td>{$row.title|escape:'html':'UTF-8'}</td>
              <td>{$row.status_label|escape:'html':'UTF-8'}</td>
              <td>{$row.username|escape:'html':'UTF-8'}</td>
              <td>{$row.panel_id|escape:'html':'UTF-8'}</td>
              <td>{if $row.error}<span class="text-danger">{$row.error|escape:'html':'UTF-8'}</span>{/if}</td>
              <td>
                <form method="post" action="{$xp_action|escape:'html':'UTF-8'}" class="form-inline">
                  <input type="hidden" name="xp_token" value="{$xp_token|escape:'html':'UTF-8'}">
                  <input type="hidden" name="id_order" value="{$xp_order_id|intval}">
                  <input type="hidden" name="detail_id" value="{$row.detail_id|intval}">
                  <input type="hidden" name="unit_no" value="{$row.unit_no|intval}">
                  {if $row.can_renew}<button type="submit" name="xp_action" value="renew" class="btn btn-sm btn-outline-secondary">{l s='Renew' d='Modules.Xtreampro.Admin'}</button>{/if}
                  {if $row.can_suspend}<button type="submit" name="xp_action" value="suspend" class="btn btn-sm btn-outline-secondary">{l s='Suspend' d='Modules.Xtreampro.Admin'}</button>{/if}
                  {if $row.can_resume}<button type="submit" name="xp_action" value="resume" class="btn btn-sm btn-outline-secondary">{l s='Resume' d='Modules.Xtreampro.Admin'}</button>{/if}
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p>{l s='Nothing was provisioned for this order yet.' d='Modules.Xtreampro.Admin'}</p>
    {/if}

    <form method="post" action="{$xp_action|escape:'html':'UTF-8'}">
      <input type="hidden" name="xp_token" value="{$xp_token|escape:'html':'UTF-8'}">
      <input type="hidden" name="id_order" value="{$xp_order_id|intval}">
      <button type="submit" name="xp_action" value="provision" class="btn btn-primary">{l s='Provision again' d='Modules.Xtreampro.Admin'}</button>
      <small class="form-text text-muted">{l s='Retries what failed. Only works while the order has a paid status. Nothing is charged twice.' d='Modules.Xtreampro.Admin'}</small>
    </form>
  </div>
</div>
