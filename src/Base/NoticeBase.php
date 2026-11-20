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
use GlpiPlugin\Mantenimientos\Config;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Notice;

/**
 * Avisos al usuario en Windows (fase 3).
 *
 * Los equipos consultan front/notify.php con el token del plugin; este calcula
 * los avisos que le tocan al equipo (semáforo del preventivo y mantenimientos
 * programados), decide cuáles mostrar ahora y registra si el usuario los vio,
 * los aceptó ("Entendido") o los pospuso ("Recordar más tarde").
 *
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../Notice.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_notices
 */
abstract class NoticeBase extends CommonDBTM
{
    public const LEVEL_WARNING   = 'warning';
    public const LEVEL_CRITICAL  = 'critical';
    public const LEVEL_OVERDUE   = 'overdue';
    public const LEVEL_SCHEDULED = 'scheduled';

    public const ACK_NONE = 0;
    public const ACK_DONE = 1;

    /** Seriales genéricos que ponen los fabricantes: no sirven para identificar. */
    private const JUNK_SERIALS = [
        '', '0', '00000000', 'none', 'default string', 'to be filled by o.e.m.',
        'system serial number', 'not applicable', 'n/a', 'invalid', '123456789',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Aviso en Windows', 'Avisos en Windows', $nb, 'mantenimientos');
    }

    public static function getIcon()
    {
        return 'ti ti-bell-ringing';
    }

    // ---------------------------------------------------------------------
    // Configuración
    // ---------------------------------------------------------------------

    /**
     * @return array{enabled: bool, min_light: string, scheduled: bool, reshow_hours: int,
     *               snooze_hours: int, repeat_hours: int, poll_minutes: int, contact: string}
     */
    public static function getSettings(): array
    {
        $min = (string) Config::getValue('notify_min_light', self::LEVEL_WARNING);
        return [
            'enabled'      => (bool) (int) Config::getValue('notify_enabled', 0),
            'min_light'    => self::levelRank($min) > 0 ? $min : self::LEVEL_WARNING,
            'scheduled'    => (bool) (int) Config::getValue('notify_scheduled', 1),
            'reshow_hours' => max(1, (int) Config::getValue('notify_reshow_hours', 4)),
            'snooze_hours' => max(1, (int) Config::getValue('notify_snooze_hours', 4)),
            'repeat_hours' => max(1, (int) Config::getValue('notify_repeat_hours', 24)),
            'poll_minutes' => min(1440, max(15, (int) Config::getValue('notify_poll_minutes', 60))),
            'contact'      => (string) Config::getValue('notify_contact', ''),
        ];
    }

    /**
     * Compara el token recibido con el configurado (tiempo constante).
     */
    public static function checkToken(string $given): bool
    {
        $expected = (string) Config::getValue('notify_token', '');
        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * Orden de severidad de los niveles que generan aviso (0 = no avisa).
     */
    public static function levelRank(string $level): int
    {
        return match ($level) {
            self::LEVEL_WARNING  => 1,
            self::LEVEL_CRITICAL => 2,
            self::LEVEL_OVERDUE  => 3,
            default              => 0,
        };
    }

    /**
     * Etiquetas de los niveles mínimos que se pueden configurar.
     *
     * @return array<string, string>
     */
    public static function getMinLightChoices(): array
    {
        $lights = Item::getLights();
        return [
            self::LEVEL_WARNING  => sprintf(__('Desde %s', 'mantenimientos'), $lights[Item::LIGHT_WARNING]['label']),
            self::LEVEL_CRITICAL => sprintf(__('Desde %s', 'mantenimientos'), $lights[Item::LIGHT_CRITICAL]['label']),
            self::LEVEL_OVERDUE  => sprintf(__('Solo %s', 'mantenimientos'), $lights[Item::LIGHT_OVERDUE]['label']),
        ];
    }

    // ---------------------------------------------------------------------
    // Identificación del equipo
    // ---------------------------------------------------------------------

    public static function isUsableSerial(string $serial): bool
    {
        return !in_array(strtolower(trim($serial)), self::JUNK_SERIALS, true);
    }

    /**
     * Busca el computador por serial (preferido) o por nombre.
     * Si el serial coincide con varios equipos, desempata con el nombre.
     *
     * @return array<string, mixed>|null id, name, serial, users_id, entities_id
     */
    public static function findComputer(string $serial, string $name): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $serial = trim($serial);
        $name   = trim($name);
        $base   = [
            'SELECT' => ['id', 'name', 'serial', 'users_id', 'entities_id'],
            'FROM'   => \Computer::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'is_template' => 0],
        ];

