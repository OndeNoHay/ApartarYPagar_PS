<?php
/**
 * Pedidos > Apartados en tienda: lista de apartados con acciones rápidas
 * «Cobrado» y «Anular».
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'canelaapartado/classes/CanelaApartadoReservation.php';

class AdminCanelaApartadoController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'canelaapartado_reservation';
        $this->className = 'CanelaApartadoReservation';
        $this->identifier = 'id_canelaapartado_reservation';
        $this->lang = false;
        $this->allow_export = true;
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'date_add';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        $this->_select = 'o.`reference`, o.`total_paid_tax_incl` AS total,
            CONCAT(c.`firstname`, \' \', c.`lastname`) AS customer,
            c.`email`,
            IF(ad.`phone_mobile` <> \'\', ad.`phone_mobile`, ad.`phone`) AS phone,
            o.`id_currency`,
            a.`id_canelaapartado_reservation` AS actions';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON o.`id_order` = a.`id_order`
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = a.`id_customer`
            LEFT JOIN `' . _DB_PREFIX_ . 'address` ad ON ad.`id_address` = o.`id_address_delivery`';

        $statuses = [];
        foreach ([CanelaApartadoReservation::STATUS_PENDING, CanelaApartadoReservation::STATUS_COLLECTED,
            CanelaApartadoReservation::STATUS_EXPIRED, CanelaApartadoReservation::STATUS_CANCELLED, ] as $s) {
            $statuses[$s] = CanelaApartadoReservation::statusLabel($s);
        }

        $this->fields_list = [
            'reference' => ['title' => 'Pedido', 'filter_key' => 'o!reference', 'callback' => 'orderLink'],
            'customer' => ['title' => 'Clienta', 'havingFilter' => true],
            'phone' => ['title' => 'Teléfono', 'havingFilter' => true],
            'email' => ['title' => 'Email', 'filter_key' => 'c!email'],
            'quantity' => ['title' => 'Prendas', 'align' => 'center', 'class' => 'fixed-width-xs'],
            'total' => ['title' => 'Importe', 'type' => 'price', 'currency' => true, 'align' => 'right', 'search' => false],
            'date_add' => ['title' => 'Apartado el', 'type' => 'datetime', 'filter_key' => 'a!date_add'],
            'expires_at' => ['title' => 'Caduca', 'type' => 'datetime', 'filter_key' => 'a!expires_at'],
            'status' => [
                'title' => 'Estado',
                'type' => 'select',
                'list' => $statuses,
                'filter_key' => 'a!status',
                'callback' => 'statusBadge',
            ],
            'actions' => [
                'title' => 'Acciones',
                'search' => false,
                'orderby' => false,
                'callback' => 'actionButtons',
                'remove_onclick' => true,
            ],
        ];
    }

    public function initToolbar()
    {
        parent::initToolbar();
        unset($this->toolbar_btn['new']);
    }

    public function initPageHeaderToolbar()
    {
        $this->page_header_toolbar_btn['configure'] = [
            'href' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => 'canelaapartado']),
            'desc' => 'Configurar',
            'icon' => 'process-icon-configure',
        ];
        parent::initPageHeaderToolbar();
    }

    public function orderLink($reference, $row)
    {
        $url = $this->context->link->getAdminLink('AdminOrders', true, [], ['id_order' => (int) $row['id_order'], 'vieworder' => 1]);

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $reference, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    public function statusBadge($status, $row)
    {
        $classes = [
            CanelaApartadoReservation::STATUS_PENDING => 'warning',
            CanelaApartadoReservation::STATUS_COLLECTED => 'success',
            CanelaApartadoReservation::STATUS_EXPIRED => 'danger',
            CanelaApartadoReservation::STATUS_CANCELLED => 'default',
        ];
        $class = isset($classes[$status]) ? $classes[$status] : 'default';

        return '<span class="badge badge-' . $class . '">' . htmlspecialchars(CanelaApartadoReservation::statusLabel($status), ENT_QUOTES, 'UTF-8') . '</span>';
    }

    private function actionLink($id, $action, $label, $icon, $confirm)
    {
        $url = self::$currentIndex . '&token=' . $this->token . '&' . $this->identifier . '=' . (int) $id . '&canela_action=' . $action;

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="btn btn-default"'
            . ' onclick="return confirm(\'' . htmlspecialchars(addslashes($confirm), ENT_QUOTES, 'UTF-8') . '\');">'
            . '<i class="' . $icon . '"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    public function actionButtons($id, $row)
    {
        if ($row['status'] !== CanelaApartadoReservation::STATUS_PENDING) {
            return '';
        }

        return '<div class="btn-group">'
            . $this->actionLink($id, 'collect', 'Cobrado', 'icon-check', '¿Confirmas que la clienta ha pagado y recogido el pedido en tienda?')
            . $this->actionLink($id, 'cancel', 'Anular', 'icon-remove', '¿Anular el apartado? El stock volverá a la web y no contará como no recogido.')
            . '</div>';
    }

    public function postProcess()
    {
        $action = Tools::getValue('canela_action');
        if ($action === 'collect' || $action === 'cancel') {
            $reservation = new CanelaApartadoReservation((int) Tools::getValue($this->identifier));
            $order = Validate::isLoadedObject($reservation) ? new Order((int) $reservation->id_order) : null;

            if (!$order || !Validate::isLoadedObject($order) || $reservation->status !== CanelaApartadoReservation::STATUS_PENDING) {
                $this->errors[] = 'Este apartado ya no está pendiente.';
            } else {
                $state = $action === 'collect'
                    ? (int) Configuration::get(CanelaApartado::CFG_OS_PAID)
                    : (int) Configuration::get('PS_OS_CANCELED');
                if ($this->module->changeOrderState($order, $state)) {
                    $reservation->status = $action === 'collect'
                        ? CanelaApartadoReservation::STATUS_COLLECTED
                        : CanelaApartadoReservation::STATUS_CANCELLED;
                    $reservation->update();
                    $this->confirmations[] = $action === 'collect'
                        ? 'Pedido ' . $order->reference . ' marcado como pagado y recogido.'
                        : 'Apartado ' . $order->reference . ' anulado. El stock ha vuelto a la web.';
                } else {
                    $this->errors[] = 'No se pudo cambiar el estado del pedido ' . $order->reference . '.';
                }
            }
        }

        return parent::postProcess();
    }
}
