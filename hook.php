<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Instalación y desinstalación: tablas, configuración por defecto y permisos.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

/**
 * Permisos del plugin y valor que recibe el perfil Super-Admin al instalar.
 */
function plugin_mantenimientos_rights(): array
{
    return [
        'plugin_mantenimientos_plan'        => ALLSTANDARDRIGHT, // Planes y checklists
        'plugin_mantenimientos_maintenance' => ALLSTANDARDRIGHT, // Mantenimientos, checklist ejecutado, costos
        'plugin_mantenimientos_metrics'     => READ,             // Seguimiento y control
        'plugin_mantenimientos_config'      => READ | UPDATE,    // Configuración del plugin
    ];
}

/**
 * Esquema de tablas propias del plugin.
 * Convenciones GLPI 11: InnoDB, utf8mb4, claves unsigned, fechas TIMESTAMP.
 */
function plugin_mantenimientos_schema(string $sign): array
{
    $fk = "int {$sign} NOT NULL DEFAULT '0'";

    return [
        // Planes por tipo de equipo
        'glpi_plugin_mantenimientos_plans' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` {$fk},
            `is_recursive` tinyint NOT NULL DEFAULT '0',
            `name` varchar(255) DEFAULT NULL,
            `itemtype` varchar(100) NOT NULL DEFAULT '' COMMENT 'Computer, Phone',
            `types_ids` text COMMENT 'JSON con los subtipos (ej. ComputerType Notebook, Convertible). Vacío = todos',
            `frequency_days` int NOT NULL DEFAULT '180',
            `tolerance_days` int NOT NULL DEFAULT '15',
            `service_mode` tinyint NOT NULL DEFAULT '1' COMMENT '1 interno, 2 externo',
            `suppliers_id` {$fk} COMMENT 'Proveedor por defecto si es externo',
            `is_active` tinyint NOT NULL DEFAULT '1',
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `itemtype` (`itemtype`),
            KEY `suppliers_id` (`suppliers_id`),
            KEY `is_active` (`is_active`)
        ",

        // Plantilla de checklist del plan
        'glpi_plugin_mantenimientos_plantasks' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_mantenimientos_plans_id` {$fk},
            `name` varchar(255) DEFAULT NULL,
            `description` text,
            `rank` int NOT NULL DEFAULT '0',
            `is_mandatory` tinyint NOT NULL DEFAULT '1',
            `is_active` tinyint NOT NULL DEFAULT '1',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_mantenimientos_plans_id` (`plugin_mantenimientos_plans_id`),
            KEY `rank` (`rank`)
        ",

        // Relación equipo ↔ plan y estado calculado del preventivo
        'glpi_plugin_mantenimientos_items' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` {$fk},
            `plugin_mantenimientos_plans_id` {$fk},
            `frequency_days_override` int NOT NULL DEFAULT '0' COMMENT '0 = usa la del plan',
            `last_preventive_date` date DEFAULT NULL,
            `next_due_date` date DEFAULT NULL,
            `is_excluded` tinyint NOT NULL DEFAULT '0',
            `exclusion_reason` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item` (`itemtype`, `items_id`),
            KEY `plugin_mantenimientos_plans_id` (`plugin_mantenimientos_plans_id`),
            KEY `next_due_date` (`next_due_date`)
        ",

        // Registro principal: preventivos y correctivos
        'glpi_plugin_mantenimientos_maintenances' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` {$fk},
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` {$fk},
            `maintenance_type` tinyint NOT NULL DEFAULT '1' COMMENT '1 preventivo, 2 correctivo',
            `status` int NOT NULL DEFAULT '1',
            `plugin_mantenimientos_plans_id` {$fk},
            `plugin_mantenimientos_maintenances_id_parent` {$fk} COMMENT 'Preventivo que originó el correctivo',
            `tickets_id` {$fk} COMMENT 'Enlace opcional a un ticket, solo trazabilidad',
            `scheduled_date` date DEFAULT NULL,
            `date_start` timestamp NULL DEFAULT NULL,
            `date_end` timestamp NULL DEFAULT NULL,
            `users_id_tech` {$fk},
            `users_id_assigned` {$fk} COMMENT 'Usuario asignado al equipo en ese momento',
            `reschedule_count` int NOT NULL DEFAULT '0',
            `cancel_reason` text,
            `observations` text,
            `diagnosis` text,
            `solution` text,
            `result` tinyint NOT NULL DEFAULT '0' COMMENT '0 n/a, 1 reparado, 2 no reparable',
            `is_external` tinyint NOT NULL DEFAULT '0',
            `suppliers_id` {$fk},
            `supplier_reference` varchar(255) DEFAULT NULL COMMENT 'Orden o radicado del proveedor',
            `date_sent` timestamp NULL DEFAULT NULL,
            `date_returned` timestamp NULL DEFAULT NULL,
            `users_id_creator` {$fk},
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `type_status` (`maintenance_type`, `status`),
            KEY `plugin_mantenimientos_plans_id` (`plugin_mantenimientos_plans_id`),
            KEY `plugin_mantenimientos_maintenances_id_parent` (`plugin_mantenimientos_maintenances_id_parent`),
            KEY `tickets_id` (`tickets_id`),
            KEY `scheduled_date` (`scheduled_date`),
            KEY `users_id_tech` (`users_id_tech`),
            KEY `users_id_assigned` (`users_id_assigned`),
            KEY `suppliers_id` (`suppliers_id`)
        ",

        // Checklist ejecutado (copia de la tarea para conservar historial)
        'glpi_plugin_mantenimientos_checks' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_mantenimientos_maintenances_id` {$fk},
            `plugin_mantenimientos_plantasks_id` {$fk},
            `name` varchar(255) DEFAULT NULL,
            `is_mandatory` tinyint NOT NULL DEFAULT '1',
            `result` tinyint NOT NULL DEFAULT '0' COMMENT '0 pendiente, 1 hecho, 2 no hecho, 3 no aplica',
            `comment` text,
            `users_id` {$fk},
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_mantenimientos_maintenances_id` (`plugin_mantenimientos_maintenances_id`),
            KEY `plugin_mantenimientos_plantasks_id` (`plugin_mantenimientos_plantasks_id`)
        ",

        // Costos por mantenimiento
        'glpi_plugin_mantenimientos_costs' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_mantenimientos_maintenances_id` {$fk},
            `cost_type` tinyint NOT NULL DEFAULT '1' COMMENT '1 repuesto, 2 mano de obra, 3 servicio externo, 4 envío',
            `description` varchar(255) DEFAULT NULL,
            `quantity` decimal(20,4) NOT NULL DEFAULT '1.0000',
            `unit_cost` decimal(20,4) NOT NULL DEFAULT '0.0000',
            `total` decimal(20,4) NOT NULL DEFAULT '0.0000',
            `suppliers_id` {$fk},
            `cost_date` date DEFAULT NULL,
            `users_id` {$fk},
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_mantenimientos_maintenances_id` (`plugin_mantenimientos_maintenances_id`),
            KEY `cost_type` (`cost_type`),
            KEY `suppliers_id` (`suppliers_id`),
            KEY `cost_date` (`cost_date`)
        ",

        // Bitácora de cambios de estado (base de las métricas de tiempos)
        'glpi_plugin_mantenimientos_statuslogs' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_mantenimientos_maintenances_id` {$fk},
            `old_status` int NOT NULL DEFAULT '0',
            `new_status` int NOT NULL DEFAULT '0',
            `comment` text,
            `users_id` {$fk},
            `date` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_mantenimientos_maintenances_id` (`plugin_mantenimientos_maintenances_id`),
            KEY `date` (`date`)
        ",

        // Foto diaria del semáforo (tendencia del estado del parque)
        'glpi_plugin_mantenimientos_snapshots' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `snapshot_date` date NOT NULL,
            `overdue` int NOT NULL DEFAULT '0',
            `critical` int NOT NULL DEFAULT '0',
            `warning` int NOT NULL DEFAULT '0',
            `ok` int NOT NULL DEFAULT '0',
            `repair` int NOT NULL DEFAULT '0',
            `noplan` int NOT NULL DEFAULT '0',
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `snapshot_date` (`snapshot_date`)
        ",

        // Avisos al usuario en Windows (fase 3): uno por equipo y motivo.
        // notice_key identifica el motivo (ej. due:2026-12-01:critical); si el
        // semáforo escala, cambia la clave y el aviso se vuelve a mostrar.
        'glpi_plugin_mantenimientos_notices' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` {$fk},
            `notice_key` varchar(100) NOT NULL DEFAULT '',
            `level` varchar(20) NOT NULL DEFAULT '' COMMENT 'warning, critical, overdue, scheduled',
            `title` varchar(255) DEFAULT NULL,
            `message` text,
            `times_shown` int NOT NULL DEFAULT '0',
            `date_first_shown` timestamp NULL DEFAULT NULL,
            `date_last_shown` timestamp NULL DEFAULT NULL,
            `snooze_until` timestamp NULL DEFAULT NULL,
            `snooze_count` int NOT NULL DEFAULT '0',
            `ack_action` tinyint NOT NULL DEFAULT '0' COMMENT '0 sin respuesta, 1 entendido',
            `date_ack` timestamp NULL DEFAULT NULL,
            `windows_user` varchar(255) DEFAULT NULL,
            `computer_name` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_notice` (`itemtype`, `items_id`, `notice_key`),
            KEY `level` (`level`),
            KEY `date_last_shown` (`date_last_shown`),
            KEY `date_ack` (`date_ack`)
        ",

        // Configuración clave/valor
        'glpi_plugin_mantenimientos_configs' => "
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL DEFAULT '',
            `value` text,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ",
    ];
}

/**
 * Valores por defecto de configuración.
 */
function plugin_mantenimientos_default_config(): array
{
    return [
        'threshold_warning_days'  => '30', // 🟡 faltan 30 días o menos
        'threshold_critical_days' => '7',  // 🟠 faltan 7 días o menos
        'goal_compliance_pct'     => '90', // Meta de cumplimiento preventivo
        'goal_corrective_pct'     => '20', // Máximo de correctivos frente a preventivos
        'replacement_cost_pct'    => '50', // Costo acumulado vs valor de compra
        'recurrence_count'        => '2',  // Correctivos para marcar reincidente...
        'recurrence_months'       => '12', // ...dentro de estos meses
        // Equipos en servicio: los demás no reciben plan, no cuentan y no reciben avisos
        'excluded_states'         => '[]', // Estados de GLPI fuera de servicio (JSON de ids)
        'stale_inventory_days'    => '0',  // Computadores sin inventario en N días (0 = no aplica)
        // Avisos en Windows (fase 3)
        'notify_enabled'          => '0',       // Apagado hasta que se configure
        'notify_token'            => '',        // Se genera al instalar
        'notify_min_light'        => 'warning', // Desde qué color se avisa: warning, critical, overdue
        'notify_scheduled'        => '1',       // Avisar mantenimientos programados con fecha
        'notify_reshow_hours'     => '4',       // Repetir un aviso sin respuesta cada N horas (y al iniciar sesión)
        'notify_snooze_hours'     => '4',       // "Recordar más tarde" lo oculta N horas
        'notify_repeat_hours'     => '24',      // Vencidos: se repiten cada N horas aunque ya se hayan visto
        'notify_poll_minutes'     => '60',      // Cada cuánto consulta el equipo
        'notify_contact'          => '',        // Texto final, ej. "Soporte TI: ext. 123"
        'notify_display_name'     => 'Mantenimiento de equipos', // Origen que muestra Windows
    ];
}

/**
 * Cambios de esquema para instalaciones existentes.
 */
function plugin_mantenimientos_upgrade_schema(Migration $migration): void
{
    /** @var \DBmysql $DB */
    global $DB;

    // 0.4.0: un plan admite varios subtipos (types_id -> types_ids JSON)
    $plans = 'glpi_plugin_mantenimientos_plans';
    if ($DB->tableExists($plans) && $DB->fieldExists($plans, 'types_id')) {
        if (!$DB->fieldExists($plans, 'types_ids')) {
            $migration->addField($plans, 'types_ids', 'text', ['after' => 'itemtype']);
            $migration->migrationOneTable($plans);
        }
        foreach ($DB->request(['SELECT' => ['id', 'types_id'], 'FROM' => $plans]) as $row) {
            $ids = (int) $row['types_id'] > 0 ? [(int) $row['types_id']] : [];
            $DB->update($plans, ['types_ids' => json_encode($ids)], ['id' => $row['id']]);
        }
        $migration->dropKey($plans, 'itemtype_types');
        $migration->dropField($plans, 'types_id');
        $migration->addKey($plans, 'itemtype');
        $migration->migrationOneTable($plans);
    }
}

function plugin_mantenimientos_install(): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_MANTENIMIENTOS_VERSION);
    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // 1. Tablas
    foreach (plugin_mantenimientos_schema($sign) as $table => $columns) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery(
                "CREATE TABLE `{$table}` ({$columns})
                 ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
            );
        }
    }

    // 1b. Actualizaciones de esquema desde versiones anteriores
    plugin_mantenimientos_upgrade_schema($migration);

    // 2. Configuración por defecto (sin sobrescribir valores existentes)
    foreach (plugin_mantenimientos_default_config() as $name => $value) {
        $exists = countElementsInTable('glpi_plugin_mantenimientos_configs', ['name' => $name]);
        if ($exists === 0) {
            $DB->insert('glpi_plugin_mantenimientos_configs', [
                'name'  => $name,
                'value' => $value,
            ]);
        }
    }

    // 2b. Token del canal de avisos (solo si aún no existe)
    $token_row = $DB->request([
        'FROM'  => 'glpi_plugin_mantenimientos_configs',
        'WHERE' => ['name' => 'notify_token'],
    ])->current();
    if ($token_row !== null && ($token_row['value'] ?? '') === '') {
        $DB->update(
            'glpi_plugin_mantenimientos_configs',
            ['value' => bin2hex(random_bytes(24))],
            ['name' => 'notify_token']
        );
    }

    // 3. Permisos: se crean en todos los perfiles en 0...
    $rights = plugin_mantenimientos_rights();
    foreach (array_keys($rights) as $rightname) {
        if (countElementsInTable('glpi_profilerights', ['name' => $rightname]) === 0) {
            ProfileRight::addProfileRights([$rightname]);
        }
    }

    // ...y se conceden completos a los perfiles que administran la configuración (Super-Admin)
    $admin_profiles = $DB->request([
        'SELECT' => ['profiles_id'],
        'FROM'   => 'glpi_profilerights',
        'WHERE'  => [
            'name'   => 'config',
            'rights' => ['&', UPDATE],
        ],
    ]);
    foreach ($admin_profiles as $row) {
        ProfileRight::updateProfileRights((int) $row['profiles_id'], $rights);
    }

    // Refrescar la sesión actual para que el menú aparezca sin cerrar sesión
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $current = (int) $_SESSION['glpiactiveprofile']['id'];
        foreach ($admin_profiles as $row) {
            if ((int) $row['profiles_id'] === $current) {
                foreach ($rights as $rightname => $value) {
                    $_SESSION['glpiactiveprofile'][$rightname] = $value;
                }
            }
        }
    }

    // 4. Tarea programada diaria: asignar planes y recalcular fechas
    CronTask::register(
        'GlpiPlugin\\Mantenimientos\\Item',
        'syncItems',
        DAY_TIMESTAMP,
        [
            'comment' => 'Mantenimientos: asignar planes a equipos y recalcular fechas',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    $migration->executeMigration();
    return true;
}

function plugin_mantenimientos_uninstall(): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    foreach (array_keys(plugin_mantenimientos_schema('unsigned')) as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    CronTask::unregister('Mantenimientos');

    $rightnames = array_keys(plugin_mantenimientos_rights());
    ProfileRight::deleteProfileRights($rightnames);
    foreach ($rightnames as $rightname) {
        unset($_SESSION['glpiactiveprofile'][$rightname]);
    }

    $DB->delete('glpi_displaypreferences', [
        'itemtype' => ['LIKE', 'GlpiPlugin\\\\Mantenimientos\\\\%'],
    ]);

    return true;
}
