<div class="panel">
  <h3><i class="icon-info-circle"></i> Estado</h3>
  {if $carrier_name}
    <p>Transportista: <strong>{$carrier_name|escape:'html':'UTF-8'}</strong>
      {if $carrier_active}<span class="label label-success">activo</span>{else}<span class="label label-danger">desactivado</span>{/if}
      — <a href="{$carrier_link|escape:'html':'UTF-8'}">gestionar transportistas</a></p>
  {else}
    <p class="alert alert-danger">No se encuentra el transportista del módulo. Reinstala el módulo.</p>
  {/if}
  <p>Solo este transportista ofrece «Apartar y pagar en la tienda», y con él no se ofrece ningún otro método de pago.
    Si alguien toca <em>Pago &gt; Preferencias &gt; Restricciones de transportistas</em>, puedes restablecerlo:</p>
  <form method="post" action="{$form_action|escape:'html':'UTF-8'}">
    <button type="submit" name="submitCanelaApartadoRestrict" class="btn btn-default"><i class="icon-refresh"></i> Restablecer restricciones de pago</button>
    <a class="btn btn-default" href="{$list_link|escape:'html':'UTF-8'}"><i class="icon-list"></i> Ver apartados</a>
  </form>
  <hr>
  <p><strong>Para el PoS:</strong> los apartados pendientes están en el estado de pedido <code>{$os_pending|intval}</code>.
    Al cobrarlos, el PoS <u>no</u> debe volver a descontar stock (ya se descontó al apartar): basta con pasar el pedido al estado
    <code>{$os_paid|intval}</code> (API: <code>POST /api/order_histories</code> con <code>id_order</code> e <code>id_order_state</code>).</p>
  <hr>
  <p><strong>Tarea programada (recomendado, cada 15 minutos):</strong></p>
  <pre>*/15 * * * * curl -s "{$cron_url|escape:'html':'UTF-8'}" &gt;/dev/null</pre>
  <p class="help-block">Si no la configuras, los apartados se revisan igualmente con las visitas a la tienda (como mucho cada 5 minutos), pero de madrugada puede tardar más.
    Última revisión: {if $last_run}{$last_run|date_format:'%d/%m/%Y %H:%M'}{else}nunca{/if}.</p>
</div>
