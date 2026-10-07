<?php
/**
 * La clienta anula su propio apartado desde «Mis pedidos». Libera el stock y
 * no cuenta como apartado no recogido.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CanelaApartadoCancelModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = true;
    public $guestAllowed = false;

    public function postProcess()
    {
        /** @var CanelaApartado $module */
        $module = $this->module;
        $history = $this->context->link->getPageLink('history', true);

        if (!Tools::isSubmit('canelaapartado_cancel') || Tools::getValue('token') !== Tools::getToken(false)) {
            Tools::redirect($history);
        }

        $order = new Order((int) Tools::getValue('id_order'));
        $reservation = Validate::isLoadedObject($order) ? CanelaApartadoReservation::getByOrderId((int) $order->id) : null;

        if (!$reservation
            || (int) $order->id_customer !== (int) $this->context->customer->id
            || $reservation->status !== CanelaApartadoReservation::STATUS_PENDING
            || (int) $order->getCurrentState() !== (int) Configuration::get(CanelaApartado::CFG_OS_PENDING)) {
            $this->errors[] = $module->l('Este apartado ya no se puede anular.', 'cancel');
            $this->redirectWithNotifications($history);
        }

        if ($module->changeOrderState($order, (int) Configuration::get('PS_OS_CANCELED'))) {
            $reservation->status = CanelaApartadoReservation::STATUS_CANCELLED;
            $reservation->update();
            $module->sendCancelledByCustomerMails($order, $reservation);
            $this->success[] = $module->l('Has anulado el apartado. ¡Gracias por avisarnos!', 'cancel');
        } else {
            $this->errors[] = $module->l('No se pudo anular el apartado. Llámanos o escríbenos.', 'cancel');
        }

        $this->redirectWithNotifications($this->context->link->getPageLink('order-detail', true, null, ['id_order' => (int) $order->id]));
    }
}
