<?php

/**
 * -------------------------------------------------------------------------
 * Mantenimientos - Plugin GLPI
 * -------------------------------------------------------------------------
 * @license   GPL-3.0-or-later
 * @link      https://github.com/respanat/mantenimientos_total
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Mantenimientos;

use CommonGLPI;
use Html;
use Session;

/**
 * Pestaña "Mantenimientos" en Administración > Perfiles para asignar permisos.
 * No redeclara \$rightname: hereda el de \Profile ('profile'), válido en GLPI 10, 11 y 12.
 */
class Profile extends \Profile
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof \Profile && $item->getField('id')) {
            return self::createTabEntry(Menu::getTypeName(Session::getPluralNumber()), 0, null, Menu::getIcon());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof \Profile) {
            (new self())->showForm((int) $item->getID());
        }
        return true;
    }

    /**
     * Definición de permisos mostrada en la matriz del perfil.
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Plan::class,
                'label'    => __('Planes de mantenimiento', 'mantenimientos'),
                'field'    => Plan::$rightname,
            ],
            [
                'itemtype' => Maintenance::class,
                'label'    => __('Mantenimientos (ejecución, checklist y costos)', 'mantenimientos'),
                'field'    => Maintenance::$rightname,
            ],
            [
                'itemtype' => Metrics::class,
                'label'    => __('Métricas: seguimiento y control', 'mantenimientos'),
                'field'    => Metrics::$rightname,
                'rights'   => [READ => __('Read')],
            ],
            [
                'itemtype' => Config::class,
                'label'    => __('Configuración del plugin', 'mantenimientos'),
                'field'    => Config::$rightname,
                'rights'   => [READ => __('Read'), UPDATE => __('Update')],
            ],
        ];
    }

    public function showForm($ID, array $options = []): bool
    {
        $profile = new \Profile();
        if (!$profile->getFromDB($ID)) {
            return false;
        }

        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . Compat::e($profile->getFormURL()) . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Mantenimientos', 'mantenimientos'),
        ]);

        if ($canedit) {
            echo "<div class='text-center'>";
            echo Html::hidden('id', ['value' => $ID]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";

        return true;
    }
}
