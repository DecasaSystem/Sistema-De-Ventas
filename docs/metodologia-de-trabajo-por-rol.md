# Roles y módulos personalizables — BORRADOR

> Estado: **borrador para cuadrar con el usuario** (2026-11-19). Nada programado todavía.
> Idea del usuario: que el programa le sirva a **cualquier empresa que fabrica y vende en
> sus almacenes** (muebles, ropa, cerrajería, carros…), no solo a Decasa. Por eso los roles,
> los módulos y las funciones del taller tienen que poder armarse a la medida.
> Lo marcado con ❓ lo decide el usuario.

## 1. La idea en una frase

**Una persona = un rol (con el nombre que la empresa quiera) + los módulos que ese rol trae
+ los módulos extra que se le prendan a ella + las funciones de fábrica que tenga a cargo.**

Ejemplos que tienen que poder armarse:
- Vendedor que además factura.
- Vendedor que además es supervisor.
- Vendedor que además trabaja en la fábrica (lleva el paso de "Pintura").
- Contador que solo factura (no vende).
- Conductor que también vende.
- Empresa sin conductores: entregan vendedores y supervisores desde la orden.

## 2. Lo que el sistema ya tiene (no hay que inventarlo)

| Ya existe | Dónde | Sirve para |
|---|---|---|
| Roles con nombre libre ("Metalero", "Soldador") | Gestión → Roles | Que cada empresa llame a su gente como quiera |
| Módulos que se prenden por persona (~20 interruptores) | Trabajadores → ficha | Facturación, Redes, Costos, Despacho, Reserva, Nómina, Compras… |
| **Pasos del taller con nombre libre** y encargados por persona | Gestión → Procesos | El taller ya no es solo ebanistería/tapicería: un taller de ropa pone "Corte, Costura, Planchado"; uno de carros "Latonería, Pintura, Revisión" |
| **Inventario por cantidad renombrable y clonable** (Telas) | Gestión → Personalización | Telas puede llamarse "Láminas", y de la misma plantilla salen "Bombillos", "Parabrisas"… sin programar uno por uno |
| Esconder módulos que la empresa no usa | Gestión → Personalización | Quitar Telas, Redes, Citas… a quien no los necesita |
| Entrega sin conductor | permiso "Entrega directa" | Vendedor o supervisor entrega desde la orden |
| Barra de abajo personalizable | Perfil | Cada uno pone a la mano lo que más usa |

## 3. Lo que falta o está mal para que sea de verdad personalizable

1. **Un rol nuevo no se comporta como lo que dice ser.** El rol nuevo "Gerente" (de tipo
   supervisor) queda guardado como `gerente`, y el programa pregunta en ~200 sitios
   "¿es `supervisor`?". Resultado: un Gerente **no** puede aprobar cambios ni entrar a
   Gestión. Hoy solo funcionan bien los roles que vinieron de fábrica. **Es lo primero que
   hay que arreglar**, antes que cualquier otra empresa use el programa.
2. **Vender no es un módulo, es el rol.** Nueva orden, Órdenes, Clientes, Cotizaciones, Caja,
   Estadísticas y Consultar costo se abren solo si el rol es vendedor o supervisor. Un
   conductor que vende, o un taller que vende, no puede.
3. **El Inicio está armado a mano por rol** (una lista para conductor, otra para "ebanista",
   otra para vendedor…). Tiene que salir de los módulos que la persona tenga prendidos.
4. **Algunos módulos están amarrados al rol:** Facturación solo se le puede dar a un
   vendedor (el contador que no vende queda por fuera); Redes y recargar Telas solo a
   vendedor o supervisor.
5. **El rol guarda módulos pero no se usan:** la tabla de roles tiene interruptores (Redes,
   Costos, Reserva…) que no se le pasan a nadie. Al crear un trabajador hay que prender todo
   a mano.
6. **Acciones finas que hoy son "solo supervisor"** y algunas empresas querrán darle a otro:
   asignar fechas de entrega por producto, aprobar cambios de plata, editar precios del
   catálogo.
7. **Surtir y Traslado son el mismo interruptor.** Surtir (de la fábrica a las tiendas) y
   trasladar (de una tienda a otra) son trabajos distintos de gente distinta.
