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

use GlpiPlugin\Mantenimientos\Base\MetricsBase;

/**
 * Seguimiento y control.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\MetricsBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Metrics extends MetricsBase
    {
        public static string $rightname = 'plugin_mantenimientos_metrics';
    }
} else {
    // GLPI 10 y 11
    class Metrics extends MetricsBase
    {
        public static $rightname = 'plugin_mantenimientos_metrics';
    }
}
