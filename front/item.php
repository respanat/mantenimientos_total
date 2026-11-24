<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Equipos con su plan y semáforo de preventivo.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Config;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Plan;
use GlpiPlugin\Mantenimientos\Ui;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Maintenance::$rightname, READ);

/** @var \DBmysql $DB */
global $DB;

$lights  = Item::getLights();
$filters = [
    'light'    => array_key_exists($_GET['light'] ?? '', $lights) ? $_GET['light'] : '',
    'itemtype' => array_key_exists($_GET['itemtype'] ?? '', Plan::SUPPORTED_ITEMTYPES) ? $_GET['itemtype'] : '',
    'plans_id' => (int) ($_GET['plans_id'] ?? 0),
    'states_id' => (int) ($_GET['states_id'] ?? 0),
];

// Estados de GLPI disponibles para computadores o teléfonos
$states = [];
foreach ($DB->request(['FROM' => 'glpi_states', 'ORDER' => 'completename']) as $st) {
    $states[(int) $st['id']] = $st['completename'];
}
$excluded_states = Item::getExcludedStates();

Html::header(Item::getTypeName(Session::getPluralNumber()), '', 'tools', Menu::class, 'item');

$counts = Item::getCounts();
$rows   = Item::getRows($filters);
$base   = Item::getSearchURL();

echo "<div class='container-fluid'>";

// Mosaicos de resumen (clic = filtrar; clic de nuevo = quitar filtro)
echo Ui::lightTiles($counts, function (string $key) use ($base, $filters) {
    return $base . '?' . http_build_query(['light' => $filters['light'] === $key ? '' : $key] + array_filter([
        'itemtype' => $filters['itemtype'], 'plans_id' => $filters['plans_id'] ?: null,
        'states_id' => $filters['states_id'] ?: null,
    ]));
}, $filters['light']);

