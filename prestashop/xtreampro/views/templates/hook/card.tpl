{**
 * One bought item: credentials and links. Every value is escaped here.
 *}
<div class="xtreampro-card">
  <h4>{$card.title|escape:'html':'UTF-8'}</h4>
  {if $card.notice}
    <p>{$card.notice|escape:'html':'UTF-8'}</p>
  {/if}
  {if $card.fields}
    <table class="table table-bordered">
      <tbody>
        {foreach from=$card.fields item=field}
          <tr>
            <th scope="row">{$field.label|escape:'html':'UTF-8'}</th>
            <td>{if $field.url}<a href="{$field.url|escape:'html':'UTF-8'}">{$field.value|escape:'html':'UTF-8'}</a>{else}{$field.value|escape:'html':'UTF-8'}{/if}</td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {/if}
</div>
