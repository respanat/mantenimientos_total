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

/**
 * Capa de compatibilidad GLPI 10 / 11 / 12.
 *
 * Diferencias que cubre (verificadas en el código fuente 10.0.19, 11.0.6 y 12.0.0):
 *
 * 1. GLPI 12 tipa las propiedades de las clases base:
 *      CommonGLPI::$rightname   -> public static string
 *      CommonDBChild::$itemtype -> public static string
 *      CommonDBChild::$items_id -> public static string
 *      CommonDBTM::$dohistory   -> public bool
 *    PHP exige que la clase hija repita exactamente la misma declaración, así que
 *    un mismo archivo no puede servir para 11 y 12. Solución: la lógica vive en
 *    src/Base/*Base.php (sin esas propiedades) y cada clase final en src/*.php se
 *    declara en dos variantes condicionales según typedCoreProperties().
 *    Se detecta por característica (Reflection), no por número de versión, así que
 *    GLPI 13 funciona sin cambios mientras mantenga el mismo esquema.
 *
 * 2. Escape de salida: htmlescape() existe desde GLPI 11. En GLPI 10 los datos
 *    que vienen de la BD ya están codificados (Sanitizer), por eso no se re-escapan.
 *
 * 3. Archivos front/: GLPI 10 necesita incluir inc/includes.php; en 11 no hace
 *    nada y en 12 está obsoleto (lanza aviso). Ver front/*.php.
 */
final class Compat
{
    private static ?bool $typed = null;

    /**
     * true si el núcleo declara tipos nativos en sus propiedades (GLPI 12+).
     */
    public static function typedCoreProperties(): bool
    {
        if (self::$typed === null) {
            self::$typed = (new \ReflectionProperty(\CommonGLPI::class, 'rightname'))->hasType();
        }
        return self::$typed;
    }

    /**
     * true en GLPI 10.x (entrada saneada automáticamente, sin htmlescape()).
     */
    public static function isGlpi10(): bool
    {
        return version_compare(GLPI_VERSION, '11.0.0-dev', '<');
    }

    /**
     * Escapa texto para HTML de forma correcta en cada versión.
     * - Literales del plugin: siempre usar con $from_db = false.
     * - Valores leídos de la BD: $from_db = true (en GLPI 10 ya vienen codificados).
     */
    public static function e(?string $value, bool $from_db = false): string
    {
        $value ??= '';
        if ($from_db && self::isGlpi10()) {
            return $value;
        }
        if (function_exists('htmlescape')) {
            return htmlescape($value);
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
