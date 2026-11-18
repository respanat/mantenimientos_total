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

    private static bool $chart_js_printed = false;

    /**
     * Gráfica de líneas interactiva (SVG + JS propio, sin librerías externas, igual
     * en GLPI 10, 11 y 12): clic en la leyenda activa/desactiva series, valores al
     * pasar el cursor, se adapta al ancho.
     *
     * @param list<string> $labels
     * @param list<array{name: string, color: string, data: list<int|float|null>}> $series
     */
    public static function lineChart(string $id, array $labels, array $series, string $empty_message = ''): string
    {
        $html = '';
        if (!self::$chart_js_printed) {
            self::$chart_js_printed = true;
            $html .= self::chartAssets();
        }
        $config = json_encode(
            ['labels' => array_values($labels), 'series' => array_values($series), 'empty' => $empty_message],
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
        );
        $safe_id = preg_replace('/[^a-z0-9_-]/i', '', $id);
        $html .= "<div class='mant-chart' id='{$safe_id}'></div>";
        $html .= "<script>document.addEventListener('DOMContentLoaded',function(){window.mantLineChart(document.getElementById('{$safe_id}'),{$config});});"
            . "if(document.readyState!=='loading'){window.mantLineChart(document.getElementById('{$safe_id}'),{$config});}</script>";
        return $html;
    }

    private static function chartAssets(): string
    {
        return <<<'HTML'
<style>
.mant-chart { position: relative; width: 100%; min-height: 300px; }
.mant-chart svg { display: block; width: 100%; height: 280px; }
.mant-chart .mant-legend { display: flex; flex-wrap: wrap; gap: .4rem .9rem; margin-top: .4rem; }
.mant-chart .mant-legend button {
    background: none; border: 0; padding: 0; cursor: pointer; font-size: .85rem; display: inline-flex; align-items: center; color: inherit;
}
.mant-chart .mant-legend button i { width: 14px; height: 4px; border-radius: 2px; margin-right: .35rem; display: inline-block; }
.mant-chart .mant-legend button.off { opacity: .35; text-decoration: line-through; }
.mant-chart .mant-tip {
    position: absolute; pointer-events: none; background: rgba(29,39,59,.92); color: #fff; font-size: .78rem;
    padding: .35rem .5rem; border-radius: 4px; white-space: nowrap; display: none; z-index: 5;
}
.mant-chart .mant-empty { padding: 2.5rem 1rem; text-align: center; color: #868e96; }
</style>
<script>
window.mantLineChart = window.mantLineChart || function (el, cfg) {
    if (!el || el.dataset.ready) { return; }
    el.dataset.ready = '1';
    var NS = 'http://www.w3.org/2000/svg', hidden = {}, H = 280, P = {l: 42, r: 12, t: 12, b: 30};
    var hasData = cfg.labels.length > 0 && cfg.series.some(function (s) { return s.data.some(function (v) { return v !== null && v > 0; }); });
    if (!hasData) { el.innerHTML = '<div class="mant-empty"></div>'; el.firstChild.textContent = cfg.empty || 'Sin datos'; return; }
    var svg = document.createElementNS(NS, 'svg'), legend = document.createElement('div'), tip = document.createElement('div');
    legend.className = 'mant-legend'; tip.className = 'mant-tip';
    el.appendChild(svg); el.appendChild(legend); el.appendChild(tip);
    function mk(tag, attrs) { var n = document.createElementNS(NS, tag); for (var k in attrs) { n.setAttribute(k, attrs[k]); } return n; }
    function nice(max) { if (max <= 5) { return 5; } var p = Math.pow(10, Math.floor(Math.log10(max))), m = max / p; return (m <= 2 ? 2 : m <= 5 ? 5 : 10) * p; }
    function draw() {
        var W = Math.max(el.clientWidth, 320), n = cfg.labels.length;
        svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.innerHTML = '';
        var vis = cfg.series.filter(function (s, i) { return !hidden[i]; });
        var max = nice(Math.max.apply(null, [1].concat(vis.map(function (s) { return Math.max.apply(null, s.data.map(function (v) { return v || 0; })); }))));
        var x = function (i) { return P.l + (n === 1 ? (W - P.l - P.r) / 2 : i * (W - P.l - P.r) / (n - 1)); };
        var y = function (v) { return P.t + (H - P.t - P.b) * (1 - v / max); };
        for (var g = 0; g <= 4; g++) {
            var gv = max * g / 4, gy = y(gv);
            svg.appendChild(mk('line', {x1: P.l, x2: W - P.r, y1: gy, y2: gy, stroke: '#e6e7e9'}));
            var t = mk('text', {x: P.l - 6, y: gy + 4, 'text-anchor': 'end', 'font-size': 11, fill: '#868e96'}); t.textContent = Math.round(gv); svg.appendChild(t);
        }
        var step = Math.max(1, Math.ceil(n / 8));
        cfg.labels.forEach(function (lb, i) {
            var lastFits = (n - 1) % step === 0 || (n - 1) % step >= step / 2 + 0.5;
            if (i % step !== 0 && !(i === n - 1 && lastFits)) { return; }
            var t = mk('text', {x: x(i), y: H - 8, 'text-anchor': 'middle', 'font-size': 11, fill: '#868e96'}); t.textContent = lb; svg.appendChild(t);
        });
        cfg.series.forEach(function (s, si) {
            if (hidden[si]) { return; }
            var d = '', pen = false;
            s.data.forEach(function (v, i) { if (v === null) { pen = false; return; } d += (pen ? 'L' : 'M') + x(i) + ' ' + y(v); pen = true; });
            svg.appendChild(mk('path', {d: d, fill: 'none', stroke: s.color, 'stroke-width': 2.2, 'stroke-linejoin': 'round'}));
            if (n <= 60) { s.data.forEach(function (v, i) { if (v !== null) { svg.appendChild(mk('circle', {cx: x(i), cy: y(v), r: 2.6, fill: s.color})); } }); }
        });
        var guide = mk('line', {y1: P.t, y2: H - P.b, stroke: '#adb5bd', 'stroke-dasharray': '3 3', visibility: 'hidden'});
        svg.appendChild(guide);
        var hit = mk('rect', {x: P.l, y: P.t, width: W - P.l - P.r, height: H - P.t - P.b, fill: 'transparent'});
        svg.appendChild(hit);
        hit.addEventListener('mousemove', function (ev) {
            var r = svg.getBoundingClientRect(), px = (ev.clientX - r.left) * W / r.width;
            var i = n === 1 ? 0 : Math.round((px - P.l) / ((W - P.l - P.r) / (n - 1)));
            i = Math.max(0, Math.min(n - 1, i));
            guide.setAttribute('x1', x(i)); guide.setAttribute('x2', x(i)); guide.setAttribute('visibility', 'visible');
            var rows = [cfg.labels[i]];
            cfg.series.forEach(function (s, si) { if (!hidden[si]) { rows.push('● ' + s.name + ': ' + (s.data[i] === null ? '—' : s.data[i])); } });
            tip.textContent = ''; rows.forEach(function (line, k) { var dv = document.createElement('div'); dv.textContent = line; if (k === 0) { dv.style.fontWeight = '600'; } tip.appendChild(dv); });
            tip.style.display = 'block';
            var left = x(i) * r.width / W + 12; if (left + tip.offsetWidth > r.width) { left -= tip.offsetWidth + 24; }
            tip.style.left = left + 'px'; tip.style.top = '10px';
        });
        hit.addEventListener('mouseleave', function () { guide.setAttribute('visibility', 'hidden'); tip.style.display = 'none'; });
    }
    cfg.series.forEach(function (s, si) {
        var b = document.createElement('button'), sw = document.createElement('i');
        b.type = 'button'; sw.style.background = s.color; b.appendChild(sw); b.appendChild(document.createTextNode(s.name));
        b.addEventListener('click', function () { hidden[si] = !hidden[si]; b.classList.toggle('off', !!hidden[si]); draw(); });
        legend.appendChild(b);
    });
    draw();
    var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(draw, 150); });
};
</script>
HTML;
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
