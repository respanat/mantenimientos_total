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

use GlpiPlugin\Mantenimientos\Menu;

if (!Menu::canView()) {
    Html::displayRightError();
}

Html::header(Menu::getMenuName(), '', 'admin', Menu::class);

echo "<div class='container-fluid'>";
echo "<div class='card'>";
echo "<div class='card-header'><h3 class='card-title'><i class='" . htmlescape(Menu::getIcon()) . " me-2'></i>"
    . htmlescape(Menu::getMenuName()) . "</h3></div>";
echo "<div class='card-body'>";
echo "<p>" . htmlescape(__('Plugin instalado. Las vistas de Tablero, Equipos, Historial, Planes y Métricas se habilitan en las próximas entregas.', 'mantenimientos')) . "</p>";
echo "</div></div></div>";

Html::footer();
