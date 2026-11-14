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

use CommonDBChild;

/**
 * Tarea de la plantilla de checklist de un plan.
 * Tabla: glpi_plugin_mantenimientos_plantasks
 */
class PlanTask extends CommonDBChild
{
    public static $rightname = 'plugin_mantenimientos_plan';

    public static $itemtype = Plan::class;
    public static $items_id = 'plugin_mantenimientos_plans_id';

    public static function getTypeName($nb = 0)
    {
        return _n('Tarea del checklist', 'Tareas del checklist', $nb, 'mantenimientos');
    }
}
