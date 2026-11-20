<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Avisos en Windows: configuración, instalador para los equipos, vista previa
 * y registro de lo que vio cada usuario.
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Config;
use GlpiPlugin\Mantenimientos\Item;
use GlpiPlugin\Mantenimientos\Menu;
use GlpiPlugin\Mantenimientos\Notice;
use GlpiPlugin\Mantenimientos\Ui;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

Session::checkRight(Config::$rightname, READ);
$can_update = Session::haveRight(Config::$rightname, UPDATE);
$self_url   = Menu::WEB_ROOT . '/front/notify.config.php';

// ---------------------------------------------------------------------------
// Acciones
// ---------------------------------------------------------------------------

if (isset($_POST['save'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    $min = (string) ($_POST['notify_min_light'] ?? '');
    Config::setValue('notify_enabled', !empty($_POST['notify_enabled']) ? '1' : '0');
    Config::setValue('notify_min_light', array_key_exists($min, Notice::getMinLightChoices()) ? $min : Notice::LEVEL_WARNING);
    Config::setValue('notify_scheduled', !empty($_POST['notify_scheduled']) ? '1' : '0');
    foreach (
        [
            'notify_reshow_hours' => [1, 168],
            'notify_snooze_hours' => [1, 168],
            'notify_repeat_hours' => [1, 720],
            'notify_poll_minutes' => [15, 1440],
        ] as $name => [$lo, $hi]
    ) {
        Config::setValue($name, (string) min($hi, max($lo, (int) ($_POST[$name] ?? $lo))));
    }
    Config::setValue('notify_contact', mb_substr(trim((string) ($_POST['notify_contact'] ?? '')), 0, 255));
    $display = mb_substr(trim((string) ($_POST['notify_display_name'] ?? '')), 0, 60);
    Config::setValue('notify_display_name', $display !== '' ? $display : 'Mantenimiento de equipos');
    Session::addMessageAfterRedirect(__('Configuración de avisos guardada.', 'mantenimientos'));
    Html::back();
} elseif (isset($_POST['send_test'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    $test_computer = Notice::getComputerById((int) ($_POST['computers_id'] ?? 0));
    if ($test_computer === null) {
        Session::addMessageAfterRedirect(__('No se encontró el computador.', 'mantenimientos'), false, ERROR);
    } else {
        Notice::queueTest($test_computer, (string) ($_SESSION['glpifriendlyname'] ?? ''));
        Session::addMessageAfterRedirect(sprintf(
            __('Aviso de prueba en cola para %1$s. Se mostrará en la próxima consulta del equipo (cada %2$d minutos) o al iniciar sesión.', 'mantenimientos'),
            (string) $test_computer['name'],
            Notice::getSettings()['poll_minutes']
        ));
    }
    Html::back();
} elseif (isset($_POST['cancel_test'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    Notice::cancelTest((int) ($_POST['notices_id'] ?? 0));
    Session::addMessageAfterRedirect(__('Aviso de prueba cancelado.', 'mantenimientos'));
    Html::back();
} elseif (isset($_POST['regenerate_token'])) {
    Session::checkRight(Config::$rightname, UPDATE);
    Config::setValue('notify_token', Notice::generateToken());
    Session::addMessageAfterRedirect(
        __('Token regenerado. Vuelva a desplegar el instalador: los equipos con el token anterior dejarán de recibir avisos.', 'mantenimientos'),
        false,
        WARNING
    );
    Html::back();
}

if (($_GET['download'] ?? '') === 'installer') {
    // Contiene el token: solo quien puede modificar la configuración
    Session::checkRight(Config::$rightname, UPDATE);
    $display = (string) Config::getValue('notify_display_name', 'Mantenimiento de equipos');
    if (Compat::isGlpi10()) {
        $display = html_entity_decode($display, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $content = Notice::buildInstaller($display);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="Instalar-AvisosMantenimiento.ps1"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store');
    echo $content;
    return;
}

// ---------------------------------------------------------------------------
// Pantalla
// ---------------------------------------------------------------------------

Html::header(Notice::getTypeName(Session::getPluralNumber()), '', 'tools', Menu::class, 'notify');

$settings = Notice::getSettings();
$token    = (string) Config::getValue('notify_token', '');
$display  = (string) Config::getValue('notify_display_name', 'Mantenimiento de equipos');
$disabled = $can_update ? '' : ' disabled';
$t        = static fn (string $s): string => Compat::e(__($s, 'mantenimientos'));

echo "<div class='container-fluid'>";

// Resumen de los últimos 30 días
$sum    = Notice::getSummary(30);
$lights = Item::getLights();
echo "<h4 class='mb-2'>" . $t('Avisos de los últimos 30 días') . "</h4>";
echo Ui::tiles([
    ['value' => $sum['computers'], 'label' => __('Equipos avisados', 'mantenimientos'), 'color' => '#6495d6', 'text' => '#fff', 'icon' => 'ti ti-device-desktop'],
    ['value' => $sum['done'], 'label' => __('Entendidos', 'mantenimientos'), 'color' => $lights['ok']['tile'], 'icon' => 'ti ti-circle-check'],
    ['value' => $sum['snoozed'], 'label' => __('Pospuestos', 'mantenimientos'), 'color' => $lights['warning']['tile'], 'icon' => 'ti ti-clock-pause'],
    ['value' => $sum['pending'], 'label' => __('Sin respuesta', 'mantenimientos'), 'color' => $lights['critical']['tile'], 'icon' => 'ti ti-bell-question'],
]);

echo "<div class='row g-3'>";

// --- Configuración ---------------------------------------------------------
echo "<div class='col-xl-6'><form method='post' action='" . Compat::e($self_url) . "' class='card h-100'>";
echo "<div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-settings me-2'></i>" . $t('Configuración') . "</h3></div>";
echo "<div class='card-body'>";

if (!$settings['enabled']) {
    echo "<div class='alert alert-warning'>" . $t('Los avisos están desactivados: los equipos consultan pero no reciben nada.') . "</div>";
}

$check = static function (string $name, string $label, bool $on) use ($disabled): string {
    return "<label class='form-check form-switch mb-2'><input class='form-check-input' type='checkbox' name='{$name}' value='1'"
        . ($on ? ' checked' : '') . "{$disabled}><span class='form-check-label'>" . Compat::e($label) . "</span></label>";
};
$number = static function (string $name, string $label, int $value, int $min, int $max, string $help) use ($disabled): string {
    return "<div class='col-md-6'><label class='form-label'>" . Compat::e($label) . "</label>"
        . "<input type='number' class='form-control' name='{$name}' value='{$value}' min='{$min}' max='{$max}'{$disabled}>"
        . "<div class='form-hint'>" . Compat::e($help) . "</div></div>";
};

echo $check('notify_enabled', __('Enviar avisos a los equipos', 'mantenimientos'), $settings['enabled']);
echo $check('notify_scheduled', __('Avisar los mantenimientos programados con fecha', 'mantenimientos'), $settings['scheduled']);

echo "<div class='row g-3 mt-1'>";
echo "<div class='col-md-6'><label class='form-label'>" . $t('Avisar el preventivo') . "</label><select name='notify_min_light' class='form-select'{$disabled}>";
foreach (Notice::getMinLightChoices() as $value => $label) {
    $sel = $settings['min_light'] === $value ? ' selected' : '';
    echo "<option value='" . Compat::e($value) . "'{$sel}>" . Compat::e($label) . "</option>";
}
echo "</select><div class='form-hint'>" . $t('Cada cambio de color vuelve a avisar.') . "</div></div>";
echo $number('notify_poll_minutes', __('Consulta cada (minutos)', 'mantenimientos'), $settings['poll_minutes'], 15, 1440, __('Se aplica al descargar de nuevo el instalador.', 'mantenimientos'));
echo $number('notify_reshow_hours', __('Repetir si no responde (horas)', 'mantenimientos'), $settings['reshow_hours'], 1, 168, __('Al iniciar sesión siempre se muestran los pendientes.', 'mantenimientos'));
echo $number('notify_snooze_hours', __('"Recordar más tarde" (horas)', 'mantenimientos'), $settings['snooze_hours'], 1, 168, __('Tiempo que se oculta el aviso.', 'mantenimientos'));
echo $number('notify_repeat_hours', __('Vencidos: repetir cada (horas)', 'mantenimientos'), $settings['repeat_hours'], 1, 720, __('Aunque el usuario ya haya pulsado "Entendido".', 'mantenimientos'));
echo "<div class='col-md-6'><label class='form-label'>" . $t('Nombre que muestra Windows') . "</label>"
    . "<input type='text' class='form-control' name='notify_display_name' maxlength='60' value='" . Compat::e($display, true) . "'{$disabled}>"
    . "<div class='form-hint'>" . $t('Se aplica al descargar de nuevo el instalador.') . "</div></div>";
echo "<div class='col-12'><label class='form-label'>" . $t('Texto de contacto al final del aviso') . "</label>"
    . "<input type='text' class='form-control' name='notify_contact' maxlength='255' placeholder='" . $t('Ej.: Soporte TI, extensión 123') . "' value='"
    . Compat::e($settings['contact'], true) . "'{$disabled}></div>";
echo "</div></div>";

if ($can_update) {
    echo "<div class='card-footer text-end'><button type='submit' name='save' value='1' class='btn btn-primary'><i class='ti ti-device-floppy me-1'></i>"
        . $t('Guardar') . "</button></div>";
}
Html::closeForm();
echo "</div>";

// --- Instalación en los equipos -------------------------------------------
echo "<div class='col-xl-6'><div class='card h-100'>";
echo "<div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-brand-windows me-2'></i>" . $t('Instalación en los equipos') . "</h3></div>";
echo "<div class='card-body'>";
echo "<p>" . $t('Los equipos con Windows 10/11 consultan esta dirección y muestran notificaciones nativas con los botones "Entendido" y "Recordar más tarde". Se identifican por número de serie (o por nombre si el serial es genérico).') . "</p>";
echo "<label class='form-label'>" . $t('Dirección que consultan los equipos') . "</label>";
echo "<input type='text' class='form-control mb-3' readonly value='" . Compat::e(Notice::getEndpointUrl()) . "' onclick='this.select()'>";

if ($can_update) {
    echo "<label class='form-label'>" . $t('Token') . "</label>";
    echo "<div class='input-group mb-1'><input type='password' class='form-control' readonly id='mant-token' value='" . Compat::e($token) . "'>";
    echo "<button type='button' class='btn btn-outline-secondary' onclick=\"var f=document.getElementById('mant-token');f.type=f.type==='password'?'text':'password';\"><i class='ti ti-eye'></i></button></div>";
    echo "<div class='form-hint mb-3'>" . $t('Va dentro del instalador. Si se filtra, regenérelo y vuelva a desplegar.') . "</div>";

    echo "<ol class='mb-3'>";
    echo "<li>" . $t('Descargue el instalador (ya trae la dirección, el token y los archivos).') . "</li>";
    echo "<li>" . $t('En GLPI Inventory cree un paquete de despliegue: archivo = el instalador; acción "Ejecutar comando":') . "<br><code>powershell.exe -NoProfile -ExecutionPolicy Bypass -File Instalar-AvisosMantenimiento.ps1</code></li>";
    echo "<li>" . $t('Asigne el paquete a los equipos con una tarea de despliegue. También se puede ejecutar a mano como administrador.') . "</li>";
    echo "<li>" . $t('Prueba en un equipo, con el usuario normal (CMD):') . "<br><code>wscript \"%ProgramData%\\MantenimientosAvisos\\ejecutar-oculto.vbs\" -Prueba</code><br>"
        . $t('En PowerShell:') . "<br><code>wscript \"\$env:ProgramData\\MantenimientosAvisos\\ejecutar-oculto.vbs\" -Prueba</code></li>";
    echo "</ol>";
    echo "<p class='text-muted small'>" . $t('Para quitarlo: el mismo instalador con -Desinstalar.') . "</p>";

    echo "<div class='d-flex gap-2 flex-wrap'>";
    echo "<a class='btn btn-primary' href='" . Compat::e($self_url . '?download=installer') . "'><i class='ti ti-download me-1'></i>" . $t('Descargar instalador') . "</a>";
    echo "<form method='post' action='" . Compat::e($self_url) . "' class='d-inline' onsubmit=\"return confirm('"
        . Compat::e(__('Los equipos con el token actual dejarán de recibir avisos hasta reinstalar. ¿Continuar?', 'mantenimientos')) . "');\">";
    echo "<button type='submit' name='regenerate_token' value='1' class='btn btn-outline-danger'><i class='ti ti-refresh me-1'></i>" . $t('Regenerar token') . "</button>";
    Html::closeForm();
    echo "</div>";
} else {
    echo "<div class='alert alert-info'>" . $t('Se necesita permiso de actualizar la configuración para descargar el instalador.') . "</div>";
}
echo "</div></div></div>";

// --- Vista previa y equipos con aviso hoy ----------------------------------
$preview_id = (int) ($_GET['preview_id'] ?? 0);
$preview_q  = mb_substr(trim((string) ($_GET['preview'] ?? '')), 0, 255);

$computers = Item::getRows(['itemtype' => Computer::class]);
$ids       = array_map(static fn ($r) => (int) $r['items_id'], $computers);
$info      = Notice::getComputerInfo($ids);
$last      = Notice::getLastByComputer($ids);
$lights    = Item::getLights();
$min_rank  = Notice::levelRank($settings['min_light']);

echo "<div class='col-12'><div class='card'>";
echo "<div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-eye me-2'></i>" . $t('Vista previa por equipo') . "</h3></div>";
echo "<div class='card-body'>";

// Buscador con autocompletado (datalist: igual en GLPI 10, 11 y 12)
echo "<form method='get' action='" . Compat::e($self_url) . "' class='row g-2 align-items-end mb-3' id='mant-preview-form'>";
echo "<div class='col-md-6'><label class='form-label'>" . $t('Computador (nombre, serial o usuario)') . "</label>"
    . "<input type='text' name='preview' list='mant-computers' autocomplete='off' class='form-control' id='mant-preview-input' placeholder='"
    . $t('Escriba para buscar…') . "' value='" . Compat::e($preview_q, true) . "'>";
echo "<datalist id='mant-computers'>";
foreach ($computers as $r) {
    $label = implode(' · ', array_filter([
        $info[(int) $r['items_id']]['serial'] ?? '',
        ($info[(int) $r['items_id']]['contact'] ?? '') ?: (string) $r['user'],
        $lights[$r['light']]['label'] ?? '',
    ], static fn ($v) => $v !== ''));
    echo "<option value='" . Compat::e((string) $r['asset_name'], true) . "' label='" . Compat::e($label, true) . "'></option>";
}
echo "</datalist></div>";
echo "<div class='col-md-2'><button type='submit' class='btn btn-primary w-100'><i class='ti ti-search me-1'></i>" . $t('Ver avisos') . "</button></div>";
echo "<div class='col-md-4 text-muted small'>" . $t('Muestra lo que recibiría ese equipo hoy, sin registrarlo como visto.') . "</div>";
echo "</form>";
echo "<script>(function () {
    var input = document.getElementById('mant-preview-input'), list = document.getElementById('mant-computers');
    if (!input || !list) { return; }
    input.addEventListener('change', function () {
        for (var i = 0; i < list.options.length; i++) {
            if (list.options[i].value === input.value) { document.getElementById('mant-preview-form').submit(); return; }
        }
    });
})();</script>";

// Resultado de la vista previa
$computer = null;
if ($preview_id > 0) {
    $computer = Notice::getComputerById($preview_id);
} elseif ($preview_q !== '') {
    $computer = Notice::findComputer($preview_q, $preview_q);
}
if (($preview_id > 0 || $preview_q !== '') && $computer === null) {
    echo "<div class='alert alert-warning'>" . $t('No se encontró un computador con ese serial o nombre.') . "</div>";
} elseif ($computer !== null) {
    $candidates = Notice::buildCandidates($computer);
    echo "<div class='border rounded p-3 mb-3 bg-light'>";
    echo "<div class='mb-2'><i class='ti ti-device-desktop me-1'></i><a href='" . Compat::e(Computer::getFormURLWithID((int) $computer['id'])) . "'><strong>"
        . Compat::e((string) $computer['name'], true) . "</strong></a>"
        . ($computer['serial'] ? " <span class='text-muted'>· " . Compat::e((string) $computer['serial'], true) . "</span>" : '') . "</div>";
    if ($candidates === []) {
        echo "<div class='text-success'><i class='ti ti-circle-check me-1'></i>" . $t('Este equipo no tiene avisos hoy.') . "</div>";
    }
    foreach ($candidates as $c) {
        echo "<div class='border rounded p-3 mb-2 bg-white'>" . Notice::levelBadge($c['level']) . " <strong class='ms-1'>"
            . Compat::e($c['title'], true) . "</strong><div class='mt-1'>" . Compat::e($c['message'], true) . "</div></div>";
    }
    if (!$settings['enabled']) {
        echo "<div class='text-muted small'>" . $t('(Los avisos están desactivados; así se verían al activarlos.)') . "</div>";
    }

    // Aviso de prueba: confirma de punta a punta que el equipo recibe notificaciones
    $test = Notice::getLastTest((int) $computer['id']);
    echo "<div class='border-top pt-3 mt-3 d-flex flex-wrap align-items-center gap-2'>";
    echo "<span class='fw-bold me-2'><i class='ti ti-send me-1'></i>" . $t('Aviso de prueba') . "</span>";
    if ($test !== null) {
        echo "<span>" . Notice::responseBadge($test) . "</span>";
        if ((int) $test['times_shown'] > 0) {
            echo "<span class='text-muted small'>" . Compat::e(sprintf(
                __('mostrado %1$s a %2$s', 'mantenimientos'),
                Html::convDateTime($test['date_last_shown']),
                (string) $test['windows_user']
            ), true) . "</span>";
        }
    } else {
        echo "<span class='text-muted small'>" . $t('Nunca se ha enviado una prueba a este equipo.') . "</span>";
    }
    if ($can_update) {
        echo "<span class='ms-auto d-flex gap-2'>";
        if ($test !== null && (int) $test['times_shown'] === 0) {
            echo "<form method='post' action='" . Compat::e($self_url) . "' class='d-inline'>"
                . "<input type='hidden' name='notices_id' value='" . (int) $test['id'] . "'>"
                . "<button type='submit' name='cancel_test' value='1' class='btn btn-sm btn-outline-secondary'><i class='ti ti-x me-1'></i>" . $t('Cancelar prueba') . "</button>";
            Html::closeForm();
        } else {
            echo "<form method='post' action='" . Compat::e($self_url) . "' class='d-inline'>"
                . "<input type='hidden' name='computers_id' value='" . (int) $computer['id'] . "'>"
                . "<button type='submit' name='send_test' value='1' class='btn btn-sm btn-primary'><i class='ti ti-send me-1'></i>" . $t('Enviar aviso de prueba') . "</button>";
            Html::closeForm();
        }
        echo "</span>";
    }
    echo "</div>";
    echo "<div class='form-hint mt-1'>" . Compat::e(sprintf(
        __('El equipo no recibe nada al instante: lo toma en su próxima consulta (cada %d minutos) o al iniciar sesión. Recargue esta página para ver si ya se mostró y qué respondió el usuario.', 'mantenimientos'),
        $settings['poll_minutes']
    )) . "</div>";
    if (!$settings['enabled']) {
        echo "<div class='text-warning small mt-1'>" . $t('Con los avisos desactivados el equipo no consulta avisos, ni siquiera los de prueba.') . "</div>";
    }
    echo "</div>";
}

// Equipos que hoy reciben aviso por el semáforo
$due = array_values(array_filter(
    $computers,
    static fn ($r) => Notice::levelRank((string) $r['light']) > 0 && Notice::levelRank((string) $r['light']) >= $min_rank
));
echo "<h4 class='mt-2 mb-2'>" . Compat::e(sprintf(__('Equipos con aviso hoy (%d)', 'mantenimientos'), count($due))) . "</h4>";
if ($due === []) {
    echo "<div class='text-muted'>" . $t('Ningún computador está en el rango de aviso configurado.') . "</div>";
} else {
    echo "<div class='table-responsive'><table class='table table-sm table-hover'><thead><tr>";
    foreach (['Estado', 'Equipo', 'Serial', 'Usuario', 'Próximo', 'Días', 'Último aviso', ''] as $th) {
        echo "<th>" . $t($th) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($due as $r) {
        $id   = (int) $r['items_id'];
        $days = $r['days'];
        $url  = $self_url . '?preview_id=' . $id;
        $sel  = $computer !== null && (int) $computer['id'] === $id ? " class='table-active'" : '';
        echo "<tr{$sel}>";
        echo "<td>" . Item::lightBadge((string) $r['light']) . "</td>";
        echo "<td><a href='" . Compat::e(Computer::getFormURLWithID($id)) . "'>" . Compat::e((string) ($r['asset_name'] ?: '#' . $id), true) . "</a></td>";
        echo "<td>" . Compat::e($info[$id]['serial'] ?? '', true) . "</td>";
        echo "<td>" . Notice::userCell($info[$id]['contact'] ?? '', (string) $r['user']) . "</td>";
        echo "<td>" . Compat::e($r['next_due_date'] ? Html::convDate($r['next_due_date']) : '—') . "</td>";
        echo "<td>" . Compat::e($days === null ? '—' : ($days < 0 ? sprintf(__('%d de atraso', 'mantenimientos'), -$days) : (string) $days)) . "</td>";
        echo "<td>" . (isset($last[$id]) ? Notice::responseBadge($last[$id]) : "<span class='text-muted'>" . $t('Nunca') . "</span>") . "</td>";
        echo "<td class='text-end'><a class='btn btn-sm btn-primary' href='" . Compat::e($url) . "'><i class='ti ti-eye me-1'></i>" . $t('Ver aviso') . "</a></td>";
        echo "</tr>";
    }
    echo "</tbody></table></div>";
}
echo "</div></div></div>";

// --- Registro --------------------------------------------------------------
$rows = Notice::getRecent(100);
echo "<div class='col-12'><div class='card'>";
echo "<div class='card-header'><h3 class='card-title mb-0'><i class='ti ti-list-check me-2'></i>" . $t('Registro de avisos') . "</h3></div>";
echo "<div class='table-responsive'><table class='table table-hover card-table'><thead><tr>";
foreach (['Equipo', 'Usuario', 'Usuario de Windows', 'Aviso', 'Veces', 'Primera vez', 'Última vez', 'Respuesta'] as $th) {
    echo "<th>" . $t($th) . "</th>";
}
echo "</tr></thead><tbody>";
if ($rows === []) {
    echo "<tr><td colspan='8' class='text-center text-muted p-4'>" . $t('Todavía no se ha mostrado ningún aviso.') . "</td></tr>";
}
foreach ($rows as $row) {
    $user   = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? '')) ?: (string) ($row['user_login'] ?? '');
    $answer = Notice::responseBadge($row);

    echo "<tr>";
    echo "<td><a href='" . Compat::e(Computer::getFormURLWithID((int) $row['items_id'])) . "'>"
        . Compat::e((string) ($row['asset_name'] ?: '#' . $row['items_id']), true) . "</a></td>";
    echo "<td>" . Notice::userCell((string) ($row['contact'] ?? ''), $user) . "</td>";
    echo "<td>" . Compat::e((string) $row['windows_user'], true) . "</td>";
    echo "<td>" . Notice::levelBadge((string) $row['level']) . " <span title='" . Compat::e((string) $row['message'], true) . "'>"
        . Compat::e((string) $row['title'], true) . "</span></td>";
    echo "<td>" . (int) $row['times_shown'] . "</td>";
    echo "<td>" . Compat::e(Html::convDateTime($row['date_first_shown'])) . "</td>";
    echo "<td>" . Compat::e(Html::convDateTime($row['date_last_shown'])) . "</td>";
    echo "<td>{$answer}</td>";
    echo "</tr>";
}
echo "</tbody></table></div></div></div>";

echo "</div></div>";

Html::footer();
