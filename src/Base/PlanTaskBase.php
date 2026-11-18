<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Mantenimientos\Base;

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Plan;
use GlpiPlugin\Mantenimientos\PlanTask;
use Html;
use Session;

/**
 * Tarea de la plantilla de checklist de un plan.
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../PlanTask.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_plantasks
 */
abstract class PlanTaskBase extends CommonDBChild
{
    public static function getTypeName($nb = 0)
    {
        return _n('Tarea del checklist', 'Checklist', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    // ---------------------------------------------------------------------
    // Pestaña "Checklist" dentro del plan
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Plan && !$item->isNewItem()) {
            $count = countElementsInTable(PlanTask::getTable(), [
                'plugin_mantenimientos_plans_id' => $item->getID(),
            ]);
            return self::createTabEntry(_n('Checklist', 'Checklist', 2, 'mantenimientos'), $count, null, self::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Plan) {
            self::showForPlan($item);
        }
        return true;
    }

    /**
     * Lista de tareas del plan + formulario rápido para agregar.
     */
    public static function showForPlan(Plan $plan): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $plans_id = (int) $plan->getID();
        $canedit  = $plan->can($plans_id, UPDATE);
        $form_url = PlanTask::getFormURL();

        echo "<div class='mt-2'>";

        if ($canedit) {
            echo "<form method='post' action='" . Compat::e($form_url) . "' class='mb-3'>";
            echo "<input type='hidden' name='plugin_mantenimientos_plans_id' value='{$plans_id}'>";
            echo "<div class='row g-2 align-items-end'>";
            echo "<div class='col-md-6'><label class='form-label'>" . Compat::e(__('Nueva tarea', 'mantenimientos')) . "</label>";
            echo "<input type='text' name='name' class='form-control' required maxlength='255' placeholder='"
                . Compat::e(__('Ej.: Limpieza interna y cambio de pasta térmica', 'mantenimientos')) . "'></div>";
            echo "<div class='col-md-2'><label class='form-label'>" . Compat::e(__('Obligatoria', 'mantenimientos')) . "</label>";
            Dropdown::showYesNo('is_mandatory', 1, -1, ['width' => '100%']);
            echo "</div>";
            echo "<div class='col-md-2'><label class='form-label'>" . Compat::e(__('Orden', 'mantenimientos')) . "</label>";
            echo "<input type='number' name='rank' class='form-control' min='0' step='1' placeholder='"
                . Compat::e(__('Auto', 'mantenimientos')) . "'></div>";
            echo "<div class='col-md-2'><button type='submit' name='add' value='1' class='btn btn-primary w-100'>"
                . "<i class='ti ti-plus me-1'></i>" . Compat::e(_x('button', 'Add')) . "</button></div>";
            echo "</div>";
            Html::closeForm();
        }

        $rows = $DB->request([
            'FROM'  => PlanTask::getTable(),
            'WHERE' => ['plugin_mantenimientos_plans_id' => $plans_id],
            'ORDER' => ['rank ASC', 'id ASC'],
        ]);

        if (count($rows) === 0) {
            echo "<div class='alert alert-info'>"
                . Compat::e(__('Este plan aún no tiene tareas. Agregue las verificaciones que el técnico debe marcar en cada preventivo.', 'mantenimientos'))
                . "</div></div>";
            return;
        }

        echo "<table class='table table-hover card-table'>";
        echo "<thead><tr>";
        foreach (
            [__('Orden', 'mantenimientos'), __('Name'), __('Description'), __('Obligatoria', 'mantenimientos'), __('Active'), ''] as $th
        ) {
            echo "<th>" . Compat::e($th) . "</th>";
        }
        echo "</tr></thead><tbody>";

        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $muted = $row['is_active'] ? '' : " class='text-muted'";
            echo "<tr{$muted}>";
            echo "<td>" . (int) $row['rank'] . "</td>";
            echo "<td><a href='" . Compat::e(PlanTask::getFormURLWithID($id)) . "'>"
                . Compat::e($row['name'], true) . "</a></td>";
            echo "<td>" . Compat::e(mb_strimwidth((string) $row['description'], 0, 120, '…'), true) . "</td>";
            echo "<td>" . Compat::e(Dropdown::getYesNo($row['is_mandatory'])) . "</td>";
            echo "<td>" . Compat::e(Dropdown::getYesNo($row['is_active'])) . "</td>";
            echo "<td class='text-end'>";
            if ($canedit) {
                echo "<form method='post' action='" . Compat::e($form_url) . "' class='d-inline'"
                    . " onsubmit=\"return confirm('" . Compat::e(__('¿Eliminar esta tarea del checklist?', 'mantenimientos')) . "');\">";
                echo "<input type='hidden' name='id' value='{$id}'>";
                echo "<button type='submit' name='purge' value='1' class='btn btn-sm btn-ghost-danger' title='"
                    . Compat::e(_x('button', 'Delete permanently')) . "'><i class='ti ti-trash'></i></button>";
                Html::closeForm();
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    // ---------------------------------------------------------------------
    // Formulario individual (editar una tarea)
    // ---------------------------------------------------------------------

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        TemplateRenderer::getInstance()->display('@mantenimientos/plantask.form.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);
        return true;
    }

    public function prepareInputForAdd($input)
    {
        if (trim((string) ($input['name'] ?? '')) === '') {
            Session::addMessageAfterRedirect(__('El nombre de la tarea es obligatorio.', 'mantenimientos'), false, ERROR);
            return false;
        }

        // Orden automático: al final de la lista, de 10 en 10
        if (!isset($input['rank']) || $input['rank'] === '') {
            /** @var \DBmysql $DB */
            global $DB;
            $max = $DB->request([
                'SELECT' => ['MAX' => 'rank AS max_rank'],
                'FROM'   => PlanTask::getTable(),
                'WHERE'  => ['plugin_mantenimientos_plans_id' => (int) ($input['plugin_mantenimientos_plans_id'] ?? 0)],
            ])->current();
            $input['rank'] = ((int) ($max['max_rank'] ?? 0)) + 10;
        }

        return parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('El nombre de la tarea es obligatorio.', 'mantenimientos'), false, ERROR);
            return false;
        }
        return parent::prepareInputForUpdate($input);
    }
}
