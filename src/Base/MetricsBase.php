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

use CommonGLPI;

/**
 * Módulo de seguimiento y control (cumplimiento, seguimiento, costos).
 * Lógica común (GLPI 10, 11 y 12); la clase final es ../Metrics.php.
 * Sin tabla propia: calcula sobre maintenances, statuslogs y costs.
 * Los cálculos se implementan en la siguiente fase.
 */
abstract class MetricsBase extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Seguimiento y control', 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-bar';
    }
}