8. **Palabras de muebles en pantalla** (tapizado, restauración, telas, anexo de garantías…).
   No es de este trabajo, pero para venderlo a otra empresa hay que revisarlas después.

## 4. Catálogo de módulos (propuesta)

Cada módulo se prende o apaga **en el rol** (para todos los que lo tienen) y **en la
persona** (extra solo para ella). La columna "Por defecto" es solo el arranque sugerido.

| Grupo | Módulo | Qué es | Por defecto |
|---|---|---|---|
| **Ventas** | Nueva orden | Vender | Vendedor, Supervisor |
| | Órdenes | Ver y seguir las órdenes | Vendedor, Supervisor |
| | Clientes | Base de clientes | Vendedor, Supervisor |
| | Cotizaciones | Propuestas | Vendedor, Supervisor |
| | Consultar costo | Pedir precio de algo personalizado | Vendedor |
| | Estadísticas | Mis ventas y metas (sale solo si tiene Nueva orden) | Quien vende |
| | Redes | Clientes que pasa Elena (WhatsApp/Instagram) | Vendedor |
| | Caja | Plata de la tienda | Vendedor, Supervisor |
| **Inventario** | Ver inventario | Existencias por tienda | Todos |
| | Cambiar inventario | Entradas, salidas, repartir por medida/tela | Vendedor (su tienda), Supervisor |
| | Inventarios por cantidad (Telas, Láminas, Bombillos…) | Lo que se mide en metros, unidades, litros | Quien lo use |
| | Reserva de fábrica | Lo fabricado que está en la bodega | Supervisor, Fábrica |
| | Surtir | Mandar de la fábrica a las tiendas | Supervisor, Fábrica |
| | Traslado | Mover de una tienda a otra | Vendedor, Conductor, Supervisor |
| **Fábrica** | Producción | Tablero del taller | Supervisor, Fábrica |
| | Mis pasos | Lo que me toca hacer (sale solo si tiene pasos a cargo) | Quien tenga pasos |
| | Carga de trabajo | Cuánto trabajo hay | Supervisor |
| | Costos | Costo de fabricación, materiales (sin precio de venta) | Supervisor, Fábrica |
| **Entregas** | Despacho | Armar rutas, Por recoger | Supervisor |
| | Mis entregas | Hacer las entregas de la ruta | Conductor |
| | Entrega directa | Entregar desde la orden sin ruta | Vendedor, Supervisor |
| **Plata y admin** | Facturación electrónica | Facturar en Alegra | Contador / quien factura |
| | Bonificaciones | Comisiones | Supervisor |
| | Nómina | Pagos al personal | Quien la lleve |
| | Finanzas | Utilidad, gastos, flujo | Dueño |
| | Proveedores y Compras | Facturas de proveedores | Supervisor |
| | Encargos | Herramientas a cargo | Quien revise |
| **Para todos** | Citas | Recordatorio de citas | Todos |
| **Administración** | Trabajadores, Reportes, Gestión | Configurar la empresa | Supervisor |

**Permisos especiales** (acciones dentro de un módulo, no pantallas):

| Permiso | Hoy | Propuesta |
|---|---|---|
| Aprobar cambios de plata y cancelaciones | Solo supervisor | ✅ Se queda solo supervisor (decisión del usuario) |
| Asignar fechas de entrega por producto | Solo supervisor | ✅ Activable (`asigna_fechas`) |
| Editar precios, nombres y fotos del catálogo | Solo supervisor | ✅ Activable (`edita_catalogo`) |
| Ver todas las órdenes (no solo las suyas y las de su tienda) | Activable | Igual |
| Ver el valor en pesos del inventario | Solo supervisor | Activable |
| Cambiar reglas de bonificación | Activable | Igual |

## 5. Las bases (lo que no se personaliza)

El nombre del rol es libre, pero por debajo el programa necesita saber unas pocas cosas para
la plata y la visibilidad. Propuesta: que la persona escoja la base al crear el rol y no se
cambie después (como hoy).

| Base | Qué cambia por debajo |
|---|---|
| **Administra** (supervisor) | Ve toda la operación, todas las tiendas; puede tener todos los módulos |
| **Vende en tienda** | Pertenece a una tienda; sus ventas son de la tienda y suyas; entra al pool de la tienda |
| **Vende por su cuenta** (independiente) | Sin tienda fija, caja propia, bonificación propia |
| **Entrega** (conductor) | Sin tienda; le llegan rutas |
| **Fábrica** | Sin tienda; le llegan pasos; puede no usar el programa (lo anota el encargado) |

