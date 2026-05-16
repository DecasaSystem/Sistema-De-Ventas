# Plan: Agente de IA para Decasa

## Resumen

Implementar un agente conversacional en español que permita a supervisores y vendedores consultar datos del negocio mediante lenguaje natural. El agente correrá del lado del servidor (Laravel) usando **OpenAI GPT-4o-mini** con **function calling**, lo que le permite decidir qué datos consultar en la BD para responder cada pregunta.

---

## Stack Tecnológico

| Capa | Tecnología |
|---|---|
| LLM | OpenAI GPT-4o-mini (function calling) |
| Backend | Laravel (PHP) — `openai-php/laravel` SDK |
| Base de datos | SQLite (ya existente, acceso read-only desde el agente) |
| Frontend | Vue 3 — widget flotante de chat |
| Autenticación | Sanctum (mismo token del usuario) |

---

## Arquitectura

```
Usuario (chat widget en Vue)
  │
  └─► POST /api/agent/chat  {messages: [...]}
         │
         ▼
    AgentController
         │  ① Arma system prompt + tools + historial
         │  ② Llama OpenAI API
         ▼
    OpenAI GPT-4o-mini
         │  ③ Decide qué tool llamar (o responde directo)
         ▼
    AgentController (tool_call handler)
         │  ④ Ejecuta query en SQLite via Eloquent/DB
         │  ⑤ Devuelve resultado JSON al modelo
         ▼
    OpenAI GPT-4o-mini
         │  ⑥ Genera respuesta en lenguaje natural
         ▼
    Vue chat widget (muestra la respuesta)
```

**Principio clave:** El agente nunca ejecuta SQL libre. Tiene un conjunto finito de "tools" (funciones PHP) que son las únicas operaciones que puede invocar — todas son `SELECT`, sin modificar datos.

---

## Capacidades del Agente

### Capacidad 1 — Calcular costo de un producto (existente o escalado)

El usuario pregunta cuánto costaría fabricar un producto que ya existe en la BD pero con más puestos/medidas diferentes. El agente busca la ficha técnica base y aplica factores de escala.

**Ejemplos de preguntas:**
- "¿A cuánto sale fabricar el Comedor Roma de 6 puestos?"
- "Tengo el comedor clásico de 4 puestos, ¿cuánto costaría hacerlo de 8?"
- "¿Cuánto cuestan los materiales del sofá Valencia?"

**Lógica de escalado:**
- Materiales de tapizado/tela: escalan linealmente con # de puestos
- Madera/esqueleto: escalan ~80% por puesto adicional (economía de escala)
- Mano de obra: escala ~60% (no es proporcional)
- Se muestra desglose: costo base → costo escalado por sección

### Capacidad 2 — Calcular costo de producto personalizado

El usuario describe las especificaciones de un producto a medida. El agente busca precios de materiales en el catálogo y construye un estimado.

**Ejemplos:**
- "Quiero un comedor de 2.5m x 1m, madera sólida, 6 sillas tapizadas en cuero. ¿Cuánto costaría fabricarlo?"
- "¿Cuánto saldría una sala de 3 módulos con tela lino?"

**Lógica:**
- Consulta tabla `materiales` para precios unitarios vigentes
- Consulta fichas técnicas similares como referencia de cantidades
- Devuelve estimado con rango (±15%) y desglose por sección

### Capacidad 3 — Inventario y stock

**Ejemplos:**
- "¿Cuántas sillas Tipo A hay en la tienda Bogotá?"
- "¿Qué productos están bajos de stock en todas las tiendas?"
- "Muéstrame el inventario completo de la tienda Medellín"
- "¿En qué tiendas hay sofás disponibles?"

### Capacidad 4 — Ventas y estadísticas

**Ejemplos:**
- "¿Cuál es el producto más vendido este mes?"
- "¿Qué categoría genera más ingresos?"
- "¿Cuántas sillas Roma se han vendido en los últimos 3 meses por tienda?"
- "¿Cuál es el cliente que más ha comprado?"
- "¿Qué vendedor tiene más ventas este mes?"
- "Dame el top 5 de productos más vendidos en Cali"

### Capacidad 5 — Producción

**Ejemplos:**
- "¿Cuántos productos hay en producción ahora?"
- "¿Qué órdenes están en tapicería?"
- "¿Hay algún producto retrasado?"
- "¿Cuántos items están listos para despacho?"

### Capacidad 6 — Órdenes

**Ejemplos:**
- "¿Cuántas órdenes están pendientes de entrega?"
- "¿Qué órdenes tiene la tienda Barranquilla sin confirmar?"
- "¿Cuáles son las órdenes más recientes?"
- "¿Hay órdenes con saldo pendiente de cobro?"

---

## Tools del Agente (Function Calling)

Estas son las funciones PHP que OpenAI puede invocar:

