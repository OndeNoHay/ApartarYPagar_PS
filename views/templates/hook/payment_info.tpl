<section class="canelaapartado-payment-info">
  <p>{l s='No pagas nada ahora. Te guardamos las prendas hasta el' mod='canelaapartado'} <strong>{$canelaapartado_expiry|escape:'html':'UTF-8'}</strong>; {l s='las pagas en la tienda al recogerlas.' mod='canelaapartado'}</p>
  <p>{l s='Si no pasas antes de esa hora, el apartado se anula y las prendas vuelven a la venta.' mod='canelaapartado'}</p>
  {if $canelaapartado_pickup_info}
    <p class="canelaapartado-pickup">{$canelaapartado_pickup_info|escape:'html':'UTF-8'|nl2br nofilter}</p>
  {/if}
</section>
