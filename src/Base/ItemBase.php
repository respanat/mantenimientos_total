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

use GlpiPlugin\Mantenimientos\Config;
use GlpiPlugin\Mantenimientos\Plan;
use CommonDBTM;

/**
 * Relación equipo ↔ plan y estado del preventivo (semáforo).
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../Item.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_items
 */
abstract class ItemBase extends CommonDBTM
{
    public const LIGHT_OK       = 'ok';       // 🟢 faltan más días que el umbral de advertencia
    public const LIGHT_WARNING  = 'warning';  // 🟡 dentro del umbral de advertencia
    public const LIGHT_CRITICAL = 'critical'; // 🟠 dentro del umbral crítico
    public const LIGHT_OVERDUE  = 'overdue';  // 🔴 vencido
    public const LIGHT_REPAIR   = 'repair';   // 🔧 con correctivo abierto
    public const LIGHT_NOPLAN   = 'noplan';   // ⚪ sin plan

    public static function getTypeName($nb = 0)
    {
        return _n('Equipo en mantenimiento', 'Equipos en mantenimiento', $nb, 'mantenimientos');
    }

    /**
     * Frecuencia efectiva: la propia del equipo o la del plan.
     */
    public function getEffectiveFrequency(): int
    {
        $override = (int) ($this->fields['frequency_days_override'] ?? 0);
        if ($override > 0) {
            return $override;
        }
        $plan = new Plan();
        if ($plan->getFromDB((int) $this->fields['plugin_mantenimientos_plans_id'])) {
            return (int) $plan->fields['frequency_days'];
        }
        return 0;
    }

    /**
     * Calcula el color del semáforo a partir de la próxima fecha.
     * El caso "correctivo abierto" se resuelve en la capa que consulta Maintenance.
     */
    public static function computeLight(?string $next_due_date, ?\DateTimeImmutable $today = null): string
    {
        if (empty($next_due_date)) {
            return self::LIGHT_NOPLAN;
        }

        $today ??= new \DateTimeImmutable('today');
        $due    = new \DateTimeImmutable($next_due_date);
        $days   = (int) $today->diff($due)->format('%r%a');

        $warning  = (int) Config::getValue('threshold_warning_days', 30);
        $critical = (int) Config::getValue('threshold_critical_days', 7);

        return match (true) {
            $days < 0         => self::LIGHT_OVERDUE,
            $days <= $critical => self::LIGHT_CRITICAL,
            $days <= $warning  => self::LIGHT_WARNING,
            default            => self::LIGHT_OK,
        };
    }
}
