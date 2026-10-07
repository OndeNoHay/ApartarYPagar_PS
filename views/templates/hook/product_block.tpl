<div class="canelaapartado-product-block">
  <p class="canelaapartado-product-title">
    <span class="canelaapartado-icon" aria-hidden="true">🛍️</span>
    <strong>{$canelaapartado_title|escape:'html':'UTF-8'}</strong>
  </p>
  <p>{l s='Apártalo online sin pagar nada y págalo al recogerlo en la tienda. Te lo guardamos %d horas (máximo %d prendas a la vez).' sprintf=[$canelaapartado_hours, $canelaapartado_max] mod='canelaapartado'}</p>
  {if !$canelaapartado_logged}
    <p class="canelaapartado-note">{l s='Solo necesitas tener cuenta en la tienda: es gratis y tardas un minuto.' mod='canelaapartado'}</p>
  {/if}
  {if $canelaapartado_cms_url}
    <p><a href="{$canelaapartado_cms_url|escape:'html':'UTF-8'}" class="canelaapartado-link">{l s='Cómo funciona' mod='canelaapartado'} →</a></p>
  {/if}
</div>
