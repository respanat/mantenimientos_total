<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Listado de planes de mantenimiento.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Plan;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Plan::$rightname, READ);

Html::header(Plan::getTypeName(Session::getPluralNumber()), '', 'tools', Menu::class, 'plan');
Search::show(Plan::class);
Html::footer();
