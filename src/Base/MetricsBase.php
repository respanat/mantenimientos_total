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

use CommonGLPI;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;

/**
 * Módulo de seguimiento y control (cumplimiento, seguimiento, costos).
 * Lógica común (GLPI 10, 11 y 12); la clase final es ../Metrics.php.
 * Sin tabla propia: calcula sobre maintenances, statuslogs, costs y snapshots.
 */
abstract class MetricsBase extends CommonGLPI
{
    /** Periodos disponibles en los tableros: días => etiqueta */
    public const PERIODS = [30 => '30 días', 90 => '90 días', 365 => '12 meses'];

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    public static function getTypeName($nb = 0)
    {
        return __('Seguimiento y control', 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-chart-bar';
    }

    public static function normalizePeriod(mixed $period): int
    {
        $period = (int) $period;
        return array_key_exists($period, self::PERIODS) ? $period : 30;
    }

    /**
     * Agrupación según el periodo: día (30), semana (90) o mes (365).
     */
    private static function bucketOf(string $date, int $period): string
    {
        $d = new \DateTimeImmutable(substr($date, 0, 10));
        return match (true) {
            $period <= 30 => $d->format('Y-m-d'),
            $period <= 90 => $d->modify('monday this week')->format('Y-m-d'),
            default       => $d->format('Y-m-01'),
        };
    }

    /**
     * Lista ordenada de grupos (buckets) del periodo con su etiqueta.
     *
     * @return array<string, string> bucket => etiqueta
     */
    private static function buckets(int $period): array
    {
        $today = new \DateTimeImmutable('today');
        $start = $today->modify(sprintf('-%d days', $period - 1));
        $out   = [];
        for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
            $key = self::bucketOf($d->format('Y-m-d'), $period);
            if (!isset($out[$key])) {
                $b = new \DateTimeImmutable($key);
                $out[$key] = $period > 90
                    ? self::MONTHS[(int) $b->format('n') - 1] . ' ' . $b->format('y')
                    : $b->format('d/m');
            }
        }
        return $out;
    }

    /**
     * A. Estado del parque: cuántos equipos había en cada color del semáforo.
     * Usa la foto diaria; por grupo toma la última foto disponible.
     *
     * @return array{labels: list<string>, series: list<array{name: string, color: string, data: list<int|null>}>}
     */
    public static function parkTrend(int $period): array
    {
        $buckets = self::buckets($period);
        $from    = array_key_first($buckets);
        $last    = [];
        foreach (Item::getSnapshots($from) as $snap) {
            $last[self::bucketOf($snap['snapshot_date'], $period)] = $snap; // ordenadas: queda la última
        }

        $series = [];
        foreach (Item::getLights() as $key => $def) {
            $data = [];
            foreach (array_keys($buckets) as $bucket) {
                $data[] = isset($last[$bucket]) ? (int) $last[$bucket][$key] : null;
            }
            $series[] = ['name' => $def['label'], 'color' => $def['color'], 'data' => $data];
        }
        return ['labels' => array_values($buckets), 'series' => $series];
    }

    /**
     * B. Actividad: preventivos iniciados, completados y cancelados por grupo.
     *
     * @return array{labels: list<string>, series: list<array{name: string, color: string, data: list<int>}>}
     */
    public static function activityTrend(int $period): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $buckets = self::buckets($period);
        $from    = array_key_first($buckets) . ' 00:00:00';
        $zero    = array_fill_keys(array_keys($buckets), 0);
        $started = $completed = $cancelled = $zero;

        $iterator = $DB->request([
            'SELECT' => ['status', 'date_start', 'date_end'],
            'FROM'   => Maintenance::getTable(),
            'WHERE'  => [
                'maintenance_type' => Maintenance::TYPE_PREVENTIVE,
                'OR' => [
                    ['date_start' => ['>=', $from]],
                    ['date_end'   => ['>=', $from]],
                ],
            ],
        ]);
        foreach ($iterator as $row) {
            if (!empty($row['date_start']) && $row['date_start'] >= $from) {
                $b = self::bucketOf($row['date_start'], $period);
                if (isset($started[$b])) {
                    $started[$b]++;
                }
            }
            if (!empty($row['date_end']) && $row['date_end'] >= $from) {
                $b = self::bucketOf($row['date_end'], $period);
                if ((int) $row['status'] === Maintenance::STATUS_COMPLETED && isset($completed[$b])) {
                    $completed[$b]++;
                } elseif ((int) $row['status'] === Maintenance::STATUS_CANCELLED && isset($cancelled[$b])) {
                    $cancelled[$b]++;
                }
            }
        }

        return [
            'labels' => array_values($buckets),
            'series' => [
                ['name' => __('Iniciados', 'mantenimientos'),   'color' => '#4263eb', 'data' => array_values($started)],
                ['name' => __('Completados', 'mantenimientos'), 'color' => '#2fb344', 'data' => array_values($completed)],
                ['name' => __('Cancelados', 'mantenimientos'),  'color' => '#868e96', 'data' => array_values($cancelled)],
            ],
        ];
    }
}
