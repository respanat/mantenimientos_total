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
 * Componentes visuales comunes de los tableros del plugin.
 *
 * Mosaicos al estilo del resumen de tickets de GLPI: fondo de color completo,
 * número grande, etiqueta, ícono arriba a la derecha y descripción al pasar el
 * cursor. Todos los tableros del plugin (Equipos, Tablero, Métricas) usan esto.
 */
final class Ui
{
    private static bool $css_printed = false;

    private static function css(): string
    {
        if (self::$css_printed) {
            return '';
        }
        self::$css_printed = true;
        return <<<CSS
<style>
.mant-tiles { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
.mant-tile {
    position: relative; flex: 1 1 160px; min-height: 105px; padding: .6rem .8rem;
    border-radius: 4px; text-decoration: none !important; display: flex; flex-direction: column;
    justify-content: flex-end; transition: transform .1s ease, box-shadow .1s ease;
}
.mant-tile:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.15); }
.mant-tile .mant-tile-number { font-size: 2.6rem; line-height: 1; font-weight: 400; }
.mant-tile .mant-tile-label  { font-size: 1.05rem; margin-top: .35rem; }
.mant-tile .mant-tile-icon   { position: absolute; top: .45rem; right: .55rem; font-size: 1rem; opacity: .7; }
.mant-tile.mant-tile-active  { outline: 3px solid rgba(0,0,0,.45); outline-offset: -3px; }
.mant-tile.mant-tile-dimmed  { opacity: .55; }
</style>
CSS;
    }

    /**
     * @param list<array{
     *   value: int|string, label: string, color: string, text?: string,
     *   icon?: string, url?: string, hint?: string, active?: bool, dimmed?: bool
     * }> $tiles
     */
    public static function tiles(array $tiles): string
    {
        $html = self::css() . "<div class='mant-tiles'>";
        foreach ($tiles as $tile) {
            $tag     = !empty($tile['url']) ? 'a' : 'div';
            $href    = !empty($tile['url']) ? " href='" . Compat::e($tile['url']) . "'" : '';
            $title   = !empty($tile['hint']) ? " title='" . Compat::e($tile['hint']) . "' data-bs-toggle='tooltip'" : '';
            $classes = 'mant-tile'
                . (!empty($tile['active']) ? ' mant-tile-active' : '')
                . (!empty($tile['dimmed']) ? ' mant-tile-dimmed' : '');
            $style = sprintf(
                "background:%s;color:%s",
                $tile['color'],
                $tile['text'] ?? 'rgba(0,0,0,.72)'
            );

            $html .= "<{$tag} class='{$classes}' style='{$style}'{$href}{$title}>";
            if (!empty($tile['icon'])) {
                $html .= "<i class='mant-tile-icon " . Compat::e($tile['icon']) . "'></i>";
            }
            $html .= "<span class='mant-tile-number'>" . Compat::e((string) $tile['value']) . "</span>";
            $html .= "<span class='mant-tile-label'>" . Compat::e($tile['label']) . "</span>";
            $html .= "</{$tag}>";
        }
        return $html . "</div>";
    }

    /**
     * Mosaicos del semáforo de preventivos.
     *
     * @param array<string, int> $counts
     * @param callable(string): string $url_for  Recibe la clave del color y devuelve la URL.
     */
    public static function lightTiles(array $counts, callable $url_for, string $active = ''): string
    {
        $tiles = [];
        foreach (Item::getLights() as $key => $def) {
            $tiles[] = [
                'value'  => (int) ($counts[$key] ?? 0),
                'label'  => $def['label'],
                'color'  => $def['tile'],
                'text'   => $def['tile_text'],
                'icon'   => $def['icon'],
                'hint'   => $def['hint'],
                'url'    => $url_for($key),
                'active' => $active === $key,
                'dimmed' => $active !== '' && $active !== $key,
            ];
        }
        return self::tiles($tiles);
    }
}
