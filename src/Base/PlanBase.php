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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\PlanTask;
use Session;
use Supplier;

/**
 * Plan de mantenimiento preventivo por tipo de equipo.
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../Plan.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_plans
 */
abstract class PlanBase extends CommonDBTM
{
    public const SERVICE_INTERNAL = 1;
    public const SERVICE_EXTERNAL = 2;

    /** Tipos de equipo soportados en la fase 1 => clase de su subtipo (dropdown) */
    public const SUPPORTED_ITEMTYPES = [
        \Computer::class => \ComputerType::class, // PC / Portátil (subtipo)
        \Printer::class  => \PrinterType::class,
        \Phone::class    => \PhoneType::class,    // Teléfonos y tablets (subtipo)
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Plan de mantenimiento', 'Planes de mantenimiento', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-event';
    }

    public static function getServiceModes(): array
    {
        return [
            self::SERVICE_INTERNAL => __('Interno', 'mantenimientos'),
            self::SERVICE_EXTERNAL => __('Externo (proveedor)', 'mantenimientos'),
        ];
    }

    /**
     * Tipos de equipo para el selector: clase => nombre traducido por GLPI.
     */
    public static function getItemtypeChoices(): array
    {
        $choices = [];
        foreach (array_keys(self::SUPPORTED_ITEMTYPES) as $itemtype) {
            $choices[$itemtype] = $itemtype::getTypeName(1);
        }
        return $choices;
    }

    // ---------------------------------------------------------------------
    // Pestañas y formulario
    // ---------------------------------------------------------------------

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(PlanTask::class, $tabs, $options);
        $this->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $itemtype   = $this->fields['itemtype'] ?? '';
        $type_class = self::SUPPORTED_ITEMTYPES[$itemtype] ?? null;

        TemplateRenderer::getInstance()->display('@mantenimientos/plan.form.html.twig', [
            'item'          => $this,
            'params'        => $options,
            'itemtypes'     => self::getItemtypeChoices(),
            'type_class'    => $type_class,
            'types_ids'     => $this->getTypesIds(),
            'service_modes' => self::getServiceModes(),
        ]);

