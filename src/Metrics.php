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

use CommonGLPI;

/**
 * Módulo de seguimiento y control (cumplimiento, seguimiento, costos).
 * Sin tabla propia: calcula sobre maintenances, statuslogs y costs.
 * Los cálculos se implementan en la siguiente fase.
 */
class Metrics extends CommonGLPI
{
    public static $rightname = 'plugin_mantenimientos_metrics';

    public static function getTypeName($nb = 0)
    {
        return __('Seguimiento y control', 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-bar';
    }
}
