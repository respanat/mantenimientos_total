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

use GlpiPlugin\Mantenimientos\Base\StatusLogBase;

/**
 * Bitácora de cambios de estado.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\StatusLogBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class StatusLog extends StatusLogBase
    {
        public static string $rightname = 'plugin_mantenimientos_maintenance';
        public static string $itemtype = Maintenance::class;
        public static string $items_id = 'plugin_mantenimientos_maintenances_id';
    }
} else {
    // GLPI 10 y 11
    class StatusLog extends StatusLogBase
    {
        public static $rightname = 'plugin_mantenimientos_maintenance';
        public static $itemtype = Maintenance::class;
        public static $items_id = 'plugin_mantenimientos_maintenances_id';
    }
}
