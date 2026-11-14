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

use GlpiPlugin\Mantenimientos\Base\ItemBase;

/**
 * Relación equipo ↔ plan y semáforo del preventivo.
 *
 * Clase final: solo declara las propiedades que GLPI 12 tipa en el núcleo.
 * Toda la lógica está en Base\ItemBase. Ver Compat::typedCoreProperties().
 */
if (Compat::typedCoreProperties()) {
    // GLPI 12+
    class Item extends ItemBase
    {
        public static string $rightname = 'plugin_mantenimientos_plan';
    }
} else {
    // GLPI 10 y 11
    class Item extends ItemBase
    {
        public static $rightname = 'plugin_mantenimientos_plan';
    }
}
