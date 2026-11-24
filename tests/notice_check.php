<?php
/**
 * Pruebas sin GLPI de las reglas de los avisos en Windows (Notice::shouldShow)
 * y del instalador generado (Notice::buildInstaller).
 * Uso: php tests/notice_check.php
 */
define('GLPI_VERSION', '11.0.6');
eval('abstract class CommonGLPI { public static $rightname = "";
    public static function getTypeName($nb = 0) { return ""; }
    public static function getTable() { return "glpi_plugin_mantenimientos_" . strtolower((new ReflectionClass(static::class))->getShortName()) . "s"; } }
class CommonDBTM extends CommonGLPI { public $dohistory = false; public $fields = []; }');
function __($s, $d = '') { return $s; } function _n($a, $b, $n, $d = '') { return $n > 1 ? $b : $a; }
$CFG_GLPI = ['url_base' => 'https://glpi.ejemplo.local'];

spl_autoload_register(function ($c) {
    $p = 'GlpiPlugin\\Mantenimientos\\';
    if (str_starts_with($c, $p)) { require __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; }
});

use GlpiPlugin\Mantenimientos\Base\ConfigBase;
use GlpiPlugin\Mantenimientos\Notice;

// Configuración simulada (sin base de datos)
$cache = new ReflectionProperty(ConfigBase::class, 'cache');
$cache->setValue(null, [
    'notify_token' => "abc'123", 'notify_poll_minutes' => '45',
    'notify_reshow_hours' => '4', 'notify_repeat_hours' => '24',
]);

$fails = 0;
$check = function (string $label, bool $ok) use (&$fails) {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FALLA', $label);
    $fails += $ok ? 0 : 1;
};

$s   = ['reshow_hours' => 4, 'repeat_hours' => 24];
$now = new DateTimeImmutable('2026-11-20 10:00:00');
$h   = fn (int $hours) => $now->modify("-{$hours} hours")->format('Y-m-d H:i:s');

echo "Reglas de repetición\n";
$check('nunca mostrado -> se muestra', Notice::shouldShow(null, 'warning', false, $now, $s));
$check('mostrado hace 1 h sin respuesta -> no', !Notice::shouldShow(['date_last_shown' => $h(1)], 'warning', false, $now, $s));
$check('mostrado hace 1 h, inicio de sesión -> sí', Notice::shouldShow(['date_last_shown' => $h(1)], 'warning', true, $now, $s));
$check('mostrado hace 5 h sin respuesta -> sí', Notice::shouldShow(['date_last_shown' => $h(5)], 'warning', false, $now, $s));
$check('pospuesto vigente -> no (ni al iniciar sesión)', !Notice::shouldShow(
    ['date_last_shown' => $h(5), 'snooze_until' => $now->modify('+1 hour')->format('Y-m-d H:i:s')], 'overdue', true, $now, $s));
$check('pospuesto vencido -> sí', Notice::shouldShow(
    ['date_last_shown' => $h(5), 'snooze_until' => $h(1)], 'warning', false, $now, $s));
$check('entendido (amarillo) hace 2 h -> no, ni al iniciar sesión', !Notice::shouldShow(
    ['date_last_shown' => $h(3), 'ack_action' => 1, 'date_ack' => $h(2)], 'warning', true, $now, $s));
$check('entendido (amarillo) hace 25 h, mantenimiento sin hacer -> vuelve', Notice::shouldShow(
    ['date_last_shown' => $h(26), 'ack_action' => 1, 'date_ack' => $h(25)], 'warning', false, $now, $s));
$check('entendido (programado) hace 25 h -> vuelve', Notice::shouldShow(
    ['date_last_shown' => $h(26), 'ack_action' => 1, 'date_ack' => $h(25)], 'scheduled', false, $now, $s));
$check('entendido vencido hace 2 h -> no', !Notice::shouldShow(
    ['date_last_shown' => $h(3), 'ack_action' => 1, 'date_ack' => $h(2)], 'overdue', true, $now, $s));
$check('entendido vencido hace 25 h -> sí', Notice::shouldShow(
    ['date_last_shown' => $h(26), 'ack_action' => 1, 'date_ack' => $h(25)], 'overdue', false, $now, $s));
$check('vencido re-mostrado hace 1 h tras aceptarlo -> no', !Notice::shouldShow(
    ['date_last_shown' => $h(1), 'ack_action' => 1, 'date_ack' => $h(30)], 'overdue', false, $now, $s));
$check('vencido re-mostrado hace 5 h tras aceptarlo -> sí', Notice::shouldShow(
    ['date_last_shown' => $h(5), 'ack_action' => 1, 'date_ack' => $h(30)], 'overdue', false, $now, $s));

echo "Niveles\n";
$check('orden warning < critical < overdue', Notice::levelRank('warning') < Notice::levelRank('critical')
    && Notice::levelRank('critical') < Notice::levelRank('overdue') && Notice::levelRank('ok') === 0);
$check('serial genérico se descarta', !Notice::isUsableSerial('To be filled by O.E.M.') && Notice::isUsableSerial('PF3ABC12'));

echo "Instalador generado\n";
$ps = Notice::buildInstaller("Soporte TI d'AED");
$check('empieza con BOM', str_starts_with($ps, "\xEF\xBB\xBF"));
$check('sin marcadores pendientes', !preg_match("/'__(ENDPOINT|TOKEN|INTERVALO|NOMBRE|B64_CLIENTE|B64_VBS)__'/", $ps));
$check('endpoint', str_contains($ps, "'https://glpi.ejemplo.local/plugins/mantenimientos/front/notify.php'"));
$check('token con comilla escapada', str_contains($ps, "[string]\$Token = 'abc''123'"));
$check('intervalo', str_contains($ps, "[string]\$IntervaloMinutos = '45'"));
$check('nombre con comilla escapada', str_contains($ps, "'Soporte TI d''AED'"));
$check('finales de línea CRLF', !preg_match("/(?<!\r)\n/", $ps));
preg_match("/'MantenimientosAvisos\.ps1' = '([A-Za-z0-9+\/=]+)'/", $ps, $m);
$check('cliente incluido completo', isset($m[1]) && base64_decode($m[1]) === file_get_contents(__DIR__ . '/../tools/windows/MantenimientosAvisos.ps1'));

echo $fails === 0 ? "OK\n" : "$fails FALLAS\n";
exit($fails === 0 ? 0 : 1);
