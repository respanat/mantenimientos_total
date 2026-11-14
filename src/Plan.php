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

use GlpiPlugin\Mantenimientos\Base\PlanBase;

/**
 * Plan de mantenimiento por tipo de equipo.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\PlanBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Plan extends PlanBase
    {
        public static string $rightname = 'plugin_mantenimientos_plan';
        public bool $dohistory = true;
    }
} else {
    // GLPI 10 y 11
    class Plan extends PlanBase
    {
        public static $rightname = 'plugin_mantenimientos_plan';
        public $dohistory = true;
    }
}
