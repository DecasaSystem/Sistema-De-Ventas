<script setup>
// Ayuda de Comisiones: cómo funciona el módulo en todos los casos, en
// palabras sencillas y con ejemplos con cifras.
//
// No trae datos de nadie: son las reglas y ejemplos inventados, así que la
// puede abrir cualquiera que haya iniciado sesión (los vendedores también
// quieren entender por qué les salió lo que les salió). Las reglas salen de
// ComisionController::enriquecer(), libroDelPool(), ComisionIndependientes y
// AnticiposComision: si cambia una regla allá, hay que cambiarla aquí.
import { ref, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import {
  QuestionMarkCircleIcon, ChevronDownIcon, ChevronUpIcon, ArrowLeftIcon,
  BuildingStorefrontIcon, UserIcon, UsersIcon, WrenchScrewdriverIcon, CreditCardIcon,
  CalendarDaysIcon, BanknotesIcon, ArrowsRightLeftIcon, ClockIcon, LightBulbIcon,
  ReceiptPercentIcon, ChartBarIcon, DocumentTextIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()

const SECCIONES = [
  { id: 'basico',        titulo: 'Lo básico',                          icono: LightBulbIcon },
  { id: 'cual',          titulo: '¿Cuál es mi caso?',                  icono: QuestionMarkCircleIcon },
  { id: 'meta',          titulo: 'Tienda con meta y equipo',           icono: BuildingStorefrontIcon },
  { id: 'individual',    titulo: 'Sin meta o tienda que no comparte',  icono: UserIcon },
  { id: 'compartida',    titulo: 'Venta compartida entre dos',         icono: UsersIcon },
  { id: 'restauracion',  titulo: 'Restauraciones',                     icono: WrenchScrewdriverIcon },
  { id: 'independiente', titulo: 'Independientes',                     icono: UserIcon },
  { id: 'almacen',       titulo: 'Independiente que comparte con un almacén', icono: ArrowsRightLeftIcon },
  { id: 'tarjeta',       titulo: 'Pagos con tarjeta o Addi',           icono: CreditCardIcon },
  { id: 'fv2',           titulo: 'FV2 y "sin restar IVA"',             icono: DocumentTextIcon },
  { id: 'trimestral',    titulo: 'Tiendas trimestrales',               icono: ChartBarIcon },
  { id: 'equipo',        titulo: 'Equipo, reemplazos y traslados',     icono: ArrowsRightLeftIcon },
  { id: 'cuando',        titulo: '¿Cuándo queda lista para pagar?',    icono: CalendarDaysIcon },
  { id: 'pago',          titulo: 'Cómo se paga',                       icono: BanknotesIcon },
  { id: 'atrasados',     titulo: 'Si se atrasa el pago',               icono: ClockIcon },
  { id: 'anticipos',     titulo: 'Anticipos de comisión',              icono: ReceiptPercentIcon },
  { id: 'preguntas',     titulo: 'Preguntas frecuentes',               icono: ExclamationTriangleIcon },
]

// Lo básico abierto de entrada: es lo que todos tienen que leer primero.
const abiertas = ref(new Set(['basico']))

function alternar(id) {
  const s = new Set(abiertas.value)
  s.has(id) ? s.delete(id) : s.add(id)
  abiertas.value = s
}

const todoAbierto = ref(false)
function alternarTodo() {
  todoAbierto.value = !todoAbierto.value
  abiertas.value = new Set(todoAbierto.value ? SECCIONES.map(s => s.id) : [])
}

/** Abre una sección y la trae a la vista (desde el índice o un enlace interno). */
async function ir(id) {
  abiertas.value = new Set([...abiertas.value, id])
  await nextTick()
  document.getElementById(`ayuda-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const titulo = id => SECCIONES.find(s => s.id === id)?.titulo
</script>

<template>
  <div class="ayuda-comisiones p-4 max-w-2xl mx-auto space-y-4 pb-24">
    <div class="flex items-center gap-2">
      <button type="button" @click="router.back()" class="text-gray-400 hover:text-gray-600" aria-label="Volver">
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
        <QuestionMarkCircleIcon class="w-6 h-6 text-green-600" />
        Cómo funcionan las comisiones
      </h2>
    </div>

    <!-- En 30 segundos -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">En 30 segundos</p>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Cómo se gana</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            En una tienda con meta, el equipo se reparte el 5 % de lo que la tienda vende <b>por encima de la meta</b> (sin IVA).
            Sin meta, cada uno cobra el 5 % de lo suyo.
          </p>
        </div>
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Cuándo se cobra</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            El <b>20 del mes siguiente</b> a la venta, si el cliente ya pagó <b>la mitad</b> de la orden
            (las restauraciones no esperan la mitad).
          </p>
        </div>
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Cómo se paga</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            Todo junto por persona, desde <b>Comisiones → Listas</b>. Si se juntan meses, primero el más viejo.
          </p>
        </div>
      </div>
    </div>

    <!-- Índice -->
    <div class="bg-white rounded-xl shadow-sm p-4">
      <div class="flex items-center justify-between mb-2">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Temas</p>
        <button type="button" @click="alternarTodo" class="text-[11px] font-semibold text-blue-600">
          {{ todoAbierto ? 'Cerrar todo' : 'Abrir todo' }}
        </button>
      </div>
      <div class="flex flex-wrap gap-1.5">
        <button
          v-for="s in SECCIONES" :key="s.id" type="button" @click="ir(s.id)"
          class="text-[11px] font-semibold rounded-full px-2.5 py-1 bg-gray-100 text-gray-600 hover:bg-gray-200"
        >{{ s.titulo }}</button>
      </div>
    </div>

    <!-- Secciones -->
    <section
      v-for="s in SECCIONES" :key="s.id" :id="`ayuda-${s.id}`"
      class="bg-white rounded-xl shadow-sm overflow-hidden scroll-mt-4"
    >
      <button type="button" @click="alternar(s.id)" class="w-full flex items-center justify-between gap-2 px-4 py-3 text-left">
        <span class="flex items-center gap-2 min-w-0">
          <component :is="s.icono" class="w-5 h-5 text-green-600 shrink-0" />
          <span class="text-sm font-semibold text-gray-800">{{ s.titulo }}</span>
        </span>
        <ChevronUpIcon v-if="abiertas.has(s.id)" class="w-4 h-4 text-gray-400 shrink-0" />
        <ChevronDownIcon v-else class="w-4 h-4 text-gray-400 shrink-0" />
      </button>

      <div v-if="abiertas.has(s.id)" class="ayuda px-4 pb-4 space-y-3 text-[13px] text-gray-600 leading-relaxed">

        <!-- ── Lo básico ─────────────────────────────────────────────── -->
        <template v-if="s.id === 'basico'">
          <p>Palabras que salen en toda la pantalla de Comisiones:</p>
          <dl class="space-y-2">
            <div><dt>Meta</dt><dd>Lo que una tienda tiene que vender en el mes. Se pone en Comisiones → Metas.</dd></div>
            <div><dt>Pool</dt><dd>La plata que gana el equipo de una tienda cuando pasa la meta: el 5 % de lo vendido por encima de la meta, sin IVA.</dd></div>
            <div><dt>÷ 1,19</dt><dd>Los precios traen IVA. Antes de sacar el 5 % de una <b>venta</b> se le quita el IVA (se divide entre 1,19). A una restauración no se le quita.</dd></div>
            <div><dt>El 50 %</dt><dd>Una venta solo cuenta (para la meta y para cobrar) cuando el cliente ya pagó al menos la mitad de la orden.</dd></div>
            <div><dt>Mes de venta</dt><dd>El mes en que se confirmó la orden, en hora de Colombia. Una cotización es venta del mes en que el cliente la aceptó.</dd></div>
            <div><dt>Día de pago</dt><dd>El 20 del mes siguiente a la venta (venta de julio → 20 de agosto). En tiendas trimestrales, el 20 del mes siguiente al cierre del trimestre.</dd></div>
          </dl>
          <p>Cada comisión pasa por tres estados:</p>
          <div class="flex flex-wrap gap-1.5">
            <span class="chip bg-orange-100 text-orange-700">Pendiente: le falta algo</span>
            <span class="chip bg-green-100 text-green-700">Lista: se puede pagar</span>
            <span class="chip bg-gray-100 text-gray-600">Pagada: ya se entregó</span>
          </div>
          <p class="nota">Lo pagado queda congelado con el monto que se pagó. Lo que no se ha pagado se vuelve a calcular cada mañana (6:30) por si un cliente abonó, se canceló una orden o cambió un precio.</p>
          <p class="nota">Las órdenes <b>canceladas</b>, las cotizaciones y los borradores no cuentan para nada.</p>
        </template>

        <!-- ── ¿Cuál es mi caso? ────────────────────────────────────────── -->
        <template v-else-if="s.id === 'cual'">
          <p>Busca tu caso y abre su tema para ver el detalle:</p>
          <div class="space-y-2">
            <button v-for="c in [
                { caso: 'Trabajo en una tienda con meta y equipo (comparten)', como: 'Parte del pool, igual para todos (por días)', ir: 'meta' },
                { caso: 'Mi tienda no tiene meta o no comparte, o soy supervisor de Tienda Virtual / Vía Jardines', como: '5 % de lo mío, sin IVA', ir: 'individual' },
                { caso: 'Vendí con un compañero', como: 'Cada uno sobre la mitad', ir: 'compartida' },
                { caso: 'Hice una restauración', como: '5 % del valor, sin quitar IVA', ir: 'restauracion' },
                { caso: 'Soy independiente', como: '5 % de mis ventas + el bolsón de restauraciones', ir: 'independiente' },
                { caso: 'Un independiente me pasó un cliente', como: 'La tienda cobra su 5 %', ir: 'almacen' },
                { caso: 'El cliente pagó con tarjeta o Addi', como: 'Se descuenta la franquicia (5,5 %)', ir: 'tarjeta' },
                { caso: 'Mi tienda es trimestral', como: 'Se mide y se paga cada 3 meses', ir: 'trimestral' },
              ]" :key="c.ir + c.caso" type="button" @click="ir(c.ir)"
              class="w-full text-left rounded-lg border border-gray-100 px-3 py-2 hover:bg-gray-50">
              <p class="text-xs font-semibold text-gray-800">{{ c.caso }}</p>
              <p class="text-[11px] text-gray-500">{{ c.como }} · <span class="text-blue-600 font-semibold">Ver {{ titulo(c.ir) }}</span></p>
            </button>
          </div>
        </template>

        <!-- ── Tienda con meta ──────────────────────────────────────────── -->
        <template v-else-if="s.id === 'meta'">
          <p>En una tienda con meta que <b>comparte</b> la comisión, la comisión no es de cada venta sino de la <b>tienda</b>: el equipo se reparte lo que la tienda vende por encima de la meta.</p>
          <div class="formula">Pool = (ventas de la tienda − meta) ÷ 1,19 × 5 %</div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Meta $40.000.000. La tienda vendió $45.000.000 (con el 50 % pagado).</p>
            <p>Por encima de la meta: $5.000.000 → ÷ 1,19 = $4.201.681 → × 5 % = <b>$210.084</b> de pool.</p>
            <p>Tres personas en el equipo todo el mes: <b>$70.028</b> cada una.</p>
          </div>
          <p><b>Todos cobran lo mismo</b> (según los días que estuvieron), venda cada uno mucho, poco o nada. Quien no vendió nada ese mes también cobra su parte: sale en un renglón "parte del equipo".</p>
          <p><b>El 50 % se mira en la tienda, no en cada persona.</b> Para la meta y para el pool solo cuentan las ventas cuyo cliente ya pagó la mitad, las venda quien las venda.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo real</p>
            <p>El Edén, agosto: vendió $49,5 millones, pero con el 50 % pagado solo $39,6 millones. La meta era $40 millones: <b>no llegó y el pool es $0</b>, aunque "vendió más que la meta".</p>
            <p>Si después un cliente paga la mitad y la tienda pasa la meta, el pool aparece. Si alguien ya había cobrado, solo sale <b>la diferencia</b>.</p>
          </div>
          <p>Si la tienda no llega a la meta ese mes, el pool es <b>$0</b> y no hay nada que repartir (en las mensuales no se arrastra deuda; en las trimestrales sí, ver <a @click.prevent="ir('trimestral')" href="#">Tiendas trimestrales</a>).</p>
          <p class="nota">A la meta le suma el valor que comisiona cada venta: sin lo que se llevó la franquicia de la tarjeta, y la mitad si la venta es compartida. Las restauraciones no le suman. La mitad de lo que comparte un independiente sí (ver <a @click.prevent="ir('almacen')" href="#">Independiente que comparte</a>).</p>
          <p class="nota">Quien vende en la tienda sin estar en su equipo (ni cubriendo a nadie) cobra el 5 % de lo suyo por fuera del pool. Sus ventas sí empujan la meta de la tienda.</p>
        </template>

        <!-- ── Individual ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'individual'">
          <p>Cada quien cobra sobre lo que vendió, sin pool ni reparto, cuando:</p>
          <ul>
            <li>Su tienda <b>no tiene meta</b> ese mes (por ejemplo, Tienda Virtual o Vía Jardines, donde casi todos son supervisores).</li>
            <li>Su tienda está marcada para que <b>cada quien cobre lo suyo</b> (no comparte), aunque tenga meta.</li>
            <li>Vende en una tienda con meta sin ser de su equipo ni estar cubriendo a nadie.</li>
          </ul>
          <div class="formula">Comisión = valor de la venta ÷ 1,19 × 5 %</div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Venta de $10.000.000 → ÷ 1,19 = $8.403.361 → × 5 % = <b>$420.168</b>.</p>
          </div>
          <p>No depende de ninguna meta: queda lista el 20 del mes siguiente si el cliente pagó la mitad.</p>
          <p class="nota">Lo que decide si es individual es la tienda de la persona (la de su perfil), no la tienda que se escogió en la orden.</p>
        </template>

        <!-- ── Compartida ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'compartida'">
          <p>Cuando dos asesores hacen la venta juntos, la orden se parte <b>por la mitad</b>: cada uno comisiona sobre la mitad, con las reglas de su propia tienda.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Venta compartida de $10.000.000: a cada uno le cuentan $5.000.000.</p>
            <p>Si los dos cobran el 5 % directo: $5.000.000 ÷ 1,19 × 5 % = <b>$210.084</b> cada uno.</p>
            <p>Si uno está en una tienda con pool, sus $5.000.000 suman a la meta de su tienda y cobra por el pool.</p>
          </div>
          <p class="nota">El 50 % se mide contra la orden entera, no contra la mitad: el cliente tiene que haber pagado $5.000.000 de los $10.000.000.</p>
        </template>

        <!-- ── Restauraciones ───────────────────────────────────────────── -->
        <template v-else-if="s.id === 'restauracion'">
          <p>Una restauración se paga distinto a una venta:</p>
          <ul>
            <li><b>5 % del valor, sin quitar el IVA</b> (es mano de obra).</li>
            <li><b>No espera la mitad:</b> queda lista con solo llegar el 20 del mes siguiente, haya pagado el cliente lo que haya pagado.</li>
            <li><b>No suma a la meta ni entra al pool.</b></li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Restauración de $2.000.000 → 5 % = <b>$100.000</b>.</p>
            <p>En una tienda que comparte, ese 5 % es del equipo que estaba en la tienda <b>ese día</b>: con dos personas, $50.000 cada una.</p>
            <p>Quien trabaja por su cuenta (tienda que no comparte, sin meta) cobra los $100.000 completos.</p>
          </div>
          <p class="nota">Toda restauración también le suma al bolsón de los independientes (ver <a @click.prevent="ir('independiente')" href="#">Independientes</a>). Eso no le quita nada a la tienda: la tienda cobra su 5 % por su lado.</p>
          <p class="nota">Una orden cuenta como restauración cuando todos sus productos son restauración. Si mezcla venta y restauración, va como venta.</p>
        </template>

        <!-- ── Independientes ───────────────────────────────────────────── -->
        <template v-else-if="s.id === 'independiente'">
          <p>Los independientes no tienen meta ni pool. Cobran un porcentaje fijo:</p>
          <ul>
            <li><b>Sus ventas:</b> 5 % de lo suyo, sin IVA, solo para quien vendió.</li>
            <li><b>Restauraciones (bolsón):</b> se suman todas las restauraciones del mes, de cualquier independiente y también las de los almacenes. <b>Cada</b> independiente cobra el 5 % de ese bolsón <b>completo</b> (no la mitad).</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Henry vendió $10.000.000 → cobra <b>$420.168</b> de sus ventas.</p>
            <p>En el mes hubo $6.000.000 en restauraciones (de todos) → bolsón al 5 % = $300.000. Henry cobra <b>$300.000</b> y el otro independiente también $300.000.</p>
            <p>Total de Henry: $720.168.</p>
          </div>
          <p>Se pagan <b>el mes completo desde su tarjeta</b>, no orden por orden. Las ventas esperan la mitad pagada y el 20; las restauraciones, solo el 20.</p>
          <p class="nota">Si comparte la venta con un almacén, el independiente sigue cobrando el 5 % sobre la venta <b>entera</b>; lo del almacén va aparte (siguiente tema).</p>
        </template>

        <!-- ── Independiente + almacén ──────────────────────────────────── -->
        <template v-else-if="s.id === 'almacen'">
          <p>Cuando un independiente comparte una orden con un almacén (la tienda le pasó el cliente o lo atendió), pasan dos cosas:</p>
          <ul>
            <li><b>El almacén cobra su propio 5 %</b> sobre el valor completo (a la venta se le quita el IVA; a la restauración no). Ese 5 % se reparte entre el equipo de la tienda ese día. Nunca va por el pool.</li>
            <li>A la <b>meta del almacén le suma la mitad</b> de la venta. Una restauración compartida no le suma a la meta.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Henry vende $10.000.000 compartido con Norte (equipo de 3).</p>
            <p>Henry: <b>$420.168</b>. Norte: 5 % de $10.000.000 ÷ 1,19 = $420.168, o sea <b>$140.056</b> para cada uno de los 3.</p>
            <p>A la meta de Norte le suman $5.000.000.</p>
          </div>
        </template>

        <!-- ── Tarjeta ──────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'tarjeta'">
          <p>De cada peso que se cobra por <b>datáfono (tarjeta) o Addi</b>, la franquicia se queda el <b>5,5 %</b>. Esa plata nunca llega a la caja, así que nadie comisiona sobre ella.</p>
          <div class="formula">Base = valor de la orden − (pagado con tarjeta o Addi × 5,5 %)</div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo (pago mixto)</p>
            <p>Orden de $10.000.000: el cliente pagó $4.000.000 en efectivo y $6.000.000 con tarjeta.</p>
            <p>Franquicia: $6.000.000 × 5,5 % = $330.000 → base $9.670.000.</p>
            <p>Con el 5 % directo: $9.670.000 ÷ 1,19 × 5 % = <b>$406.303</b> (en vez de $420.168).</p>
          </div>
          <p>Solo se descuenta lo que de verdad se pagó con tarjeta. Si el cliente todavía no ha pagado con tarjeta, no se descuenta nada; el día que lo haga, la comisión se ajusta sola.</p>
          <p class="nota">Esto también baja lo que la venta le suma a la meta de la tienda. Y aplica igual a independientes y a tiendas.</p>
          <p class="nota">Aparte de la comisión: pagar con tarjeta, Addi u "otro" hace perder el descuento condicionado de la orden (ese descuento solo vale en efectivo o transferencia). Eso cambia el total de la orden, y con él la comisión.</p>
        </template>

        <!-- ── FV2 ──────────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'fv2'">
          <p>Las FV2 (órdenes con descuento especial) llevan su propia numeración, pero comisionan con las mismas reglas que cualquier venta.</p>
          <p>Si una FV2 está marcada <b>"sin restar IVA"</b> y quien la vendió cobra el 5 % directo, el 5 % se saca del valor <b>entero</b>:</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>FV2 de $10.000.000 sin restar IVA → 5 % = <b>$500.000</b> (en vez de $420.168).</p>
          </div>
          <p class="nota">La marca solo le sirve a quien vendió la orden y solo en el 5 % directo. Si la venta va por el pool de una tienda, se mezcla con las demás y no cambia nada. Al covendedor o al almacén que ayudó se les paga con la regla de siempre.</p>
        </template>

        <!-- ── Trimestral ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'trimestral'">
          <p>Algunas tiendas (por ejemplo, Unicentro Pereira) no se miden cada mes sino cada <b>trimestre</b>: ene–mar, abr–jun, jul–sep, oct–dic. Se marca en Metas → "Se liquida".</p>
          <ul>
            <li>La meta es la misma cada mes y hay que cumplir <b>los 3 meses</b>.</li>
            <li>Lo que le sobra a un mes paga lo que le falta a otro del mismo trimestre.</li>
            <li>Si el trimestre entero no llega, no comisiona y <b>la deuda pasa al trimestre siguiente</b>.</li>
            <li>Se cobra el <b>20 del mes siguiente al cierre</b>: lo de jul–sep, el 20 de octubre. Ese día queda fijo.</li>
            <li>El pool del trimestre se reparte por los días que estuvo cada quien en los 3 meses.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo (meta $40 millones)</p>
            <p>Julio $60 M (sobran 20), agosto $20 M (faltan 20, los paga julio), septiembre $50 M (sobran 10).</p>
            <p>Comisiona lo que sobra al final: $10.000.000 ÷ 1,19 × 5 % = <b>$420.168</b>.</p>
          </div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo con deuda</p>
            <p>Jul–sep: $105 M contra $120 M → faltan $15 M → no comisiona; pasan $15 M de deuda.</p>
            <p>Oct–dic: $140 M contra $120 M → sobran $20 M − $15 M de deuda = $5 M → <b>$210.084</b>.</p>
          </div>
          <p class="nota">Solo cuentan las ventas con el 50 % pagado. Los anticipos de los 3 meses se descuentan juntos al pagar el trimestre.</p>
        </template>

        <!-- ── Equipo ───────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'equipo'">
          <p>Entre quiénes se reparte lo de una tienda lo dice el <b>equipo</b> de cada tienda, en Comisiones → Metas. Se arrastra de mes a mes hasta que alguien lo cambie. Tener la tienda como sede en el perfil no mete a nadie en el reparto.</p>
          <p><b>Reemplazos:</b> cuando alguien se va unos días (vacaciones, incapacidad) y otro lo cubre, el que llega ocupa su puesto. El pool se reparte por días: el que cubre pesa los días que cubrió y el cubierto pierde esos mismos días.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Pool de $210.084 en Norte. Equipo: Marta, NN y Paola. Manuela cubre a Paola del 16 al 30 de septiembre.</p>
            <p>Días: Marta 30, NN 30, Paola 15, Manuela 15 (total 90).</p>
            <p>Marta y NN: <b>$70.028</b> cada una. Paola y Manuela: <b>$35.014</b> cada una.</p>
          </div>
          <p><b>Traslados:</b> si alguien se cambia de tienda para quedarse, no cubre a nadie: desde ese día es uno más del equipo nuevo. Se hace solo al cambiarle la tienda en Usuarios. Para cubrir unos días no se cambia la tienda del perfil: se registra el reemplazo.</p>
          <p><b>Ventas digitales:</b> lo que se cierra por WhatsApp, Instagram, Facebook o la página cuenta para la tienda <b>de quien la cerró</b>. Lo del mostrador cuenta para la tienda de la orden.</p>
          <p class="nota">Al registrar o corregir un reemplazo, o al cambiar el equipo, los repartos sin pagar de ese mes se rehacen solos. Lo pagado no se toca.</p>
          <p class="nota">Cuando una tienda se cierra, desde el mes siguiente deja de arrastrar meta y equipo.</p>
        </template>

        <!-- ── Cuándo ───────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'cuando'">
          <p>Una comisión pasa a <b>Lista</b> cuando se cumple todo lo de su caso:</p>
          <div class="space-y-2">
            <div v-for="c in [
                { caso: 'Venta en tienda con meta (pool)', req: ['El cliente pagó la mitad', 'Llegó el 20', 'La tienda llegó a la meta'] },
                { caso: 'Parte del equipo (no vendió)', req: ['Llegó el 20', 'La tienda llegó a la meta'] },
                { caso: 'Venta al 5 % directo', req: ['El cliente pagó la mitad', 'Llegó el 20'] },
                { caso: 'Restauración', req: ['Llegó el 20'] },
                { caso: 'Venta de independiente', req: ['El cliente pagó la mitad', 'Llegó el 20'] },
                { caso: 'Tienda trimestral', req: ['El cliente pagó la mitad', 'Llegó el 20 después del cierre', 'El trimestre cubrió la meta y la deuda'] },
              ]" :key="c.caso" class="rounded-lg border border-gray-100 px-3 py-2">
              <p class="text-xs font-semibold text-gray-800">{{ c.caso }}</p>
              <div class="flex flex-wrap gap-1 mt-1">
                <span v-for="r in c.req" :key="r" class="chip bg-gray-100 text-gray-600">{{ r }}</span>
              </div>
            </div>
          </div>
          <p class="nota">Estas tres condiciones del pool (la mitad pagada, el día 20 y la meta) son reglas del negocio y no cambian.</p>
        </template>

        <!-- ── Pago ─────────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'pago'">
          <ul>
            <li>El <b>20</b> llega un aviso a los supervisores con acceso a comisiones: cuántas personas, el total, lo que se descuenta de anticipos y lo que hay que entregar.</li>
            <li>En <b>Comisiones → Listas</b> sale una tarjeta por persona con todo lo que tiene listo. Se paga <b>todo junto por persona</b>, no orden por orden.</li>
            <li>Al pagar, el sistema le descuenta los anticipos que deba y muestra un <b>recibo</b>: comisión, anticipo y lo que se entrega.</li>
            <li>Un pago marcado por error se puede <b>deshacer</b> desde la comisión pagada (pide motivo). Lo descontado de anticipos vuelve a deberse.</li>
            <li>Todo queda en el <b>historial</b>: quién pagó, quién deshizo, quién cambió metas, equipos o reemplazos.</li>
          </ul>
          <p class="nota"><b>Recalcular</b> pone todo al día en el momento. El sistema lo hace solo cada mañana a las 6:30.</p>
        </template>

        <!-- ── Atrasados ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'atrasados'">
          <p>Si un 20 no se pagó, el mes siguiente se juntan dos meses. Listas los muestra juntos y separados:</p>
          <ul>
            <li>Arriba sale un aviso rojo con cuánto quedó sin pagar y desde qué fecha.</li>
            <li>Cada persona tiene un <b>bloque por mes</b>: "Atrasada · tocaba el 20 oct" o "Toca el 20 nov".</li>
            <li>Un solo botón paga todo, <b>siempre del mes más viejo al más nuevo</b>, y deja un recibo por mes.</li>
            <li>No se puede pagar un mes si hay uno más viejo listo sin pagar.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">¿Por qué en orden?</p>
            <p>Los anticipos se descuentan "hasta el mes que se paga". Con $200.000 de anticipo al mes: pagando septiembre y luego octubre, cada mes descuenta su anticipo. Pagando octubre primero, a octubre se le descontaban los dos y lo entregado ese día cambiaba.</p>
          </div>
          <p class="nota">El aviso del 20 también dice lo que quedó sin pagar de meses anteriores.</p>
        </template>

        <!-- ── Anticipos ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'anticipos'">
          <p>Algunos vendedores se llevan cada mes una parte de la comisión por adelantado. Se configura por persona en Comisiones → Metas → Anticipos: cuánto cada mes y desde qué mes.</p>
          <ul>
            <li>Al pagar la comisión se resta lo que debe de anticipos <b>hasta el mes de esa comisión</b>. El anticipo de octubre es de la comisión de octubre, no de la de septiembre.</li>
            <li>Si la comisión no alcanza (o no comisionó), se descuenta lo que haya y el resto lo sigue debiendo para el pago siguiente.</li>
            <li>Si un mes se llevó otra cantidad, se corrige ese mes. Cambiar el monto vale desde el mes que se escoja en adelante.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo trimestral</p>
            <p>$200.000 de anticipo al mes. Al pagar jul–sep se descuentan los tres: <b>$600.000</b>.</p>
          </div>
          <p class="nota">La comisión queda registrada completa; el descuento va aparte, para poder ver las dos cosas.</p>
        </template>

        <!-- ── Preguntas ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'preguntas'">
          <dl class="space-y-3">
            <div>
              <dt>Vendí más que la meta y me salió $0. ¿Por qué?</dt>
              <dd>Porque para la meta solo cuentan las ventas con la mitad pagada. Revisa cuánto de lo vendido ya tiene el 50 %: la pantalla lo muestra aparte ("vendido sin el 50 % pagado").</dd>
            </div>
            <div>
              <dt>¿Por qué mi compañero cobra lo mismo si vendió menos?</dt>
              <dd>En una tienda que comparte, la comisión es del equipo: se reparte igual por los días trabajados. Si tu tienda prefiere que cada uno cobre lo suyo, se cambia en Metas (no comparte).</dd>
            </div>
            <div>
              <dt>Ya pasó el 20 y mi comisión sigue pendiente.</dt>
              <dd>Le falta otra condición: que el cliente pague la mitad o que la tienda llegue a la meta. El detalle de la comisión dice cuál.</dd>
            </div>
            <div>
              <dt>El cliente pagó la mitad después del 20. ¿Pierdo la comisión?</dt>
              <dd>No. Queda lista apenas pague y se cobra en el siguiente pago. En las tiendas trimestrales, el trimestre queda fijo el día que se liquida.</dd>
            </div>
            <div>
              <dt>Se canceló una orden que ya me pagaron.</dt>
              <dd>Lo pagado no se cambia solo. Si hay que corregirlo, un supervisor deshace el pago y queda en el historial.</dd>
            </div>
            <div>
              <dt>¿Por qué me descontaron algo si el cliente pagó todo?</dt>
              <dd>Si pagó con tarjeta o Addi, se descuenta el 5,5 % de la franquicia sobre lo pagado así. O puede ser un anticipo que ya te habías llevado.</dd>
            </div>
            <div>
              <dt>¿Qué mes es una cotización que el cliente aceptó después?</dt>
              <dd>El mes en que la aceptó. Cotizada en julio y aceptada en agosto es venta de agosto, y se cobra el 20 de septiembre.</dd>
            </div>
          </dl>
        </template>
      </div>
    </section>
  </div>
</template>

<style scoped>
.ayuda b { font-weight: 600; color: rgb(31 41 55); }
.ayuda ul { list-style: disc; padding-left: 1.1rem; display: grid; gap: 0.35rem; }
.ayuda dt { font-size: 12px; font-weight: 600; color: rgb(31 41 55); }
.ayuda dd { font-size: 12px; color: rgb(107 114 128); margin-top: 1px; }
.ayuda a { color: rgb(37 99 235); font-weight: 600; cursor: pointer; }
.chip { font-size: 10px; font-weight: 600; border-radius: 9999px; padding: 2px 8px; }
.formula {
  font-size: 12px; font-weight: 600; color: rgb(31 41 55);
  border: 1px solid rgb(229 231 235); border-radius: 0.5rem; padding: 0.5rem 0.75rem; text-align: center;
}
.ejemplo {
  border-left: 3px solid rgb(34 197 94); border-radius: 0; padding: 0.25rem 0 0.25rem 0.75rem;
  font-size: 12px; display: grid; gap: 0.2rem;
}
.ej-titulo { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: rgb(22 163 74); }
.nota { font-size: 11px; color: rgb(107 114 128); }
</style>

<style>
/* Modo oscuro (sin scoped: `:global(html.dark) .x` en un bloque scoped deja
   solo `html.dark` y se pierde el resto). Los mismos tonos que main.css. */
html.dark .ayuda-comisiones .ayuda b,
html.dark .ayuda-comisiones .ayuda dt,
html.dark .ayuda-comisiones .formula { color: #e2e8f0; }
html.dark .ayuda-comisiones .ayuda dd,
html.dark .ayuda-comisiones .nota { color: #94a3b8; }
html.dark .ayuda-comisiones .formula { border-color: #334155; }
html.dark .ayuda-comisiones .ayuda a { color: #60a5fa; }
</style>
