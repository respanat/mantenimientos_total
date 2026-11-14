<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Gestión de mantenimientos preventivos y correctivos del parque informático,
 * independiente del sistema de tickets.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Profile;

define('PLUGIN_MANTENIMIENTOS_VERSION', '0.1.0');
define('PLUGIN_MANTENIMIENTOS_MIN_GLPI', '11.0.0');
define('PLUGIN_MANTENIMIENTOS_MAX_GLPI', '11.0.99');

/**
 * Inicialización del plugin: registro de clases, pestañas y menú.
 */
function plugin_init_mantenimientos(): void
{
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('mantenimientos')) {
        return;
    }

    // Pestaña de permisos en los perfiles de GLPI
    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);

    // Menú propio en Administración
    if (Menu::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['mantenimientos'] = ['admin' => Menu::class];
    }
}

/**
 * Información del plugin.
 */
function plugin_version_mantenimientos(): array
{
    return [
        'name'         => 'Mantenimientos',
        'version'      => PLUGIN_MANTENIMIENTOS_VERSION,
        'author'       => 'Ramiro España',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/respanat/mantenimientos_total',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MANTENIMIENTOS_MIN_GLPI,
                'max' => PLUGIN_MANTENIMIENTOS_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Los requisitos de versión se validan con 'requirements'.
 */
function plugin_mantenimientos_check_prerequisites(): bool
{
    return true;
}

function plugin_mantenimientos_check_config($verbose = false): bool
{
    return true;
}
