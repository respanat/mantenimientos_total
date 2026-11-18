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

/**
 * Checklists sugeridos por tipo de equipo.
 *
 * Se cargan en un plan con el botón "Cargar checklist sugerido" y luego se
 * ajustan a mano en el plan (editar, eliminar, agregar). Para cambiar la base
 * para todos los planes nuevos, se edita esta lista.
 *
 * Formato de cada tarea: [nombre, obligatoria (bool), descripción]
 */
final class DefaultChecklist
{
    /**
     * @return array<string, list<array{0: string, 1: bool, 2: string}>>
     */
    public static function all(): array
    {
        return [
            // PC de escritorio y portátiles (subtipos Desktop, Notebook, Convertible…)
            \Computer::class => [
                ['Limpieza externa (carcasa, teclado, pantalla)', true, ''],
                ['Limpieza interna con aire comprimido (ventiladores, disipador)', true, ''],
                ['Revisión de temperaturas y ventiladores', true, 'Cambiar pasta térmica si las temperaturas son altas o han pasado más de 2 años.'],
                ['Salud del disco (SMART) y espacio libre', true, ''],
                ['Actualizaciones de Windows y drivers', true, ''],
                ['Antivirus / EDR activo y actualizado', true, ''],
                ['Cifrado BitLocker activo', true, ''],
                ['Agente GLPI reportando inventario', true, 'Verificar la fecha del último inventario en la ficha del equipo.'],
                ['Limpieza de archivos temporales', false, ''],
                ['Batería y cargador (solo portátil)', false, 'Revisar salud de la batería, hinchamiento y estado del cargador.'],
                ['Bisagras, puertos y cables (solo portátil)', false, ''],
                ['Respaldo / OneDrive sincronizando', true, ''],
                ['Prueba funcional con el usuario', true, 'El usuario confirma que el equipo queda funcionando.'],
            ],
            // Tablets y teléfonos
            \Phone::class => [
                ['Inspección física (pantalla, carcasa, puertos, botones)', true, ''],
                ['Salud de la batería (hinchamiento, capacidad)', true, ''],
                ['Versión del sistema operativo y parches de seguridad', true, ''],
                ['Inscripción en MDM / Intune y cumplimiento', true, ''],
                ['Apps corporativas actualizadas', true, ''],
                ['Bloqueo de pantalla y cifrado', true, ''],
                ['Almacenamiento libre', false, ''],
                ['Respaldo verificado', true, ''],
                ['Limpieza física, estuche y protector', false, ''],
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    public static function forItemtype(string $itemtype): array
    {
        return self::all()[$itemtype] ?? [];
    }
}