### `obtener_ficha_tecnica`
```
Descripción: Obtiene la ficha técnica de un producto con todos sus items de costo.
             Opcionalmente calcula el costo escalado para una cantidad diferente de puestos.
Parámetros:
  - nombre_producto: string (obligatorio) — nombre o parte del nombre
  - puestos_nuevo: integer (opcional) — si difiere del base, escala los costos
Fuente: fichas_tecnicas + ficha_tecnica_items
```

### `buscar_fichas_por_categoria`
```
Descripción: Lista todas las fichas técnicas de una categoría para dar contexto al agente.
Parámetros:
  - categoria: string (opcional)
  - busqueda: string (opcional)
Fuente: fichas_tecnicas
```

### `calcular_costo_personalizado`
```
Descripción: Estima el costo de un producto a medida usando precios del catálogo de materiales
             y fichas técnicas similares como referencia de cantidades.
Parámetros:
  - descripcion_producto: string — descripción del mueble a fabricar
  - categoria: string — "comedor", "sala", "alcoba", etc.
  - num_puestos: integer (opcional)
  - materiales_solicitados: array de {nombre, cantidad_estimada, unidad} (opcional)
Fuente: materiales + fichas_tecnicas + ficha_tecnica_items
```

### `consultar_inventario`
```
Descripción: Consulta stock disponible por producto y/o tienda.
Parámetros:
  - producto: string (opcional) — filtro por nombre de producto
  - tienda_id: integer (opcional) — filtro por tienda específica
  - solo_bajo_stock: boolean (opcional) — solo productos bajo stock mínimo
  - todas_tiendas: boolean (opcional) — agrupar por tienda
Fuente: inventario + productos + tiendas
```

### `productos_mas_vendidos`
```
Descripción: Top productos por cantidad vendida o valor generado.
Parámetros:
  - periodo: string — "hoy"|"semana"|"mes"|"mes_anterior"|"anio" o {desde, hasta}
  - tienda_id: integer (opcional)
  - categoria: string (opcional)
  - top_n: integer (default 10)
  - criterio: "cantidad"|"valor" (default "cantidad")
Fuente: orden_items + ordenes + productos (WHERE estado != 'cancelado')
```

### `ventas_por_categoria`
```
Descripción: Resumen de ventas agrupado por categoría de producto.
Parámetros:
  - periodo: string
  - tienda_id: integer (opcional)
Fuente: orden_items + ordenes + productos
```

### `ventas_producto_especifico`
```
Descripción: Historial de ventas detallado de un producto específico con
             desglose mensual y por tienda.
Parámetros:
  - nombre_producto: string (obligatorio)
  - periodo: string (default "anio")
  - tienda_id: integer (opcional)
  - agrupar_por: "tienda"|"mes"|"ambos" (default "ambos")
Fuente: orden_items + ordenes + productos + tiendas
```

### `clientes_top`
```
Descripción: Clientes que más han comprado en valor o en número de órdenes.
Parámetros:
  - top_n: integer (default 10)
  - periodo: string (default "anio")
  - tienda_id: integer (opcional)
  - criterio: "valor"|"ordenes" (default "valor")
Fuente: clientes + ordenes + pagos
```

### `estado_produccion`
```
Descripción: Consulta el estado actual de producción: items en proceso,
             paso en que están, si hay retrasos.
Parámetros:
  - estado: string (opcional) — "pendiente"|"en_proceso"|"completado"|"retrasado"
  - paso: string (opcional) — "tapiceria"|"esqueleteria"|"laca"|etc.
  - solo_retrasados: boolean (opcional)
Fuente: produccion + produccion_pasos + orden_items + productos + ordenes
```

### `consultar_ordenes`
```
Descripción: Lista de órdenes con filtros por estado, tienda, cliente o fecha.
Parámetros:
  - estado: string (opcional)
  - tienda_id: integer (opcional)
  - cliente_nombre: string (opcional)
  - solo_con_saldo: boolean (opcional) — órdenes con pago pendiente
  - periodo: string (opcional)
  - limit: integer (default 20)
Fuente: ordenes + clientes + tiendas + v_saldo_ordenes
```

---

## Plan de Implementación

### Fase 1 — Backend (Laravel)

**Paso 1: Instalar SDK y configurar**
```bash
composer require openai-php/laravel
php artisan vendor:publish --provider="OpenAI\Laravel\ServiceProvider"
```
- Agregar `OPENAI_API_KEY=sk-...` al `.env`
- Agregar `OPENAI_MODEL=gpt-4o-mini` al `.env`

**Paso 2: Crear `AgentService.php`**
- Archivo: `decasa-api/app/Services/AgentService.php`
- Responsabilidades:
  - Definir el array de `tools` con sus schemas JSON (para OpenAI)
  - Implementar cada handler PHP: `handleToolCall(string $toolName, array $args): array`
  - Ejecutar las queries en la BD y retornar datos estructurados
  - Método `chat(array $messages, User $user): string` — orquesta el loop con OpenAI

