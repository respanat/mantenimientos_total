# Mantenimientos — Plugin para GLPI 11

Gestión de **mantenimientos preventivos y correctivos** del parque informático (PC, portátiles, impresoras, tablets y teléfonos), **independiente del sistema de tickets**.

- Planes por tipo de equipo con frecuencia, tolerancia y checklist.
- Preventivo con checklist obligatorio y cálculo automático de la próxima fecha.
- Correctivo (reparación) con flujo para proveedor externo (envío, estadía, devolución).
- Costos por mantenimiento.
- Semáforo por equipo y módulo de seguimiento y control (cumplimiento, reincidencia, costos).
- Enlace opcional a tickets solo para trazabilidad.

Diseño completo en [`docs/DISENO.md`](docs/DISENO.md).

## Requisitos

- GLPI 11.0.x
- PHP 8.2 o superior

## Instalación

El nombre interno del plugin es **`mantenimientos`** (GLPI solo admite letras y números en el nombre de la carpeta), así que el repo se clona con ese nombre:

```bash
cd /var/www/html/glpi/plugins
git clone https://github.com/respanat/mantenimientos_total.git mantenimientos
chown -R www-data:www-data mantenimientos
```

Luego en GLPI: **Configuración > Plugins > Mantenimientos > Instalar > Activar**.

Al instalar, los perfiles con permiso de actualizar la configuración (Super-Admin) reciben todos los permisos del plugin. Para otros perfiles: **Administración > Perfiles > (perfil) > pestaña Mantenimientos**.

## Permisos

| Permiso | Alcance |
|---|---|
| `plugin_mantenimientos_plan` | Planes y plantillas de checklist |
| `plugin_mantenimientos_maintenance` | Mantenimientos, checklist ejecutado y costos |
| `plugin_mantenimientos_metrics` | Seguimiento y control |
| `plugin_mantenimientos_config` | Configuración (umbrales y metas) |

## Estado

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Estructura, tablas, permisos, menú | ✅ v0.1.0 |
| 1 | Planes y checklist (pantallas) | ⏳ |
| 1 | Registro de preventivos y correctivos, costos | ⏳ |
| 1 | Tablero, semáforo, historial, métricas | ⏳ |
| 2 | Aviso al usuario asignado al iniciar sesión en GLPI / correo | 🔜 |
| 3 | Aviso al iniciar sesión en Windows (tareas de despliegue) | 🔜 |

## Licencia

GPL-3.0-or-later
