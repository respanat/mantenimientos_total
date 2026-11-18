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

use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Plan;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Plan::$rightname, UPDATE);

if (isset($_POST['sync'])) {
    $stats = Item::syncAll();
    Item::recordSnapshot();
    Session::addMessageAfterRedirect(sprintf(
        __('Sincronización lista: %1$d equipos revisados, %2$d asignados, %3$d actualizados, %4$d quedaron sin plan.', 'mantenimientos'),
        $stats['total'],
        $stats['assigned'],
        $stats['updated'],
        $stats['unassigned']
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