**Paso 3: Crear `AgentController.php`**
- Archivo: `decasa-api/app/Http/Controllers/AgentController.php`
- Un solo endpoint: `POST /api/agent/chat`
- Recibe `{ messages: [{role, content}, ...] }` (historial corto, máx. últimos 10 turnos)
- Aplica restricciones por rol (vendedor solo ve su tienda en los resultados)
- Devuelve `{ respuesta: "...", datos: {...} }` (datos opcionales para enriquecer el frontend)

**Paso 4: Agregar ruta**
```php
// En api.php, dentro del grupo auth:sanctum:
Route::post('/agent/chat', [AgentController::class, 'chat']);
```

**Paso 5: System prompt**
```
Eres el asistente de Decasa, una empresa de muebles. Respondes en español de 
forma concisa y útil. Solo tienes acceso a la información de la empresa que 
está en la base de datos. Cuando el usuario pregunte por costos de producción, 
usa las fichas técnicas disponibles. Para escalado de productos, aplica los 
multiplicadores definidos. No inventas datos, si no encuentras información lo 
dices claramente.
```
El system prompt incluirá el rol y tienda del usuario logueado para filtrar automáticamente.

### Fase 2 — Frontend (Vue 3)

**Paso 6: Crear `AgentChat.vue`**
- Archivo: `decasa-app/src/components/AgentChat.vue`
- Widget flotante (botón en esquina inferior derecha, tipo chat de soporte)
- Al abrir: panel de chat con historial de mensajes de la sesión
- Input de texto + botón enviar
- Indicador de "escribiendo..."
- Mensajes del agente con formato markdown básico (listas, negritas)

**Paso 7: Crear `decasa-app/src/api/agent.js`**
```javascript
export const chatWithAgent = (messages) =>
  api.post('/agent/chat', { messages })
```

**Paso 8: Integrar widget en `App.vue`**
- Visible en todas las rutas excepto login
- Solo para roles: supervisor, vendedor (no conductor, no ebanista)

### Fase 3 — Lógica de Escalado (crítica para Capacidad 1)

Los multiplicadores de escala al cambiar de N puestos base a M puestos nuevos:

```
factor_base = M / N

Por sección:
  TAPICERIA / TELA / CORTE_COSTURA  → factor_base × 1.0  (100% proporcional)
  ESQUELETERIA / CARPINTERIA        → factor_base × 0.85 (economía de escala)
  MANO_OBRA tapicero                → factor_base × 0.70 (no proporcional)
  MANO_OBRA ebanista                → factor_base × 0.75
  HERRAJES / ACABADOS               → factor_base × 0.90
  LACA / PINTURA                    → factor_base × 0.80 (área no proporcional)

Costo_escalado = Σ (item.subtotal × multiplicador_de_sección[item.seccion])
```

Esto se implementa en `AgentService::escalarFicha()`.

---

## Archivos a Crear/Modificar

| Archivo | Acción |
|---|---|
| `decasa-api/app/Services/AgentService.php` | **CREAR** — core del agente |
| `decasa-api/app/Http/Controllers/AgentController.php` | **CREAR** |
| `decasa-api/routes/api.php` | **MODIFICAR** — agregar ruta |
| `decasa-api/.env` | **MODIFICAR** — agregar OPENAI_API_KEY |
| `decasa-app/src/components/AgentChat.vue` | **CREAR** — widget de chat |
| `decasa-app/src/api/agent.js` | **CREAR** — cliente API |
| `decasa-app/src/App.vue` | **MODIFICAR** — montar widget |

---

## Seguridad y Restricciones

- El agente solo puede leer datos (SELECT). Ninguna tool modifica la BD.
- Las queries usan Eloquent/DB con bindings preparados (no SQL dinámico del LLM).
- Si el usuario es `vendedor`, todas las tools auto-filtran por `tienda_default_id` del usuario.
- Si el usuario es `supervisor`, puede consultar cualquier tienda.
- El historial de conversación solo vive en el frontend (no se persiste en BD por ahora).
- El endpoint está protegido por `auth:sanctum` — requiere token válido.

---

## Preguntas Pendientes

Antes de empezar a implementar, confirmar:

1. **API Key de OpenAI**: ¿Ya tienes una? ¿O prefieres usar otro modelo (ej. Anthropic Claude)?
2. **Despliegue**: El backend está en Render (según `render.yaml`). Las variables de entorno OPENAI_API_KEY deben agregarse ahí también.
3. **Fichas técnicas en BD**: ¿Ya hay fichas técnicas cargadas en la BD, o aún están por importar desde Excel? Esto es crítico para que Capacidad 1 funcione.
4. **Widget flotante vs vista propia**: ¿El chat debe ser un botón flotante en todas las pantallas, o prefieres una vista dedicada en el menú de navegación?
5. **Historial de conversación**: ¿Quieres que el historial del chat persista entre sesiones (requiere tabla en BD), o es suficiente que se borre al recargar la página?
