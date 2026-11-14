<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Mantenimientos\Base;

use CommonDBChild;

/**
 * Tarea de la plantilla de checklist de un plan.
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../PlanTask.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_plantasks
 */
abstract class PlanTaskBase extends CommonDBChild
{
    public static function getTypeName($nb = 0)
    {
        return _n('Tarea del checklist', 'Tareas del checklist', $nb, 'mantenimientos');
    }
}
