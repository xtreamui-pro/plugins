{**
 * Credentials on the order confirmation page and on the order detail page.
 *}
<section class="box xtreampro-credentials">
  <h3>{l s='Your IPTV details' d='Modules.Xtreampro.Shop'}</h3>
  {foreach from=$xp_cards item=card}
    {include file='module:xtreampro/views/templates/hook/card.tpl' card=$card}
  {/foreach}
</section>
