<?php
/**
 * Un apartado: el pedido sin pagar que la clienta recogerá y pagará en tienda.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class CanelaApartadoReservation extends ObjectModel
{
    const STATUS_PENDING = 'pending';     // esperando a que venga a pagar
    const STATUS_COLLECTED = 'collected'; // pagado y recogido
    const STATUS_EXPIRED = 'expired';     // no vino a tiempo: cancelado por el módulo
    const STATUS_CANCELLED = 'cancelled'; // cancelado por la clienta o la tienda

    public $id_order;
    public $id_customer;
    public $id_shop;
    public $quantity;
    public $status;
    public $expires_at;
    public $counts_noshow;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'canelaapartado_reservation',
        'primary' => 'id_canelaapartado_reservation',
        'fields' => [
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'quantity' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16],
            'expires_at' => ['type' => self::TYPE_DATE, 'validate' => 'isDate', 'required' => true],
            'counts_noshow' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public static function statusLabel($status)
    {
        $labels = [
            self::STATUS_PENDING => 'Pendiente de recoger',
            self::STATUS_COLLECTED => 'Pagado y recogido',
            self::STATUS_EXPIRED => 'Caducado (no recogido)',
            self::STATUS_CANCELLED => 'Cancelado',
        ];

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /** @return self|null */
    public static function getByOrderId($idOrder)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_canelaapartado_reservation` FROM `' . _DB_PREFIX_ . 'canelaapartado_reservation`
             WHERE `id_order` = ' . (int) $idOrder
        );

        return $id ? new self($id) : null;
    }

    /** Prendas que la clienta tiene apartadas y sin recoger. */
    public static function getPendingQuantity($idCustomer)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COALESCE(SUM(`quantity`), 0) FROM `' . _DB_PREFIX_ . 'canelaapartado_reservation`
             WHERE `id_customer` = ' . (int) $idCustomer . ' AND `status` = \'' . self::STATUS_PENDING . '\''
        );
    }

    public static function countNoShows($idCustomer)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'canelaapartado_reservation`
             WHERE `id_customer` = ' . (int) $idCustomer . ' AND `counts_noshow` = 1'
        );
    }

    /** El historial se conserva; solo deja de contar para el bloqueo. */
    public static function forgiveNoShows($idCustomer)
    {
        return Db::getInstance()->update(
            'canelaapartado_reservation',
            ['counts_noshow' => 0],
            '`id_customer` = ' . (int) $idCustomer
        );
    }

    /** @return self[] */
    public static function getExpiredPending()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_canelaapartado_reservation` FROM `' . _DB_PREFIX_ . 'canelaapartado_reservation`
             WHERE `status` = \'' . self::STATUS_PENDING . '\' AND `expires_at` < \'' . pSQL(date('Y-m-d H:i:s')) . '\'
             ORDER BY `expires_at` ASC LIMIT 200'
        );

        return array_map(function ($r) {
            return new self((int) $r['id_canelaapartado_reservation']);
        }, $rows ?: []);
    }

    public static function getBlockedCustomers($limit)
    {
        return Db::getInstance()->executeS(
            'SELECT r.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, COUNT(*) AS noshows
             FROM `' . _DB_PREFIX_ . 'canelaapartado_reservation` r
             LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = r.`id_customer`
             WHERE r.`counts_noshow` = 1
             GROUP BY r.`id_customer`, c.`firstname`, c.`lastname`, c.`email`
             HAVING noshows >= ' . (int) $limit . '
             ORDER BY c.`lastname`, c.`firstname`'
        ) ?: [];
    }
}
