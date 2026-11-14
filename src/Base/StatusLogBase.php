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

use CommonDBChild;
use GlpiPlugin\Mantenimientos\StatusLog;

/**
 * Bitácora de cambios de estado. Base de las métricas de tiempos
 * (tiempo de reparación, tiempo en proveedor, etc.).
 * Lógica común (GLPI 10, 11 y 12). La clase final declara las propiedades
 * estáticas según la versión: ver ../StatusLog.php y Compat::typedCoreProperties().
 * Tabla: glpi_plugin_mantenimientos_statuslogs
 */
abstract class StatusLogBase extends CommonDBChild
{
    public static function getTypeName($nb = 0)
    {
        return _n('Cambio de estado', 'Cambios de estado', $nb, 'mantenimientos');
    }

    /**
     * Registra un cambio de estado.
     */
    public static function record(int $maintenances_id, int $old_status, int $new_status, string $comment = ''): void
    {
        (new StatusLog())->add([
            'plugin_mantenimientos_maintenances_id' => $maintenances_id,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'comment'    => $comment,
            'users_id'   => \Session::getLoginUserID() ?: 0,
            'date'       => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }
}
