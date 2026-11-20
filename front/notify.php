<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * Canal de avisos en Windows: lo consultan los equipos, no los usuarios de GLPI.
 *
 * Sin sesión de GLPI: se protege con el token del plugin (cabecera X-Mant-Token
 * o parámetro token). Solo GET, así no aplica la verificación CSRF.
 * En GLPI 11+ setup.php lo declara en el firewall como público y sin estado.
 *
 *   GET notify.php?action=pending&serial=..&name=..&user=..&logon=1
 *   GET notify.php?action=respond&id=..&answer=done|snooze&serial=..&name=..&user=..
 *   GET notify.php?action=ping
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Mantenimientos\Compat;
use GlpiPlugin\Mantenimientos\Notice;

// GLPI 10 necesita el bootstrap clásico; en 11 y 12 el núcleo ya está cargado.
if (!defined('GLPI_ROOT')) {
    include __DIR__ . '/../../../inc/includes.php';
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$mant_reply = static function (int $code, array $data): void {
    // GLPI 10 guarda el texto codificado en HTML; el cliente necesita texto plano.
    if (Compat::isGlpi10()) {
        array_walk_recursive($data, static function (&$v) {
            if (is_string($v)) {
                $v = html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });
    }
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$mant_param = static fn (string $name, int $max = 255): string => mb_substr(trim((string) ($_GET[$name] ?? '')), 0, $max);

$mant_token = (string) ($_SERVER['HTTP_X_MANT_TOKEN'] ?? $mant_param('token', 200));
$mant_action = $mant_param('action', 20) ?: 'pending';

if (!Notice::checkToken($mant_token)) {
    $mant_reply(403, ['error' => 'token']);
    return;
}

$mant_settings = Notice::getSettings();
$mant_base     = [
    'enabled'      => $mant_settings['enabled'],
    'poll_minutes' => $mant_settings['poll_minutes'],
];

if ($mant_action === 'ping') {
    $mant_reply(200, ['ok' => true] + $mant_base);
    return;
}

if (!$mant_settings['enabled']) {
    $mant_reply(200, $mant_base + ['notices' => []]);
    return;
}

$mant_computer = Notice::findComputer($mant_param('serial'), $mant_param('name'));
if ($mant_computer === null) {
    $mant_reply(200, $mant_base + ['found' => false, 'notices' => []]);
    return;
}

$mant_user = $mant_param('user');

if ($mant_action === 'respond') {
    $ok = Notice::respond(
        (int) ($_GET['id'] ?? 0),
        (int) $mant_computer['id'],
        $mant_param('answer', 10),
        $mant_user
    );
    $mant_reply($ok ? 200 : 404, $mant_base + ['ok' => $ok]);
    return;
}

if ($mant_action !== 'pending') {
    $mant_reply(400, ['error' => 'action']);
    return;
}

$mant_notices = Notice::getPendingFor(
    $mant_computer,
    ($_GET['logon'] ?? '') === '1',
    $mant_user,
    $mant_param('name')
);

$mant_reply(200, $mant_base + [
    'found'   => true,
    'notices' => array_map(static fn (array $n) => [
        'id'       => $n['id'],
        'level'    => $n['level'],
        'title'    => $n['title'],
        'message'  => $n['message'],
        // Los vencidos se quedan en pantalla hasta que el usuario responda
        'sticky'   => $n['level'] === Notice::LEVEL_OVERDUE,
    ], $mant_notices),
]);
