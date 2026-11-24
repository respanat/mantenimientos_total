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
use CommonGLPI;
use CronTask;
use DbUtils;
use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Config;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Plan;
use Html;
use Session;

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
        return _n('Equipo', 'Equipos', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-traffic-lights';
    }

    // ---------------------------------------------------------------------
    // Semáforo
    // ---------------------------------------------------------------------

    /**
     * Definición visual del semáforo. "color"/"text" para etiquetas pequeñas,
     * "tile"/"tile_text" para los mosaicos de los tableros.
     *
     * @return array<string, array{label: string, color: string, text: string, tile: string, tile_text: string, icon: string, hint: string}>
     */
    public static function getLights(): array
    {
        $warning  = (int) Config::getValue('threshold_warning_days', 30);
        $critical = (int) Config::getValue('threshold_critical_days', 7);

        return [
            self::LIGHT_OVERDUE  => [
                'label' => __('Vencido', 'mantenimientos'), 'color' => '#d63939', 'text' => '#fff',
                'tile'  => '#f07575', 'tile_text' => 'rgba(0,0,0,.75)', 'icon' => 'ti ti-alert-octagon',
                'hint'  => __('El preventivo ya pasó su fecha', 'mantenimientos'),
            ],
            self::LIGHT_CRITICAL => [
                'label' => __('Muy próximo', 'mantenimientos'), 'color' => '#f76707', 'text' => '#fff',
                'tile'  => '#ffb066', 'tile_text' => 'rgba(0,0,0,.72)', 'icon' => 'ti ti-alert-triangle',
                'hint'  => sprintf(__('Faltan %d días o menos', 'mantenimientos'), $critical),
            ],
            self::LIGHT_WARNING  => [
                'label' => __('Próximo', 'mantenimientos'), 'color' => '#f59f00', 'text' => '#1d273b',
                'tile'  => '#ffd95a', 'tile_text' => 'rgba(0,0,0,.72)', 'icon' => 'ti ti-clock',
                'hint'  => sprintf(__('Faltan %d días o menos', 'mantenimientos'), $warning),
            ],
            self::LIGHT_OK       => [
                'label' => __('Al día', 'mantenimientos'), 'color' => '#2fb344', 'text' => '#fff',
                'tile'  => '#6fd06c', 'tile_text' => 'rgba(0,0,0,.72)', 'icon' => 'ti ti-circle-check',
                'hint'  => sprintf(__('Faltan más de %d días', 'mantenimientos'), $warning),
            ],
            self::LIGHT_REPAIR   => [
                'label' => __('En correctivo', 'mantenimientos'), 'color' => '#4263eb', 'text' => '#fff',
                'tile'  => '#6495d6', 'tile_text' => '#fff', 'icon' => 'ti ti-tool',
                'hint'  => __('Equipo con una reparación abierta', 'mantenimientos'),
            ],
            self::LIGHT_NOPLAN   => [
                'label' => __('Sin plan', 'mantenimientos'), 'color' => '#adb5bd', 'text' => '#1d273b',
                'tile'  => '#d6d6d6', 'tile_text' => 'rgba(0,0,0,.6)', 'icon' => 'ti ti-circle-dashed',
                'hint'  => __('Sin plan que aplique, plan inactivo o equipo excluido', 'mantenimientos'),
            ],
        ];
    }

    public static function lightBadge(string $light): string
    {
        $def = self::getLights()[$light] ?? self::getLights()[self::LIGHT_NOPLAN];
        return sprintf(
            "<span class='badge' style='background:%s;color:%s'>%s</span>",
            $def['color'],
            $def['text'],
            Compat::e($def['label'])
        );
    }

    /**
     * Días que faltan (negativo = días de atraso). null si no hay fecha.
     */
    public static function daysUntil(?string $date, ?\DateTimeImmutable $today = null): ?int
    {
        if (empty($date)) {
            return null;
        }
        $today ??= new \DateTimeImmutable('today');
        return (int) $today->diff(new \DateTimeImmutable($date))->format('%r%a');
    }

    /**
     * Calcula el color del semáforo a partir de la próxima fecha.
     */
    public static function computeLight(?string $next_due_date, ?\DateTimeImmutable $today = null): string
    {
        $days = self::daysUntil($next_due_date, $today);
        if ($days === null) {
            return self::LIGHT_NOPLAN;
        }

        $warning  = (int) Config::getValue('threshold_warning_days', 30);
        $critical = (int) Config::getValue('threshold_critical_days', 7);

        return match (true) {
            $days < 0          => self::LIGHT_OVERDUE,
            $days <= $critical => self::LIGHT_CRITICAL,
            $days <= $warning  => self::LIGHT_WARNING,
            default            => self::LIGHT_OK,
        };
    }

    // ---------------------------------------------------------------------
    // Asignación de planes y cálculo de fechas
    // ---------------------------------------------------------------------

    /**
     * Fecha base cuando el equipo no tiene preventivos registrados:
     * fecha de puesta en servicio > fecha de compra > fecha de alta en GLPI.
     */
    private static function baseDate(?string $use_date, ?string $buy_date, ?string $date_creation): string
    {
        foreach ([$use_date, $buy_date, $date_creation] as $candidate) {
            if (!empty($candidate) && !str_starts_with((string) $candidate, '0000')) {
                return substr((string) $candidate, 0, 10);
            }
        }
        return date('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Sincroniza los equipos con los planes activos:
     *  - asigna a cada equipo el plan que le corresponde (los planes con subtipos
     *    específicos tienen prioridad sobre los de "todos los subtipos");
     *  - calcula la próxima fecha = último preventivo (o fecha base) + frecuencia;
     *  - respeta los equipos excluidos.
     *
     * @return array{assigned: int, updated: int, unassigned: int, total: int}
     */
    public static function syncAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $stats  = ['assigned' => 0, 'updated' => 0, 'unassigned' => 0, 'total' => 0, 'out_of_service' => 0];
        $dbu    = new DbUtils();
        $now    = date('Y-m-d H:i:s');
        $table  = Item::getTable();

        // Planes activos, los específicos primero
        $plans = [];
        foreach ($DB->request(['FROM' => Plan::getTable(), 'WHERE' => ['is_active' => 1], 'ORDER' => 'id']) as $row) {
            $row['_types']    = Plan::decodeTypesIds($row['types_ids'] ?? null);
            $row['_entities'] = $row['is_recursive']
                ? array_map('intval', array_values($dbu->getSonsOf('glpi_entities', (int) $row['entities_id'])))
                : [(int) $row['entities_id']];
            $plans[] = $row;
        }
        usort($plans, fn ($a, $b) => [count($a['_types']) === 0, $a['id']] <=> [count($b['_types']) === 0, $b['id']]);

        foreach (Plan::SUPPORTED_ITEMTYPES as $itemtype => $type_class) {
            $asset_table = $itemtype::getTable();
            $type_field  = $type_class::getForeignKeyField();

            // Registros existentes del plugin para este tipo
            $existing = [];
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['itemtype' => $itemtype]]) as $row) {
                $existing[(int) $row['items_id']] = $row;
            }

            // Fechas de compra / puesta en servicio
            $infocoms = [];
            foreach (
                $DB->request([
                    'SELECT' => ['items_id', 'use_date', 'buy_date'],
                    'FROM'   => 'glpi_infocoms',
                    'WHERE'  => ['itemtype' => $itemtype],
                ]) as $row
            ) {
                $infocoms[(int) $row['items_id']] = $row;
            }

            $assets = $DB->request([
                'SELECT' => ['id', 'entities_id', $type_field, 'date_creation'],
                'FROM'   => $asset_table,
                'WHERE'  => ['is_deleted' => 0, 'is_template' => 0] + self::inServiceCriteria($itemtype),
            ]);

            $seen = [];
            foreach ($assets as $asset) {
                $stats['total']++;
                $id       = (int) $asset['id'];
                $seen[$id] = true;
                $row      = $existing[$id] ?? null;
                $types_id = (int) $asset[$type_field];
                $entity   = (int) $asset['entities_id'];

                if ($row !== null && (int) $row['is_excluded'] === 1) {
                    continue;
                }

                $plan = null;
                foreach ($plans as $candidate) {
                    if (
                        $candidate['itemtype'] === $itemtype
                        && in_array($entity, $candidate['_entities'], true)
                        && ($candidate['_types'] === [] || in_array($types_id, $candidate['_types'], true))
                    ) {
                        $plan = $candidate;
                        break;
                    }
                }

                if ($plan === null) {
                    if ($row !== null && (int) $row['plugin_mantenimientos_plans_id'] > 0) {
                        $DB->update($table, [
                            'plugin_mantenimientos_plans_id' => 0,
                            'next_due_date' => null,
                            'date_mod'      => $now,
                        ], ['id' => $row['id']]);
                        $stats['unassigned']++;
                    }
                    continue;
                }

                $frequency = (int) ($row['frequency_days_override'] ?? 0) > 0
                    ? (int) $row['frequency_days_override']
                    : (int) $plan['frequency_days'];
                $last = $row['last_preventive_date'] ?? null;
                $base = !empty($last)
                    ? $last
                    : self::baseDate(
                        $infocoms[$id]['use_date'] ?? null,
                        $infocoms[$id]['buy_date'] ?? null,
                        $asset['date_creation'] ?? null
                    );
                $next = self::addDays($base, $frequency);

                if ($row === null) {
                    $DB->insert($table, [
                        'itemtype'                       => $itemtype,
                        'items_id'                       => $id,
                        'plugin_mantenimientos_plans_id' => (int) $plan['id'],
                        'next_due_date'                  => $next,
                        'date_creation'                  => $now,
                        'date_mod'                       => $now,
                    ]);
                    $stats['assigned']++;
                } elseif (
                    (int) $row['plugin_mantenimientos_plans_id'] !== (int) $plan['id']
                    || ($row['next_due_date'] ?? null) !== $next
                ) {
                    $DB->update($table, [
                        'plugin_mantenimientos_plans_id' => (int) $plan['id'],
                        'next_due_date'                  => $next,
                        'date_mod'                       => $now,
                    ], ['id' => $row['id']]);
                    $stats['updated']++;
                }
            }

            // Equipos que salieron de servicio (estado excluido o sin inventario):
            // pierden el plan; así no cuentan en el semáforo ni reciben avisos.
            foreach ($existing as $items_id => $row) {
                if (isset($seen[$items_id])) {
                    continue;
                }
                $stats['out_of_service']++;
                if ((int) $row['plugin_mantenimientos_plans_id'] > 0 || !empty($row['next_due_date'])) {
                    $DB->update($table, [
                        'plugin_mantenimientos_plans_id' => 0,
                        'next_due_date'                  => null,
                        'date_mod'                       => $now,
                    ], ['id' => $row['id']]);
                    $stats['unassigned']++;
                }
            }
        }

        return $stats;
    }

    // ---------------------------------------------------------------------
    // Equipos en servicio (filtro global de mantenimientos)
    // ---------------------------------------------------------------------

    /**
     * Estados de GLPI (Activos > Estado) que dejan al equipo fuera de servicio:
     * no recibe plan, no cuenta en el semáforo ni en las métricas y no recibe avisos.
     *
     * @return int[]
     */
    public static function getExcludedStates(): array
    {
        return Plan::decodeTypesIds(Config::getValue('excluded_states', '[]'));
    }

    /**
     * Días sin inventario para considerar fuera de servicio un computador (0 = no aplica).
     * Solo computadores: los teléfonos y tablets casi nunca se inventarían por agente.
     */
    public static function getStaleDays(): int
    {
        return max(0, (int) Config::getValue('stale_inventory_days', 0));
    }

    /**
     * Criterios SQL (DBmysqlIterator) de "equipo en servicio" para un tipo de equipo.
     *
     * @return array<string, mixed>
     */
    public static function inServiceCriteria(string $itemtype, string $alias = ''): array
    {
        $p    = $alias !== '' ? $alias . '.' : '';
        $and  = [];
        $excl = self::getExcludedStates();
        if ($excl !== []) {
            $and[] = ['NOT' => [$p . 'states_id' => $excl]];
        }
        $days = self::getStaleDays();
        if ($days > 0 && $itemtype === \Computer::class) {
            $limit = date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $days)));
            $and[] = ['OR' => [
                [$p . 'last_inventory_update' => null],
                [$p . 'last_inventory_update' => ['>=', $limit]],
            ]];
        }
        return $and === [] ? [] : ['AND' => $and];
    }

    /**
     * ¿El equipo está en servicio? Devuelve null si sí, o el motivo si no.
     */
    public static function outOfServiceReason(\CommonDBTM $asset): ?string
    {
        $state = (int) ($asset->fields['states_id'] ?? 0);
        if ($state > 0 && in_array($state, self::getExcludedStates(), true)) {
            return sprintf(
                __('Estado "%s" configurado como fuera de servicio', 'mantenimientos'),
                html_entity_decode((string) \Dropdown::getDropdownName('glpi_states', $state), ENT_QUOTES, 'UTF-8')
            );
        }
        $days = self::getStaleDays();
        $last = (string) ($asset->fields['last_inventory_update'] ?? '');
        if ($days > 0 && $asset instanceof \Computer && $last !== '' && strtotime($last) < strtotime(sprintf('-%d days', $days))) {
            return sprintf(__('Sin inventario desde %s (más de %d días)', 'mantenimientos'), Html::convDate(substr($last, 0, 10)), $days);
        }
        return null;
    }

    /**
     * Cuántos equipos quedan fuera de servicio por la configuración actual.
     */
    public static function countOutOfService(bool $all_entities = false): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $dbu = new DbUtils();
        $out = 0;
        foreach (array_keys(Plan::SUPPORTED_ITEMTYPES) as $itemtype) {
            $table = $itemtype::getTable();
            $base  = ['is_deleted' => 0, 'is_template' => 0]
                + ($all_entities ? [] : $dbu->getEntitiesRestrictCriteria($table, '', '', true));
            $all = (int) $DB->request(['COUNT' => 'cpt', 'FROM' => $table, 'WHERE' => $base])->current()['cpt'];
            $in  = (int) $DB->request(['COUNT' => 'cpt', 'FROM' => $table, 'WHERE' => $base + self::inServiceCriteria($itemtype)])->current()['cpt'];
            $out += max(0, $all - $in);
        }
        return $out;
    }

    /**
     * Recalcula un solo equipo (tras editar su frecuencia, exclusión o último preventivo).
     */
    public static function syncOne(string $itemtype, int $items_id): void
    {
        // La sincronización completa es barata y garantiza las mismas reglas.
        self::syncAll();
    }

    // ---------------------------------------------------------------------
    // Consultas para el listado y el tablero
    // ---------------------------------------------------------------------

    /**
     * Equipos con su plan, usuario asignado y semáforo, dentro de las entidades
     * activas del usuario. Ordenados del más atrasado al más holgado.
     *
     * @param array{itemtype?: string, light?: string, plans_id?: int, states_id?: int} $filters
     * @param bool $all_entities true = sin restringir por las entidades de la sesión (tarea programada)
     * @return list<array<string, mixed>>
     */
    public static function getRows(array $filters = [], bool $all_entities = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $dbu  = new DbUtils();
        $rows = [];

        foreach (Plan::SUPPORTED_ITEMTYPES as $itemtype => $type_class) {
            if (!empty($filters['itemtype']) && $filters['itemtype'] !== $itemtype) {
                continue;
            }
            $asset_table = $itemtype::getTable();
            $type_field  = $type_class::getForeignKeyField();

            $where = [
                'i.itemtype'    => $itemtype,
                'a.is_deleted'  => 0,
                'a.is_template' => 0,
            ] + ($all_entities ? [] : $dbu->getEntitiesRestrictCriteria('a', '', '', true))
              + self::inServiceCriteria($itemtype, 'a');
            if (!empty($filters['plans_id'])) {
                $where['i.plugin_mantenimientos_plans_id'] = (int) $filters['plans_id'];
            }
            if (!empty($filters['states_id'])) {
                $where['a.states_id'] = (int) $filters['states_id'];
            }

            $iterator = $DB->request([
                'SELECT' => [
                    'i.id', 'i.items_id', 'i.itemtype', 'i.plugin_mantenimientos_plans_id',
                    'i.last_preventive_date', 'i.next_due_date', 'i.is_excluded',
                    'a.name AS asset_name', 'a.users_id', 'a.states_id',
                    's.completename AS state_name',
                    'p.name AS plan_name',
                    't.name AS type_name',
                    'u.name AS user_login', 'u.realname', 'u.firstname',
                ],
                'FROM'      => Item::getTable() . ' AS i',
                'INNER JOIN' => [
                    $asset_table . ' AS a' => ['ON' => ['i' => 'items_id', 'a' => 'id']],
                ],
                'LEFT JOIN' => [
                    Plan::getTable() . ' AS p'           => ['ON' => ['i' => 'plugin_mantenimientos_plans_id', 'p' => 'id']],
                    $type_class::getTable() . ' AS t'    => ['ON' => ['a' => $type_field, 't' => 'id']],
                    'glpi_users AS u'                    => ['ON' => ['a' => 'users_id', 'u' => 'id']],
                    'glpi_states AS s'                   => ['ON' => ['a' => 'states_id', 's' => 'id']],
                ],
                'WHERE' => $where,
            ]);

            foreach ($iterator as $row) {
                $has_plan = (int) $row['plugin_mantenimientos_plans_id'] > 0 && !(int) $row['is_excluded'];
                $row['light'] = $has_plan ? self::computeLight($row['next_due_date']) : self::LIGHT_NOPLAN;
                $row['days']  = $has_plan ? self::daysUntil($row['next_due_date']) : null;
                $row['user']  = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? '')) ?: ($row['user_login'] ?? '');

                if (!empty($filters['light']) && $filters['light'] !== $row['light']) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        usort($rows, fn ($a, $b) => [$a['days'] === null, $a['days']] <=> [$b['days'] === null, $b['days']]);
        return $rows;
    }

    /**
     * Conteo por color del semáforo. "Sin plan" incluye los equipos que nunca
     * quedaron asociados a un plan.
     *
     * @return array<string, int>
     */
    public static function getCounts(bool $all_entities = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $counts = array_fill_keys(array_keys(self::getLights()), 0);
        $with_record = 0;
        foreach (self::getRows([], $all_entities) as $row) {
            $counts[$row['light']]++;
            $with_record++;
        }

        // Equipos sin registro en el plugin
        $dbu   = new DbUtils();
        $total = 0;
        foreach (array_keys(Plan::SUPPORTED_ITEMTYPES) as $itemtype) {
            $total += (int) $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => $itemtype::getTable(),
                'WHERE' => ['is_deleted' => 0, 'is_template' => 0]
                    + ($all_entities ? [] : $dbu->getEntitiesRestrictCriteria($itemtype::getTable(), '', '', true))
                    + self::inServiceCriteria($itemtype),
            ])->current()['cpt'];
        }
        $counts[self::LIGHT_NOPLAN] += max(0, $total - $with_record);

        return $counts;
    }

    // ---------------------------------------------------------------------
    // Foto diaria del semáforo (para la gráfica de tendencia)
    // ---------------------------------------------------------------------

    public const SNAPSHOT_TABLE = 'glpi_plugin_mantenimientos_snapshots';

    /**
     * Guarda (o actualiza) la foto del día con los conteos de todo el parque.
     */
    public static function recordSnapshot(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::SNAPSHOT_TABLE)) {
            return;
        }
        $counts = self::getCounts(true);
        $today  = date('Y-m-d');
        $data   = [];
        foreach (array_keys(self::getLights()) as $key) {
            $data[$key] = (int) ($counts[$key] ?? 0);
        }
        $data['date_mod'] = date('Y-m-d H:i:s');

        $exists = $DB->request(['SELECT' => ['id'], 'FROM' => self::SNAPSHOT_TABLE, 'WHERE' => ['snapshot_date' => $today]])->current();
        if ($exists) {
            $DB->update(self::SNAPSHOT_TABLE, $data, ['id' => $exists['id']]);
        } else {
            $DB->insert(self::SNAPSHOT_TABLE, ['snapshot_date' => $today] + $data);
        }
    }

    /**
     * Garantiza que exista la foto de hoy (por si la tarea programada no ha corrido).
     */
    public static function ensureTodaySnapshot(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (
            $DB->tableExists(self::SNAPSHOT_TABLE)
            && countElementsInTable(self::SNAPSHOT_TABLE, ['snapshot_date' => date('Y-m-d')]) === 0
        ) {
            self::recordSnapshot();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getSnapshots(string $from): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!$DB->tableExists(self::SNAPSHOT_TABLE)) {
            return [];
        }
        return iterator_to_array($DB->request([
            'FROM'  => self::SNAPSHOT_TABLE,
            'WHERE' => ['snapshot_date' => ['>=', $from]],
            'ORDER' => 'snapshot_date ASC',
        ]), false);
    }

    // ---------------------------------------------------------------------
    // Tarea programada (diaria)
    // ---------------------------------------------------------------------

    public static function cronInfo($name)
    {
        if ($name === 'syncItems') {
            return ['description' => __('Mantenimientos: asignar planes a equipos y recalcular fechas', 'mantenimientos')];
        }
        return [];
    }

    public static function cronSyncItems($task)
    {
        $stats = self::syncAll();
        self::recordSnapshot();
        if ($task instanceof CronTask) {
            $task->addVolume($stats['assigned'] + $stats['updated'] + $stats['unassigned']);
            $task->log(sprintf(
                'Equipos: %d, asignados: %d, actualizados: %d, sin plan: %d',
                $stats['total'],
                $stats['assigned'],
                $stats['updated'],
                $stats['unassigned']
            ));
        }
        return 1;
    }

    // ---------------------------------------------------------------------
    // Pestaña "Mantenimientos" en la ficha del computador / teléfono
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (
            array_key_exists($item::class, Plan::SUPPORTED_ITEMTYPES)
            && !$item->isNewItem()
            && Session::haveRight(Maintenance::$rightname, READ)
        ) {
            return self::createTabEntry(_n('Mantenimiento', 'Mantenimientos', 2, 'mantenimientos'), 0, null, Maintenance::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonDBTM) {
            self::showForAsset($item);
        }
        return true;
    }

    public static function showForAsset(CommonDBTM $asset): void
    {
        $record = new Item();
        $found  = $record->getFromDBByCrit(['itemtype' => $asset::class, 'items_id' => $asset->getID()]);

        $plan = new Plan();
        $has_plan = $found && $plan->getFromDB((int) $record->fields['plugin_mantenimientos_plans_id']);

        $next  = $found ? ($record->fields['next_due_date'] ?? null) : null;
        $light = $found && (int) $record->fields['is_excluded'] === 1 ? self::LIGHT_NOPLAN : self::computeLight($next);
        $days  = self::daysUntil($next);

        $out_reason = self::outOfServiceReason($asset);
        if ($out_reason !== null) {
            echo "<div class='alert alert-secondary mt-2'><i class='ti ti-plug-connected-x me-1'></i><b>"
                . Compat::e(__('Fuera de servicio', 'mantenimientos')) . ":</b> " . Compat::e($out_reason) . ". "
                . Compat::e(__('No recibe plan, no cuenta en el semáforo ni en las métricas y no recibe avisos.', 'mantenimientos'))
                . "</div>";
        }

        echo "<div class='card mt-2'><div class='card-body'>";
        echo "<div class='d-flex align-items-center mb-3'>" . self::lightBadge($light);
        if ($days !== null) {
            echo "<span class='ms-2 text-muted'>" . Compat::e(
                $days < 0
                    ? sprintf(__('%d días de atraso', 'mantenimientos'), -$days)
                    : sprintf(__('faltan %d días', 'mantenimientos'), $days)
            ) . "</span>";
        }
        echo "</div>";

        echo "<dl class='row mb-0'>";
        $rows = [
            __('Plan', 'mantenimientos') => $has_plan
                ? "<a href='" . Compat::e(Plan::getFormURLWithID($plan->getID())) . "'>" . Compat::e($plan->fields['name'], true) . "</a>"
                : Compat::e(__('Ninguno (sin plan que aplique o equipo excluido)', 'mantenimientos')),
            __('Último preventivo', 'mantenimientos') => Compat::e(
                $found && !empty($record->fields['last_preventive_date'])
                    ? Html::convDate($record->fields['last_preventive_date'])
                    : __('Sin registro', 'mantenimientos')
            ),
            __('Próximo preventivo', 'mantenimientos') => Compat::e($next ? Html::convDate($next) : '—'),
        ];
        foreach ($rows as $label => $value) {
            echo "<dt class='col-sm-3'>" . Compat::e($label) . "</dt><dd class='col-sm-9'>{$value}</dd>";
        }
        echo "</dl></div></div>";

        // Registrar / continuar preventivo + historial del equipo
        Maintenance::showHistoryForAsset($asset);

        if (!$found || !Session::haveRight(Plan::$rightname, UPDATE)) {
            return;
        }

        // Ajustes del equipo
        $id = (int) $record->getID();
        echo "<form method='post' action='" . Compat::e(Item::getFormURL()) . "' class='card mt-3'><div class='card-body'>";
        echo "<h4 class='card-title'>" . Compat::e(__('Ajustes de este equipo', 'mantenimientos')) . "</h4>";
        echo "<input type='hidden' name='id' value='{$id}'>";
        echo "<div class='row g-3'>";

        echo "<div class='col-md-4'><label class='form-label'>" . Compat::e(__('Último preventivo (histórico)', 'mantenimientos')) . "</label>";
        echo "<input type='date' name='last_preventive_date' class='form-control' value='"
            . Compat::e((string) ($record->fields['last_preventive_date'] ?? '')) . "'>";
        echo "<div class='form-hint'>" . Compat::e(__('Para cargar el último preventivo hecho antes de usar el plugin.', 'mantenimientos')) . "</div></div>";

        echo "<div class='col-md-4'><label class='form-label'>" . Compat::e(__('Frecuencia propia (días)', 'mantenimientos')) . "</label>";
        echo "<input type='number' min='0' max='3650' name='frequency_days_override' class='form-control' value='"
            . (int) $record->fields['frequency_days_override'] . "'>";
        echo "<div class='form-hint'>" . Compat::e(__('0 = usar la del plan.', 'mantenimientos')) . "</div></div>";

        echo "<div class='col-md-4'><label class='form-label'>" . Compat::e(__('Excluir del plan', 'mantenimientos')) . "</label>";
        \Dropdown::showYesNo('is_excluded', (int) $record->fields['is_excluded'], -1, ['width' => '100%']);
        echo "</div>";

        echo "<div class='col-12'><label class='form-label'>" . Compat::e(__('Motivo de exclusión', 'mantenimientos')) . "</label>";
        echo "<input type='text' maxlength='255' name='exclusion_reason' class='form-control' value='"
            . Compat::e((string) ($record->fields['exclusion_reason'] ?? ''), true) . "'></div>";

        echo "</div></div><div class='card-footer text-end'>";
        echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>"
            . Compat::e(_x('button', 'Save')) . "</button></div>";
        Html::closeForm();
    }

    public function prepareInputForUpdate($input)
    {
        if (array_key_exists('last_preventive_date', $input) && $input['last_preventive_date'] === '') {
            $input['last_preventive_date'] = 'NULL';
        }
        if (isset($input['frequency_days_override'])) {
            $input['frequency_days_override'] = max(0, (int) $input['frequency_days_override']);
        }
        if (isset($input['is_excluded']) && (int) $input['is_excluded'] === 1) {
            $input['next_due_date'] = 'NULL';
        }
        return $input;
    }

    public function post_updateItem($history = true)
    {
        self::syncOne((string) $this->fields['itemtype'], (int) $this->fields['items_id']);
    }
}
