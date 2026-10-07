# Aparta y paga en tienda (`canelaapartado`)

Módulo de PrestaShop para que las clientas **registradas** aparten prendas online y las **paguen al recogerlas** en la tienda física.

Probado en **PrestaShop 8.1.4** (PHP 8.1, tema Classic).

## Cómo funciona

1. **Checkout.** Aparece un transportista nuevo, «Aparta y recoge en tienda» (gratis). Si se elige, el único método de pago disponible es «Apartar y pagar en la tienda». Con los demás transportistas este método de pago no aparece.
2. **Requisitos para apartar.** Si la clienta no los cumple, el transportista se oculta y debajo de la lista se le explica el motivo:
   - Tener cuenta (no comprar como invitada).
   - Tener teléfono en la dirección (para avisarla de incidencias).
   - Como máximo **2 prendas** entre el carrito y sus apartados aún pendientes (configurable).
   - No estar bloqueada por apartados no recogidos (por defecto, se bloquea con 2).
3. **Pedido.** Se crea en el estado «Apartado - pendiente de pago en tienda», sin cobro.
   - PrestaShop **descuenta el stock online** en ese momento, así que la prenda deja de venderse en la web.
   - Se envían dos correos: uno a la clienta (qué ha apartado, hasta cuándo, importe y datos de recogida) y otro a la tienda (`pedidos1@canelamoda.es`) con las prendas a separar, la referencia o EAN y el teléfono.
4. **Plazo.** El apartado dura **24 h**. Si en ese momento la tienda está cerrada (noche, mediodía, sábado tarde, domingo o festivo), se alarga **hasta el cierre del siguiente día en que abra**. Ejemplo: apartado el viernes a las 18:00 → caduca el lunes a las 20:30 (o el martes, si el lunes es festivo).
5. **Cobro en tienda.** La clienta paga en el PoS. Después, el pedido se marca como «Pagado y recogido en tienda» de cualquiera de estas formas:
   - *Pedidos → Apartados en tienda → Cobrado*.
   - Cambiando el estado en la ficha del pedido.
   - Desde el PoS por la API (ver más abajo).

   Este estado **no genera factura** en PrestaShop, porque el ticket lo emite el PoS.
6. **Caducidad.** Si no viene a tiempo, el pedido pasa a «Cancelado» y PrestaShop **devuelve el stock** a la web.
   - La clienta recibe un correo avisándola.
   - El apartado cuenta como no recogido para el bloqueo.
7. **Anulación por la clienta.** Puede anular el apartado desde *Mi cuenta → Pedidos*. El stock vuelve a la web y no cuenta como no recogido.

## Instalación

1. Comprime la carpeta `canelaapartado/` en `canelaapartado.zip`. La carpeta tiene que ir dentro del zip.
2. En el back-office: *Módulos → Gestor de módulos → Subir un módulo* → sube el zip.
3. Abre *Configurar* y revisa:
   - **El horario de cada día.** Viene precargado como **supuesto**: L-V 10:00-14:00 y 17:00-20:30, sábado 10:00-14:00, domingo cerrado. Cámbialo por el real.
   - **Los festivos**, uno por línea. `25/12` se repite todos los años; `19/03/2027` vale solo esa fecha. Hay que meter los nacionales, autonómicos y locales, porque el módulo no los conoce.
   - **La información de recogida**: dirección y horario que verá la clienta.
4. **Tarea programada** (recomendada): en el hosting, crea un cron cada 15 minutos con la URL que aparece en la configuración:
   ```
   */15 * * * * curl -s "https://canelamoda.es/module/canelaapartado/cron?token=…" >/dev/null
   ```
   Sin el cron, los apartados caducados se revisan con las visitas a la web (como mucho cada 5 minutos), así que de madrugada pueden tardar más en liberarse.
5. Recomendado: *Clientes → Direcciones → Establecer campos obligatorios* → marca `phone_mobile` o `phone`. Así el teléfono se pide al crear la dirección y no solo al intentar apartar.

## Integración con vuestro PoS (importante)

El apartado **ya descuenta el stock en PrestaShop** al crearse. Por tanto, al cobrarlo en tienda **el PoS no debe registrarlo como una venta nueva que vuelva a restar stock**, porque se descontaría dos veces.

Lo correcto:

1. El PoS ya lee los pedidos entrantes. Los apartados pendientes son los pedidos en el estado cuyo ID aparece en la configuración del módulo («Para el PoS»).
2. Al cobrarlo, el PoS pasa el pedido al estado «Pagado y recogido» por la API de PrestaShop:
   ```
   POST /api/order_histories
   <prestashop><order_history>
     <id_order>123</id_order>
     <id_order_state>ID_PAGADO</id_order_state>
   </order_history></prestashop>
   ```
   La clave de API necesita permiso `POST` en `order_histories`. Esto está probado en 8.1.4: el módulo detecta el cambio y marca el apartado como recogido.
3. Si la clienta, al llegar, se lleva otra talla o algo distinto, anulad el apartado (botón *Anular*; el stock vuelve a la web) y haced en el PoS una venta normal.

## Convivencia con Packlink PRO y otros métodos de pago

- El transportista del módulo es un transportista normal de PrestaShop, independiente de los de Packlink.
- Al instalarse, el módulo limita su método de pago a su transportista y quita los demás métodos de pago de ese transportista (*Pago → Preferencias → Restricciones de transportistas*). Si alguien lo cambia, en la configuración hay un botón para restablecerlo.
- **No se ha podido probar con Packlink PRO instalado.** Antes de activarlo en producción, haced un pedido de prueba en un entorno de pruebas o con la tienda en mantenimiento.

## Back-office

- **Pedidos → Apartados en tienda.** Lista con clienta, teléfono, importe, fecha de caducidad y estado, con botones *Cobrado* y *Anular*. Se puede filtrar por estado.
- **Ficha del pedido.** Panel lateral con el estado del apartado y la fecha de caducidad. Avisa si la clienta tiene apartados sin recoger.
- **Configuración → Clientas bloqueadas.** Permite desbloquear a una clienta.

## Desinstalar

El transportista y los dos estados de pedido se marcan como borrados, pero no se eliminan, porque hay pedidos que los usan. La tabla de apartados se conserva. Si se reinstala, se reutilizan.

## Pruebas realizadas (PrestaShop 8.1.4 en Docker)

- **Lógica (36 comprobaciones):**
  - Cálculo de la caducidad: mediodía, noche, sábado tarde, domingo, festivo puntual y festivo anual.
  - Requisitos: invitada, sin teléfono, más de 2 prendas, límite acumulado con apartados pendientes, bloqueo y desbloqueo.
  - Transportista y método de pago visibles solo cuando corresponde.
  - Stock descontado al apartar y devuelto al caducar.
  - Al cobrar, sin doble descuento y sin factura.
- **Navegador:**
  - Login, carrito, checkout completo, página de confirmación y detalle del pedido.
  - Anulación por la clienta.
  - Configuración (incluida la validación de horario).
  - Lista del back-office con *Cobrado* y panel en la ficha del pedido.
- **Correos** (capturados por SMTP): confirmación a la clienta, aviso a la tienda y aviso de caducidad.
- **API:** cambio a «Pagado y recogido» vía `POST /api/order_histories`, simulando el PoS.
- **Cron:** token incorrecto → 403; token correcto → caduca los vencidos.
- **Desinstalar y reinstalar** sin duplicar el transportista.
