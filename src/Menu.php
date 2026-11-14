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
use Session;

/**
 * Entrada "Mantenimientos" en el menú Administración.
 * Vistas: Tablero, Equipos (semáforo), Historial, Planes, Métricas, Configuración.
 */
class Menu extends CommonGLPI
{
    public const WEB_ROOT = '/plugins/mantenimientos';

    public static function getTypeName($nb = 0)
    {
        return _n('Mantenimiento', 'Mantenimientos', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Maintenance::$rightname, READ)
            || Session::haveRight(Plan::$rightname, READ)
            || Session::haveRight(Metrics::$rightname, READ)
            || Session::haveRight(Config::$rightname, READ);
    }

    public static function getMenuName()
    {
        return self::getTypeName(Session::getPluralNumber());
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        // Las sub-vistas (Planes, Equipos, Historial, Métricas, Configuración)
        // se agregan en 'options' a medida que se implementan sus pantallas.
        return [
            'title'   => self::getMenuName(),
            'page'    => self::WEB_ROOT . '/front/dashboard.php',
            'icon'    => self::getIcon(),
            'options' => [],
        ];
    }
}
