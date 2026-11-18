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
use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Plan;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

if (!Menu::canView()) {
    Html::displayRightError();
}

Html::header(Menu::getMenuName(), '', 'admin', Menu::class);

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='" . Compat::e(Menu::getIcon()) . " me-2'></i>"
    . Compat::e(Menu::getMenuName()) . "</h3></div>";
echo "<div class='card-body'>";
echo "<p>" . Compat::e(__('El tablero con semáforo e indicadores llega en una próxima entrega. Por ahora puede configurar los planes de mantenimiento.', 'mantenimientos')) . "</p>";
if (Session::haveRight(Plan::$rightname, READ)) {
    echo "<a class='btn btn-primary' href='" . Compat::e(Plan::getSearchURL()) . "'><i class='" . Compat::e(Plan::getIcon()) . " me-1'></i>"
        . Compat::e(Plan::getTypeName(Session::getPluralNumber())) . "</a>";
}
echo "</div></div></div>";

Html::footer();