        if (self::isUsableSerial($serial)) {
            $rows = iterator_to_array($DB->request(array_merge_recursive($base, ['WHERE' => ['serial' => $serial]])), false);
            if (count($rows) === 1) {
                return $rows[0];
            }
            foreach ($rows as $row) {
                if ($name !== '' && strcasecmp((string) $row['name'], $name) === 0) {
                    return $row;
                }
            }
            if ($rows !== []) {
                return $rows[0];
            }
        }

        if ($name !== '') {
            $rows = iterator_to_array($DB->request(array_merge_recursive($base, ['WHERE' => ['name' => $name]])), false);
            if (count($rows) === 1) {
                return $rows[0];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Cálculo de avisos
    // ---------------------------------------------------------------------

    /**
     * Avisos que le corresponden al equipo hoy, sin mirar si ya se mostraron.
     *
     * @param array<string, mixed> $computer fila de findComputer()
     * @return list<array{key: string, level: string, title: string, message: string}>
     */
    public static function buildCandidates(array $computer, ?\DateTimeImmutable $today = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $today    ??= new \DateTimeImmutable('today');
        $settings = self::getSettings();
        $items_id = (int) $computer['id'];
        $greeting = self::greeting((int) ($computer['users_id'] ?? 0));
        $asset    = (string) ($computer['name'] ?? '') !== '' ? (string) $computer['name'] : ('#' . $items_id);
        $out      = [];

        // 1. Mantenimiento programado con fecha (tiene prioridad sobre el semáforo)
        if ($settings['scheduled']) {
            $scheduled = $DB->request([
                'SELECT' => ['id', 'scheduled_date'],
                'FROM'   => Maintenance::getTable(),
                'WHERE'  => [
                    'itemtype'       => \Computer::class,
                    'items_id'       => $items_id,
                    'status'         => [Maintenance::STATUS_SCHEDULED, Maintenance::STATUS_RESCHEDULED],
                    'scheduled_date' => ['>=', $today->format('Y-m-d')],
                ],
                'ORDER'  => ['scheduled_date ASC'],
                'LIMIT'  => 1,
            ])->current();
            if ($scheduled !== null) {
                $date  = (string) $scheduled['scheduled_date'];
                $out[] = [
                    'key'     => sprintf('sched:%d:%s', (int) $scheduled['id'], $date),
                    'level'   => self::LEVEL_SCHEDULED,
                    'title'   => __('Mantenimiento programado', 'mantenimientos'),
                    'message' => self::withContact(sprintf(
                        __('%1$s tu equipo %2$s tiene mantenimiento programado para el %3$s. Por favor déjalo encendido y disponible ese día.', 'mantenimientos'),
                        $greeting,
                        $asset,
                        self::formatDate($date)
                    ), $settings['contact']),
                ];
                return $out;
            }
        }

        // 2. Semáforo del preventivo
        $record = $DB->request([
            'SELECT' => ['plugin_mantenimientos_plans_id', 'next_due_date', 'is_excluded'],
            'FROM'   => Item::getTable(),
            'WHERE'  => ['itemtype' => \Computer::class, 'items_id' => $items_id],
        ])->current();

        if (
            $record === null
            || (int) $record['plugin_mantenimientos_plans_id'] === 0
            || (int) $record['is_excluded'] === 1
            || empty($record['next_due_date'])
        ) {
            return $out;
        }

        $due   = (string) $record['next_due_date'];
        $light = Item::computeLight($due, $today);
        if (self::levelRank($light) === 0 || self::levelRank($light) < self::levelRank($settings['min_light'])) {
            return $out;
        }

        $days = (int) Item::daysUntil($due, $today);
        if ($light === self::LEVEL_OVERDUE) {
            $title = __('Mantenimiento preventivo vencido', 'mantenimientos');
            $text  = sprintf(
                _n(
                    '%1$s el mantenimiento preventivo de tu equipo %2$s venció el %3$s (hace %4$d día). Comunícate con soporte para programarlo lo antes posible.',
                    '%1$s el mantenimiento preventivo de tu equipo %2$s venció el %3$s (hace %4$d días). Comunícate con soporte para programarlo lo antes posible.',
                    -$days,
                    'mantenimientos'
                ),
                $greeting,
                $asset,
                self::formatDate($due),
                -$days
            );
        } else {
            $title = $light === self::LEVEL_CRITICAL
                ? __('Mantenimiento preventivo muy próximo', 'mantenimientos')
                : __('Mantenimiento preventivo próximo', 'mantenimientos');
            $text = $days === 0
                ? sprintf(__('%1$s hoy le corresponde el mantenimiento preventivo a tu equipo %2$s.', 'mantenimientos'), $greeting, $asset)
                : sprintf(
                    _n(
                        '%1$s a tu equipo %2$s le corresponde el mantenimiento preventivo el %3$s (falta %4$d día).',
                        '%1$s a tu equipo %2$s le corresponde el mantenimiento preventivo el %3$s (faltan %4$d días).',
                        $days,
                        'mantenimientos'
                    ),
                    $greeting,
                    $asset,
                    self::formatDate($due),
                    $days
                );
        }

        $out[] = [
            'key'     => sprintf('due:%s:%s', $due, $light),
            'level'   => $light,
            'title'   => $title,
            'message' => self::withContact($text, $settings['contact']),
        ];
        return $out;
    }

    /**
     * Decide si un aviso se muestra ahora. Función pura (sin BD) para poder probarla.
     *
     * Reglas:
     *  - "Recordar más tarde" lo oculta hasta snooze_until.
     *  - "Entendido" lo cierra; solo los vencidos vuelven a salir pasadas repeat_hours.
     *  - Sin respuesta: se repite cada reshow_hours, y siempre al iniciar sesión.
     *
     * @param array<string, mixed>|null $row registro guardado (null = nunca mostrado)
     * @param array{reshow_hours: int, repeat_hours: int} $settings
     */
    public static function shouldShow(?array $row, string $level, bool $logon, \DateTimeImmutable $now, array $settings): bool
    {
        if ($row === null) {
            return true;
        }

        $ts = static fn ($v): ?\DateTimeImmutable => empty($v) ? null : new \DateTimeImmutable((string) $v);

        $snooze = $ts($row['snooze_until'] ?? null);
        if ($snooze !== null && $snooze > $now) {
            return false;
        }

        $acked     = (int) ($row['ack_action'] ?? 0) === self::ACK_DONE;
        $date_ack  = $ts($row['date_ack'] ?? null);
        $last_seen = $ts($row['date_last_shown'] ?? null);

        if ($acked) {
            if ($level !== self::LEVEL_OVERDUE || $date_ack === null) {
                return false;
            }
            if ($date_ack->modify(sprintf('+%d hours', $settings['repeat_hours'])) > $now) {
                return false;
            }
            // Vencido aceptado hace rato: vuelve a salir. Si ya se mostró después
            // de aceptarlo y sigue sin respuesta, aplica la regla de repetición normal.
            if ($last_seen === null || $last_seen <= $date_ack) {
                return true;
            }
        }

        if ($logon || $last_seen === null) {
            return true;
        }
        return $last_seen->modify(sprintf('+%d hours', $settings['reshow_hours'])) <= $now;
    }

    /**
     * Avisos a mostrar ahora para el equipo; los registra como mostrados.
     *
     * @param array<string, mixed> $computer
     * @return list<array{id: int, key: string, level: string, title: string, message: string}>
     */
    public static function getPendingFor(array $computer, bool $logon, string $windows_user, string $computer_name): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $settings = self::getSettings();
        $now      = new \DateTimeImmutable();
        $now_txt  = $now->format('Y-m-d H:i:s');
        $items_id = (int) $computer['id'];
        $table    = Notice::getTable();
        $out      = [];

        foreach (self::buildCandidates($computer) as $candidate) {
            $crit = ['itemtype' => \Computer::class, 'items_id' => $items_id, 'notice_key' => $candidate['key']];
            $row  = $DB->request(['FROM' => $table, 'WHERE' => $crit])->current();

            if (!self::shouldShow($row, $candidate['level'], $logon, $now, $settings)) {
                continue;
            }

            $values = [
                'level'           => $candidate['level'],
                'title'           => $candidate['title'],
                'message'         => $candidate['message'],
                'date_last_shown' => $now_txt,
                'windows_user'    => mb_substr($windows_user, 0, 255),
                'computer_name'   => mb_substr($computer_name, 0, 255),
                'date_mod'        => $now_txt,
            ];
            if ($row === null) {
                $DB->insert($table, $crit + $values + [
                    'times_shown'      => 1,
                    'date_first_shown' => $now_txt,
                    'date_creation'    => $now_txt,
                ]);
                $id = (int) $DB->insertId();
            } else {
                $id = (int) $row['id'];
                // Si vuelve a salir (vencido ya aceptado), queda otra vez sin
                // respuesta; date_ack conserva la última aceptación.
                $DB->update($table, $values + [
                    'times_shown'  => (int) $row['times_shown'] + 1,
                    'snooze_until' => null,
                    'ack_action'   => self::ACK_NONE,
                ], ['id' => $id]);
            }

            $out[] = ['id' => $id] + $candidate;
        }

        return $out;
    }

    /**
     * Respuesta del usuario a un aviso. Solo se acepta si el aviso es del equipo
     * que responde, para que un equipo no pueda cerrar avisos de otro.
     *
     * @param string $action 'done' (Entendido) o 'snooze' (Recordar más tarde)
     */
    public static function respond(int $notices_id, int $computers_id, string $action, string $windows_user): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = Notice::getTable();
        $row   = $DB->request([
            'FROM'  => $table,
            'WHERE' => ['id' => $notices_id, 'itemtype' => \Computer::class, 'items_id' => $computers_id],
        ])->current();
        if ($row === null) {
            return false;
        }

        $now    = new \DateTimeImmutable();
        $values = ['date_mod' => $now->format('Y-m-d H:i:s')];
        if ($windows_user !== '') {
            $values['windows_user'] = mb_substr($windows_user, 0, 255);
        }

        if ($action === 'done') {
            $values += [
                'ack_action'   => self::ACK_DONE,
                'date_ack'     => $now->format('Y-m-d H:i:s'),
                'snooze_until' => null,
            ];
        } elseif ($action === 'snooze') {
            $hours   = self::getSettings()['snooze_hours'];
            $values += [
                'snooze_until' => $now->modify(sprintf('+%d hours', $hours))->format('Y-m-d H:i:s'),
                'snooze_count' => (int) $row['snooze_count'] + 1,
            ];
        } else {
            return false;
        }

        return (bool) $DB->update($table, $values, ['id' => $notices_id]);
    }

