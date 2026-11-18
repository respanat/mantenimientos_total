<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Registro de preventivos: iniciar, guardar avance, completar, cancelar.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Menu;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Maintenance::$rightname, READ);

$maintenance = new Maintenance();

if (isset($_POST['start_preventive'])) {
    Session::checkRight(Maintenance::$rightname, CREATE);
    $id = Maintenance::startPreventive((string) ($_POST['itemtype'] ?? ''), (int) ($_POST['items_id'] ?? 0));
    if ($id !== null) {
        Html::redirect(Maintenance::getFormURLWithID($id));
    }
    Html::back();
} elseif (isset($_POST['save']) || isset($_POST['complete'])) {
    $maintenance->check((int) $_POST['id'], UPDATE);
    $maintenance->saveChecklist(
        is_array($_POST['checks'] ?? null) ? $_POST['checks'] : [],
        (string) ($_POST['observations'] ?? '')
    );
    $maintenance->getFromDB((int) $_POST['id']);
    if (isset($_POST['complete'])) {
        if ($maintenance->complete()) {
            Session::addMessageAfterRedirect(__('Preventivo completado. La próxima fecha del equipo se recalculó.', 'mantenimientos'));
        }
    } else {
        Session::addMessageAfterRedirect(__('Avance guardado.', 'mantenimientos'));
    }
    Html::redirect(Maintenance::getFormURLWithID((int) $_POST['id']));
} elseif (isset($_POST['cancel'])) {
    $maintenance->check((int) $_POST['id'], UPDATE);
    if ($maintenance->cancel((string) ($_POST['cancel_reason'] ?? ''))) {
        Session::addMessageAfterRedirect(__('Preventivo cancelado.', 'mantenimientos'));
    }
    Html::redirect(Maintenance::getFormURLWithID((int) $_POST['id']));
} else {
    Html::header(Maintenance::getTypeName(1), '', 'tools', Menu::class, 'maintenance');
    $maintenance->display(['id' => (int) ($_GET['id'] ?? 0)]);
    Html::footer();
}
