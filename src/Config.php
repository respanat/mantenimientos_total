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

use CommonDBTM;

/**
 * Configuración clave/valor del plugin (umbrales del semáforo y metas).
 * Tabla: glpi_plugin_mantenimientos_configs
 */
class Config extends CommonDBTM
{
    public static $rightname = 'plugin_mantenimientos_config';

    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function getTypeName($nb = 0)
    {
        return __('Configuración de mantenimientos', 'mantenimientos');
    }

    public static function getValue(string $name, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = [];
            /** @var \DBmysql $DB */
            global $DB;
            foreach ($DB->request(['FROM' => self::getTable()]) as $row) {
                self::$cache[$row['name']] = $row['value'];
            }
        }
        return self::$cache[$name] ?? $default;
    }

    public static function setValue(string $name, string $value): bool
    {
        $config = new self();
        $ok = $config->getFromDBByCrit(['name' => $name])
            ? $config->update(['id' => $config->getID(), 'value' => $value])
            : (bool) $config->add(['name' => $name, 'value' => $value]);
        self::$cache = null;
        return $ok;
    }
}
