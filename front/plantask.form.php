<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Alta, edición y eliminación de tareas del checklist de un plan.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Plan;
use GlpiPlugin\Mantenimientos\PlanTask;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Plan::$rightname, READ);

$task = new PlanTask();

if (isset($_POST['load_defaults'])) {
    $plan = new Plan();
    $plan->check((int) ($_POST['plugin_mantenimientos_plans_id'] ?? 0), UPDATE);
    $added = PlanTask::loadDefaults($plan);
    Session::addMessageAfterRedirect(
        $added > 0
            ? sprintf(__('Se agregaron %d tareas sugeridas.', 'mantenimientos'), $added)
            : __('El plan ya tenía todas las tareas sugeridas.', 'mantenimientos')
    );
    Html::back();
} elseif (isset($_POST['add'])) {
    $task->check(-1, CREATE, $_POST);
    $task->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $task->check((int) $_POST['id'], UPDATE);
    $task->update($_POST);
    Html::back();
} elseif (isset($_POST['purge'])) {
    $task->check((int) $_POST['id'], PURGE);
    $plans_id = (int) $task->fields['plugin_mantenimientos_plans_id'];
    $task->delete($_POST, true);
    Html::redirect(Plan::getFormURLWithID($plans_id));
} else {
    Html::header(PlanTask::getTypeName(1), '', 'admin', Menu::class, 'plan');
    $task->display(['id' => (int) ($_GET['id'] ?? 0)]);
    Html::footer();
}
