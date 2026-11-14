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
 * Tarea del checklist ejecutada en un mantenimiento.
 * Tabla: glpi_plugin_mantenimientos_checks
 */
class Check extends CommonDBChild
{
    public static $rightname = 'plugin_mantenimientos_maintenance';

    public static $itemtype = Maintenance::class;
    public static $items_id = 'plugin_mantenimientos_maintenances_id';

    public const RESULT_PENDING = 0;
    public const RESULT_DONE    = 1;
    public const RESULT_NOT_DONE = 2;
    public const RESULT_NA      = 3;

    public static function getTypeName($nb = 0)
    {
        return _n('Verificación', 'Checklist', $nb, 'mantenimientos');
    }

    public static function getResults(): array
    {
        return [
            self::RESULT_PENDING  => __('Pendiente', 'mantenimientos'),
            self::RESULT_DONE     => __('Hecho', 'mantenimientos'),
            self::RESULT_NOT_DONE => __('No hecho', 'mantenimientos'),
            self::RESULT_NA       => __('No aplica', 'mantenimientos'),
        ];
    }
}
