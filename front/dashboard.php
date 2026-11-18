<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Tablero de mantenimientos (marcador de posición de la fase 1).
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
use GlpiPlugin\Mantenimientos\Ui;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

if (!Menu::canView()) {
    Html::displayRightError();
}

Html::header(Menu::getMenuName(), '', 'tools', Menu::class);

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='" . Compat::e(Menu::getIcon()) . " me-2'></i>"
    . Compat::e(Menu::getMenuName()) . "</h3></div>";
echo "<div class='card-body'>";
if (Session::haveRight(Maintenance::$rightname, READ)) {
    $counts = Item::getCounts();
    echo "<h4 class='mb-2'>" . Compat::e(__('Estado del preventivo', 'mantenimientos')) . "</h4>";
    echo Ui::lightTiles($counts, fn (string $key) => Item::getSearchURL() . '?light=' . urlencode($key));
    echo "<a class='btn btn-outline-primary me-2' href='" . Compat::e(Item::getSearchURL()) . "'><i class='" . Compat::e(Item::getIcon()) . " me-1'></i>"
        . Compat::e(Item::getTypeName(Session::getPluralNumber())) . "</a>";
}
if (Session::haveRight(Plan::$rightname, READ)) {
    echo "<a class='btn btn-primary' href='" . Compat::e(Plan::getSearchURL()) . "'><i class='" . Compat::e(Plan::getIcon()) . " me-1'></i>"
        . Compat::e(Plan::getTypeName(Session::getPluralNumber())) . "</a>";
}
echo "</div></div></div>";

Html::footer();
