# Plan: hoja de trabajo de cada trabajador del taller

Pedido del usuario (2026-11-27). Decisiones del usuario el mismo día (§3).
Estado: **hecho el 2026-11-27, sin subir** (§4; sin migración). La pestaña se llama
"Taller" en Nómina. Lo de §5 sigue pendiente.

## 1. El problema

Hoy, al final del mes, cada trabajador del taller llena a mano una hoja con todo lo
que hizo. La llena él mismo, de memoria, y nada impide que se ponga más de lo que hizo.

La idea: lo que pasa por Producción se le va acumulando solo a cada trabajador, y al
final del periodo se imprime su hoja (de uno o de todos) con cada producto en el que
trabajó. A mano queda solo lo que no pasó por el sistema (hoy, sobre todo, lo que se
fabrica para los almacenes).

## 2. Lo que ya existe (no hay que inventarlo)

| Dato de la hoja | Dónde está hoy |
|---|---|
| Quién trabajó en cada paso | `paso_trabajadores` (una fila por persona y paso, desde 2026-08-25). Se llena al asignar y al terminar (`completarPaso` **exige** al menos un trabajador) |
| Qué paso fue (tapizado, lijado, laca…) | `produccion_pasos.tipo_proceso` → nombre en `tipos_proceso` |
| Cuándo se terminó | `produccion_pasos.completado_at` (UTC) |
| Qué producto, qué versión, cuántos | `produccion` → `orden_item` (venta) o `producto_id` / `variante_id` / `cantidad` (fabricación para la Reserva, "Producir") |
| De qué orden | `orden_item.orden` |
| Periodos de pago | `Services/CicloNomina::rango` / `nombre` ya saben semanal, quincenal, cada 20 días y mensual |

Lo que **no** existe: una consulta "todo lo que hizo esta persona en este periodo" ni la
hoja imprimible. `historialPasos` solo da los últimos 50 pasos que **cerró** el propio
usuario, no en los que participó.

## 3. Decisiones del usuario (2026-11-27)

1. **Paso devuelto y rehecho:** cuenta **una vez, para quien lo termina** (normalmente se
   lo devuelven a la misma persona). Esto es justo lo que ya pasa en el sistema: un paso
   tiene una sola fila, y al cerrarlo quedan los que se pusieron al cerrar. **No hace
   falta** guardar cada intento.
2. **Paso con varios trabajadores:** el renglón **completo a cada uno**.
3. **Garantías y restauraciones:** igual que todo, en la misma hoja. Cuenta todo en lo que
   trabajen.
4. **Para dónde iba (orden, Reserva, tienda):** no importa. **Lo que importa es lo que
   hizo cada uno.** La hoja no pone destino ni cliente.
5. **La hoja termina en Nómina, en una sección aparte** (no mezclada con las
   bonificaciones por producción ni con los pagos). No se pasa a plata sola.
6. **Periodo:** por defecto **quincenal**, y se puede cambiar a **mensual (1 al 31), cada
   20 días, semanal o personalizado** (de tal fecha a tal fecha).
7. **Quién la ve e imprime:** quien tiene **acceso a Nómina** (`permiso:acceso_nomina`).
8. **Qué se imprime:** **solo el trabajo que hizo y el nombre de la persona.** Nada de
   horas, calidad ni opiniones.
9. **Una persona o todas.** En la de todos se tiene que saber quién hizo cada cosa; en la
   individual, el nombre va arriba y todo es de esa persona.
10. **Tamaño carta, que se entienda y que no sea larga:** idealmente una hoja por persona,
    a lo sumo dos.

11. **Poner quién hizo el paso es opcional por ahora** (mismo día): "que deje hacerlo sin
    eso, como ahora se está haciendo", mientras se decide cómo se maneja lo que va para
    inventario y Reserva. `completarPaso` ya no exige trabajador; el formulario de
    "Terminar paso" dice "(opcional)". Si alguien se apuntó al empezar el paso, sigue
    figurando. Un paso cerrado sin nadie **no sale en la hoja de nadie**.

## 4. Diseño

### 4.1 Qué cuenta

Cada paso **terminado** (`estado = completado`) con `completado_at` dentro del periodo,
**en hora de Bogotá** (`RangoFechas`; un paso cerrado el 31 a las 8 p. m. es del 31), en
el que la persona está en `paso_trabajadores`.

- No cuenta el paso **Despacho** (es salida, no trabajo de taller).
- Sí cuentan los pasos de piezas que después se cancelaron: el trabajo se hizo.
- Pasos de antes del 2026-08-25 solo tienen nombres escritos a mano (JSON
  `produccion_pasos.trabajadores`) y no se pueden pegar a nadie: no salen. Para periodos
  de ahora en adelante no importa.