        return true;
    }

    // ---------------------------------------------------------------------
    // Subtipos (varios por plan; vacío = todos)
    // ---------------------------------------------------------------------

    /**
     * Subtipos del plan. Ej.: un plan "Portátiles" incluye Notebook,
     * Convertible y Laptop, tal como los crea el inventario de GLPI.
     *
     * @return int[]
     */
    public function getTypesIds(): array
    {
        return self::decodeTypesIds($this->fields['types_ids'] ?? null);
    }

    /**
     * @return int[]
     */
    public static function decodeTypesIds(mixed $raw): array
    {
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $ids = json_decode((string) $raw, true);
            if (!is_array($ids)) {
                return [];
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
        sort($ids);
        return $ids;
    }

    /**
     * ¿El plan aplica a un equipo de este tipo y subtipo?
     */
    public function appliesTo(string $itemtype, int $types_id): bool
    {
        if (($this->fields['itemtype'] ?? '') !== $itemtype) {
            return false;
        }
        $ids = $this->getTypesIds();
        return $ids === [] || in_array($types_id, $ids, true);
    }

    // ---------------------------------------------------------------------
    // Validación
    // ---------------------------------------------------------------------

    public function prepareInputForAdd($input)
    {
        return $this->validateInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        // Si cambia el tipo de equipo, los subtipos anteriores dejan de tener sentido
        if (
            isset($input['itemtype'], $this->fields['itemtype'])
            && $input['itemtype'] !== $this->fields['itemtype']
        ) {
            $input['types_ids'] = [];
            unset($input['_types_ids_sent']);
        }
        return $this->validateInput($input);
    }

    private function validateInput(array $input): array|false
    {
        // Subtipos: el formulario envía types_ids[]; si se quitan todos, el navegador
        // no envía nada, por eso el formulario incluye _types_ids_sent.
        if (array_key_exists('types_ids', $input) || isset($input['_types_ids_sent'])) {
            $input['types_ids'] = json_encode(self::decodeTypesIds($input['types_ids'] ?? []));
        }
        unset($input['_types_ids_sent']);

        if (array_key_exists('itemtype', $input) && !array_key_exists($input['itemtype'], self::SUPPORTED_ITEMTYPES)) {
            Session::addMessageAfterRedirect(__('Seleccione un tipo de equipo válido.', 'mantenimientos'), false, ERROR);
            return false;
        }
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('El nombre del plan es obligatorio.', 'mantenimientos'), false, ERROR);
            return false;
        }
        foreach (['frequency_days' => 1, 'tolerance_days' => 0] as $field => $min) {
            if (array_key_exists($field, $input) && (int) $input[$field] < $min) {
                Session::addMessageAfterRedirect(
                    $field === 'frequency_days'
                        ? __('La frecuencia debe ser de al menos 1 día.', 'mantenimientos')
                        : __('La tolerancia no puede ser negativa.', 'mantenimientos'),
                    false,
                    ERROR
                );
                return false;
            }
        }
        return $input;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([PlanTask::class]);
    }

    // ---------------------------------------------------------------------
    // Búsqueda (listado)
    // ---------------------------------------------------------------------

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(1),
        ];
        $tab[] = [
            'id'            => '1',
            'table'         => $this->getTable(),
            'field'         => 'name',
            'name'          => __('Name'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '2',
            'table'         => $this->getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '3',
            'table'         => $this->getTable(),
            'field'         => 'itemtype',
            'name'          => __('Tipo de equipo', 'mantenimientos'),
            'datatype'      => 'itemtypename',
            'types'         => array_keys(self::SUPPORTED_ITEMTYPES),
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => '9',
            'table'         => $this->getTable(),
            'field'         => 'types_ids',
            'name'          => __('Subtipos', 'mantenimientos'),
            'datatype'      => 'specific',
            'nosearch'      => true,
            'massiveaction' => false,
            'additionalfields' => ['itemtype'],
        ];
        $tab[] = [
            'id'       => '4',
            'table'    => $this->getTable(),
            'field'    => 'frequency_days',
            'name'     => __('Frecuencia (días)', 'mantenimientos'),
            'datatype' => 'number',
            'min'      => 1,
            'max'      => 3650,
        ];
        $tab[] = [
            'id'       => '5',
            'table'    => $this->getTable(),
            'field'    => 'tolerance_days',
            'name'     => __('Tolerancia (días)', 'mantenimientos'),
            'datatype' => 'number',
            'min'      => 0,
            'max'      => 365,
        ];
        $tab[] = [
            'id'         => '6',
            'table'      => $this->getTable(),
            'field'      => 'service_mode',
            'name'       => __('Servicio', 'mantenimientos'),
            'datatype'   => 'specific',
            'searchtype' => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id'       => '7',
            'table'    => Supplier::getTable(),
            'field'    => 'name',
            'name'     => __('Proveedor por defecto', 'mantenimientos'),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => '8',
            'table'    => $this->getTable(),
            'field'    => 'is_active',
            'name'     => __('Active'),
            'datatype' => 'bool',
        ];
        $tab[] = [
            'id'       => '16',
            'table'    => $this->getTable(),
            'field'    => 'comment',
            'name'     => __('Comments'),
            'datatype' => 'text',
        ];
        $tab[] = [
            'id'            => '19',
            'table'         => $this->getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'       => '80',
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => \Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];
        $tab[] = [
            'id'       => '86',
            'table'    => $this->getTable(),
            'field'    => 'is_recursive',
            'name'     => __('Child entities'),
            'datatype' => 'bool',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'service_mode') {
            return Compat::e(self::getServiceModes()[(int) $values[$field]] ?? '');
        }
        if ($field === 'types_ids') {
            $type_class = self::SUPPORTED_ITEMTYPES[$values['itemtype'] ?? ''] ?? null;
            $ids        = self::decodeTypesIds($values[$field]);
            if ($type_class === null || $ids === []) {
                return Compat::e(__('Todos', 'mantenimientos'));
            }
            $names = [];
            foreach ($ids as $id) {
                $names[] = \Dropdown::getDropdownName($type_class::getTable(), $id);
            }
            return Compat::e(implode(', ', $names), true);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'service_mode') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return \Dropdown::showFromArray($name, self::getServiceModes(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
