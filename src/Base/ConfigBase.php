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

use CommonDBTM;
use GlpiPlugin\Mantenimientos\Config;

/**
 * Configuración clave/valor del plugin (umbrales del semáforo y metas).
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../Config.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_configs
 */
abstract class ConfigBase extends CommonDBTM
{
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
            foreach ($DB->request(['FROM' => Config::getTable()]) as $row) {
                self::$cache[$row['name']] = $row['value'];
            }
        }
        return self::$cache[$name] ?? $default;
    }

    public static function setValue(string $name, string $value): bool
    {
        $config = new Config();
        $ok = $config->getFromDBByCrit(['name' => $name])
            ? $config->update(['id' => $config->getID(), 'value' => $value])
            : (bool) $config->add(['name' => $name, 'value' => $value]);
        self::$cache = null;
        return $ok;
    }
}
