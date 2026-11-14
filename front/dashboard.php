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
echo "<p>" . Compat::e(__('Plugin instalado. Las vistas de Tablero, Equipos, Historial, Planes y Métricas se habilitan en las próximas entregas.', 'mantenimientos')) . "</p>";
echo "</div></div></div>";

Html::footer();