### 4.2 Para que quepa en una hoja: agrupar

En vez de un renglón por paso, **se juntan los iguales**: mismo producto + misma versión +
mismo paso = un renglón con la cantidad sumada.

```
JUAN PÉREZ                                Quincena del 1 al 15 de noviembre de 2026

Tapizado
  Sofá Telavid 3 puestos · Taupe ......................... 4
  Cama Bali 1.40 ......................................... 2
  Silla Nube (restauración) .............................. 6
Lijado
  Mesa de comedor Roble 6 puestos ........................ 1

Total: 13 piezas en 4 trabajos

Otros trabajos (escribir a mano)
  ______________________________________________   ____
  ______________________________________________   ____
  ...

Firma del trabajador ______________    Revisó ______________
```

- Agrupado por **tipo de paso**, y dentro por producto.
- La versión (medida, tela/color) va corta al lado del nombre, porque "cama 1.40" y
  "cama 1.60" no son el mismo trabajo.
- Marca corta "(restauración)" o "(garantía)" al lado del producto, porque son trabajo
  distinto al de un mueble nuevo. Nada más.
- Unos renglones en blanco para lo que escribe a mano (lo que no pasó por Producción).
- Si una persona tiene tanto que no cabe en dos hojas, se achica la letra antes de pasar
  a una tercera.

### 4.3 La de todos

Un solo PDF con **una página (o dos) por persona**, cada una con su nombre arriba: así se
puede cortar y entregar a cada uno, y en la de todos se sabe quién hizo qué. Solo salen
los que trabajaron en el periodo, en orden alfabético.

### 4.4 En Nómina: sección aparte

Pestaña nueva en Nómina: **"Trabajo del taller"** (junto a Gente / Pagos, no dentro de
Novedades ni de la bonificación por producción).

- Arriba: periodo (pastillas Quincena · Mes · Cada 20 días · Semana · Personalizado) y
  flechas para ir al periodo anterior / siguiente. Por defecto, la quincena en curso.
- Lista de trabajadores con cuántas piezas y trabajos llevan en ese periodo.
- Tocar uno abre su detalle (lo mismo que la hoja) con botón **Imprimir**.
- Botón **Imprimir todas** arriba.
- Patrón visual de siempre, revisado en 390 px y en modo oscuro.

### 4.5 Servidor

- `GET /nomina/trabajo-taller` → por persona: id, nombre, piezas, trabajos y los
  renglones agrupados (la pantalla abre el detalle sin pedir otra vez).
- `GET /nomina/trabajo-taller/pdf[?usuario_id=]` → la hoja (DomPDF, `pdf/trabajo_taller`,
  carta). Con `usuario_id` de alguien sin trabajo, sale la hoja solo con los renglones a mano.
- Periodo: `periodicidad` + `fecha` (el servidor saca el ciclo con `CicloNomina::rango`; el
  de 20 días tiene ancla, por eso no se repite en la pantalla) o `desde` + `hasta`.
- Todo con `permiso:acceso_nomina`.
- Una lógica en `app/Services/TrabajoDelTaller` (una sola consulta con los joins; nada de
  N+1: muchos pasos × 200 ms por consulta en Aiven).
- **Solo lee.** Sin migración. No toca Producción, plata ni bonificaciones.

## 5. Lo que queda para después (no pedido aún)

- **"Trabajo suelto" anotado en el sistema** (lo que nunca pasa por el tablero): un
  renglón con trabajador, fecha, qué hizo y cantidad, que sale impreso igual. Necesita
  una tabla nueva. Por ahora eso va en los renglones en blanco.
- **Pasarlo a la bonificación por producción** de Nómina con un valor por trabajo: el
  usuario dijo "en una sección aparte", así que **no** se conecta con la plata. Si un día
  se quiere, es decisión del dueño.

## 6. Pruebas

- Un paso con dos trabajadores sale completo en la hoja de cada uno.
- Un paso devuelto y rehecho sale una vez, para quien lo cerró al final.
- Un paso cerrado el último día a las 8 p. m. (hora de Bogotá) cae en ese periodo.
- El paso Despacho no sale; un paso de pieza cancelada sí.
- Sin acceso a Nómina: 403.
- La de todos trae solo a quien trabajó, y el PDF se genera (con `set_time_limit(0)` después
  en la prueba, ver la trampa de `AnexoGarantiaController::pdf` en `MEMORY.md`).