// Filtros
echo "<form method='get' action='" . Compat::e($base) . "' class='card mb-3'><div class='card-body row g-2 align-items-end'>";
echo "<input type='hidden' name='light' value='" . Compat::e($filters['light']) . "'>";
echo "<div class='col-md-2'><label class='form-label'>" . Compat::e(__('Tipo de equipo', 'mantenimientos')) . "</label>";
echo "<select name='itemtype' class='form-select'><option value=''>" . Compat::e(__('Todos', 'mantenimientos')) . "</option>";
foreach (Plan::getItemtypeChoices() as $class => $label) {
    $sel = $filters['itemtype'] === $class ? ' selected' : '';
    echo "<option value='" . Compat::e($class) . "'{$sel}>" . Compat::e($label) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-3'><label class='form-label'>" . Compat::e(__('Estado del activo', 'mantenimientos')) . "</label>";
echo "<select name='states_id' class='form-select'><option value='0'>" . Compat::e(__('Todos (en servicio)', 'mantenimientos')) . "</option>";
foreach ($states as $sid => $sname) {
    if (in_array($sid, $excluded_states, true)) {
        continue;
    }
    $sel = $filters['states_id'] === $sid ? ' selected' : '';
    echo "<option value='{$sid}'{$sel}>" . Compat::e($sname, true) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-3'><label class='form-label'>" . Compat::e(Plan::getTypeName(1)) . "</label>";
echo "<select name='plans_id' class='form-select'><option value='0'>" . Compat::e(__('Todos', 'mantenimientos')) . "</option>";
foreach ((new Plan())->find([], ['name']) as $plan) {
    $sel = $filters['plans_id'] === (int) $plan['id'] ? ' selected' : '';
    echo "<option value='" . (int) $plan['id'] . "'{$sel}>" . Compat::e($plan['name'], true) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-1'><button type='submit' class='btn btn-primary w-100'><i class='ti ti-filter me-1'></i>"
    . Compat::e(__('Filtrar', 'mantenimientos')) . "</button></div>";
echo "</div></form>";

// Equipos en servicio: qué queda fuera del semáforo, las métricas y los avisos
$out_count  = Item::countOutOfService();
$stale_days = Item::getStaleDays();
$scope_txt  = [];
foreach ($excluded_states as $sid) {
    $scope_txt[] = $states[$sid] ?? ('#' . $sid);
}
if ($stale_days > 0) {
    $scope_txt[] = sprintf(__('computadores sin inventario en %d días', 'mantenimientos'), $stale_days);
}
echo "<div class='card mb-3'><div class='card-body py-2 d-flex align-items-center flex-wrap'>";
echo "<i class='ti ti-plug-connected-x me-2 text-muted'></i><span>";
echo "<b>" . Compat::e(sprintf(_n('%d equipo fuera de servicio', '%d equipos fuera de servicio', $out_count, 'mantenimientos'), $out_count)) . "</b> ";
echo Compat::e($scope_txt === []
    ? __('— no hay criterios configurados: hoy se incluyen todos los equipos.', 'mantenimientos')
    : sprintf(__('(%s): no reciben plan, no cuentan en el semáforo ni en las métricas y no reciben avisos.', 'mantenimientos'), implode(', ', $scope_txt)));
echo "</span>";
$can_config = Session::haveRight(Config::$rightname, UPDATE);
if ($can_config) {
    echo "<button type='button' class='btn btn-sm btn-outline-secondary ms-auto' data-bs-toggle='collapse' data-bs-target='#mant-scope'>"
        . "<i class='ti ti-adjustments me-1'></i>" . Compat::e(__('Configurar', 'mantenimientos')) . "</button>";
}
echo "</div>";
if ($can_config) {
    echo "<div id='mant-scope' class='collapse'><div class='card-body border-top'>";
    echo "<form method='post' action='" . Compat::e(Item::getFormURL()) . "'>";
    echo "<div class='row g-3'>";
    echo "<div class='col-md-8'><label class='form-label fw-bold'>" . Compat::e(__('Estados de GLPI que dejan el equipo fuera de servicio', 'mantenimientos')) . "</label>";
    echo "<div class='text-muted small mb-2'>" . Compat::e(__('Por ejemplo: Retirado, Defectuoso, Dado de baja. Se toman del campo Estado de la ficha del activo.', 'mantenimientos')) . "</div>";
    echo "<input type='hidden' name='excluded_states[]' value='0'>";
    if ($states === []) {
        echo "<div class='text-muted'>" . Compat::e(__('No hay estados creados en Configuración > Desplegables > Estados.', 'mantenimientos')) . "</div>";
    }
    echo "<div class='row'>";
    foreach ($states as $sid => $sname) {
        $chk = in_array($sid, $excluded_states, true) ? ' checked' : '';
        echo "<div class='col-sm-6 col-lg-4'><label class='form-check'><input class='form-check-input' type='checkbox' name='excluded_states[]' value='{$sid}'{$chk}>"
            . "<span class='form-check-label'>" . Compat::e($sname, true) . "</span></label></div>";
    }
    echo "</div></div>";
    echo "<div class='col-md-4'><label class='form-label fw-bold'>" . Compat::e(__('Computadores que no reportan', 'mantenimientos')) . "</label>";
    echo "<div class='input-group'><span class='input-group-text'>" . Compat::e(__('Sin inventario en más de', 'mantenimientos')) . "</span>";
    echo "<input type='number' min='0' class='form-control' name='stale_inventory_days' value='" . (int) $stale_days . "'>";
    echo "<span class='input-group-text'>" . Compat::e(__('días', 'mantenimientos')) . "</span></div>";
    echo "<div class='text-muted small mt-1'>" . Compat::e(__('0 = no aplica. Solo computadores con agente (los que nunca se inventariaron por agente no se afectan). Teléfonos y tablets se controlan con el estado.', 'mantenimientos')) . "</div>";
    echo "</div></div>";
    echo "<button type='submit' name='save_scope' value='1' class='btn btn-primary mt-3'><i class='ti ti-device-floppy me-1'></i>"
        . Compat::e(__('Guardar y sincronizar', 'mantenimientos')) . "</button>";
    Html::closeForm();
    echo "</div></div>";
}
echo "</div>";

if (Session::haveRight(Plan::$rightname, UPDATE)) {
    echo "<form method='post' action='" . Compat::e(Item::getFormURL()) . "' class='mb-3 d-flex align-items-center'>";
    echo "<button type='submit' name='sync' value='1' class='btn btn-primary'><i class='ti ti-refresh me-1'></i>"
        . Compat::e(__('Sincronizar equipos con los planes', 'mantenimientos')) . "</button>";
    echo "<span class='text-muted small ms-2'>"
        . Compat::e(__('También se ejecuta solo una vez al día (Configuración > Acciones automáticas > syncItems).', 'mantenimientos'))
        . "</span>";
    Html::closeForm();
}

// Tabla
echo Ui::tableTools();
echo "<div class='card'><div class='table-responsive'><table class='table table-hover card-table mant-table'>";
echo "<thead><tr>";
foreach (
    [__('Estado', 'mantenimientos'), __('Equipo', 'mantenimientos'), __('Tipo', 'mantenimientos'),
        __('Estado del activo', 'mantenimientos'), __('Usuario asignado', 'mantenimientos'), Plan::getTypeName(1), __('Último preventivo', 'mantenimientos'),
        __('Próximo', 'mantenimientos'), __('Días', 'mantenimientos'), ''] as $th
) {
    echo "<th>" . Compat::e($th) . "</th>";
}
echo "</tr></thead><tbody>";

if ($rows === []) {
    echo "<tr><td colspan='10' class='text-center text-muted p-4'>"
        . Compat::e(__('No hay equipos para mostrar. Cree un plan activo y pulse "Sincronizar equipos con los planes".', 'mantenimientos'))
        . "</td></tr>";
}

$can_register = Session::haveRight(Maintenance::$rightname, CREATE);
foreach ($rows as $row) {
    $itemtype = $row['itemtype'];
    $days     = $row['days'];
    $days_txt = $days === null ? '—' : ($days < 0 ? sprintf(__('%d de atraso', 'mantenimientos'), -$days) : (string) $days);
    echo "<tr>";
    echo "<td data-sort='" . Item::lightRank($row['light']) . "'>" . Item::lightBadge($row['light']) . "</td>";
    echo "<td><a href='" . Compat::e($itemtype::getFormURLWithID((int) $row['items_id'])) . "'>"
        . Compat::e($row['asset_name'] ?: ('#' . $row['items_id']), true) . "</a></td>";
    echo "<td>" . Compat::e($itemtype::getTypeName(1)) . ($row['type_name'] ? ' / ' . Compat::e($row['type_name'], true) : '') . "</td>";
    echo "<td>" . ($row['state_name'] ? Compat::e($row['state_name'], true) : '<span class="text-muted">—</span>') . "</td>";
    echo "<td>" . Compat::e($row['user'], true) . "</td>";
    echo "<td>" . ($row['plan_name'] ? "<a href='" . Compat::e(Plan::getFormURLWithID((int) $row['plugin_mantenimientos_plans_id'])) . "'>"
        . Compat::e($row['plan_name'], true) . "</a>" : '—') . "</td>";
    echo "<td data-sort='" . Compat::e((string) ($row['last_preventive_date'] ?? '')) . "'>" . Compat::e($row['last_preventive_date'] ? Html::convDate($row['last_preventive_date']) : __('Sin registro', 'mantenimientos')) . "</td>";
    echo "<td data-sort='" . Compat::e((string) ($row['next_due_date'] ?? '')) . "'>" . Compat::e($row['next_due_date'] ? Html::convDate($row['next_due_date']) : '—') . "</td>";
    echo "<td data-sort='" . ($days === null ? '' : (int) $days) . "'>" . Compat::e($days_txt) . "</td>";
    echo "<td class='text-end'>";
    if ($can_register && $row['light'] !== Item::LIGHT_NOPLAN) {
        $open = Maintenance::findOpenPreventive($itemtype, (int) $row['items_id']);
        if ($open !== null) {
            echo "<a class='btn btn-sm btn-outline-primary' href='" . Compat::e(Maintenance::getFormURLWithID($open)) . "'>"
                . "<i class='ti ti-clipboard-check me-1'></i>" . Compat::e(__('Continuar', 'mantenimientos')) . "</a>";
        } else {
            echo "<form method='post' action='" . Compat::e(Maintenance::getFormURL()) . "' class='d-inline'>";
            echo "<input type='hidden' name='itemtype' value='" . Compat::e($itemtype) . "'>";
            echo "<input type='hidden' name='items_id' value='" . (int) $row['items_id'] . "'>";
            echo "<button type='submit' name='start_preventive' value='1' class='btn btn-sm btn-primary'>"
                . "<i class='ti ti-clipboard-plus me-1'></i>" . Compat::e(__('Registrar', 'mantenimientos')) . "</button>";
            Html::closeForm();
        }
    }
    echo "</td></tr>";
}
echo "</tbody></table></div></div></div>";

Html::footer();
