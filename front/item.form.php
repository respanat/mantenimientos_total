<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Acciones sobre equipos: sincronizar con planes y ajustes por equipo.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Config;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Plan;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

if (isset($_POST['save_scope'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    $ids = Plan::decodeTypesIds($_POST['excluded_states'] ?? []);
    Config::setValue('excluded_states', json_encode($ids));
    Config::setValue('stale_inventory_days', (string) max(0, (int) ($_POST['stale_inventory_days'] ?? 0)));
    $stats = Item::syncAll();
    Item::recordSnapshot();
    Session::addMessageAfterRedirect(sprintf(
        __('Criterios guardados: %1$d equipos en servicio, %2$d fuera de servicio (%3$d perdieron su plan).', 'mantenimientos'),
        $stats['total'],
        $stats['out_of_service'],
        $stats['unassigned']
    ));
    Html::back();
}

Session::checkRight(Plan::$rightname, UPDATE);

if (isset($_POST['sync'])) {
    $stats = Item::syncAll();
    Item::recordSnapshot();
    Session::addMessageAfterRedirect(sprintf(
        __('Sincronización lista: %1$d equipos en servicio revisados, %2$d asignados, %3$d actualizados, %4$d quedaron sin plan, %5$d fuera de servicio.', 'mantenimientos'),
        $stats['total'],
        $stats['assigned'],
        $stats['updated'],
        $stats['unassigned'],
        $stats['out_of_service']
    ));
    Html::back();
} elseif (isset($_POST['update'])) {
    $item = new Item();
    $item->check((int) $_POST['id'], UPDATE);
    $item->update([
        'id'                      => (int) $_POST['id'],
        'last_preventive_date'    => (string) ($_POST['last_preventive_date'] ?? ''),
        'frequency_days_override' => (int) ($_POST['frequency_days_override'] ?? 0),
        'is_excluded'             => (int) ($_POST['is_excluded'] ?? 0),
        'exclusion_reason'        => (string) ($_POST['exclusion_reason'] ?? ''),
    ]);
    Html::back();
}

Html::redirect(Item::getSearchURL());
