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

use GlpiPlugin\Mantenimientos\Base\PlanTaskBase;

/**
 * Tarea de la plantilla de checklist de un plan.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\PlanTaskBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class PlanTask extends PlanTaskBase
    {
        public static string $rightname = 'plugin_mantenimientos_plan';
        public static string $itemtype = Plan::class;
        public static string $items_id = 'plugin_mantenimientos_plans_id';
    }
} else {
    // GLPI 10 y 11
    class PlanTask extends PlanTaskBase
    {
        public static $rightname = 'plugin_mantenimientos_plan';
        public static $itemtype = Plan::class;
        public static $items_id = 'plugin_mantenimientos_plans_id';
    }
}