Lo demás (qué pantallas ve, qué puede hacer) sale **solo** de los módulos y permisos.

## 6. Metodología: el recorrido de una venta

| # | Momento | Quién (módulo que lo habilita) |
|---|---|---|
| 1 | Cotiza y vende; pone la **fecha que cuadró con el cliente** | Quien tenga Nueva orden |
| 2 | Si mueve plata (descuento, cambio de pago, cancelar): lo pide con motivo y soporte | Quien vende → lo aprueba quien tenga "Aprobar cambios" |
| 3 | Fija fechas por producto si hace falta | Quien tenga "Asignar fechas" |
| 4 | Se fabrica por los pasos que la empresa definió | Encargados de cada paso (Mis pasos) |
| 5 | Último paso (revisión/empaque, si la empresa lo tiene) → queda lista | Encargado de ese paso |
| 6 | Se arma la ruta | Quien tenga Despacho |
| 7 | Se entrega, se cobra el saldo, se firma el acta | Conductor (Mis entregas) o quien tenga Entrega directa |
| 8 | Se factura el mismo día de la entrega | Quien tenga Facturación electrónica |
| 9 | Se pagan bonificaciones | Quien tenga Bonificaciones |

Mercancía: la fábrica surte las tiendas desde la Reserva (**Surtir**); entre tiendas se
mueve con **Traslado**, que normalmente hace el vendedor y lo acepta quien recibe.

## 7. Cómo se haría (fases, para cuando lo aprueben)

1. **Arreglar las bases**: que un rol nuevo se comporte como su base. Sin esto nada de lo
   demás sirve para otra empresa. No cambia nada para Decasa (sus roles ya funcionan).
   ✅ **Hecha el 2026-11-19, sin subir** (`Rol::rolEnElCodigo`, migración
   `2026_11_20_000001_roles_nuevos_se_comportan_como_su_base`, tests en
   `RolNuevoSeComportaComoSuBaseTest`). Las fases 2–7 siguen pendientes; el super admin y
   los rangos van con la fase 3.
2. **"Vender" como módulos**: Nueva orden, Órdenes, Clientes, Cotizaciones, Caja, Consultar
   costo y Estadísticas pasan a ser interruptores. Migración que se los prende a los
   vendedores y supervisores de hoy (nadie pierde nada).
   ✅ **Hecha el 2026-11-19, sin subir** (`ModulosDeVenta`, migración
   `2026_11_20_000002_modulos_de_venta_en_usuarios`, tests en `ModulosDeVentaTest`). Detalle en
   `docs/PROYECTO.md` §7. De paso se cerró que un conductor listaba todas las órdenes por la API.
   Pendiente para la fase 3: quién puede cambiarlos (hoy, como todo lo de la ficha, cualquier
   supervisor) y los predeterminados por rol.
3. **El rol trae sus módulos**: en Gestión → Roles se marcan los módulos del rol; la persona
   tiene en vivo los de su rol, más los que se le agregaron, menos los que se le quitaron
   (decidido: el rol manda y la persona se ajusta encima).
   ✅ **Hecha el 2026-11-19, sin subir**, con el super admin y los rangos (migración
   `2026_11_20_000003_super_admin_y_modulos_por_rol`, comando `usuarios:super-admin`, tests en
   `SuperAdminYRangosTest`). Por ahora el rol trae solo los 7 módulos de venta; los demás
   (Redes, Traslado, Reserva…) siguen por persona. Detalle en `docs/PROYECTO.md` §7.
4. **Inicio y barra armados por módulos**, no por rol.
   ✅ **Hecha el 2026-11-19, sin subir** (solo front, sin migración). El Inicio es un solo
   catálogo (`DashboardView`, `CATALOGO`) donde cada acceso dice cuándo se ve, con la misma regla
   del guard; la sección de administración sale a quien tenga el permiso (Trabajadores, Reportes y
   Gestión siguen siendo de supervisor). La barra de abajo conserva el orden de entrada de cada
   puesto, pero filtra cada acceso por permiso y deja agregar a cualquiera lo que pueda abrir.
   Ver inventario, Citas y Proveedores salen a todos. Comparado en el navegador con vendedor,
   supervisor, conductor, conductor que vende y vendedor con Telas.
