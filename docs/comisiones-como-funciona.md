# Comisiones: cómo funcionan hoy

Documento de negocio, al día al 2026-11-04. Reemplaza a `explicacion_modulo_comisiones.txt`
(que quedó viejo en partes). La explicación que ven los usuarios, con las cifras de las reglas
vigentes, está en la app: **Comisiones → Ayuda**. Lo técnico, en `docs/PROYECTO.md` §6.10 y
`docs/plan-comisiones-personalizables.md`.

Todas las cifras de abajo son **las de hoy** y se pueden cambiar en **Comisiones → Reglas**
(con fecha "desde qué mes", por tienda o por persona). Lo de antes de la fecha no se mueve.

## 1. De dónde sale una comisión

- Al confirmar una orden se crea su comisión: vendedor, tienda, mes de la venta y valor.
  Cotizaciones y borradores no comisionan; las canceladas tampoco.
- **Venta compartida:** la mitad para cada uno (regla "Parte de quien registra").
- **Base:** lo que vale la orden menos lo que se llevó la franquicia de lo pagado con tarjeta o
  Addi (5,5 %). Sobre eso nadie comisiona.
- **IVA:** a las ventas se les quita (÷ 1,19); a las restauraciones no (son mano de obra).

## 2. Cómo cobra cada quien

| Caso | Cómo cobra |
|---|---|
| Tienda con meta y equipo (pool) | El equipo se reparte el pool: (lo que cuenta − meta) ÷ 1,19 × 5 %, por los días que estuvo cada uno. Sin meta cumplida, $0 de pool. |
| Tienda sin meta, que no reparte, o alguien de fuera del equipo | 5 % de lo suyo (÷ 1,19). |
| Restauración | 5 % de su valor, sin quitar el IVA, repartido entre los que estaban ese día, **si las ventas solas llegan a la meta**. Si las ventas no llegan pero con las restauraciones sí, ese mes cuentan como ventas (entran al pool con el IVA quitado, no se pagan aparte). Si ni con ellas se llega, no se paga nada (regla "Las restauraciones ayudan a llegar a la meta", encendida para todas desde siempre). En tiendas sin meta, su 5 % como siempre. |
| Independiente | 5 % de sus ventas (÷ 1,19) + el 5 % del bolsón de restauraciones de todos. Sin meta. |
| Venta de un independiente con un almacén | Al almacén le suma la mitad a la meta y cobra su propio 5 %. |
| Quien reemplaza | Entra al pool por los días que cubrió (o, si la tienda lo escoge, un % de lo que vendió). |
| Orden "fuera del pool" | No suma a la meta, no arma pool y no paga comisión. La marca un supervisor, con motivo. |

## 3. Las tres condiciones para cobrar (no cambian nunca)

1. El cliente pagó **la mitad** de la orden.
2. Llegó el **día de pago**: el 20 del mes siguiente (o el 20 después del cierre del trimestre).
3. La tienda llegó a la **meta**, medida solo con las ventas que ya tienen la mitad pagada.

Lo vendido sin la mitad pagada es venta, pero no suma a la meta hasta que la paguen.

## 4. Mensual o trimestral

- Cada tienda va **cada mes** o **por trimestre** (ene–mar, abr–jun, jul–sep, oct–dic). En la
  trimestral se suman los tres meses: lo que le sobra a un mes paga lo que le falta a otro, y si
  el trimestre no llega, la deuda pasa al siguiente.
- Se cambia en Metas ("Se liquida") o, con fecha, en Reglas ("Cada cuánto se liquida"). Solo
  puede empezar el primer mes de un trimestre, para que ninguno quede partido.
- Hoy: Unicentro Pereira va **cada mes desde julio de 2026** (metas: sep $50 M, oct en adelante
  $60 M). Circunvalar era trimestral y cerró el 27/08/2026.

## 5. Cómo se paga

- **Listas** junta por persona todo lo que está listo, un bloque por mes, y dice de dónde sale
  cada peso, qué falta cobrar y por qué una tienda no comisiona. Se paga del mes más viejo al
  más nuevo, con un recibo por mes.
- **Anticipos y abonos:** lo que se le dio antes se descuenta al pagar, del más viejo al más
  nuevo. La comisión no cambia; cambia lo que se entrega.
- **Excel:** "Lo que se va a pagar" (con un comprobante por persona para firmar y la hoja Por
  cobrar), "Un mes completo" y "Cómo va el mes" (estimado).
- **Recalcular** pone al día los montos guardados (también solo, a las 6:30). Listas calcula al
  abrirse: lo que muestra es lo que se paga.

## 6. Decisiones del dueño que conviene recordar

- El 50 % y la meta se miran **a nivel de tienda**: todos los del equipo cobran igual por día.
- Las ventas de una tienda son de la tienda y de quien vendió (ver `MEMORY.md` §2).
- Nada se paga dos veces: lo vendido cuenta en un solo mes y en una sola tienda.
- Una regla de plata nueva o cambiada se pregunta al dueño antes, y queda con fecha.
- Las decisiones que todavía están por confirmar están en `docs/plan-comisiones-personalizables.md` §13.
