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
 * Tarea del checklist ejecutada en un mantenimiento.
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../Check.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_checks
 */
abstract class CheckBase extends CommonDBChild
{
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
            self::RESULT_DONE     => __('Realizado', 'mantenimientos'),
            self::RESULT_NOT_DONE => __('No realizado', 'mantenimientos'),
            self::RESULT_NA       => __('No aplica', 'mantenimientos'),
        ];
    }
}
