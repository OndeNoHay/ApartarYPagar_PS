<?php
/**
 * Crea el pedido-apartado al pulsar «Apartar y pagar en la tienda».
 * Basado en el flujo de ps_checkpayment (módulo oficial de PrestaShop).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CanelaApartadoValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = true;
    public $guestAllowed = false;

    public function postProcess()
    {
        /** @var CanelaApartado $module */
        $module = $this->module;
        $cart = $this->context->cart;
        $orderStep = $this->context->link->getPageLink('order', true);

        if (!Validate::isLoadedObject($cart) || !$cart->id_customer || !$cart->id_address_delivery
            || !$cart->id_address_invoice || !$module->active) {
            Tools::redirect($orderStep);
        }

        if ((int) $cart->id_customer !== (int) $this->context->customer->id) {
            Tools::redirect($orderStep);
        }

        // ¿Sigue disponible este método de pago? (dirección, transportista, divisa)
        $authorized = false;
        foreach (Module::getPaymentModules() as $paymentModule) {
            if ($paymentModule['name'] === $module->name) {
                $authorized = true;
                break;
            }
        }
        if (!$authorized || !$module->isOurCarrier((int) $cart->id_carrier)) {
            $this->errors[] = $module->l('Para apartar, elige el envío «Aparta y recoge en tienda».', 'validation');
            $this->redirectWithNotifications($orderStep);
        }

        $customer = $this->context->customer;
        $db = Db::getInstance();
        // Bloqueo por clienta: evita superar el límite de prendas con dos
        // pedidos enviados a la vez (dos pestañas, doble clic...).
        $lock = _DB_PREFIX_ . 'canelaapartado_c' . (int) $customer->id;
        if (!(int) $db->getValue('SELECT GET_LOCK(\'' . pSQL($lock) . '\', 10)')) {
            $this->errors[] = $module->l('Estamos procesando otro apartado tuyo. Inténtalo de nuevo en unos segundos.', 'validation');
            $this->redirectWithNotifications($orderStep);
        }

        try {
            $reasons = $module->getIneligibilityReasons($cart, $customer);
            if ($reasons) {
                $this->errors = array_merge($this->errors, $reasons);
                $this->redirectWithNotifications($orderStep);
            }

            $secureKey = $customer->secure_key;
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

            $module->validateOrder(
                (int) $cart->id,
                (int) Configuration::get(CanelaApartado::CFG_OS_PENDING),
                $total,
                $module->displayName,
                null,
                [],
                (int) $this->context->currency->id,
                false,
                $secureKey
            );

            $order = new Order((int) $module->currentOrder);
            if (Validate::isLoadedObject($order)) {
                $module->registerReservation($order);
            }
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lock) . '\')');
        }

        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $module->id,
            'id_order' => (int) $module->currentOrder,
            'key' => $secureKey,
        ]));
    }
}
