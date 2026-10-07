{**
 * "My IPTV" page of the customer account.
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
  {l s='My IPTV' d='Modules.Xtreampro.Shop'}
{/block}

{block name='page_content'}
  {if $xp_cards}
    {foreach from=$xp_cards item=card}
      {include file='module:xtreampro/views/templates/hook/card.tpl' card=$card}
    {/foreach}
  {else}
    <p>{l s='You have not bought any IPTV subscription yet.' d='Modules.Xtreampro.Shop'}</p>
  {/if}
{/block}
