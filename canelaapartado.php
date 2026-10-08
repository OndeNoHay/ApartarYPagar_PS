<?php
/**
 * Aparta y paga en tienda (canelaapartado)
 *
 * Permite a las clientas registradas apartar prendas online y pagarlas al
 * recogerlas en la tienda física. El pedido se crea sin cobro, descuenta el
 * stock online y caduca automáticamente si no se recoge a tiempo; al caducar
 * pasa a «Cancelado» y PrestaShop devuelve el stock.
 *
 * Probado en PrestaShop 8.1.4.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/CanelaApartadoReservation.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class CanelaApartado extends PaymentModule
{
    // Claves de configuración
    const CFG_HOURS = 'CANELAAPARTADO_HOURS';
    const CFG_MAX_ITEMS = 'CANELAAPARTADO_MAX_ITEMS';
    const CFG_OPENING_HOURS = 'CANELAAPARTADO_OPENING_HOURS';
    const CFG_HOLIDAYS = 'CANELAAPARTADO_HOLIDAYS';
    const CFG_NOSHOW_LIMIT = 'CANELAAPARTADO_NOSHOW_LIMIT';
    const CFG_STORE_EMAIL = 'CANELAAPARTADO_STORE_EMAIL';
    const CFG_PICKUP_INFO = 'CANELAAPARTADO_PICKUP_INFO';
    const CFG_CARRIER_REF = 'CANELAAPARTADO_CARRIER_REF';
    const CFG_OS_PENDING = 'CANELAAPARTADO_OS_PENDING';
    const CFG_OS_PAID = 'CANELAAPARTADO_OS_PAID';
    const CFG_CRON_TOKEN = 'CANELAAPARTADO_CRON_TOKEN';
    const CFG_LAST_RUN = 'CANELAAPARTADO_LAST_RUN';
    const CFG_BADGE_ENABLED = 'CANELAAPARTADO_BADGE_ENABLED';
    const CFG_BADGE_TEXT = 'CANELAAPARTADO_BADGE_TEXT';
    const CFG_BADGE_COLOR = 'CANELAAPARTADO_BADGE_COLOR';
    const CFG_BLOCK_ENABLED = 'CANELAAPARTADO_BLOCK_ENABLED';
    const CFG_CMS_ID = 'CANELAAPARTADO_CMS_ID';

    const DEFAULT_BADGE_TEXT = 'Aparta y paga en tienda';
    const DEFAULT_BADGE_COLOR = '#F28C28'; // naranja

    // Cada cuánto, como mínimo, el propio tráfico de la tienda revisa los
    // apartados caducados (respaldo por si el cron del servidor no está puesto).
    const PSEUDO_CRON_SECONDS = 300;

    /** Evita que el cambio de estado que hace el propio módulo se procese dos veces. */
    public static $changingStatus = false;

    public function __construct()
    {
        $this->name = 'canelaapartado';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.2';
        $this->author = 'Canela';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = ['validation', 'cancel', 'cron'];
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => '8.99.99'];

        parent::__construct();

        $this->displayName = $this->l('Aparta y paga en tienda');
        $this->description = $this->l('Las clientas registradas apartan prendas online y las pagan al recogerlas en la tienda física.');
        $this->confirmUninstall = $this->l('Los apartados pendientes dejarán de caducar automáticamente. ¿Desinstalar?');
    }

    /* ------------------------------------------------------------------
     * Instalación
     * ------------------------------------------------------------------ */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $ok = $this->installDb()
            && $this->installOrderStates()
            && $this->installCarrier()
            && $this->installTab()
            && $this->registerHook([
                'paymentOptions',
                'displayPaymentReturn',
                'actionFilterDeliveryOptionList',
                'displayAfterCarrier',
                'actionOrderStatusPostUpdate',
                'actionFrontControllerSetMedia',
                'actionAdminControllerSetMedia',
                'displayAdminOrderSide',
                'displayOrderDetail',
            ]);

        if (!$ok) {
            return false;
        }

        Configuration::updateValue(self::CFG_HOURS, 24);
        Configuration::updateValue(self::CFG_MAX_ITEMS, 2);
        if (!Configuration::get(self::CFG_OPENING_HOURS)) {
            // Supuesto inicial (ajustar en la configuración): sábado solo mañana, domingo cerrado.
            Configuration::updateValue(self::CFG_OPENING_HOURS, json_encode([
                1 => '10:00-14:00, 17:00-20:30',
                2 => '10:00-14:00, 17:00-20:30',
                3 => '10:00-14:00, 17:00-20:30',
                4 => '10:00-14:00, 17:00-20:30',
                5 => '10:00-14:00, 17:00-20:30',
                6 => '10:00-14:00',
                7 => '',
            ]));
        }
        if (Configuration::get(self::CFG_HOLIDAYS) === false) {
            Configuration::updateValue(self::CFG_HOLIDAYS, '');
        }
        Configuration::updateValue(self::CFG_NOSHOW_LIMIT, 2);
        Configuration::updateValue(self::CFG_STORE_EMAIL, 'pedidos1@canelamoda.es');
        if (!Configuration::get(self::CFG_PICKUP_INFO)) {
            Configuration::updateValue(self::CFG_PICKUP_INFO, '');
        }
        if (!Configuration::get(self::CFG_CRON_TOKEN)) {
            Configuration::updateValue(self::CFG_CRON_TOKEN, bin2hex(random_bytes(16)));
        }

        return $this->restrictPaymentToCarrier() && $this->installProductBadge();
    }

    /**
     * Etiqueta «Aparta y paga en tienda» en los productos y página «Cómo funciona».
     * Se llama al instalar y al actualizar desde 1.0.0 (upgrade/upgrade-1.1.0.php).
     */
    public function installProductBadge()
    {
        if (Configuration::get(self::CFG_BADGE_ENABLED) === false) {
            Configuration::updateValue(self::CFG_BADGE_ENABLED, 1);
        }
        if (Configuration::get(self::CFG_BLOCK_ENABLED) === false) {
            Configuration::updateValue(self::CFG_BLOCK_ENABLED, 1);
        }
        if (!Configuration::get(self::CFG_BADGE_TEXT)) {
            Configuration::updateValue(self::CFG_BADGE_TEXT, self::DEFAULT_BADGE_TEXT);
        }
        if (!Configuration::get(self::CFG_BADGE_COLOR)) {
            Configuration::updateValue(self::CFG_BADGE_COLOR, self::DEFAULT_BADGE_COLOR);
        }

        return $this->registerHook(['actionProductFlagsModifier', 'displayProductAdditionalInfo', 'displayHeader'])
            && $this->installCmsPage();
    }

    /** Crea (o reactiva) la página CMS «Aparta y paga en tienda». */
    private function installCmsPage()
    {
        $cms = new CMS((int) Configuration::get(self::CFG_CMS_ID));
        if (Validate::isLoadedObject($cms)) {
            $cms->active = true;

            return $cms->save();
        }

        $html = file_get_contents(__DIR__ . '/views/templates/cms/como-funciona.html');
        $cms = new CMS();
        $cms->id_cms_category = 1; // categoría raíz «Inicio»
        $cms->active = true;
        $cms->indexation = true;
        foreach (Language::getLanguages(false) as $lang) {
            $id = (int) $lang['id_lang'];
            $cms->meta_title[$id] = 'Aparta y paga en tienda';
            $cms->head_seo_title[$id] = 'Aparta online y paga en la tienda';
            $cms->meta_description[$id] = 'Aparta tus prendas online sin pagar nada y págalas al recogerlas en nuestra tienda. Te las guardamos 24 horas, como mínimo hasta el final del siguiente turno en que abramos.';
            $cms->link_rewrite[$id] = 'aparta-y-paga-en-tienda';
            $cms->content[$id] = $html;
        }
        if (!$cms->add()) {
            return false;
        }

        return Configuration::updateValue(self::CFG_CMS_ID, (int) $cms->id);
    }

    public function uninstall()
    {
        // Los estados de pedido y el transportista no se borran: hay pedidos que
        // los referencian. Se ocultan para que no se usen más.
        $carrier = $this->getCarrier();
        if ($carrier) {
            $carrier->deleted = true;
            $carrier->save();
        }
        foreach ([self::CFG_OS_PENDING, self::CFG_OS_PAID] as $key) {
            $state = new OrderState((int) Configuration::get($key));
            if (Validate::isLoadedObject($state)) {
                $state->deleted = true;
                $state->unremovable = false;
                $state->save();
            }
        }

        $tabId = (int) Tab::getIdFromClassName('AdminCanelaApartado');
        if ($tabId) {
            (new Tab($tabId))->delete();
        }

        // La página «Cómo funciona» se desactiva (no se borra: puede tener cambios).
        $cms = new CMS((int) Configuration::get(self::CFG_CMS_ID));
        if (Validate::isLoadedObject($cms)) {
            $cms->active = false;
            $cms->save();
        }

        // La tabla de apartados se conserva a propósito (histórico de no-shows).
        foreach ([self::CFG_HOURS, self::CFG_MAX_ITEMS, self::CFG_OPENING_HOURS, self::CFG_HOLIDAYS, self::CFG_NOSHOW_LIMIT,
            self::CFG_STORE_EMAIL, self::CFG_PICKUP_INFO, self::CFG_LAST_RUN, self::CFG_BADGE_ENABLED,
            self::CFG_BADGE_TEXT, self::CFG_BADGE_COLOR, self::CFG_BLOCK_ENABLED, ] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function installDb()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'canelaapartado_reservation` (
            `id_canelaapartado_reservation` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT UNSIGNED NOT NULL,
            `id_customer` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `expires_at` DATETIME NOT NULL,
            `counts_noshow` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_canelaapartado_reservation`),
            UNIQUE KEY `id_order` (`id_order`),
            KEY `customer_status` (`id_customer`, `status`),
            KEY `status_expires` (`status`, `expires_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function installOrderStates()
    {
        $states = [
            self::CFG_OS_PENDING => [
                'name' => 'Apartado - pendiente de pago en tienda',
                'color' => '#E7A33E',
                'logable' => false,
                'paid' => false,
                'shipped' => false,
            ],
            self::CFG_OS_PAID => [
                'name' => 'Pagado y recogido en tienda',
                'color' => '#01B887',
                'logable' => true,
                'paid' => true,
                'shipped' => true,
            ],
        ];

        foreach ($states as $key => $def) {
            $state = new OrderState((int) Configuration::get($key));
            if (!Validate::isLoadedObject($state)) {
                $state = new OrderState();
            }
            $state->name = [];
            foreach (Language::getLanguages(false) as $lang) {
                $state->name[(int) $lang['id_lang']] = $def['name'];
            }
            $state->module_name = $this->name;
            $state->color = $def['color'];
            $state->logable = $def['logable'];
            $state->paid = $def['paid'];
            $state->shipped = $def['shipped'];
            $state->delivery = false;
            // El ticket lo emite el PoS: PrestaShop no debe generar factura.
            $state->invoice = false;
            $state->pdf_invoice = false;
            $state->pdf_delivery = false;
            // Los correos los manda el módulo con su propia plantilla.
            $state->send_email = false;
            $state->hidden = false;
            $state->unremovable = true;
            $state->deleted = false;
            if (!$state->save()) {
                return false;
            }
            Configuration::updateValue($key, (int) $state->id);

            $icon = __DIR__ . '/logo.gif';
            if (file_exists($icon)) {
                @copy($icon, _PS_ORDER_STATE_IMG_DIR_ . (int) $state->id . '.gif');
            }
        }

        return true;
    }

    private function installCarrier()
    {
        // Al reinstalar se recupera el transportista anterior (aunque esté
        // marcado como borrado) para no duplicarlo.
        $idCarrier = (int) Db::getInstance()->getValue(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
             WHERE `id_reference` = ' . (int) Configuration::get(self::CFG_CARRIER_REF) . '
             ORDER BY `id_carrier` DESC'
        );
        $carrier = new Carrier($idCarrier ?: null);

        $carrier->name = 'Aparta y recoge en tienda';
        $carrier->delay = [];
        foreach (Language::getLanguages(false) as $lang) {
            $carrier->delay[(int) $lang['id_lang']] = 'Te lo guardamos y lo pagas al recogerlo en la tienda';
        }
        $carrier->active = true;
        $carrier->deleted = false;
        $carrier->is_free = true;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = false;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_FREE;
        $carrier->is_module = false;
        $carrier->url = '';

        if (!$carrier->save()) {
            return false;
        }

        // id_reference se mantiene aunque PrestaShop duplique el transportista al editarlo.
        $carrier = new Carrier((int) $carrier->id);
        Configuration::updateValue(self::CFG_CARRIER_REF, (int) $carrier->id_reference);

        $groups = array_map(function ($g) {
            return (int) $g['id_group'];
        }, Group::getGroups((int) Configuration::get('PS_LANG_DEFAULT')));
        $carrier->setGroups($groups);

        foreach (Zone::getZones(true) as $zone) {
            $carrier->deleteZone((int) $zone['id_zone']);
            $carrier->addZone((int) $zone['id_zone']);
        }

        return true;
    }

    /**
     * Este método de pago solo para su transportista, y ningún otro método de
     * pago para ese transportista (si no, se podría elegir «recoger» y pagar con
     * tarjeta, que es otro flujo).
     */
    public function restrictPaymentToCarrier()
    {
        $ref = (int) Configuration::get(self::CFG_CARRIER_REF);
        if (!$ref) {
            return false;
        }
        $db = Db::getInstance();
        $ok = $db->delete('module_carrier', 'id_module = ' . (int) $this->id)
            && $db->delete('module_carrier', 'id_reference = ' . $ref);
        foreach (Shop::getShops(false, null, true) as $idShop) {
            $ok = $ok && $db->insert('module_carrier', [
                'id_module' => (int) $this->id,
                'id_shop' => (int) $idShop,
                'id_reference' => $ref,
            ]);
        }

        return $ok;
    }

    private function installTab()
    {
        $tabId = (int) Tab::getIdFromClassName('AdminCanelaApartado');
        $tab = $tabId ? new Tab($tabId) : new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminCanelaApartado';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentOrders');
        $tab->name = [];
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Apartados en tienda';
        }

        return $tab->save();
    }

    /* ------------------------------------------------------------------
     * Utilidades
     * ------------------------------------------------------------------ */

    /** @return Carrier|null */
    public function getCarrier()
    {
        $ref = (int) Configuration::get(self::CFG_CARRIER_REF);
        if (!$ref) {
            return null;
        }
        $carrier = Carrier::getCarrierByReference($ref);

        return $carrier ?: null;
    }

    public function isOurCarrier($idCarrier)
    {
        $carrier = new Carrier((int) $idCarrier);

        return Validate::isLoadedObject($carrier)
            && (int) $carrier->id_reference === (int) Configuration::get(self::CFG_CARRIER_REF);
    }

    /**
     * Convierte «10:00-14:00, 17:00-20:30» en [[600, 840], [1020, 1230]] (minutos).
     * Devuelve null si el texto no es válido.
     */
    public static function parseRanges($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return [];
        }
        $ranges = [];
        foreach (preg_split('/[,;]+/', $text) as $part) {
            if (!preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*-\s*(\d{1,2})[:.](\d{2})\s*$/', $part, $m)) {
                return null;
            }
            $from = (int) $m[1] * 60 + (int) $m[2];
            $to = (int) $m[3] * 60 + (int) $m[4];
            if ($from >= $to || $to > 24 * 60 || (int) $m[2] > 59 || (int) $m[4] > 59) {
                return null;
            }
            $ranges[] = [$from, $to];
        }
        usort($ranges, function ($a, $b) {
            return $a[0] - $b[0];
        });

        return $ranges;
    }

    /** @return array<int, string> día ISO-8601 (1 = lunes) => texto del horario */
    public function getOpeningHoursText()
    {
        $data = json_decode((string) Configuration::get(self::CFG_OPENING_HOURS), true);
        $out = [];
        for ($d = 1; $d <= 7; ++$d) {
            $out[$d] = is_array($data) && isset($data[$d]) ? (string) $data[$d] : '';
        }

        return $out;
    }

    /**
     * Festivos, uno por línea: «25/12» (todos los años) o «19/03/2027» / «2027-03-19».
     *
     * @return array{0: string[], 1: string[]} [fechas Y-m-d, fechas anuales m-d]
     */
    public static function parseHolidays($text)
    {
        $dates = [];
        $yearly = [];
        foreach (preg_split('/[\r\n,;]+/', (string) $text) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $line, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $dates[] = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $line, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                $dates[] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $line, $m) && checkdate((int) $m[2], (int) $m[1], 2024)) {
                $yearly[] = sprintf('%02d-%02d', $m[2], $m[1]);
            } else {
                return null;
            }
        }

        return [$dates, $yearly];
    }

    /** Franjas de apertura de ese día (vacío si cierra o es festivo). */
    public function getPeriodsFor(DateTime $day)
    {
        static $holidays = null;
        if ($holidays === null) {
            $holidays = self::parseHolidays(Configuration::get(self::CFG_HOLIDAYS)) ?: [[], []];
        }
        if (in_array($day->format('Y-m-d'), $holidays[0], true) || in_array($day->format('m-d'), $holidays[1], true)) {
            return [];
        }
        $hours = $this->getOpeningHoursText();

        return self::parseRanges($hours[(int) $day->format('N')]) ?: [];
    }

    /**
     * Fecha límite de recogida: ahora + N horas. Si en ese momento la tienda
     * está cerrada (noche, mediodía, sábado tarde, domingo, festivo...), se
     * alarga hasta el final del siguiente turno de apertura, para que la
     * clienta tenga siempre un turno con la tienda abierta para venir.
     */
    public function computeExpiry(DateTime $from = null)
    {
        $target = $from ? clone $from : new DateTime();
        $target->modify('+' . max(1, (int) Configuration::get(self::CFG_HOURS)) . ' hours');

        $day = clone $target;
        $day->setTime(0, 0, 0);
        for ($i = 0; $i < 60; ++$i) {
            $periods = $this->getPeriodsFor($day);
            if ($periods) {
                $minutes = (int) $target->format('H') * 60 + (int) $target->format('i');
                $sameDay = $day->format('Y-m-d') === $target->format('Y-m-d');
                foreach ($periods as $p) {
                    if ($sameDay && $minutes >= $p[0] && $minutes < $p[1]) {
                        return $target; // la tienda está abierta en ese momento
                    }
                    if (!$sameDay || $minutes < $p[0]) {
                        // primer turno que empieza después: caduca al terminar ese turno
                        $close = clone $day;
                        $close->setTime(intdiv($p[1], 60) % 24, $p[1] % 60, 0);
                        if ($p[1] >= 24 * 60) {
                            $close->modify('+1 day');
                        }

                        return $close;
                    }
                }
            }
            $day->modify('+1 day');
        }

        return $target; // sin horario configurado
    }

    /** Funciona también fuera de una página (cron, CLI), donde no hay locale cargado. */
    private function formatPrice($amount, $isoCode)
    {
        $locale = $this->context->getCurrentLocale() ?: Tools::getContextLocale($this->context);

        return $locale->formatPrice($amount, $isoCode);
    }

    public function formatDate($datetime)
    {
        $days = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
        $months = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre', ];
        $d = $datetime instanceof DateTime ? $datetime : new DateTime($datetime);

        return $days[(int) $d->format('N')] . ' ' . $d->format('j') . ' de ' . $months[(int) $d->format('n')]
            . ' a las ' . $d->format('H:i');
    }

    /**
     * Motivos por los que esta clienta no puede apartar este carrito ahora.
     * Lista vacía = puede apartar.
     *
     * @return string[]
     */
    public function getIneligibilityReasons(Cart $cart, Customer $customer)
    {
        $reasons = [];

        if (!Validate::isLoadedObject($customer) || $customer->isGuest()) {
            $reasons[] = $this->l('Inicia sesión con tu cuenta de clienta (no como invitada).');

            return $reasons;
        }

        $address = new Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($address) || (trim((string) $address->phone) === '' && trim((string) $address->phone_mobile) === '')) {
            $reasons[] = $this->l('Añade un teléfono a tu dirección para poder avisarte si hay alguna incidencia.');
        }

        if ($cart->isVirtualCart()) {
            $reasons[] = $this->l('Los productos virtuales no se pueden apartar.');
        }

        $max = (int) Configuration::get(self::CFG_MAX_ITEMS);
        $inCart = (int) $cart->nbProducts();
        $reserved = CanelaApartadoReservation::getPendingQuantity((int) $customer->id);
        if ($max > 0 && $inCart + $reserved > $max) {
            if ($reserved > 0) {
                $reasons[] = sprintf(
                    $this->l('Puedes tener como máximo %d prendas apartadas a la vez. Ya tienes %d apartada(s) y en el carrito hay %d.'),
                    $max, $reserved, $inCart
                );
            } else {
                $reasons[] = sprintf(
                    $this->l('Puedes apartar como máximo %d prendas a la vez y en el carrito hay %d.'),
                    $max, $inCart
                );
            }
        }

        $limit = (int) Configuration::get(self::CFG_NOSHOW_LIMIT);
        if ($limit > 0 && CanelaApartadoReservation::countNoShows((int) $customer->id) >= $limit) {
            $reasons[] = $this->l('No puedes apartar porque no se recogieron apartados anteriores. Escríbenos o pásate por la tienda para reactivarlo.');
        }

        return $reasons;
    }

    /* ------------------------------------------------------------------
     * Checkout
     * ------------------------------------------------------------------ */

    /** Oculta el transportista «Aparta y recoge» si la clienta no puede apartar. */
    public function hookActionFilterDeliveryOptionList($params)
    {
        if (!isset($params['delivery_option_list']) || !is_array($params['delivery_option_list'])) {
            return;
        }
        $carrier = $this->getCarrier();
        if (!$carrier || !$this->active) {
            return;
        }
        $cart = $this->context->cart;
        $customer = $this->context->customer;
        if (!Validate::isLoadedObject($cart) || !$customer) {
            return;
        }
        if (empty($this->getIneligibilityReasons($cart, $customer))) {
            return;
        }

        foreach ($params['delivery_option_list'] as $idAddress => $options) {
            foreach ($options as $key => $option) {
                if (isset($option['carrier_list'][(int) $carrier->id])) {
                    unset($params['delivery_option_list'][$idAddress][$key]);
                }
            }
        }
    }

    /** Debajo de los transportistas: explica qué es apartar o por qué ahora no se puede. */
    public function hookDisplayAfterCarrier($params)
    {
        if (!$this->active || !$this->getCarrier()) {
            return '';
        }
        $cart = $this->context->cart;
        $reasons = $this->getIneligibilityReasons($cart, $this->context->customer);

        $this->context->smarty->assign([
            'canelaapartado_reasons' => $reasons,
            'canelaapartado_hours' => (int) Configuration::get(self::CFG_HOURS),
            'canelaapartado_max' => (int) Configuration::get(self::CFG_MAX_ITEMS),
        ]);

        return $this->fetch('module:canelaapartado/views/templates/hook/after_carrier.tpl');
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active || empty($params['cart'])) {
            return [];
        }
        $cart = $params['cart'];
        if (!$this->isOurCarrier((int) $cart->id_carrier)) {
            return [];
        }
        if (!empty($this->getIneligibilityReasons($cart, $this->context->customer))) {
            return [];
        }

        $this->context->smarty->assign([
            'canelaapartado_expiry' => $this->formatDate($this->computeExpiry()),
            'canelaapartado_pickup_info' => (string) Configuration::get(self::CFG_PICKUP_INFO),
        ]);

        $option = new PaymentOption();
        $option->setModuleName($this->name)
            ->setCallToActionText($this->l('Apartar y pagar en la tienda'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
            ->setAdditionalInformation($this->fetch('module:canelaapartado/views/templates/hook/payment_info.tpl'));

        return [$option];
    }

    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || empty($params['order'])) {
            return '';
        }
        $reservation = CanelaApartadoReservation::getByOrderId((int) $params['order']->id);
        if (!$reservation) {
            return '';
        }

        $this->context->smarty->assign([
            'canelaapartado_reference' => $params['order']->reference,
            'canelaapartado_expiry' => $this->formatDate($reservation->expires_at),
            'canelaapartado_total' => $this->formatPrice(
                $params['order']->total_paid,
                (new Currency((int) $params['order']->id_currency))->iso_code
            ),
            'canelaapartado_pickup_info' => (string) Configuration::get(self::CFG_PICKUP_INFO),
        ]);

        return $this->fetch('module:canelaapartado/views/templates/hook/payment_return.tpl');
    }

    /**
     * Crea el apartado y avisa a clienta y tienda. Lo llama el controlador de
     * validación justo después de validateOrder().
     */
    public function registerReservation(Order $order)
    {
        $reservation = new CanelaApartadoReservation();
        $reservation->id_order = (int) $order->id;
        $reservation->id_customer = (int) $order->id_customer;
        $reservation->id_shop = (int) $order->id_shop;
        $reservation->quantity = (int) array_sum(array_column($order->getProductsDetail(), 'product_quantity'));
        $reservation->status = CanelaApartadoReservation::STATUS_PENDING;
        $reservation->expires_at = $this->computeExpiry()->format('Y-m-d H:i:s');
        $reservation->counts_noshow = 0;
        $reservation->add();

        $this->sendReservationMails($order, $reservation);

        return $reservation;
    }

    /* ------------------------------------------------------------------
     * Correos
     * ------------------------------------------------------------------ */

    private function productLines(Order $order)
    {
        $lines = [];
        foreach ($order->getProductsDetail() as $p) {
            $lines[] = [
                'name' => $p['product_name'],
                'reference' => $p['product_reference'],
                'ean' => $p['product_ean13'],
                'quantity' => (int) $p['product_quantity'],
            ];
        }

        return $lines;
    }

    private function mailLang(Order $order)
    {
        // Las plantillas están en es/ y en/; si el idioma del pedido es otro, español.
        $lang = new Language((int) $order->id_lang);
        if (Validate::isLoadedObject($lang) && is_dir(__DIR__ . '/mails/' . $lang->iso_code)) {
            return (int) $lang->id;
        }
        $es = (int) Language::getIdByIso('es');

        return $es ?: (int) $order->id_lang;
    }

    private function sendMail(Order $order, $template, $subject, array $vars, $to, $toName = null)
    {
        if (!$to || !Validate::isEmail($to)) {
            return false;
        }
        $idLang = $this->mailLang($order);
        // Mail::Send busca mails/<iso>/; si el iso no tiene carpeta, se copia la española.
        $iso = Language::getIsoById($idLang);
        if ($iso && !is_dir(__DIR__ . '/mails/' . $iso)) {
            return false;
        }

        return (bool) Mail::Send(
            $idLang,
            $template,
            $subject,
            $vars,
            $to,
            $toName,
            null,
            null,
            null,
            null,
            __DIR__ . '/mails/',
            false,
            (int) $order->id_shop
        );
    }

    private function productsHtml(array $lines, $withRefs)
    {
        $html = '';
        foreach ($lines as $l) {
            $html .= '<li>' . (int) $l['quantity'] . ' × ' . htmlspecialchars($l['name'], ENT_QUOTES, 'UTF-8');
            if ($withRefs && ($l['reference'] || $l['ean'])) {
                $html .= ' <small>(' . htmlspecialchars(trim($l['reference'] . ' ' . $l['ean']), ENT_QUOTES, 'UTF-8') . ')</small>';
            }
            $html .= '</li>';
        }

        return '<ul>' . $html . '</ul>';
    }

    private function productsTxt(array $lines, $withRefs)
    {
        $txt = '';
        foreach ($lines as $l) {
            $txt .= '- ' . (int) $l['quantity'] . ' x ' . $l['name'];
            if ($withRefs && ($l['reference'] || $l['ean'])) {
                $txt .= ' (' . trim($l['reference'] . ' ' . $l['ean']) . ')';
            }
            $txt .= "\n";
        }

        return $txt;
    }

    private function sendReservationMails(Order $order, CanelaApartadoReservation $reservation)
    {
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_delivery);
        $lines = $this->productLines($order);
        $currency = new Currency((int) $order->id_currency);
        $total = $this->formatPrice($order->total_paid, $currency->iso_code);
        $pickupInfo = (string) Configuration::get(self::CFG_PICKUP_INFO);
        $phone = trim($address->phone_mobile ?: $address->phone);

        $common = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{expires_at}' => $this->formatDate($reservation->expires_at),
            '{total}' => $total,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop),
            '{pickup_info_html}' => nl2br(htmlspecialchars($pickupInfo, ENT_QUOTES, 'UTF-8')),
            '{pickup_info_txt}' => $pickupInfo,
        ];

        $this->sendMail(
            $order,
            'canelaapartado_confirmacion',
            sprintf($this->l('Te guardamos tu pedido %s hasta el %s'), $order->reference, $this->formatDate($reservation->expires_at)),
            $common + [
                '{products_html}' => $this->productsHtml($lines, false),
                '{products_txt}' => $this->productsTxt($lines, false),
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );

        $this->sendMail(
            $order,
            'canelaapartado_tienda',
            sprintf($this->l('Nuevo apartado %s: separar prendas'), $order->reference),
            $common + [
                '{products_html}' => $this->productsHtml($lines, true),
                '{products_txt}' => $this->productsTxt($lines, true),
                '{email}' => $customer->email,
                '{phone}' => $phone,
            ],
            (string) Configuration::get(self::CFG_STORE_EMAIL)
        );
    }

    private function sendExpiredMail(Order $order, CanelaApartadoReservation $reservation)
    {
        $customer = new Customer((int) $order->id_customer);
        $lines = $this->productLines($order);
        $this->sendMail(
            $order,
            'canelaapartado_caducado',
            sprintf($this->l('Tu apartado %s ha caducado'), $order->reference),
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{order_reference}' => $order->reference,
                '{expires_at}' => $this->formatDate($reservation->expires_at),
                '{shop_name}' => Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop),
                '{products_html}' => $this->productsHtml($lines, false),
                '{products_txt}' => $this->productsTxt($lines, false),
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );
    }

    /** La clienta ha anulado su apartado: confirmación a ella y aviso a la tienda. */
    public function sendCancelledByCustomerMails(Order $order, CanelaApartadoReservation $reservation)
    {
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_delivery);
        $lines = $this->productLines($order);
        $common = [
            '{firstname}' => $customer->firstname,
            '{lastname}' => $customer->lastname,
            '{order_reference}' => $order->reference,
            '{shop_name}' => Configuration::get('PS_SHOP_NAME', null, null, (int) $order->id_shop),
        ];

        $this->sendMail(
            $order,
            'canelaapartado_anulado_clienta',
            sprintf($this->l('Has anulado tu apartado %s'), $order->reference),
            $common + [
                '{products_html}' => $this->productsHtml($lines, false),
                '{products_txt}' => $this->productsTxt($lines, false),
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname
        );

        $this->sendMail(
            $order,
            'canelaapartado_anulado_tienda',
            sprintf($this->l('Apartado %s anulado por la clienta: devolver prendas a la venta'), $order->reference),
            $common + [
                '{products_html}' => $this->productsHtml($lines, true),
                '{products_txt}' => $this->productsTxt($lines, true),
                '{email}' => $customer->email,
                '{phone}' => trim($address->phone_mobile ?: $address->phone),
            ],
            (string) Configuration::get(self::CFG_STORE_EMAIL)
        );
    }

    /* ------------------------------------------------------------------
     * Ciclo de vida del apartado
     * ------------------------------------------------------------------ */

    /**
     * Cambia el estado del pedido como lo haría el back-office: así PrestaShop
     * devuelve el stock al cancelar (OrderHistory::changeIdOrderState).
     */
    public function changeOrderState(Order $order, $idState)
    {
        if ((int) $order->getCurrentState() === (int) $idState) {
            return true;
        }
        self::$changingStatus = true;
        try {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->id_employee = isset($this->context->employee->id) ? (int) $this->context->employee->id : 0;
            $history->changeIdOrderState((int) $idState, $order, true);

            return $history->add();
        } finally {
            self::$changingStatus = false;
        }
    }

    /**
     * Cancela los apartados vencidos y devuelve su stock. Lo llaman el cron y,
     * como respaldo, las visitas a la tienda.
     *
     * @return int apartados caducados en esta pasada
     */
    public function expireDueReservations()
    {
        $lockName = _DB_PREFIX_ . 'canelaapartado_expire';
        $db = Db::getInstance();
        if (!(int) $db->getValue('SELECT GET_LOCK(\'' . pSQL($lockName) . '\', 0)')) {
            return 0; // otra petición ya lo está haciendo
        }

        $count = 0;
        try {
            $pendingState = (int) Configuration::get(self::CFG_OS_PENDING);
            foreach (CanelaApartadoReservation::getExpiredPending() as $reservation) {
                $order = new Order((int) $reservation->id_order);
                if (!Validate::isLoadedObject($order)) {
                    $reservation->status = CanelaApartadoReservation::STATUS_CANCELLED;
                    $reservation->update();
                    continue;
                }

                if ((int) $order->getCurrentState() !== $pendingState) {
                    // El personal ya lo movió a mano; solo sincronizamos.
                    $this->syncReservationWithState($reservation, (int) $order->getCurrentState());
                    continue;
                }

                if ($this->changeOrderState($order, (int) Configuration::get('PS_OS_CANCELED'))) {
                    $reservation->status = CanelaApartadoReservation::STATUS_EXPIRED;
                    $reservation->counts_noshow = 1;
                    $reservation->update();
                    $this->sendExpiredMail($order, $reservation);
                    ++$count;
                }
            }
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(\'' . pSQL($lockName) . '\')');
        }

        return $count;
    }

    private function syncReservationWithState(CanelaApartadoReservation $reservation, $idState)
    {
        if ($reservation->status !== CanelaApartadoReservation::STATUS_PENDING) {
            return;
        }
        if ($idState === (int) Configuration::get(self::CFG_OS_PENDING)) {
            return;
        }
        $state = new OrderState($idState);
        $reservation->status = (Validate::isLoadedObject($state) && $state->paid)
            ? CanelaApartadoReservation::STATUS_COLLECTED
            : CanelaApartadoReservation::STATUS_CANCELLED;
        $reservation->update();
    }

    /** Si el personal cambia el estado del pedido a mano, el apartado se actualiza. */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (self::$changingStatus || empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }
        $reservation = CanelaApartadoReservation::getByOrderId((int) $params['id_order']);
        if ($reservation) {
            $this->syncReservationWithState($reservation, (int) $params['newOrderStatus']->id);
        }
    }

    private function maybeRunPseudoCron()
    {
        $last = (int) Configuration::getGlobalValue(self::CFG_LAST_RUN);
        if (time() - $last < self::PSEUDO_CRON_SECONDS) {
            return;
        }
        Configuration::updateGlobalValue(self::CFG_LAST_RUN, time());
        try {
            $this->expireDueReservations();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('canelaapartado: ' . $e->getMessage(), 3, null, 'Module', (int) $this->id, true);
        }
    }

    public function hookActionFrontControllerSetMedia($params)
    {
        $this->maybeRunPseudoCron();

        // En todas las páginas: la etiqueta aparece en listados, buscador, portada...
        $this->context->controller->registerStylesheet(
            'canelaapartado',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
    }

    /* ------------------------------------------------------------------
     * Etiqueta en productos
     * ------------------------------------------------------------------ */

    /** ¿Está el servicio operativo (módulo activo y transportista activo)? */
    private function isServiceAvailable()
    {
        static $available = null;
        if ($available === null) {
            $carrier = $this->active ? $this->getCarrier() : null;
            $available = $carrier && $carrier->active;
        }

        return $available;
    }

    /**
     * Se puede apartar si hay stock. $allVersions = true mira la suma de todas las
     * tallas (listados); false, la talla seleccionada (ficha de producto).
     */
    private function hasStockToReserve($product, $allVersions)
    {
        if (!empty($product['is_virtual'])) {
            return false;
        }
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return true;
        }
        $key = $allVersions && isset($product['quantity_all_versions']) ? 'quantity_all_versions' : 'quantity';

        return isset($product[$key]) && (int) $product[$key] > 0;
    }

    public function getBadgeColor()
    {
        $color = (string) Configuration::get(self::CFG_BADGE_COLOR);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::DEFAULT_BADGE_COLOR;
    }

    /** Color de la etiqueta (configurable) como variable CSS. */
    public function hookDisplayHeader($params)
    {
        if (!$this->isServiceAvailable()) {
            return '';
        }

        return '<style>:root{--canelaapartado-color:' . $this->getBadgeColor() . ';}</style>';
    }

    /** Añade la etiqueta junto a «Nuevo», «-20 %»... (listados y ficha). */
    public function hookActionProductFlagsModifier($params)
    {
        if (!Configuration::get(self::CFG_BADGE_ENABLED) || !$this->isServiceAvailable()
            || empty($params['product']) || !$this->hasStockToReserve($params['product'], true)) {
            return;
        }
        $params['flags']['canelaapartado'] = [
            'type' => 'canelaapartado',
            'label' => (string) Configuration::get(self::CFG_BADGE_TEXT) ?: self::DEFAULT_BADGE_TEXT,
        ];
    }

    /** Bloque explicativo junto a «Añadir al carrito» (según la talla elegida). */
    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!Configuration::get(self::CFG_BLOCK_ENABLED) || !$this->isServiceAvailable()
            || empty($params['product']) || !$this->hasStockToReserve($params['product'], false)) {
            return '';
        }

        $cms = new CMS((int) Configuration::get(self::CFG_CMS_ID), (int) $this->context->language->id);
        $this->context->smarty->assign([
            'canelaapartado_title' => (string) Configuration::get(self::CFG_BADGE_TEXT) ?: self::DEFAULT_BADGE_TEXT,
            'canelaapartado_hours' => (int) Configuration::get(self::CFG_HOURS),
            'canelaapartado_max' => (int) Configuration::get(self::CFG_MAX_ITEMS),
            'canelaapartado_logged' => $this->context->customer && $this->context->customer->isLogged(),
            'canelaapartado_cms_url' => (Validate::isLoadedObject($cms) && $cms->active)
                ? $this->context->link->getCMSLink($cms) : '',
        ]);

        return $this->fetch('module:canelaapartado/views/templates/hook/product_block.tpl');
    }

    public function hookActionAdminControllerSetMedia($params)
    {
        $this->maybeRunPseudoCron();
    }

    /* ------------------------------------------------------------------
     * Back-office y área de clienta
     * ------------------------------------------------------------------ */

    public function hookDisplayAdminOrderSide($params)
    {
        $reservation = CanelaApartadoReservation::getByOrderId((int) $params['id_order']);
        if (!$reservation) {
            return '';
        }
        $customerNoShows = CanelaApartadoReservation::countNoShows((int) $reservation->id_customer);
        $this->context->smarty->assign([
            'canelaapartado' => $reservation,
            'canelaapartado_expiry' => $this->formatDate($reservation->expires_at),
            'canelaapartado_status_label' => CanelaApartadoReservation::statusLabel($reservation->status),
            'canelaapartado_paid_state' => (new OrderState((int) Configuration::get(self::CFG_OS_PAID), (int) $this->context->language->id))->name,
            'canelaapartado_noshows' => $customerNoShows,
        ]);

        return $this->fetch('module:canelaapartado/views/templates/hook/admin_order.tpl');
    }

    public function hookDisplayOrderDetail($params)
    {
        if (empty($params['order'])) {
            return '';
        }
        $order = $params['order'];
        $reservation = CanelaApartadoReservation::getByOrderId((int) $order->id);
        if (!$reservation) {
            return '';
        }
        $this->context->smarty->assign([
            'canelaapartado' => $reservation,
            'canelaapartado_expiry' => $this->formatDate($reservation->expires_at),
            'canelaapartado_status_label' => CanelaApartadoReservation::statusLabel($reservation->status),
            'canelaapartado_cancel_url' => $this->context->link->getModuleLink($this->name, 'cancel', [], true),
            'canelaapartado_token' => Tools::getToken(false),
            'canelaapartado_pickup_info' => (string) Configuration::get(self::CFG_PICKUP_INFO),
        ]);

        return $this->fetch('module:canelaapartado/views/templates/hook/order_detail.tpl');
    }

    /* ------------------------------------------------------------------
     * Configuración
     * ------------------------------------------------------------------ */

    public function getCronUrl()
    {
        return $this->context->link->getModuleLink(
            $this->name,
            'cron',
            ['token' => Configuration::get(self::CFG_CRON_TOKEN)],
            true
        );
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCanelaApartado')) {
            $hours = (int) Tools::getValue(self::CFG_HOURS);
            $max = (int) Tools::getValue(self::CFG_MAX_ITEMS);
            $noshow = (int) Tools::getValue(self::CFG_NOSHOW_LIMIT);
            $email = trim((string) Tools::getValue(self::CFG_STORE_EMAIL));
            $days = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
            $opening = [];
            $holidays = (string) Tools::getValue(self::CFG_HOLIDAYS);

            $errors = [];
            if ($hours < 1 || $hours > 24 * 14) {
                $errors[] = $this->l('Las horas de apartado deben estar entre 1 y 336.');
            }
            if ($max < 0) {
                $errors[] = $this->l('El máximo de prendas no puede ser negativo.');
            }
            if ($email !== '' && !Validate::isEmail($email)) {
                $errors[] = $this->l('El email de la tienda no es válido.');
            }
            foreach ($days as $d => $dayName) {
                $opening[$d] = trim((string) Tools::getValue('opening_' . $d));
                if (self::parseRanges($opening[$d]) === null) {
                    $errors[] = sprintf($this->l('El horario del %s no es válido. Usa el formato 10:00-14:00, 17:00-20:30 o déjalo vacío si cierra.'), $dayName);
                }
            }
            if (!array_filter($opening)) {
                $errors[] = $this->l('Indica el horario de al menos un día.');
            }
            if (self::parseHolidays($holidays) === null) {
                $errors[] = $this->l('Hay festivos con formato no válido. Usa 25/12 (todos los años) o 19/03/2027, uno por línea.');
            }

            if ($errors) {
                foreach ($errors as $e) {
                    $output .= $this->displayError($e);
                }
            } else {
                Configuration::updateValue(self::CFG_HOURS, $hours);
                Configuration::updateValue(self::CFG_MAX_ITEMS, $max);
                Configuration::updateValue(self::CFG_NOSHOW_LIMIT, max(0, $noshow));
                Configuration::updateValue(self::CFG_STORE_EMAIL, $email);
                Configuration::updateValue(self::CFG_OPENING_HOURS, json_encode($opening));
                Configuration::updateValue(self::CFG_HOLIDAYS, $holidays);
                Configuration::updateValue(self::CFG_PICKUP_INFO, (string) Tools::getValue(self::CFG_PICKUP_INFO));
                $output .= $this->displayConfirmation($this->l('Configuración guardada.'));
            }
        }

        if (Tools::isSubmit('submitCanelaApartadoBadge')) {
            $color = trim((string) Tools::getValue(self::CFG_BADGE_COLOR));
            $text = trim((string) Tools::getValue(self::CFG_BADGE_TEXT));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                $output .= $this->displayError($this->l('El color debe tener el formato #RRGGBB, por ejemplo #F28C28.'));
            } elseif ($text === '' || Tools::strlen($text) > 40) {
                $output .= $this->displayError($this->l('El texto de la etiqueta es obligatorio (máximo 40 caracteres).'));
            } else {
                Configuration::updateValue(self::CFG_BADGE_ENABLED, (int) (bool) Tools::getValue(self::CFG_BADGE_ENABLED));
                Configuration::updateValue(self::CFG_BLOCK_ENABLED, (int) (bool) Tools::getValue(self::CFG_BLOCK_ENABLED));
                Configuration::updateValue(self::CFG_BADGE_TEXT, $text);
                Configuration::updateValue(self::CFG_BADGE_COLOR, Tools::strtoupper($color));
                Configuration::updateValue(self::CFG_CMS_ID, (int) Tools::getValue(self::CFG_CMS_ID));
                $output .= $this->displayConfirmation($this->l('Etiqueta guardada.'));
            }
        }

        if (Tools::isSubmit('submitCanelaApartadoRestrict')) {
            $output .= $this->restrictPaymentToCarrier()
                ? $this->displayConfirmation($this->l('Restricciones de pago del transportista restablecidas.'))
                : $this->displayError($this->l('No se pudieron restablecer las restricciones.'));
        }

        if (Tools::isSubmit('submitCanelaApartadoUnblock')) {
            $idCustomer = (int) Tools::getValue('id_customer');
            CanelaApartadoReservation::forgiveNoShows($idCustomer);
            $output .= $this->displayConfirmation($this->l('Clienta desbloqueada.'));
        }

        return $output . $this->renderStatus() . $this->renderForm() . $this->renderBadgeForm() . $this->renderBlocked();
    }

    private function renderStatus()
    {
        $carrier = $this->getCarrier();
        $this->context->smarty->assign([
            'carrier_name' => $carrier ? $carrier->name : null,
            'carrier_active' => $carrier ? (bool) $carrier->active : false,
            'carrier_link' => $this->context->link->getAdminLink('AdminCarriers'),
            'cron_url' => $this->getCronUrl(),
            'last_run' => (int) Configuration::getGlobalValue(self::CFG_LAST_RUN),
            'list_link' => $this->context->link->getAdminLink('AdminCanelaApartado'),
            'os_pending' => (int) Configuration::get(self::CFG_OS_PENDING),
            'os_paid' => (int) Configuration::get(self::CFG_OS_PAID),
            'form_action' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/status.tpl');
    }

    private function renderBlocked()
    {
        $limit = (int) Configuration::get(self::CFG_NOSHOW_LIMIT);
        $this->context->smarty->assign([
            'blocked' => $limit > 0 ? CanelaApartadoReservation::getBlockedCustomers($limit) : [],
            'limit' => $limit,
            'form_action' => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
        ]);

        return $this->display(__FILE__, 'views/templates/admin/blocked.tpl');
    }

    private function renderBadgeForm()
    {
        $cmsOptions = [['id' => 0, 'name' => $this->l('— Sin enlace —')]];
        foreach (CMS::listCms((int) $this->context->language->id) as $page) {
            $cmsOptions[] = ['id' => (int) $page['id_cms'], 'name' => $page['meta_title']];
        }
        $switch = function ($name, $label, $desc) {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'is_bool' => true,
                'desc' => $desc,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->l('Sí')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->l('No')],
                ],
            ];
        };

        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Etiqueta en los productos'), 'icon' => 'icon-tag'],
                'description' => $this->l('Solo se muestra en productos con stock, mientras el módulo y su transportista estén activos.'),
                'input' => [
                    $switch(self::CFG_BADGE_ENABLED, $this->l('Etiqueta sobre la foto'), $this->l('Junto a «Nuevo» o «Rebajado», en listados, buscador y ficha.')),
                    $switch(self::CFG_BLOCK_ENABLED, $this->l('Bloque en la ficha de producto'), $this->l('Explicación junto a «Añadir al carrito», según la talla elegida.')),
                    [
                        'type' => 'text',
                        'label' => $this->l('Texto de la etiqueta'),
                        'name' => self::CFG_BADGE_TEXT,
                        'maxlength' => 40,
                    ],
                    [
                        'type' => 'color',
                        'label' => $this->l('Color de la etiqueta'),
                        'name' => self::CFG_BADGE_COLOR,
                        'desc' => $this->l('Formato #RRGGBB. Por defecto, naranja #F28C28.'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->l('Página «Cómo funciona»'),
                        'name' => self::CFG_CMS_ID,
                        'options' => ['query' => $cmsOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->l('Se enlaza desde el bloque de la ficha. Su texto se edita en Diseño > Páginas.'),
                    ],
                ],
                'submit' => ['title' => $this->l('Guardar'), 'name' => 'submitCanelaApartadoBadge'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name . '_badge';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitCanelaApartadoBadge';
        $helper->fields_value = [
            self::CFG_BADGE_ENABLED => (int) Tools::getValue(self::CFG_BADGE_ENABLED, Configuration::get(self::CFG_BADGE_ENABLED)),
            self::CFG_BLOCK_ENABLED => (int) Tools::getValue(self::CFG_BLOCK_ENABLED, Configuration::get(self::CFG_BLOCK_ENABLED)),
            self::CFG_BADGE_TEXT => Tools::getValue(self::CFG_BADGE_TEXT, Configuration::get(self::CFG_BADGE_TEXT)),
            self::CFG_BADGE_COLOR => Tools::getValue(self::CFG_BADGE_COLOR, $this->getBadgeColor()),
            self::CFG_CMS_ID => (int) Tools::getValue(self::CFG_CMS_ID, Configuration::get(self::CFG_CMS_ID)),
        ];

        return $helper->generateForm([$form]);
    }

    private function renderForm()
    {
        $days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
        $openingInputs = [];
        foreach ($days as $id => $name) {
            $openingInputs[] = [
                'type' => 'text',
                'label' => sprintf($this->l('Horario %s'), $name),
                'name' => 'opening_' . $id,
                'placeholder' => $this->l('Cerrado'),
                'desc' => $id === 7 ? $this->l('Vacío = cerrado todo el día. Si un apartado vence con la tienda cerrada, se alarga hasta el final del siguiente turno de apertura.') : '',
            ];
        }

        $form = [
            'form' => [
                'legend' => ['title' => $this->l('Ajustes del apartado'), 'icon' => 'icon-cogs'],
                'input' => array_merge([
                    [
                        'type' => 'text',
                        'label' => $this->l('Horas que se guarda un apartado'),
                        'name' => self::CFG_HOURS,
                        'class' => 'fixed-width-sm',
                        'suffix' => 'h',
                        'desc' => $this->l('Pasado este tiempo sin recoger, el pedido se cancela y el stock vuelve a la web.'),
                    ],
                ], $openingInputs, [
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Festivos (tienda cerrada)'),
                        'name' => self::CFG_HOLIDAYS,
                        'rows' => 6,
                        'desc' => $this->l('Uno por línea. «25/12» se repite todos los años; «19/03/2027» solo esa fecha. Puedes añadir comentarios tras #.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Máximo de prendas apartadas por clienta'),
                        'name' => self::CFG_MAX_ITEMS,
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Suma las prendas del carrito y las de sus apartados pendientes. 0 = sin límite.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Apartados no recogidos para bloquear'),
                        'name' => self::CFG_NOSHOW_LIMIT,
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Con este número de apartados caducados, la clienta ya no puede apartar. 0 = nunca bloquear.'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Email de la tienda para avisos'),
                        'name' => self::CFG_STORE_EMAIL,
                        'desc' => $this->l('Recibe un correo por cada apartado nuevo con las prendas a separar. Vacío = sin aviso.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Información de recogida'),
                        'name' => self::CFG_PICKUP_INFO,
                        'rows' => 4,
                        'desc' => $this->l('Dirección y horario de la tienda. Se muestra en el checkout y en los correos.'),
                    ],
                ]),
                'submit' => ['title' => $this->l('Guardar'), 'name' => 'submitCanelaApartado'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitCanelaApartado';

        $helper->fields_value = [
            self::CFG_HOURS => Tools::getValue(self::CFG_HOURS, Configuration::get(self::CFG_HOURS)),
            self::CFG_MAX_ITEMS => Tools::getValue(self::CFG_MAX_ITEMS, Configuration::get(self::CFG_MAX_ITEMS)),
            self::CFG_NOSHOW_LIMIT => Tools::getValue(self::CFG_NOSHOW_LIMIT, Configuration::get(self::CFG_NOSHOW_LIMIT)),
            self::CFG_STORE_EMAIL => Tools::getValue(self::CFG_STORE_EMAIL, Configuration::get(self::CFG_STORE_EMAIL)),
            self::CFG_PICKUP_INFO => Tools::getValue(self::CFG_PICKUP_INFO, Configuration::get(self::CFG_PICKUP_INFO)),
            self::CFG_HOLIDAYS => Tools::getValue(self::CFG_HOLIDAYS, Configuration::get(self::CFG_HOLIDAYS)),
        ];
        foreach ($this->getOpeningHoursText() as $d => $text) {
            $helper->fields_value['opening_' . $d] = Tools::getValue('opening_' . $d, $text);
        }

        return $helper->generateForm([$form]);
    }
}