5. **Soltar los amarres**: Facturación a cualquier rol (el contador solo); Redes y Telas a
   cualquiera.
   ✅ **Hecha el 2026-11-19, sin subir** (sin migración, tests en `ModulosSinAmarrarAlRolTest`).
   Facturación, Redes y recargar telas se le dan a cualquier rol (no a quien no usa el programa);
   descontar telas va por el permiso, no por llamarse Costurero; los avisos de facturar
   (entrega, garantías) les llegan también a los facturadores que no venden
   (`Usuario::scopeFacturadoresDe`: el vendedor que factura en esa tienda, como antes, y el que
   factura sin vender, como un contador). En la app, `isFacturador` ya no exige ser vendedor.
6. **Permisos especiales** activables (aprobar cambios, asignar fechas, editar catálogo).
   ✅ **Hecha el 2026-11-19, sin subir**, trae **migración**
   `2026_11_20_000004_permisos_especiales_en_usuarios` (agrega `usuarios.asigna_fechas` y
   `usuarios.edita_catalogo`, prendidos a todos los supervisores). Asignar fechas por producto
   (`PATCH /ordenes/{id}/fechas-entrega`, quien no es supervisor solo en órdenes que puede ver) y
   editar precio, nombre y fotos (`PATCH /productos/{id}`) van por permiso; a un supervisor se le
   pueden quitar. Borrar productos, categorías y venta por juego siguen siendo de supervisor.
   **Aprobar cambios de plata se queda solo de supervisores, sin interruptor** (decisión del
   usuario, 2026-11-19: aplicar un cambio aprobado pasa como si lo hiciera el supervisor, y abrirlo
   a otros obliga a tocar a fondo el camino de la plata). Tests en `PermisosEspecialesTest`. El
   aviso "Órdenes para asignar fecha" sigue llegando solo a supervisores.
7. **Surtir y Traslado separados.**
   ✅ **Hecha el 2026-11-19, sin subir**, trae **migración**
   `2026_11_20_000005_surtir_y_traslado_separados` (agrega `usuarios.acceso_traslado`). Traslado
   lo tiene quien tenía Surtir y todos los supervisores; Surtir se queda solo en los supervisores
   que lo tenían (en pantalla, quien no era supervisor solo hacía traslados: no pierde nada). Crear
   un traslado ahora pide el permiso (antes no pedía nada en el servidor). La pantalla de Surtir
   muestra las pestañas según cada permiso, y alguien de la fábrica que no es supervisor puede
   surtir si se le da. Tests en `SurtirYTrasladoSeparadosTest`.
8. Después: revisar las palabras de muebles en pantalla.

## 8. Lo que tiene que decidir el usuario

1. ✅ **Decidido y corregido (usuario, 2026-11-19): el rol solo manda en lo de todos los
   días; lo delicado es por persona.** Primero dijo "el rol manda"; después lo corrigió:
   "yo soy vendedor y facturador, tengo facturación; otro vendedor que no tiene nada que ver
   no puede tenerla, porque por maldad puede dañar las facturas", y "un supervisor que no
   tiene por qué ver costos no los ve solo porque otro supervisor sí". Queda así:
   - **Módulos del día a día** (Nueva orden, Órdenes, Clientes, Cotizaciones, Caja, Ver
     inventario, Citas, Traslado, Mis entregas…): los trae el rol, en vivo.
   - **Módulos delicados** (Facturación electrónica, Costos, Bonificaciones, Reglas de
     bonificación, Nómina, Finanzas, Compras, Despacho, Cambiar inventario, Ver valor del
     inventario, y los permisos especiales del §4): **nunca vienen con el rol**. Se le dan
     a cada persona, uno por uno, y solo el super admin (ver punto 5).
   ✅ Confirmado (usuario, 2026-11-19): los del rol son **predeterminados**: vienen solos
   pero se le pueden quitar a la persona. **Predeterminados del Vendedor:** Nueva orden,
   Órdenes, Ver inventario, Clientes, Traslado, Cotizaciones, Mis estadísticas, Consultar
   costo y Citas. "Solo eso": Caja, Redes y lo demás se dan aparte.
