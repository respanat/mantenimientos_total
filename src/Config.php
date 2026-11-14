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

use GlpiPlugin\Mantenimientos\Base\ConfigBase;

/**
 * Configuración del plugin (umbrales y metas).
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\ConfigBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Config extends ConfigBase
    {
        public static string $rightname = 'plugin_mantenimientos_config';
    }
} else {
    // GLPI 10 y 11
    class Config extends ConfigBase
    {
        public static $rightname = 'plugin_mantenimientos_config';
    }
}
