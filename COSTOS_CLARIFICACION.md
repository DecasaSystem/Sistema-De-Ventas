# Clarificación del sistema de costos — Decasa

Este archivo define cómo se deben calcular los costos en los tres escenarios
del cotizador IA (botón "Calcular precio con IA" en nueva orden y agente).

---

## Estado actual del sistema

### Lo que YA existe en BD (módulo Costos)

**`tarifas_proceso`** — costo por proceso de manufactura:
| Proceso | Tarifa actual | Unidad |
|---|---|---|
| esqueleteria_silla | $22.000 | pieza |
| esqueleteria_sofa | $38.000 | puesto |
| esqueleteria_cama | $65.000 | pieza |
| esqueleteria_mesa_comedor | $95.000 | pieza |
| esqueleteria_mesa_aux | $45.000 | pieza |
| esqueleteria_cajonero | $75.000 | pieza |
| tapizado | $20.000 | m² |
| corte_costura | $5.000 | ml |
| laca | $12.000 | m² |
| pintura | $9.000 | m² |
| acabados_silla | $8.000 | pieza |
| acabados_sofa | $15.000 | puesto |
| acabados_cama | $30.000 | pieza |

**`salarios_cargo`** — tarifa por hora de cada operario:
- Ebanista: ~$12.500/h (base $100.000/día ÷ 8h)
- Tapicero: ~$11.250/h (base $90.000/día)
- Costurera: ~$8.750/h (base $70.000/día)
- Lacador: ~$11.875/h (base $95.000/día)

### Lo que FALTA en la BD y en el cálculo IA

1. **Costo de energía/luz** — las máquinas consumen electricidad
2. **Costo de transporte/camión** — llevar el producto terminado al cliente
3. **Multiplicadores de ganancia** — aún no están definidos (ver sección al final)

---

## Escenario 1: Producto nuevo no está en inventario (fabricar desde cero)

**Cuándo aplica:** El vendedor selecciona un producto del catálogo, hace clic
en "Fabricar" (no hay stock), o agrega un producto personalizado sin catálogo.

**Qué incluye el costo de fabricación:**

### Materiales
- Madera / MDF / melanina según el tipo de mueble y medidas
- Tela / cuero (metros cuadrados estimados según el mueble)
- Espuma / relleno (si aplica)
- Herrajes, tornillos, bisagras, patas
- Laca / barniz / pintura
- Hilo, cremalleras, ganchos (si aplica costura)

### Mano de obra
- Ebanista / carpintero (horas según tipo de mueble)
- Tapicero (horas si lleva tapizado)
- Costurera (horas si lleva costura)
- Lacador / pintor (horas si lleva laca o pintura)

### Costos indirectos ← PENDIENTE DE IMPLEMENTAR
- **Luz/energía:** estimado fijo por tipo de mueble. Propuesta:
  - Pieza pequeña (silla, mesa aux): $3.000–$5.000
  - Pieza mediana (sofá 2p, cama sencilla): $8.000–$12.000
  - Pieza grande (sofá 3p, comedor): $15.000–$20.000
  - Mueble complejo/especial: $25.000–$40.000
- **Transporte/camión:** estimado según distancia y tamaño. Propuesta:
  - Entrega local (misma ciudad): $20.000–$40.000 según volumen
  - Entrega fuera de ciudad: a definir por el admin

### Precio de venta sugerido
```
precio_fabricacion = materiales + mano_de_obra + luz + transporte
precio_venta = precio_fabricacion × multiplicador_ganancia
```
**Multiplicador pendiente de definir** — ver sección final.

---

## Escenario 2: Producto existente en catálogo con modificaciones

**Cuándo aplica:** El producto YA EXISTE y está fabricado (en stock o se va
a fabricar estándar), pero el cliente quiere cambios: otro tapizado, altura
diferente, sin brazos, etc.

**Regla clave: el mueble base YA EXISTE — no se fabrica desde cero.**

### Sub-escenario 2A: Solo cambio de tapizado
El mueble tiene su estructura, madera, herrajes intactos.
El trabajo es ÚNICAMENTE:
- Quitar el tapizado viejo (tapicero: ~1-2h)
- Comprar tela nueva (metros según el mueble)
- Instalar tapizado nuevo (tapicero: ~2-4h según tamaño)
- Luz de máquina de tapicería: estimado pequeño
- Transporte: llevar y traer el mueble (si el cliente lo envía al taller)
  o llevar el mueble terminado al cliente

**NO incluye:** madera, estructura, herrajes, carpintería, laca.

### Sub-escenario 2B: Ajuste de medidas (más alto, más bajo, etc.)
El trabajo es ÚNICAMENTE:
- Carpintero/ebanista (1-3h para cortar patas, ajustar altura)
- Material mínimo si se necesita añadir madera
- Luz de taller

**NO incluye:** tapizado, laca, estructura completa.

### Sub-escenario 2C: Retapizado + ajuste medidas
Suma 2A + 2B de forma independiente.

