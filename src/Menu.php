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
 * Entrada "Mantenimientos" en los menús Herramientas y Activos.
 * Vistas: Tablero, Equipos (semáforo), Historial, Planes, Avisos en Windows.
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

        $menu = [
            'title'   => self::getMenuName(),
            'page'    => self::WEB_ROOT . '/front/dashboard.php',
            'icon'    => self::getIcon(),
            'options' => [],
        ];

        // Sub-vistas: se agregan a medida que se implementan sus pantallas
        // (Equipos, Historial, Métricas y Configuración vienen en próximas entregas).
        if (Session::haveRight(Plan::$rightname, READ)) {
            $menu['options']['plan'] = [
                'title' => Plan::getTypeName(Session::getPluralNumber()),
                'page'  => Plan::getSearchURL(false),
                'icon'  => Plan::getIcon(),
                'links' => [
                    'search' => Plan::getSearchURL(false),
                ],
            ];
            if (Session::haveRight(Plan::$rightname, CREATE)) {
                $menu['options']['plan']['links']['add'] = Plan::getFormURL(false);
            }
        }

        if (Session::haveRight(Maintenance::$rightname, READ)) {
            $menu['options']['item'] = [
                'title' => Item::getTypeName(Session::getPluralNumber()),
                'page'  => Item::getSearchURL(false),
                'icon'  => Item::getIcon(),
            ];
            $menu['options']['maintenance'] = [
                'title' => __('Historial', 'mantenimientos'),
                'page'  => Maintenance::getSearchURL(false),
                'icon'  => 'ti ti-history',
                'links' => ['search' => Maintenance::getSearchURL(false)],
            ];
        }

        if (Session::haveRight(Config::$rightname, READ)) {
            $menu['options']['notify'] = [
                'title' => Notice::getTypeName(Session::getPluralNumber()),
                'page'  => self::WEB_ROOT . '/front/notify.config.php',
                'icon'  => Notice::getIcon(),
            ];
        }

        return $menu;
    }
}
