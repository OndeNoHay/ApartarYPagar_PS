<section class="box canelaapartado-order-detail">
  <h3>{l s='Apartado en tienda' mod='canelaapartado'}</h3>
  <p>{l s='Estado:' mod='canelaapartado'} <strong>{$canelaapartado_status_label|escape:'html':'UTF-8'}</strong></p>
  {if $canelaapartado->status == 'pending'}
    <p>{l s='Te lo guardamos hasta el' mod='canelaapartado'} <strong>{$canelaapartado_expiry|escape:'html':'UTF-8'}</strong>. {l s='Lo pagas al recogerlo.' mod='canelaapartado'}</p>
    {if $canelaapartado_pickup_info}
      <p class="canelaapartado-pickup">{$canelaapartado_pickup_info|escape:'html':'UTF-8'|nl2br nofilter}</p>
    {/if}
    <form method="post" action="{$canelaapartado_cancel_url|escape:'html':'UTF-8'}"
          onsubmit="return confirm('{l s='¿Seguro que quieres anular el apartado?' mod='canelaapartado' js=1}');">
      <input type="hidden" name="id_order" value="{$canelaapartado->id_order|intval}">
      <input type="hidden" name="token" value="{$canelaapartado_token|escape:'html':'UTF-8'}">
      <button type="submit" name="canelaapartado_cancel" value="1" class="btn btn-secondary">{l s='Ya no lo quiero: anular apartado' mod='canelaapartado'}</button>
    </form>
  {/if}
</section>
