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
 * Plan de mantenimiento preventivo por tipo de equipo.
 * Tabla: glpi_plugin_mantenimientos_plans
 */
class Plan extends CommonDBTM
{
    public static $rightname = 'plugin_mantenimientos_plan';

    public $dohistory = true;

    public const SERVICE_INTERNAL = 1;
    public const SERVICE_EXTERNAL = 2;

    /** Tipos de equipo soportados en la fase 1 => clase de tipo (dropdown) */
    public const SUPPORTED_ITEMTYPES = [
        \Computer::class => \ComputerType::class, // PC / Portátil
        \Printer::class  => \PrinterType::class,
        \Phone::class    => \PhoneType::class,    // Teléfonos y tablets
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Plan de mantenimiento', 'Planes de mantenimiento', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-repeat';
    }

    public static function getServiceModes(): array
    {
        return [
            self::SERVICE_INTERNAL => __('Interno', 'mantenimientos'),
            self::SERVICE_EXTERNAL => __('Externo (proveedor)', 'mantenimientos'),
        ];
    }

    public function prepareInputForAdd($input)
    {
        return $this->validateInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->validateInput($input);
    }

    private function validateInput(array $input): array|false
    {
        if (isset($input['itemtype']) && !array_key_exists($input['itemtype'], self::SUPPORTED_ITEMTYPES)) {
            \Session::addMessageAfterRedirect(__('Tipo de equipo no soportado', 'mantenimientos'), false, ERROR);
            return false;
        }
        if (isset($input['frequency_days']) && (int) $input['frequency_days'] < 1) {
            \Session::addMessageAfterRedirect(__('La frecuencia debe ser mayor a cero', 'mantenimientos'), false, ERROR);
            return false;
        }
        return $input;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([PlanTask::class]);
    }
}