2. ✅ **Decidido (usuario, 2026-11-19): también se puede quitar.** A una persona se le puede
   agregar un módulo que su rol no trae **o quitar** uno que sí trae. Cómo se guarda: por
   persona solo las diferencias con su rol (agregado / quitado); lo que no se tocó sigue al
   rol. Así, si el rol cambia después, a esa persona le cambia todo menos lo que se le ajustó
   a mano. La ficha muestra cada módulo como "del rol", "agregado" o "quitado", con un botón
   para volver a lo del rol.
3. ✅ **Decidido (usuario, 2026-11-19): sirven las 5 bases del §5**, sin base aparte para el
   contador. El contador que solo factura se arma con cualquier base (por ejemplo
   Administra) quitándole todo menos Facturación electrónica.
4. ✅ **Decidido (usuario, 2026-11-19): Inventario son dos módulos, "Ver inventario" y
   "Cambiar inventario".** Ver: de fábrica para todos (se puede quitar por rol o persona).
   Cambiar (entradas, salidas, repartir el stock entre medidas/telas, corregir descuadres):
   de fábrica para Vendedor (en su tienda) y Supervisor (todas), que es lo que pasa hoy.
   Hoy "cambiar" depende del rol (`role:supervisor,vendedor`); y repartir el stock entre
   medidas y combinaciones (`/inventario/variante-configs/entrada` y
   `/variante-combinaciones/entrada`) no revisa ni rol ni tienda: cualquiera que entre lo
   puede hacer en cualquier tienda. Se cierra con el permiso nuevo.
5. ✅ **Decidido (usuario, 2026-11-19): un super admin.** El problema de hoy: cualquier
   supervisor edita los permisos de cualquiera, también los suyos (solo "Reglas de
   bonificación" se protege). Un supervisor se puede dar Finanzas o Costos a sí mismo, y
   quitárselos a otro. Propuesta:
   - **Super admin** = una marca por persona, no un rol. Se suma a lo que ya es: el super
     admin sigue siendo supervisor (o lo que sea su rol), puede pertenecer a una tienda y
     vender, y conserva todos sus módulos de vendedor (usuario, 2026-11-19: "yo también soy
     vendedor, no me puedo quedar sin esos módulos"). Solo él crea y cambia roles, da o
     quita módulos delicados, y nombra o quita a otro super admin.
   - **Nadie cambia sus propios permisos**, ni el super admin (para cambiarse algo, otro
     super admin). Excepción: si es el único super admin.
   - **Siempre queda al menos un super admin**: no se puede quitar ni desactivar al último.
   - Un supervisor no puede editar a un super admin.
   - Todo cambio de permisos queda en un historial (quién, a quién, qué, cuándo) y le llega
     aviso al super admin.
   - ✅ **Primer super admin: el usuario que maneja el sistema** (decidido 2026-11-19). Al
     subirlo, la migración no adivina: se marca su cuenta por id/correo que él confirme ese
     día. Desde la app nadie puede hacerse el primer super admin (si no, el primer supervisor
     que llegue se lo pone).
   - ✅ **Decidido (usuario, 2026-11-19, corrige lo de "solo super admin"): es una escala,
     por rangos.** "Los supervisores normales no se quitan cosas entre ellos."

     | Rango | A quién le pone y quita módulos | A quién no |
     |---|---|---|
     | **Super admin** | A todos: supervisores y trabajadores | A sí mismo, si hay otro super admin |
     | **Supervisor** | A los que no son supervisores: vendedores, conductores, fábrica | A otro supervisor, a un super admin, a sí mismo |
     | **Los demás** | A nadie | — |

     ✅ Decidido (usuario, 2026-11-19): el supervisor le puede **dar cualquier módulo** a un
     trabajador, aunque él mismo no lo tenga. Se le propuso limitarlo a "solo lo que él
     tiene" y dijo que no. Lo que queda de control: el historial de cambios y el aviso al
     super admin de cada módulo delicado que se reparte.
   - ✅ **Decidido (usuario, 2026-11-19): el supervisor crea trabajadores y les escoge o
     cambia el rol, pero nunca un rol de base Administra** (ni pasar a alguien a uno, ni
     cambiarle el rol a quien ya tiene uno de esa base, ni a un super admin). Eso solo lo
     hace el super admin. El servidor lo frena, no solo la pantalla.
