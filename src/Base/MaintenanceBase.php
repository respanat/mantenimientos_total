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
use GlpiPlugin\Mantenimientos\Check;
use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Cost;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Maintenance;
use GlpiPlugin\Mantenimientos\Plan;
use GlpiPlugin\Mantenimientos\PlanTask;
use GlpiPlugin\Mantenimientos\StatusLog;
use Html;
use Session;

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


    // =====================================================================
    // Preventivo: inicio, checklist tipo formato en papel, cierre
    // =====================================================================

    /** Estados del preventivo que cuentan como "abierto". */
    public const OPEN_PREVENTIVE = [self::STATUS_SCHEDULED, self::STATUS_RESCHEDULED, self::STATUS_IN_PROGRESS];

    /**
     * Preventivo abierto del equipo, si existe.
     */
    public static function findOpenPreventive(string $itemtype, int $items_id): ?int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Maintenance::getTable(),
            'WHERE'  => [
                'itemtype'         => $itemtype,
                'items_id'         => $items_id,
                'maintenance_type' => self::TYPE_PREVENTIVE,
                'status'           => self::OPEN_PREVENTIVE,
            ],
            'ORDER'  => 'id DESC',
            'LIMIT'  => 1,
        ])->current();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Abre un preventivo para el equipo (o devuelve el que ya está abierto) y
     * copia el checklist del plan. Devuelve el id o null si el equipo no tiene plan.
     */
    public static function startPreventive(string $itemtype, int $items_id): ?int
    {
        if (!array_key_exists($itemtype, Plan::SUPPORTED_ITEMTYPES)) {
            return null;
        }
        $open = self::findOpenPreventive($itemtype, $items_id);
        if ($open !== null) {
            return $open;
        }

        $record = new Item();
        if (
            !$record->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $items_id])
            || (int) $record->fields['plugin_mantenimientos_plans_id'] === 0
            || (int) $record->fields['is_excluded'] === 1
        ) {
            Session::addMessageAfterRedirect(
                __('Este equipo no tiene un plan de mantenimiento activo. Sincronice los equipos o revise el plan.', 'mantenimientos'),
                false,
                ERROR
            );
            return null;
        }

        $asset = new $itemtype();
        if (!$asset->getFromDB($items_id)) {
            return null;
        }

        $maintenance = new Maintenance();
        $id = $maintenance->add([
            'entities_id'                    => (int) $asset->fields['entities_id'],
            'itemtype'                       => $itemtype,
            'items_id'                       => $items_id,
            'maintenance_type'               => self::TYPE_PREVENTIVE,
            'status'                         => self::STATUS_IN_PROGRESS,
            'plugin_mantenimientos_plans_id' => (int) $record->fields['plugin_mantenimientos_plans_id'],
            'scheduled_date'                 => $record->fields['next_due_date'],
            'date_start'                     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'users_id_tech'                  => (int) Session::getLoginUserID(),
            'users_id_assigned'              => (int) ($asset->fields['users_id'] ?? 0),
            'users_id_creator'               => (int) Session::getLoginUserID(),
        ]);
        if (!$id) {
            return null;
        }

        // Copia del checklist (se conserva aunque luego cambie el plan)
        /** @var \DBmysql $DB */
        global $DB;
        foreach (
            $DB->request([
                'FROM'  => PlanTask::getTable(),
                'WHERE' => [
                    'plugin_mantenimientos_plans_id' => (int) $record->fields['plugin_mantenimientos_plans_id'],
                    'is_active' => 1,
                ],
                'ORDER' => ['rank ASC', 'id ASC'],
            ]) as $task
        ) {
            (new Check())->add([
                'plugin_mantenimientos_maintenances_id' => $id,
                'plugin_mantenimientos_plantasks_id'    => (int) $task['id'],
                'name'         => $task['name'],
                'is_mandatory' => (int) $task['is_mandatory'],
                'result'       => Check::RESULT_PENDING,
            ]);
        }

        StatusLog::record($id, 0, self::STATUS_IN_PROGRESS, __('Preventivo iniciado', 'mantenimientos'));
        return (int) $id;
    }

    /**
     * Guarda las marcas del checklist y las observaciones (sin cerrar).
     *
     * @param array<int|string, array{result?: int|string, comment?: string}> $checks
     */
    public function saveChecklist(array $checks, ?string $observations = null): void
    {
        foreach ($checks as $check_id => $data) {
            $check = new Check();
            if (
                !$check->getFromDB((int) $check_id)
                || (int) $check->fields['plugin_mantenimientos_maintenances_id'] !== (int) $this->getID()
            ) {
                continue;
            }
            $result = (int) ($data['result'] ?? Check::RESULT_PENDING);
            if (!array_key_exists($result, Check::getResults())) {
                $result = Check::RESULT_PENDING;
            }
            $check->update([
                'id'       => $check->getID(),
                'result'   => $result,
                'comment'  => (string) ($data['comment'] ?? ''),
                'users_id' => (int) Session::getLoginUserID(),
                'date_mod' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        }
        if ($observations !== null) {
            $this->update(['id' => $this->getID(), 'observations' => $observations]);
        }
    }

    /**
     * Errores que impiden completar el preventivo (vacío = se puede completar).
     *
     * @return list<string>
     */
    public function getCompletionErrors(): array
    {
        $errors = [];
        foreach ((new Check())->find(['plugin_mantenimientos_maintenances_id' => $this->getID()], ['id']) as $check) {
            $name    = html_entity_decode((string) $check['name'], ENT_QUOTES, 'UTF-8');
            $result  = (int) $check['result'];
            $comment = trim((string) $check['comment']);
            if ($result === Check::RESULT_PENDING) {
                $errors[] = sprintf(__('"%s": falta marcar.', 'mantenimientos'), $name);
            } elseif (in_array($result, [Check::RESULT_NOT_DONE, Check::RESULT_NA], true) && $comment === '') {
                $errors[] = sprintf(__('"%s": falta la justificación.', 'mantenimientos'), $name);
            }
            if ((int) $check['is_mandatory'] === 1 && $result === Check::RESULT_NOT_DONE) {
                $errors[] = sprintf(__('"%s" es obligatoria y quedó como No realizado.', 'mantenimientos'), $name);
            }
        }
        return $errors;
    }

    /**
     * Cierra el preventivo y recalcula la próxima fecha del equipo.
     */
    public function complete(): bool
    {
        if (!in_array((int) $this->fields['status'], self::OPEN_PREVENTIVE, true)) {
            return false;
        }
        $errors = $this->getCompletionErrors();
        if ($errors !== []) {
            Session::addMessageAfterRedirect(
                __('No se puede completar el preventivo:', 'mantenimientos') . '<br>' . implode('<br>', array_map(fn ($e) => Compat::e($e), $errors)),
                false,
                ERROR
            );
            return false;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $old = (int) $this->fields['status'];
        $this->update(['id' => $this->getID(), 'status' => self::STATUS_COMPLETED, 'date_end' => $now]);
        StatusLog::record((int) $this->getID(), $old, self::STATUS_COMPLETED, __('Preventivo completado', 'mantenimientos'));

        // Último preventivo del equipo = fecha real de cierre; luego se recalcula la próxima fecha
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Item::getTable(), [
            'last_preventive_date' => substr($now, 0, 10),
            'date_mod'             => $now,
        ], [
            'itemtype' => $this->fields['itemtype'],
            'items_id' => (int) $this->fields['items_id'],
        ]);
        Item::syncAll();
        return true;
    }

    public function cancel(string $reason): bool
    {
        if (trim($reason) === '') {
            Session::addMessageAfterRedirect(__('Indique el motivo de la cancelación.', 'mantenimientos'), false, ERROR);
            return false;
        }
        $old = (int) $this->fields['status'];
        if (self::isClosedStatus($old)) {
            return false;
        }
        $this->update([
            'id'            => $this->getID(),
            'status'        => self::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'date_end'      => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        StatusLog::record((int) $this->getID(), $old, self::STATUS_CANCELLED, $reason);
        return true;
    }

    // =====================================================================
    // Formulario: registro tipo formato en papel
    // =====================================================================

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    public static function statusBadge(int $status): string
    {
        $colors = [
            self::STATUS_IN_PROGRESS => '#4263eb', self::STATUS_SCHEDULED => '#4263eb', self::STATUS_RESCHEDULED => '#f59f00',
            self::STATUS_COMPLETED   => '#2fb344', self::STATUS_CANCELLED => '#868e96',
        ];
        return sprintf(
            "<span class='badge' style='background:%s;color:#fff'>%s</span>",
            $colors[$status] ?? '#868e96',
            Compat::e(self::getStatuses()[$status] ?? '?')
        );
    }

    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB((int) $ID)) {
            echo "<div class='alert alert-warning'>" . Compat::e(__('Los mantenimientos se inician desde la ficha del equipo o desde la lista de Equipos.', 'mantenimientos')) . "</div>";
            return false;
        }

        $id       = (int) $this->getID();
        $itemtype = (string) $this->fields['itemtype'];
        $status   = (int) $this->fields['status'];
        $is_open  = in_array($status, self::OPEN_PREVENTIVE, true);
        $canedit  = $is_open && $this->can($id, UPDATE);

        $asset = class_exists($itemtype) ? new $itemtype() : null;
        $asset_link = $asset && $asset->getFromDB((int) $this->fields['items_id'])
            ? "<a href='" . Compat::e($itemtype::getFormURLWithID($asset->getID())) . "'>" . Compat::e($asset->getName(), true) . "</a>"
            : '#' . (int) $this->fields['items_id'];
        $plan = new Plan();
        $plan_name = $plan->getFromDB((int) $this->fields['plugin_mantenimientos_plans_id']) ? Compat::e($plan->fields['name'], true) : '—';
        $user = fn ($uid) => (int) $uid > 0 ? Compat::e(getUserName((int) $uid), true) : '—';

        // ----- Encabezado del formato
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<div class='d-flex align-items-center mb-3'><h3 class='mb-0 me-3'>"
            . Compat::e(sprintf(__('Preventivo #%d', 'mantenimientos'), $id)) . "</h3>" . self::statusBadge($status) . "</div>";
        echo "<div class='row'>";
        $info = [
            __('Equipo', 'mantenimientos')            => $asset_link . ' (' . Compat::e($itemtype::getTypeName(1)) . ')',
            __('Usuario asignado', 'mantenimientos')  => $user($this->fields['users_id_assigned']),
            Plan::getTypeName(1)                      => $plan_name,
            __('Técnico', 'mantenimientos')           => $user($this->fields['users_id_tech']),
            __('Fecha programada', 'mantenimientos')  => Compat::e($this->fields['scheduled_date'] ? Html::convDate($this->fields['scheduled_date']) : '—'),
            __('Inicio', 'mantenimientos')            => Compat::e($this->fields['date_start'] ? Html::convDateTime($this->fields['date_start']) : '—'),
        ];
        if (!$is_open) {
            $info[__('Cierre', 'mantenimientos')] = Compat::e($this->fields['date_end'] ? Html::convDateTime($this->fields['date_end']) : '—');
        }
        foreach ($info as $label => $value) {
            echo "<div class='col-md-4 mb-2'><div class='text-muted small'>" . Compat::e($label) . "</div><div>{$value}</div></div>";
        }
        echo "</div>";
        if ($status === self::STATUS_CANCELLED) {
            echo "<div class='alert alert-secondary mb-0 mt-2'><b>" . Compat::e(__('Motivo de cancelación', 'mantenimientos')) . ":</b> "
                . Compat::e((string) $this->fields['cancel_reason'], true) . "</div>";
        }
        echo "</div></div>";

        // ----- Checklist
        $checks = (new Check())->find(['plugin_mantenimientos_maintenances_id' => $id], ['id']);
        $results = [
            Check::RESULT_DONE     => ['label' => __('Realizado', 'mantenimientos'),    'icon' => 'ti ti-check', 'class' => 'success'],
            Check::RESULT_NOT_DONE => ['label' => __('No realizado', 'mantenimientos'), 'icon' => 'ti ti-x',     'class' => 'danger'],
            Check::RESULT_NA       => ['label' => __('No aplica', 'mantenimientos'),    'icon' => 'ti ti-minus', 'class' => 'secondary'],
        ];

        $form_url = Maintenance::getFormURL();
        if ($canedit) {
            echo "<form method='post' action='" . Compat::e($form_url) . "' id='mant-checklist-form'>";
            echo "<input type='hidden' name='id' value='{$id}'>";
        }

        echo "<div class='card mb-3'><div class='card-header'><h4 class='card-title mb-0'>"
            . Compat::e(__('Lista de chequeo', 'mantenimientos')) . "</h4></div>";
        if ($checks === []) {
            echo "<div class='card-body text-muted'>" . Compat::e(__('El plan no tenía tareas en el checklist al iniciar este preventivo.', 'mantenimientos')) . "</div>";
        }
        echo "<div class='list-group list-group-flush'>";
        $n = 0;
        foreach ($checks as $check) {
            $n++;
            $cid    = (int) $check['id'];
            $result = (int) $check['result'];
            $req    = (int) $check['is_mandatory'] === 1
                ? " <span class='badge bg-red-lt' title='" . Compat::e(__('Obligatoria', 'mantenimientos')) . "'>*</span>" : '';
            echo "<div class='list-group-item'>";
            echo "<div class='row align-items-center g-2'>";
            echo "<div class='col-lg-5'><span class='text-muted me-2'>{$n}.</span>" . Compat::e($check['name'], true) . $req . "</div>";
            echo "<div class='col-lg-4'>";
            if ($canedit) {
                echo "<div class='btn-group w-100' role='group'>";
                foreach ($results as $value => $def) {
                    $rid     = "chk_{$cid}_{$value}";
                    $checked = $result === $value ? ' checked' : '';
                    echo "<input type='radio' class='btn-check mant-result' name='checks[{$cid}][result]' id='{$rid}' value='{$value}'"
                        . " data-check='{$cid}'{$checked} autocomplete='off'>";
                    echo "<label class='btn btn-outline-{$def['class']}' for='{$rid}'><i class='{$def['icon']} me-1'></i>"
                        . Compat::e($def['label']) . "</label>";
                }
                echo "</div>";
            } else {
                echo isset($results[$result])
                    ? "<span class='text-{$results[$result]['class']}'><i class='{$results[$result]['icon']} me-1'></i>" . Compat::e($results[$result]['label']) . "</span>"
                    : "<span class='text-muted'>" . Compat::e(__('Sin marcar', 'mantenimientos')) . "</span>";
            }
            echo "</div><div class='col-lg-3'>";
            if ($canedit) {
                $needs = in_array($result, [Check::RESULT_NOT_DONE, Check::RESULT_NA], true);
                echo "<input type='text' class='form-control form-control-sm mant-comment' id='comment_{$cid}' name='checks[{$cid}][comment]' maxlength='500'"
                    . " value='" . Compat::e((string) $check['comment'], true) . "'"
                    . " placeholder='" . Compat::e($needs ? __('Justificación (obligatoria)', 'mantenimientos') : __('Comentario (opcional)', 'mantenimientos')) . "'"
                    . ($needs ? ' required' : '') . ">";
            } else {
                echo "<span class='small'>" . Compat::e((string) $check['comment'], true) . "</span>";
            }
            echo "</div></div></div>";
        }
        echo "</div></div>";

        // ----- Observaciones
        echo "<div class='card mb-3'><div class='card-body'>";
        echo "<label class='form-label' for='mant-observations'>" . Compat::e(__('Observaciones generales', 'mantenimientos')) . "</label>";
        if ($canedit) {
            echo "<textarea class='form-control' id='mant-observations' name='observations' rows='3'>"
                . Compat::e((string) $this->fields['observations'], true) . "</textarea>";
        } else {
            echo "<div>" . nl2br(Compat::e((string) $this->fields['observations'], true)) . "</div>";
        }
        echo "</div></div>";

        if ($canedit) {
            echo "<div class='d-flex flex-wrap gap-2 mb-3'>";
            echo "<button type='submit' name='save' value='1' class='btn btn-outline-primary' formnovalidate>"
                . "<i class='ti ti-device-floppy me-1'></i>" . Compat::e(__('Guardar avance', 'mantenimientos')) . "</button>";
            echo "<button type='submit' name='complete' value='1' class='btn btn-success'"
                . " onclick=\"return confirm('" . Compat::e(__('¿Completar el preventivo? Se registrará la fecha de hoy y se recalculará la próxima.', 'mantenimientos')) . "');\">"
                . "<i class='ti ti-circle-check me-1'></i>" . Compat::e(__('Completar preventivo', 'mantenimientos')) . "</button>";
            echo "</div>";
            Html::closeForm();

            // Cancelación (formulario aparte, con motivo)
            echo "<form method='post' action='" . Compat::e($form_url) . "' class='card card-body mb-3'>";
            echo "<input type='hidden' name='id' value='{$id}'>";
            echo "<div class='row g-2 align-items-end'><div class='col-md-9'>";
            echo "<label class='form-label'>" . Compat::e(__('Cancelar este preventivo', 'mantenimientos')) . "</label>";
            echo "<input type='text' name='cancel_reason' class='form-control' required maxlength='500' placeholder='"
                . Compat::e(__('Motivo (ej.: el usuario no entregó el equipo)', 'mantenimientos')) . "'></div>";
            echo "<div class='col-md-3'><button type='submit' name='cancel' value='1' class='btn btn-outline-danger w-100'>"
                . "<i class='ti ti-ban me-1'></i>" . Compat::e(__('Cancelar preventivo', 'mantenimientos')) . "</button></div></div>";
            Html::closeForm();

            // La justificación pasa a obligatoria al marcar No realizado / No aplica
            $placeholder_req = Compat::e(__('Justificación (obligatoria)', 'mantenimientos'));
            $placeholder_opt = Compat::e(__('Comentario (opcional)', 'mantenimientos'));
            echo <<<JS
<script>
document.querySelectorAll('#mant-checklist-form .mant-result').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var input = document.getElementById('comment_' + this.dataset.check);
        if (!input) { return; }
        var needs = this.value === '2' || this.value === '3';
        input.required = needs;
        input.placeholder = needs ? '{$placeholder_req}' : '{$placeholder_opt}';
        if (needs) { input.focus(); }
    });
});
</script>
JS;
        }

        return true;
    }

    // =====================================================================
    // Pestaña en la ficha del equipo: preventivo abierto + historial
    // =====================================================================

    public static function showHistoryForAsset(CommonDBTM $asset): void
    {
        $itemtype = $asset::class;
        $items_id = (int) $asset->getID();

        if (Session::haveRight(Maintenance::$rightname, CREATE)) {
            $open = self::findOpenPreventive($itemtype, $items_id);
            echo "<div class='my-3'>";
            if ($open !== null) {
                echo "<a class='btn btn-primary' href='" . Compat::e(Maintenance::getFormURLWithID($open)) . "'>"
                    . "<i class='ti ti-clipboard-check me-1'></i>" . Compat::e(sprintf(__('Continuar preventivo #%d', 'mantenimientos'), $open)) . "</a>";
            } else {
                echo "<form method='post' action='" . Compat::e(Maintenance::getFormURL()) . "' class='d-inline'>";
                echo "<input type='hidden' name='itemtype' value='" . Compat::e($itemtype) . "'>";
                echo "<input type='hidden' name='items_id' value='{$items_id}'>";
                echo "<button type='submit' name='start_preventive' value='1' class='btn btn-primary'>"
                    . "<i class='ti ti-clipboard-plus me-1'></i>" . Compat::e(__('Registrar preventivo', 'mantenimientos')) . "</button>";
                Html::closeForm();
            }
            echo "</div>";
        }

        $rows = (new Maintenance())->find(['itemtype' => $itemtype, 'items_id' => $items_id], ['id DESC'], 20);
        echo "<div class='card'><div class='card-header'><h4 class='card-title mb-0'>"
            . Compat::e(__('Historial de mantenimientos', 'mantenimientos')) . "</h4></div>";
        if ($rows === []) {
            echo "<div class='card-body text-muted'>" . Compat::e(__('Sin mantenimientos registrados.', 'mantenimientos')) . "</div></div>";
            return;
        }
        echo "<table class='table table-hover card-table mb-0'><thead><tr>";
        foreach (['#', __('Tipo', 'mantenimientos'), __('Estado', 'mantenimientos'), __('Inicio', 'mantenimientos'), __('Cierre', 'mantenimientos'), __('Técnico', 'mantenimientos')] as $th) {
            echo "<th>" . Compat::e($th) . "</th>";
        }
        echo "</tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr><td><a href='" . Compat::e(Maintenance::getFormURLWithID((int) $row['id'])) . "'>" . (int) $row['id'] . "</a></td>";
            echo "<td>" . Compat::e(self::getMaintenanceTypes()[(int) $row['maintenance_type']] ?? '') . "</td>";
            echo "<td>" . self::statusBadge((int) $row['status']) . "</td>";
            echo "<td>" . Compat::e($row['date_start'] ? Html::convDateTime($row['date_start']) : '—') . "</td>";
            echo "<td>" . Compat::e($row['date_end'] ? Html::convDateTime($row['date_end']) : '—') . "</td>";
            echo "<td>" . ((int) $row['users_id_tech'] > 0 ? Compat::e(getUserName((int) $row['users_id_tech']), true) : '—') . "</td></tr>";
        }
        echo "</tbody></table></div>";
    }

    // =====================================================================
    // Búsqueda: historial general (Herramientas > Mantenimientos > Historial)
    // =====================================================================

    public function rawSearchOptions()
    {
        $tab = [];
        $tab[] = ['id' => 'common', 'name' => self::getTypeName(Session::getPluralNumber())];
        $tab[] = ['id' => '2', 'table' => $this->getTable(), 'field' => 'id', 'name' => __('ID'), 'datatype' => 'itemlink', 'massiveaction' => false];
        $tab[] = ['id' => '3', 'table' => $this->getTable(), 'field' => 'maintenance_type', 'name' => __('Tipo', 'mantenimientos'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false];
        $tab[] = ['id' => '4', 'table' => $this->getTable(), 'field' => 'status', 'name' => __('Estado', 'mantenimientos'),
            'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false];
        $tab[] = ['id' => '5', 'table' => $this->getTable(), 'field' => 'items_id', 'name' => __('Equipo', 'mantenimientos'),
            'datatype' => 'specific', 'additionalfields' => ['itemtype'], 'nosearch' => true, 'massiveaction' => false];
        $tab[] = ['id' => '6', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech',
            'name' => __('Técnico', 'mantenimientos'), 'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => '7', 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_assigned',
            'name' => __('Usuario asignado', 'mantenimientos'), 'datatype' => 'dropdown', 'right' => 'all'];
        $tab[] = ['id' => '8', 'table' => Plan::getTable(), 'field' => 'name', 'name' => Plan::getTypeName(1), 'datatype' => 'dropdown'];
        $tab[] = ['id' => '9', 'table' => $this->getTable(), 'field' => 'date_start', 'name' => __('Inicio', 'mantenimientos'), 'datatype' => 'datetime'];
        $tab[] = ['id' => '10', 'table' => $this->getTable(), 'field' => 'date_end', 'name' => __('Cierre', 'mantenimientos'), 'datatype' => 'datetime'];
        $tab[] = ['id' => '11', 'table' => $this->getTable(), 'field' => 'scheduled_date', 'name' => __('Fecha programada', 'mantenimientos'), 'datatype' => 'date'];
        $tab[] = ['id' => '12', 'table' => $this->getTable(), 'field' => 'observations', 'name' => __('Observaciones generales', 'mantenimientos'), 'datatype' => 'text'];
        $tab[] = ['id' => '80', 'table' => 'glpi_entities', 'field' => 'completename', 'name' => \Entity::getTypeName(1), 'datatype' => 'dropdown'];
        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'maintenance_type':
                return Compat::e(self::getMaintenanceTypes()[(int) $values[$field]] ?? '');
            case 'status':
                return self::statusBadge((int) $values[$field]);
            case 'items_id':
                $itemtype = $values['itemtype'] ?? '';
                if (array_key_exists($itemtype, Plan::SUPPORTED_ITEMTYPES)) {
                    $asset = new $itemtype();
                    if ($asset->getFromDB((int) $values[$field])) {
                        return Compat::e($asset->getName(), true);
                    }
                }
                return '#' . (int) $values[$field];
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        $options['value']   = $values[$field];
        switch ($field) {
            case 'maintenance_type':
                return \Dropdown::showFromArray($name, self::getMaintenanceTypes(), $options);
            case 'status':
                return \Dropdown::showFromArray($name, self::getStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([Check::class, Cost::class, StatusLog::class]);
    }
}
