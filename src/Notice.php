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

use GlpiPlugin\Mantenimientos\Base\NoticeBase;

/**
 * Avisos al usuario en Windows.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\NoticeBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Notice extends NoticeBase
    {
        public static string $rightname = 'plugin_mantenimientos_config';
    }
} else {
    // GLPI 10 y 11
    class Notice extends NoticeBase
    {
        public static $rightname = 'plugin_mantenimientos_config';
    }
}
