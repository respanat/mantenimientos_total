<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Tablero: semáforo actual + tendencias (estado del parque y actividad).
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Metrics;
use GlpiPlugin\Mantenimientos\Plan;
use GlpiPlugin\Mantenimientos\Ui;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

if (!Menu::canView()) {
    Html::displayRightError();
}

Html::header(Menu::getMenuName(), '', 'tools', Menu::class);

$period    = Metrics::normalizePeriod($_GET['period'] ?? 30);
$self_url  = Menu::WEB_ROOT . '/front/dashboard.php';

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header d-flex align-items-center'><h3 class='card-title mb-0'><i class='" . Compat::e(Menu::getIcon()) . " me-2'></i>"
    . Compat::e(Menu::getMenuName()) . "</h3>";
echo "<div class='ms-auto d-flex gap-2'>";
if (Session::haveRight(Maintenance::$rightname, READ)) {
    echo "<a class='btn btn-primary' href='" . Compat::e(Item::getSearchURL()) . "'><i class='" . Compat::e(Item::getIcon()) . " me-1'></i>"
        . Compat::e(Item::getTypeName(Session::getPluralNumber())) . "</a>";
    echo "<a class='btn btn-primary' href='" . Compat::e(Maintenance::getSearchURL()) . "'><i class='ti ti-history me-1'></i>"
        . Compat::e(__('Historial', 'mantenimientos')) . "</a>";
}
if (Session::haveRight(Plan::$rightname, READ)) {
    echo "<a class='btn btn-primary' href='" . Compat::e(Plan::getSearchURL()) . "'><i class='" . Compat::e(Plan::getIcon()) . " me-1'></i>"
        . Compat::e(Plan::getTypeName(Session::getPluralNumber())) . "</a>";
}
if (Session::haveRight(GlpiPlugin\Mantenimientos\Config::$rightname, READ)) {
    echo "<a class='btn btn-outline-primary' href='" . Compat::e(Menu::WEB_ROOT . '/front/notify.config.php') . "'><i class='"
        . Compat::e(GlpiPlugin\Mantenimientos\Notice::getIcon()) . " me-1'></i>"
        . Compat::e(GlpiPlugin\Mantenimientos\Notice::getTypeName(Session::getPluralNumber())) . "</a>";
}
echo "</div></div>";

echo "<div class='card-body'>";
if (Session::haveRight(Maintenance::$rightname, READ)) {
    Item::ensureTodaySnapshot();

    // Semáforo actual
    $counts = Item::getCounts();
    echo "<h4 class='mb-2'>" . Compat::e(__('Estado del preventivo', 'mantenimientos')) . "</h4>";
    echo Ui::lightTiles($counts, fn (string $key) => Item::getSearchURL() . '?light=' . urlencode($key));

    // Selector de periodo
    echo "<div class='d-flex align-items-center mt-4 mb-2'><h4 class='mb-0'>" . Compat::e(__('Tendencias', 'mantenimientos')) . "</h4>";
    echo "<div class='btn-group ms-auto' role='group'>";
    foreach (Metrics::PERIODS as $days => $label) {
        $cls = $days === $period ? 'btn-primary' : 'btn-outline-primary';
        echo "<a class='btn btn-sm {$cls}' href='" . Compat::e($self_url . '?period=' . $days) . "'>" . Compat::e($label) . "</a>";
    }
    echo "</div></div>";

    echo "<div class='row g-3'>";

    // A. Estado del parque (foto diaria)
    $park = Metrics::parkTrend($period);
    echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h4 class='card-title mb-0'>"
        . Compat::e(__('Estado del parque', 'mantenimientos')) . "</h4>"
        . "<span class='ms-2 text-muted small'>" . Compat::e(__('equipos por color del semáforo', 'mantenimientos')) . "</span></div>";
    echo "<div class='card-body'>" . Ui::lineChart(
        'mant-park',
        $park['labels'],
        $park['series'],
        __('Aún no hay fotos diarias suficientes. La gráfica se llena desde hoy con la tarea automática.', 'mantenimientos')
    ) . "</div></div></div>";

    // B. Actividad de mantenimientos
    $activity = Metrics::activityTrend($period);
    echo "<div class='col-xl-6'><div class='card h-100'><div class='card-header'><h4 class='card-title mb-0'>"
        . Compat::e(__('Actividad de preventivos', 'mantenimientos')) . "</h4>"
        . "<span class='ms-2 text-muted small'>" . Compat::e($period <= 30 ? __('por día', 'mantenimientos') : ($period <= 90 ? __('por semana', 'mantenimientos') : __('por mes', 'mantenimientos'))) . "</span></div>";
    echo "<div class='card-body'>" . Ui::lineChart(
        'mant-activity',
        $activity['labels'],
        $activity['series'],
        __('Todavía no hay preventivos en este periodo.', 'mantenimientos')
    ) . "</div></div></div>";

    echo "</div>";
    echo "<div class='text-muted small mt-2'>" . Compat::e(__('Clic en la leyenda para mostrar u ocultar cada serie.', 'mantenimientos')) . "</div>";
}
echo "</div></div></div>";

Html::footer();
