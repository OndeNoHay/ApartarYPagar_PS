<section class="canelaapartado-return">
  <h3>{l s='¡Te lo guardamos!' mod='canelaapartado'}</h3>
  <p>{l s='Tu apartado' mod='canelaapartado'} <strong>{$canelaapartado_reference|escape:'html':'UTF-8'}</strong> {l s='está reservado hasta el' mod='canelaapartado'} <strong>{$canelaapartado_expiry|escape:'html':'UTF-8'}</strong>.</p>
  <p>{l s='Importe a pagar en la tienda:' mod='canelaapartado'} <strong>{$canelaapartado_total|escape:'html':'UTF-8'}</strong></p>
  {if $canelaapartado_pickup_info}
    <p class="canelaapartado-pickup">{$canelaapartado_pickup_info|escape:'html':'UTF-8'|nl2br nofilter}</p>
  {/if}
  <p>{l s='Te hemos enviado un correo con los detalles. Si no puedes venir, anúlalo desde «Mis pedidos» para que otra clienta pueda llevárselo.' mod='canelaapartado'}</p>
</section>
