# Mantenimientos — Plugin para GLPI 10, 11 y 12

Gestión de **mantenimientos preventivos y correctivos** del parque informático (PC, portátiles, tablets y teléfonos), **independiente del sistema de tickets**. Las impresoras quedan fuera porque son rentadas.

- Planes por tipo de equipo (varios subtipos por plan) con frecuencia, tolerancia y checklist; checklist sugerido para PC/portátil y móviles.
- Preventivo con checklist obligatorio y cálculo automático de la próxima fecha.
- Correctivo (reparación) con flujo para proveedor externo (envío, estadía, devolución).
- Costos por mantenimiento.
- Semáforo por equipo y módulo de seguimiento y control (cumplimiento, reincidencia, costos).
- Enlace opcional a tickets solo para trazabilidad.

Diseño completo en [`docs/DISENO.md`](docs/DISENO.md).

## Requisitos

| GLPI | PHP | Estado |
|---|---|---|
| 10.0.x | 8.1 – 8.4 (el plugin no admite 7.4/8.0) | Soportado (GLPI 10 ya no recibe soporte oficial de Teclib) |
| 11.0.x | 8.2 – 8.4 | Soportado |
| 12.0.x | 8.3 – 8.4 | Soportado |
| 13 | — | Se habilitará al probarlo (subir `PLUGIN_MANTENIMIENTOS_MAX_GLPI`) |

Un solo código para todas las versiones; ver [Compatibilidad](#compatibilidad).

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
| 1 | Compatibilidad GLPI 10 → 12 en un solo código | ✅ v0.2.0 |
| 1 | Planes y checklist (pantallas) | ✅ v0.3.0 – v0.5.0 |
| 1 | Registro de preventivos con checklist tipo formato en papel | ✅ v0.7.0 |
| 1 | Correctivos y costos | ⏳ |
| 1 | Equipos con semáforo, tablero e historial | ✅ v0.6.0 – v0.7.0 |
| 1 | Métricas de cumplimiento, seguimiento y control | ⏳ |
| 2 | Aviso al usuario asignado al iniciar sesión en GLPI / correo | 🔜 |
| 3 | Avisos en Windows (notificación nativa, Entendido / Recordar más tarde) | ✅ v0.9.0 |

## Avisos en Windows

El agente de GLPI corre como servicio (sesión 0) y no puede mostrar nada en el escritorio del usuario. Por eso el plugin trae su propio notificador, que corre en la sesión de cada usuario:

1. **Mantenimientos > Avisos en Windows**: activar, elegir desde qué color avisar y descargar el instalador (ya trae la URL y el token).
2. Paquete de despliegue de GLPI Inventory con el instalador y la acción *Ejecutar comando*:
   `powershell.exe -NoProfile -ExecutionPolicy Bypass -File Instalar-AvisosMantenimiento.ps1`
3. Cada equipo consulta `front/notify.php` al iniciar sesión y cada N minutos, y muestra una notificación de Windows 10/11 con **Entendido** y **Recordar más tarde**. La respuesta queda en el registro de la misma pantalla.

Reglas: la prioridad la da el técnico. El aviso solo deja de salir cuando se registra el mantenimiento (la fecha avanza y el semáforo vuelve a verde) o mientras hay un preventivo en ejecución. El usuario solo puede silenciarlo: **Entendido** lo oculta N horas (24 por defecto) y **Recordar más tarde** unas pocas (4). Sin respuesta se repite cada N horas y al iniciar sesión; cada cambio de color (🟡 → 🟠 → 🔴) avisa de nuevo y los vencidos se quedan en pantalla hasta que el usuario responda. Un mantenimiento programado con fecha avisa en lugar del semáforo.

El equipo se identifica por número de serie (o por nombre si el serial es genérico). `front/notify.php` no usa sesión de GLPI: lo protege el token (en GLPI 11+ se declara en el firewall como público y sin estado). El token va en `%ProgramData%\MantenimientosAvisos\config.json`, legible por los usuarios del equipo; con él solo se pueden consultar y responder avisos. Si se filtra, se regenera y se vuelve a desplegar.

Archivos del cliente en `tools/windows/`. Prueba en un equipo: `wscript "%ProgramData%\MantenimientosAvisos\ejecutar-oculto.vbs" -Prueba` (CMD) o `wscript "$env:ProgramData\MantenimientosAvisos\ejecutar-oculto.vbs" -Prueba` (PowerShell). Registro local: `%LOCALAPPDATA%\MantenimientosAvisos\avisos.log`.

## Compatibilidad

GLPI 12 declara con tipo nativo propiedades del núcleo (`CommonGLPI::$rightname`, `CommonDBChild::$itemtype` y `$items_id`, `CommonDBTM::$dohistory`). PHP obliga a que la clase hija las repita igual, así que un mismo archivo no sirve para GLPI 11 y 12 a la vez.

Cómo lo resuelve el plugin:

- **`src/Base/*Base.php`**: toda la lógica, sin esas propiedades. Un solo código.
- **`src/*.php`** (Plan, Maintenance, …): clase final declarada en dos variantes condicionales; se elige la que corresponde con `Compat::typedCoreProperties()`, que detecta la característica por Reflection y no por número de versión.
- **`Compat::e()`**: escape de salida (en GLPI 10 los datos de la BD ya vienen codificados).
- **`front/*.php`**: incluyen `inc/includes.php` solo en GLPI 10.
- Hook `csrf_compliant` declarado (obligatorio en GLPI 10, ignorado después).

Reglas al desarrollar:

1. Nunca declarar `$rightname`, `$itemtype`, `$items_id` ni `$dohistory` en `src/Base`; solo en la clase final, en sus dos variantes.
2. Los métodos que se sobrescriben del núcleo deben declarar el tipo de retorno de GLPI 12 si este lo tiene (agregar tipo de retorno es válido en 10 y 11).
3. Formularios: cerrar siempre con `Html::closeForm()`, que pone el token CSRF solo en las versiones que lo usan.

Prueba rápida sin GLPI: `php tests/compat_check.php 11`, `php tests/compat_check.php 12` y `php tests/notice_check.php`.

## Licencia

GPL-3.0-or-later
