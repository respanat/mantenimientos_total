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

use GlpiPlugin\Mantenimientos\Base\MaintenanceBase;

/**
 * Registro de mantenimiento: preventivo o correctivo.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\MaintenanceBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Maintenance extends MaintenanceBase
    {
        public static string $rightname = 'plugin_mantenimientos_maintenance';
        public bool $dohistory = true;
    }
} else {
    // GLPI 10 y 11
    class Maintenance extends MaintenanceBase
    {
        public static $rightname = 'plugin_mantenimientos_maintenance';
        public $dohistory = true;
    }
}