    /**
     * Computador por id, con los mismos campos que findComputer().
     *
     * @return array<string, mixed>|null
     */
    public static function getComputerById(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'name', 'serial', 'users_id', 'entities_id'],
            'FROM'   => \Computer::getTable(),
            'WHERE'  => ['id' => $id, 'is_deleted' => 0, 'is_template' => 0],
        ])->current();
        return $row ?: null;
    }

    /**
     * Seriales de varios computadores (id => serial).
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function getSerials(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach ($DB->request(['SELECT' => ['id', 'serial'], 'FROM' => \Computer::getTable(), 'WHERE' => ['id' => $ids]]) as $row) {
            $out[(int) $row['id']] = (string) $row['serial'];
        }
        return $out;
    }

    /**
     * Último aviso registrado de cada computador (items_id => fila).
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function getLastByComputer(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach (
            $DB->request([
                'FROM'  => Notice::getTable(),
                'WHERE' => ['itemtype' => \Computer::class, 'items_id' => $ids],
                'ORDER' => ['date_last_shown ASC'],
            ]) as $row
        ) {
            $out[(int) $row['items_id']] = $row; // queda el más reciente
        }
        return $out;
    }

    /**
     * Etiqueta con la respuesta del usuario a un aviso registrado.
     *
     * @param array<string, mixed> $row
     */
    public static function responseBadge(array $row): string
    {
        $e   = [\GlpiPlugin\Mantenimientos\Compat::class, 'e'];
        $now = date('Y-m-d H:i:s');
        if ((int) $row['ack_action'] === self::ACK_DONE) {
            $html = "<span class='badge bg-green-lt'>" . $e(__('Entendido', 'mantenimientos')) . "</span> "
                . $e(\Html::convDateTime($row['date_ack']));
        } elseif (!empty($row['snooze_until']) && $row['snooze_until'] > $now) {
            $html = "<span class='badge bg-yellow-lt'>" . $e(__('Pospuesto', 'mantenimientos')) . "</span> "
                . $e(sprintf(__('hasta %s', 'mantenimientos'), \Html::convDateTime($row['snooze_until'])));
        } else {
            $html = "<span class='badge bg-secondary-lt'>" . $e(__('Sin respuesta', 'mantenimientos')) . "</span>";
        }
        if ((int) $row['snooze_count'] > 0) {
            $html .= " <span class='text-muted small'>" . $e(sprintf(
                _n('(pospuesto %d vez)', '(pospuesto %d veces)', (int) $row['snooze_count'], 'mantenimientos'),
                (int) $row['snooze_count']
            )) . "</span>";
        }
        return $html;
    }

    /**
     * Últimos avisos mostrados, para la pantalla de configuración.
     *
     * @return list<array<string, mixed>>
     */
    public static function getRecent(int $limit = 100): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        return iterator_to_array($DB->request([
            'SELECT'    => [
                'n.*', 'c.name AS asset_name',
                'u.name AS user_login', 'u.firstname', 'u.realname',
            ],
            'FROM'      => Notice::getTable() . ' AS n',
            'LEFT JOIN' => [
                \Computer::getTable() . ' AS c' => ['ON' => ['n' => 'items_id', 'c' => 'id']],
                'glpi_users AS u'                => ['ON' => ['c' => 'users_id', 'u' => 'id']],
            ],
            'ORDER'     => ['n.date_last_shown DESC'],
            'LIMIT'     => $limit,
        ]), false);
    }

    /**
     * Resumen de los últimos días para los mosaicos de la pantalla de configuración.
     *
     * @return array{shown: int, done: int, snoozed: int, pending: int, computers: int}
     */
    public static function getSummary(int $days = 30): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $since = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
        $now   = date('Y-m-d H:i:s');
        $out   = ['shown' => 0, 'done' => 0, 'snoozed' => 0, 'pending' => 0, 'computers' => 0];
        $seen  = [];
        foreach ($DB->request(['FROM' => Notice::getTable(), 'WHERE' => ['date_last_shown' => ['>=', $since]]]) as $row) {
            $out['shown']++;
            $seen[(int) $row['items_id']] = true;
            if ((int) $row['ack_action'] === self::ACK_DONE) {
                $out['done']++;
            } elseif (!empty($row['snooze_until']) && $row['snooze_until'] > $now) {
                $out['snoozed']++;
            } else {
                $out['pending']++;
            }
        }
        $out['computers'] = count($seen);
        return $out;
    }

    // ---------------------------------------------------------------------
    // Instalación en los equipos
    // ---------------------------------------------------------------------

    /**
     * URL pública del canal de avisos.
     */
    public static function getEndpointUrl(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/plugins/mantenimientos/front/notify.php';
    }

    /**
     * Instalador listo para un paquete de despliegue: la plantilla de
     * tools/windows con la URL, el token, el intervalo y los archivos incluidos.
     */
    public static function buildInstaller(string $display_name): string
    {
        $dir      = dirname(__DIR__, 2) . '/tools/windows/';
        $template = (string) file_get_contents($dir . 'Instalar-AvisosMantenimiento.ps1');
        $template = preg_replace('/^\xEF\xBB\xBF/', '', $template);
        $ps_quote = static fn (string $v): string => str_replace("'", "''", $v);

        $script = strtr($template, [
            "'__ENDPOINT__'"    => "'" . $ps_quote(self::getEndpointUrl()) . "'",
            "'__TOKEN__'"       => "'" . $ps_quote((string) Config::getValue('notify_token', '')) . "'",
            "'__INTERVALO__'"   => "'" . self::getSettings()['poll_minutes'] . "'",
            "'__NOMBRE__'"      => "'" . $ps_quote($display_name) . "'",
            "'__B64_CLIENTE__'" => "'" . base64_encode((string) file_get_contents($dir . 'MantenimientosAvisos.ps1')) . "'",
            "'__B64_VBS__'"     => "'" . base64_encode((string) file_get_contents($dir . 'ejecutar-oculto.vbs')) . "'",
        ]);

        // PowerShell 5.1 necesita BOM para leer bien las tildes
        return "\xEF\xBB\xBF" . str_replace(["\r\n", "\n"], ["\n", "\r\n"], $script);
    }

    /**
     * Etiqueta de color del nivel del aviso.
     */
    public static function levelBadge(string $level): string
    {
        $lights = Item::getLights();
        $def    = match ($level) {
            self::LEVEL_WARNING   => $lights[Item::LIGHT_WARNING],
            self::LEVEL_CRITICAL  => $lights[Item::LIGHT_CRITICAL],
            self::LEVEL_OVERDUE   => $lights[Item::LIGHT_OVERDUE],
            default               => ['label' => __('Programado', 'mantenimientos'), 'color' => '#4263eb', 'text' => '#fff'],
        };
        return sprintf(
            "<span class='badge' style='background:%s;color:%s'>%s</span>",
            $def['color'],
            $def['text'],
            \GlpiPlugin\Mantenimientos\Compat::e($def['label'])
        );
    }

    // ---------------------------------------------------------------------
    // Utilidades de texto
    // ---------------------------------------------------------------------

    private static function greeting(int $users_id): string
    {
        if ($users_id > 0) {
            $user = new \User();
            if ($user->getFromDB($users_id)) {
                $first = trim((string) ($user->fields['firstname'] ?? ''));
                if ($first !== '') {
                    return sprintf(__('Hola %s,', 'mantenimientos'), $first);
                }
            }
        }
        return __('Hola,', 'mantenimientos');
    }

    private static function withContact(string $text, string $contact): string
    {
        $contact = trim($contact);
        return $contact === '' ? $text : $text . ' ' . $contact;
    }

    public static function formatDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d/m/Y');
    }
}
