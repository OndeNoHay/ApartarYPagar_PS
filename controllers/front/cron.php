<?php
/**
 * Cancela los apartados vencidos. Programar en el servidor, p. ej. cada 15 min:
 *   curl -s "https://tu-tienda/module/canelaapartado/cron?token=XXXX"
 * La URL exacta (con su token) aparece en la configuración del módulo.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CanelaApartadoCronModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        $token = (string) Configuration::get(CanelaApartado::CFG_CRON_TOKEN);
        header('Content-Type: application/json');

        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'forbidden']));
        }

        Configuration::updateGlobalValue(CanelaApartado::CFG_LAST_RUN, time());
        $expired = $this->module->expireDueReservations();

        exit(json_encode(['ok' => true, 'expired' => $expired]));
    }
}
