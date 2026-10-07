<div class="panel">
  <h3><i class="icon-ban"></i> Clientas bloqueadas por no recoger</h3>
  {if $limit == 0}
    <p>El bloqueo está desactivado.</p>
  {elseif !$blocked}
    <p>Ninguna clienta bloqueada.</p>
  {else}
    <table class="table">
      <thead><tr><th>Clienta</th><th>Email</th><th>No recogidos</th><th></th></tr></thead>
      <tbody>
      {foreach from=$blocked item=c}
        <tr>
          <td>{$c.firstname|escape:'html':'UTF-8'} {$c.lastname|escape:'html':'UTF-8'}</td>
          <td>{$c.email|escape:'html':'UTF-8'}</td>
          <td>{$c.noshows|intval}</td>
          <td>
            <form method="post" action="{$form_action|escape:'html':'UTF-8'}">
              <input type="hidden" name="id_customer" value="{$c.id_customer|intval}">
              <button type="submit" name="submitCanelaApartadoUnblock" class="btn btn-default btn-sm">Desbloquear</button>
            </form>
          </td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  {/if}
</div>
