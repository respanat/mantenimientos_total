<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Mantenimientos;

use GlpiPlugin\Mantenimientos\Base\CostBase;

/**
 * Costo asociado a un mantenimiento.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\CostBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Cost extends CostBase
    {
        public static string $rightname = 'plugin_mantenimientos_maintenance';
        public static string $itemtype = Maintenance::class;
        public static string $items_id = 'plugin_mantenimientos_maintenances_id';
    }
} else {
    // GLPI 10 y 11
    class Cost extends CostBase
    {
        public static $rightname = 'plugin_mantenimientos_maintenance';
        public static $itemtype = Maintenance::class;
        public static $items_id = 'plugin_mantenimientos_maintenances_id';
    }
}
