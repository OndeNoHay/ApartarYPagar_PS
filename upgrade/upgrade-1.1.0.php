<?php
/**
 * 1.0.0 → 1.1.0: etiqueta «Aparta y paga en tienda» en los productos,
 * bloque en la ficha y página CMS «Cómo funciona».
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    return $module->installProductBadge();
}
