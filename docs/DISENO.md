# Diseño — Plugin Mantenimientos (fase 1, aprobado 2026-11-14)

## Principios

- Independiente de tickets: tablas y flujo propios. Un correctivo puede **enlazarse** a un ticket solo por trazabilidad.
- No duplica el inventario: lee equipos, usuarios, proveedores, tickets y valor de compra (Infocom) del núcleo de GLPI.
- El tablero muestra **solo información de mantenimientos**; el detalle del equipo vive en su ficha.
- Objetivo: preventivos a tiempo para que los correctivos sean esporádicos.

## Versiones soportadas

GLPI 10.0 a 12.x con un solo código (ver README, sección Compatibilidad). PHP 8.1 o superior.

## Alcance de equipos

Computadores (PC y portátiles, por subtipo de inventario) y teléfonos/tablets. Impresoras fuera de alcance (rentadas, 2026-11-18).

## Tablas (`glpi_plugin_mantenimientos_*`)

| Tabla | Contenido |
|---|---|
| `plans` | Plan por tipo de equipo (`itemtype` + `types_ids` JSON, varios subtipos), frecuencia, tolerancia, interno/externo, proveedor por defecto. |
| `plantasks` | Plantilla del checklist del plan (orden, obligatoria). |
| `items` | Equipo ↔ plan, frecuencia propia, último preventivo, próxima fecha, exclusión. |
| `maintenances` | Registro preventivo/correctivo: estado, fechas, técnico, usuario asignado, enlace a ticket, preventivo origen (`..._id_parent`), diagnóstico, solución, resultado, proveedor, referencia, envío y devolución. |
| `checks` | Checklist ejecutado (copia de la tarea): hecho / no hecho / no aplica + comentario. |
| `costs` | Repuesto, mano de obra, servicio externo, envío; cantidad × valor = total. |
| `statuslogs` | Cada cambio de estado con fecha y usuario (base de tiempos). |
| `configs` | Umbrales del semáforo y metas. |

## Estados

**Preventivo:** Programado → En ejecución → Completado. También Reprogramado (con motivo, suma al contador) y Cancelado (con motivo).
- *Vencido* se calcula por fecha, no se guarda.
- Al completar: próxima fecha = fecha real de ejecución + frecuencia.
- No se completa con tareas obligatorias pendientes.
- Una falla encontrada abre un correctivo vinculado.
- Checklist tipo formato en papel (aprobado 2026-11-18): cada tarea se marca Realizado / No realizado / No aplica; No realizado y No aplica exigen justificación; todas deben quedar marcadas; una obligatoria en No realizado impide completar (queda abierto o se cancela con motivo). Se puede guardar avance.

**Correctivo:** Reportado → Diagnóstico → En reparación → Reparado.
- Externo: Diagnóstico → Enviado a proveedor → En proveedor → Devuelto → Reparado.
- Esperando repuesto en cualquier punto intermedio.
- Final alternativo: No reparable (sugiere baja).

## Semáforo (umbrales configurables)

🟢 > 30 días · 🟡 ≤ 30 · 🟠 ≤ 7 · 🔴 vencido · 🔧 correctivo abierto · ⚪ sin plan

## Estilo de tableros

Todos los tableros del plugin usan `Ui::tiles()` / `Ui::lightTiles()`: mosaicos al estilo del resumen de tickets de GLPI (fondo de color completo, número grande, ícono arriba a la derecha, descripción al pasar el cursor; clic = filtrar). Aprobado 2026-11-18.

## Tendencias (tablero)

- **Estado del parque:** foto diaria (`glpi_plugin_mantenimientos_snapshots`) de los conteos del semáforo de todo el parque, tomada por la tarea `syncItems`, la sincronización manual o la primera visita del día al tablero.
- **Actividad de preventivos:** iniciados, completados y cancelados, reconstruido desde los registros.
- Gráfica de líneas propia (`Ui::lineChart`, SVG + JS, sin librerías): leyenda para activar/desactivar series, valores al pasar el cursor; periodos 30 días (por día), 90 días (por semana), 12 meses (por mes).

## Seguimiento y control

- **Cumplimiento:** % preventivos completados de los que vencían en el periodo; % a tiempo (dentro de tolerancia); días promedio de retraso; cobertura (equipos con plan / total); vencidos hoy.
- **Seguimiento:** proporción correctivos/preventivos vs. meta (20 %); tiempo promedio de reparación; tiempo en proveedor; reincidentes (≥ 2 correctivos en 12 meses); tiempo promedio entre fallas por tipo/modelo.
- **Control:** costo por periodo, tipo, equipo y proveedor; costo acumulado vs. valor de compra (alerta al 50 %); ejecutados por técnico.
- Filtros: periodo, entidad, tipo, ubicación, proveedor. Exportación CSV/PDF.

## Permisos

Planes · Mantenimientos · Métricas (lectura) · Configuración (lectura/actualización).

## Fases siguientes

2. Ventana de aviso al usuario asignado al iniciar sesión en GLPI; correo para tablets y teléfonos.
3. Aviso al iniciar sesión en Windows mediante tareas de despliegue (requiere plugin GLPI Inventory).