### Precio de venta sugerido
```
precio_servicio = mano_obra_cambios + materiales_nuevos + luz + transporte
precio_venta = precio_catalogo + precio_servicio
```
**Nota importante:** Para un mueble en stock, el precio de venta al cliente
es el precio del catálogo MÁS el costo del servicio. NO se aplica el
multiplicador de fabricación al servicio, porque el producto ya tiene
su margen incorporado en el precio del catálogo.

---

## Escenario 3: Restauración (producto viejo del cliente)

**Cuándo aplica:** El cliente trae un mueble de su casa para que Decasa lo
restaure. Puede ser cambio de tela, pintura, laca, reparación de estructura,
o una combinación.

**El mueble ES DEL CLIENTE — no se vende, solo se cobra el servicio.**

### Qué puede incluir

**Retapizado:**
- Quitar tapizado viejo (tapicero: 1-2h)
- Material nuevo: tela + espuma si está dañada
- Tapizado nuevo (tapicero: según tamaño — silla ~3h, sofá 3p ~8h)
- Corte y costura si aplica

**Pintura / laca:**
- Lijado previo (depende del estado)
- Pintura o laca nueva (m² × tarifa)
- Sellador base si aplica

**Reparación de estructura:**
- Carpintero según daño (reemplazar patas, reforzar uniones, etc.)
- Material de madera adicional si se reemplaza algo

**Costos indirectos:**
- Luz/energía según trabajos realizados
- Transporte: si Decasa recoge y entrega el mueble en casa del cliente

### Escala por cantidad
- 1 silla: base
- 4-6 sillas iguales: puede haber eficiencia de escala (~10-15% menos por pieza)
- 10+ sillas: economía de escala más pronunciada (~20-25% menos por pieza)

### Precio de venta
```
precio_costo = materiales + mano_de_obra + luz + transporte
precio_venta = precio_costo × multiplicador_restauracion
```
**Multiplicador pendiente de definir** — ver sección final.

---

## Pendiente: definir los multiplicadores de ganancia

Actualmente el sistema usa estos valores por defecto (provisionales):

| Tipo de cálculo | Multiplicador actual |
|---|---|
| Producto del catálogo (ficha técnica) | ×2.2 |
| Producto nuevo por medidas | ×2.4 |
| Producto personalizado sin referencia | ×2.6 |
| Restauración | ×1.8 |
| Retapizado sobre mueble existente | ×1.0 (solo suma el servicio al precio catálogo) |

**¿Son correctos estos multiplicadores?**
Por ejemplo, con ×2.2:
- Si fabricar una silla cuesta $80.000 → precio venta = $176.000
- Si fabricar un sofá 3p cuesta $450.000 → precio venta = $990.000

Pregunta clave para el dueño/gerente:
1. ¿Cuánto % de ganancia quieres sobre el costo de fabricación?
2. ¿Es el mismo % para todos los productos o varía por categoría?
3. ¿El transporte/camión lo cobra Decasa aparte o lo incluye en el precio?
4. ¿Cuánto cuesta el camión para entregas locales y fuera de ciudad?
5. ¿Cuánto estimas que gasta el taller en luz por mes? (para calcular por pieza)

---

## Plan de implementación cuando estén definidos los valores

### Paso 1: Agregar costos de luz y transporte a la BD
Agregar a la tabla `tarifas_proceso`:
```
proceso: 'energia_pieza_pequeña' → tarifa: X
proceso: 'energia_pieza_mediana' → tarifa: X
proceso: 'energia_pieza_grande'  → tarifa: X
proceso: 'transporte_local'      → tarifa: X
proceso: 'transporte_fuera'      → tarifa: X
```

### Paso 2: Actualizar el system prompt de la IA
La IA ya lee las `tarifas_proceso` de la BD para restauración. Solo hay que:
- Hacer lo mismo para el cotizador de producto nuevo (actualmente usa
  `handleCalcularCostoPorMedidas` / `handleCalcularCostoPersonalizado`)
- Añadir instrucción: "incluye siempre energía y transporte en el desglose"

### Paso 3: Actualizar los multiplicadores
En `AgentService.php` líneas ~2329-2334, cambiar los valores hard-coded
a configuración en `config/costos.php`:
```php
return [
    'multiplicadores' => [
        'ficha_tecnica'       => env('MULTIPLICADOR_FICHA',       2.2),
        'costo_medidas'       => env('MULTIPLICADOR_MEDIDAS',      2.4),
        'costo_personalizado' => env('MULTIPLICADOR_CUSTOM',       2.6),
        'restauracion'        => env('MULTIPLICADOR_RESTAURACION', 1.8),
    ]
];
```
Así el admin puede ajustarlos en las variables de entorno sin tocar código.

---

*Archivo creado para aclarar la lógica de costos — actualizar cuando el
gerente/admin defina los valores reales de multiplicadores, luz y transporte.*
