<div class="canelaapartado-carrier-info">
  {if $canelaapartado_reasons}
    <p class="canelaapartado-title"><strong>{l s='¿Quieres apartar y pagar en la tienda?' mod='canelaapartado'}</strong></p>
    <p>{l s='Ahora mismo no está disponible para este pedido:' mod='canelaapartado'}</p>
    <ul>
      {foreach from=$canelaapartado_reasons item=reason}
        <li>{$reason|escape:'html':'UTF-8'}</li>
      {/foreach}
    </ul>
  {else}
    <p>
      <strong>{l s='Aparta y recoge en tienda:' mod='canelaapartado'}</strong>
      {l s='te guardamos las prendas %d horas y las pagas al recogerlas. Máximo %d prendas apartadas a la vez.' sprintf=[$canelaapartado_hours, $canelaapartado_max] mod='canelaapartado'}
    </p>
  {/if}
</div>
