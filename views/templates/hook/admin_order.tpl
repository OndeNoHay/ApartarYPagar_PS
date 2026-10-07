<div class="card mt-2">
  <div class="card-header"><h3 class="card-header-title">Apartado en tienda</h3></div>
  <div class="card-body">
    <p>Estado: <strong>{$canelaapartado_status_label|escape:'html':'UTF-8'}</strong></p>
    {if $canelaapartado->status == 'pending'}
      <p>Caduca el <strong>{$canelaapartado_expiry|escape:'html':'UTF-8'}</strong>. Si no viene antes, el pedido se cancelará solo y el stock volverá a la web.</p>
      <p class="text-muted">Cuando cobre en el PoS, cambia el estado del pedido a «{$canelaapartado_paid_state|escape:'html':'UTF-8'}» (o usa Pedidos &gt; Apartados en tienda).</p>
    {/if}
    {if $canelaapartado_noshows > 0}
      <p class="text-danger">Esta clienta tiene {$canelaapartado_noshows|intval} apartado(s) sin recoger.</p>
    {/if}
  </div>
</div>
