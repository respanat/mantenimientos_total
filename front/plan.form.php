<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Alta, edición y eliminación de planes.
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

$plan = new Plan();

if (isset($_POST['add'])) {
    $plan->check(-1, CREATE, $_POST);
    $new_id = $plan->add($_POST);
    if ($new_id) {
        // Volver al formulario para que el usuario elija el subtipo
        Html::redirect(Plan::getFormURLWithID($new_id));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $plan->check((int) $_POST['id'], UPDATE);
    $plan->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $plan->check((int) $_POST['id'], PURGE);
    $plan->delete($_POST, true);
    Html::redirect(Plan::getSearchURL());
} else {
    Html::header(Plan::getTypeName(1), '', 'tools', Menu::class, 'plan');
    $plan->display(['id' => (int) ($_GET['id'] ?? 0)]);
    Html::footer();
}
