<?php
/**
 * Prueba de herencia sin GLPI: simula las declaraciones del núcleo (GLPI 10/11
 * sin tipos, GLPI 12 con tipos) y carga las clases del plugin.
 * Uso: php tests/compat_check.php 11   |   php tests/compat_check.php 12
 */
// Simula las declaraciones del núcleo según la versión pasada como argumento.
$typed = ($argv[1] ?? '') === '12';
define('GLPI_VERSION', $typed ? '12.0.0' : '11.0.6');
define('READ', 1); define('UPDATE', 2); define('CREATE', 4); define('PURGE', 16); define('ERROR', 1);
if ($typed) {
    eval('abstract class CommonGLPI { public static string $rightname = "";
        public static function getTypeName($nb = 0) { return ""; }
        public static function canView(): bool { return true; }
        public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) { return ""; }
        public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) { return true; }
        public static function getTable() { return "glpi_plugin_mantenimientos_" . strtolower((new ReflectionClass(static::class))->getShortName()) . "s"; } }
    class CommonDBTM extends CommonGLPI { public bool $dohistory = false; public $fields = [];
        public function showForm($ID, array $options = []) {} public function cleanDBonPurge() {}
        public function prepareInputForAdd($input) { return $input; } public function prepareInputForUpdate($input) { return $input; }
        public function deleteChildrenAndRelationsFromDb(array $c) {} }
    abstract class CommonDBChild extends CommonDBTM { public static string $itemtype; public static string $items_id; }');
} else {
    eval('abstract class CommonGLPI { public static $rightname = "";
        public static function getTypeName($nb = 0) { return ""; }
        public static function canView() { return true; }
        public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) { return ""; }
        public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) { return true; }
        public static function getTable() { return "glpi_plugin_mantenimientos_" . strtolower((new ReflectionClass(static::class))->getShortName()) . "s"; } }
    class CommonDBTM extends CommonGLPI { public $dohistory = false; public $fields = [];
        public function showForm($ID, array $options = []) {} public function cleanDBonPurge() {}
        public function prepareInputForAdd($input) { return $input; } public function prepareInputForUpdate($input) { return $input; }
        public function deleteChildrenAndRelationsFromDb(array $c) {} }
    abstract class CommonDBChild extends CommonDBTM { public static $itemtype; public static $items_id; }');
}
class Profile extends CommonDBTM { }
function __($s, $d = '') { return $s; } function _n($a, $b, $n, $d = '') { return $n > 1 ? $b : $a; }
class Session { public static function haveRight($r, $v) { return true; } public static function getPluralNumber() { return 2; } }
class Computer {} class ComputerType {} class Printer {} class PrinterType {} class Phone {} class PhoneType {}

spl_autoload_register(function ($c) {
    $p = 'GlpiPlugin\\Mantenimientos\\';
    if (str_starts_with($c, $p)) { require __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; }
});

use GlpiPlugin\Mantenimientos as M;
$classes = ['Plan', 'PlanTask', 'Item', 'Maintenance', 'Check', 'Cost', 'StatusLog', 'Config', 'Metrics', 'Menu'];
foreach ($classes as $c) { class_exists("GlpiPlugin\\Mantenimientos\\$c"); }

// Cada clase debe tener su propio rightname (no compartir el estático del padre)
printf("GLPI %s | typed=%s\n", GLPI_VERSION, var_export(M\Compat::typedCoreProperties(), true));
foreach (['Plan', 'PlanTask', 'Item', 'Maintenance', 'Check', 'Cost', 'StatusLog', 'Config', 'Metrics'] as $c) {
    $fq = "GlpiPlugin\\Mantenimientos\\$c";
    printf("  %-12s rightname=%-36s table=%s\n", $c, $fq::$rightname, $fq::getTable());
}
printf("  CommonGLPI::\$rightname intacto: '%s'\n", CommonGLPI::$rightname);
printf("  PlanTask itemtype=%s items_id=%s | Cost itemtype=%s\n", M\PlanTask::$itemtype, M\PlanTask::$items_id, M\Cost::$itemtype);
printf("  dohistory Plan=%s Item=%s\n", var_export((new M\Plan())->dohistory, true), var_export((new M\Item())->dohistory, true));
printf("  transición Programado->En ejecución: %s | Reportado->Reparado: %s\n",
    var_export(M\Maintenance::canTransition(M\Maintenance::STATUS_SCHEDULED, M\Maintenance::STATUS_IN_PROGRESS), true),
    var_export(M\Maintenance::canTransition(M\Maintenance::STATUS_REPORTED, M\Maintenance::STATUS_REPAIRED), true));
echo "  Menu::canView(): " . var_export(M\Menu::canView(), true) . "\n";
echo "  OK\n";
