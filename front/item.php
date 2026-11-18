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
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Plan;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Maintenance::$rightname, READ);

$lights  = Item::getLights();
$filters = [
    'light'    => array_key_exists($_GET['light'] ?? '', $lights) ? $_GET['light'] : '',
    'itemtype' => array_key_exists($_GET['itemtype'] ?? '', Plan::SUPPORTED_ITEMTYPES) ? $_GET['itemtype'] : '',
    'plans_id' => (int) ($_GET['plans_id'] ?? 0),
];

Html::header(Item::getTypeName(Session::getPluralNumber()), '', 'admin', Menu::class, 'item');

$counts = Item::getCounts();
$rows   = Item::getRows($filters);
$base   = Item::getSearchURL();

echo "<div class='container-fluid'>";

// Tarjetas de resumen (clic = filtrar)
echo "<div class='row g-2 mb-3'>";
foreach ($lights as $key => $def) {
    $active = $filters['light'] === $key ? 'border border-2 border-dark' : '';
    $url    = $base . '?' . http_build_query(['light' => $filters['light'] === $key ? '' : $key] + array_filter([
        'itemtype' => $filters['itemtype'], 'plans_id' => $filters['plans_id'] ?: null,
    ]));
    echo "<div class='col-6 col-md-4 col-xl-2'>";
    echo "<a href='" . Compat::e($url) . "' class='card card-sm text-decoration-none {$active}'>";
    echo "<div class='card-body d-flex align-items-center'>";
    echo "<span class='rounded me-2' style='width:12px;height:36px;background:{$def['color']}'></span>";
    echo "<div><div class='h2 mb-0'>" . (int) $counts[$key] . "</div><div class='text-muted'>" . Compat::e($def['label']) . "</div></div>";
    echo "</div></a></div>";
}
echo "</div>";

// Filtros
echo "<form method='get' action='" . Compat::e($base) . "' class='card mb-3'><div class='card-body row g-2 align-items-end'>";
echo "<input type='hidden' name='light' value='" . Compat::e($filters['light']) . "'>";
echo "<div class='col-md-3'><label class='form-label'>" . Compat::e(__('Tipo de equipo', 'mantenimientos')) . "</label>";
echo "<select name='itemtype' class='form-select'><option value=''>" . Compat::e(__('Todos', 'mantenimientos')) . "</option>";
foreach (Plan::getItemtypeChoices() as $class => $label) {
    $sel = $filters['itemtype'] === $class ? ' selected' : '';
    echo "<option value='" . Compat::e($class) . "'{$sel}>" . Compat::e($label) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-4'><label class='form-label'>" . Compat::e(Plan::getTypeName(1)) . "</label>";
echo "<select name='plans_id' class='form-select'><option value='0'>" . Compat::e(__('Todos', 'mantenimientos')) . "</option>";
foreach ((new Plan())->find([], ['name']) as $plan) {
    $sel = $filters['plans_id'] === (int) $plan['id'] ? ' selected' : '';
    echo "<option value='" . (int) $plan['id'] . "'{$sel}>" . Compat::e($plan['name'], true) . "</option>";
}
echo "</select></div>";
echo "<div class='col-md-2'><button type='submit' class='btn btn-primary w-100'><i class='ti ti-filter me-1'></i>"
    . Compat::e(__('Filtrar', 'mantenimientos')) . "</button></div>";
echo "</div></form>";

if (Session::haveRight(Plan::$rightname, UPDATE)) {
    echo "<form method='post' action='" . Compat::e(Item::getFormURL()) . "' class='mb-3 d-flex align-items-center'>";
    echo "<button type='submit' name='sync' value='1' class='btn btn-outline-primary'><i class='ti ti-refresh me-1'></i>"
        . Compat::e(__('Sincronizar equipos con los planes', 'mantenimientos')) . "</button>";
    echo "<span class='text-muted small ms-2'>"
        . Compat::e(__('También se ejecuta solo una vez al día (Configuración > Acciones automáticas > syncItems).', 'mantenimientos'))
        . "</span>";
    Html::closeForm();
}

// Tabla
echo "<div class='card'><div class='table-responsive'><table class='table table-hover card-table'>";
echo "<thead><tr>";
foreach (
    [__('Estado', 'mantenimientos'), __('Equipo', 'mantenimientos'), __('Tipo', 'mantenimientos'),
        __('Usuario asignado', 'mantenimientos'), Plan::getTypeName(1), __('Último preventivo', 'mantenimientos'),
        __('Próximo', 'mantenimientos'), __('Días', 'mantenimientos')] as $th
) {
    echo "<th>" . Compat::e($th) . "</th>";
}
echo "</tr></thead><tbody>";

if ($rows === []) {
    echo "<tr><td colspan='8' class='text-center text-muted p-4'>"
        . Compat::e(__('No hay equipos para mostrar. Cree un plan activo y pulse "Sincronizar equipos con los planes".', 'mantenimientos'))
        . "</td></tr>";
}

foreach ($rows as $row) {
    $itemtype = $row['itemtype'];
    $days     = $row['days'];
    $days_txt = $days === null ? '—' : ($days < 0 ? sprintf(__('%d de atraso', 'mantenimientos'), -$days) : (string) $days);
    echo "<tr>";
    echo "<td>" . Item::lightBadge($row['light']) . "</td>";
    echo "<td><a href='" . Compat::e($itemtype::getFormURLWithID((int) $row['items_id'])) . "'>"
        . Compat::e($row['asset_name'] ?: ('#' . $row['items_id']), true) . "</a></td>";
    echo "<td>" . Compat::e($itemtype::getTypeName(1)) . ($row['type_name'] ? ' / ' . Compat::e($row['type_name'], true) : '') . "</td>";
    echo "<td>" . Compat::e($row['user'], true) . "</td>";
    echo "<td>" . ($row['plan_name'] ? "<a href='" . Compat::e(Plan::getFormURLWithID((int) $row['plugin_mantenimientos_plans_id'])) . "'>"
        . Compat::e($row['plan_name'], true) . "</a>" : '—') . "</td>";
    echo "<td>" . Compat::e($row['last_preventive_date'] ? Html::convDate($row['last_preventive_date']) : __('Sin registro', 'mantenimientos')) . "</td>";
    echo "<td>" . Compat::e($row['next_due_date'] ? Html::convDate($row['next_due_date']) : '—') . "</td>";
    echo "<td>" . Compat::e($days_txt) . "</td>";
    echo "</tr>";
}
echo "</tbody></table></div></div></div>";

Html::footer();
