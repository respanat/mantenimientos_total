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

use CommonDBChild;

/**
 * Costo asociado a un mantenimiento.
 * Tabla: glpi_plugin_mantenimientos_costs
 */
class Cost extends CommonDBChild
{
    public static $rightname = 'plugin_mantenimientos_maintenance';

    public static $itemtype = Maintenance::class;
    public static $items_id = 'plugin_mantenimientos_maintenances_id';

    public const TYPE_PART     = 1;
    public const TYPE_LABOR    = 2;
    public const TYPE_EXTERNAL = 3;
    public const TYPE_SHIPPING = 4;

    public static function getTypeName($nb = 0)
    {
        return _n('Costo', 'Costos', $nb, 'mantenimientos');
    }

    public static function getCostTypes(): array
    {
        return [
            self::TYPE_PART     => __('Repuesto', 'mantenimientos'),
            self::TYPE_LABOR    => __('Mano de obra', 'mantenimientos'),
            self::TYPE_EXTERNAL => __('Servicio externo', 'mantenimientos'),
            self::TYPE_SHIPPING => __('Envío', 'mantenimientos'),
        ];
    }

    public function prepareInputForAdd($input)
    {
        return $this->computeTotal($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->computeTotal($input);
    }

    /**
     * total = cantidad × valor unitario (se calcula siempre en servidor).
     */
    private function computeTotal(array $input): array
    {
        $quantity  = (float) ($input['quantity'] ?? $this->fields['quantity'] ?? 1);
        $unit_cost = (float) ($input['unit_cost'] ?? $this->fields['unit_cost'] ?? 0);
        $input['total'] = round($quantity * $unit_cost, 4);
        return $input;
    }
}
