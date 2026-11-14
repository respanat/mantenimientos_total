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

use GlpiPlugin\Mantenimientos\Check;
use GlpiPlugin\Mantenimientos\Cost;
use GlpiPlugin\Mantenimientos\StatusLog;
use CommonDBTM;

/**
 * Registro de mantenimiento: preventivo o correctivo.
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../Maintenance.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_maintenances
 */
abstract class MaintenanceBase extends CommonDBTM
{
    // Tipos
    public const TYPE_PREVENTIVE = 1;
    public const TYPE_CORRECTIVE = 2;

    // Estados del preventivo
    public const STATUS_SCHEDULED   = 1;  // Programado
    public const STATUS_RESCHEDULED = 2;  // Reprogramado
    public const STATUS_IN_PROGRESS = 3;  // En ejecución
    public const STATUS_COMPLETED   = 4;  // Completado
    public const STATUS_CANCELLED   = 5;  // Cancelado

    // Estados del correctivo
    public const STATUS_REPORTED        = 10; // Reportado
    public const STATUS_DIAGNOSIS       = 11; // Diagnóstico
    public const STATUS_REPAIRING       = 12; // En reparación (interna)
    public const STATUS_WAITING_PART    = 13; // Esperando repuesto
    public const STATUS_SENT_SUPPLIER   = 14; // Enviado a proveedor
    public const STATUS_AT_SUPPLIER     = 15; // En proveedor
    public const STATUS_RETURNED        = 16; // Devuelto por proveedor
    public const STATUS_REPAIRED        = 17; // Reparado
    public const STATUS_NOT_REPAIRABLE  = 18; // No reparable (sugiere baja)

    // Resultado del correctivo
    public const RESULT_NONE           = 0;
    public const RESULT_REPAIRED       = 1;
    public const RESULT_NOT_REPAIRABLE = 2;

    public static function getTypeName($nb = 0)
    {
        return _n('Mantenimiento', 'Mantenimientos', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public static function getMaintenanceTypes(): array
    {
        return [
            self::TYPE_PREVENTIVE => __('Preventivo', 'mantenimientos'),
            self::TYPE_CORRECTIVE => __('Correctivo', 'mantenimientos'),
        ];
    }

    public static function getStatuses(int $type = 0): array
    {
        $preventive = [
            self::STATUS_SCHEDULED   => __('Programado', 'mantenimientos'),
            self::STATUS_RESCHEDULED => __('Reprogramado', 'mantenimientos'),
            self::STATUS_IN_PROGRESS => __('En ejecución', 'mantenimientos'),
            self::STATUS_COMPLETED   => __('Completado', 'mantenimientos'),
            self::STATUS_CANCELLED   => __('Cancelado', 'mantenimientos'),
        ];
        $corrective = [
            self::STATUS_REPORTED       => __('Reportado', 'mantenimientos'),
            self::STATUS_DIAGNOSIS      => __('Diagnóstico', 'mantenimientos'),
            self::STATUS_REPAIRING      => __('En reparación', 'mantenimientos'),
            self::STATUS_WAITING_PART   => __('Esperando repuesto', 'mantenimientos'),
            self::STATUS_SENT_SUPPLIER  => __('Enviado a proveedor', 'mantenimientos'),
            self::STATUS_AT_SUPPLIER    => __('En proveedor', 'mantenimientos'),
            self::STATUS_RETURNED       => __('Devuelto por proveedor', 'mantenimientos'),
            self::STATUS_REPAIRED       => __('Reparado', 'mantenimientos'),
            self::STATUS_NOT_REPAIRABLE => __('No reparable', 'mantenimientos'),
        ];

        return match ($type) {
            self::TYPE_PREVENTIVE => $preventive,
            self::TYPE_CORRECTIVE => $corrective,
            default               => $preventive + $corrective,
        };
    }

    /**
     * Transiciones permitidas: estado actual => estados destino.
     */
    public static function getTransitions(): array
    {
        return [
            // Preventivo
            self::STATUS_SCHEDULED   => [self::STATUS_IN_PROGRESS, self::STATUS_RESCHEDULED, self::STATUS_CANCELLED],
            self::STATUS_RESCHEDULED => [self::STATUS_IN_PROGRESS, self::STATUS_RESCHEDULED, self::STATUS_CANCELLED],
            self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED   => [],
            self::STATUS_CANCELLED   => [],

            // Correctivo
            self::STATUS_REPORTED      => [self::STATUS_DIAGNOSIS],
            self::STATUS_DIAGNOSIS     => [self::STATUS_REPAIRING, self::STATUS_SENT_SUPPLIER, self::STATUS_WAITING_PART, self::STATUS_NOT_REPAIRABLE],
            self::STATUS_REPAIRING     => [self::STATUS_REPAIRED, self::STATUS_WAITING_PART, self::STATUS_NOT_REPAIRABLE],
            self::STATUS_WAITING_PART  => [self::STATUS_REPAIRING, self::STATUS_SENT_SUPPLIER, self::STATUS_NOT_REPAIRABLE],
            self::STATUS_SENT_SUPPLIER => [self::STATUS_AT_SUPPLIER, self::STATUS_RETURNED],
            self::STATUS_AT_SUPPLIER   => [self::STATUS_RETURNED, self::STATUS_WAITING_PART],
            self::STATUS_RETURNED      => [self::STATUS_REPAIRED, self::STATUS_NOT_REPAIRABLE, self::STATUS_DIAGNOSIS],
            self::STATUS_REPAIRED       => [],
            self::STATUS_NOT_REPAIRABLE => [],
        ];
    }

    public static function canTransition(int $from, int $to): bool
    {
        return in_array($to, self::getTransitions()[$from] ?? [], true);
    }

    public static function getInitialStatus(int $type): int
    {
        return $type === self::TYPE_CORRECTIVE ? self::STATUS_REPORTED : self::STATUS_SCHEDULED;
    }

    public static function isClosedStatus(int $status): bool
    {
        return in_array($status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_REPAIRED,
            self::STATUS_NOT_REPAIRABLE,
        ], true);
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Check::class, Cost::class, StatusLog::class]);
    }
}
