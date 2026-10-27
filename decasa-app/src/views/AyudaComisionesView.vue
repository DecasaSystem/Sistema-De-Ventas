<script setup>
// Ayuda de Comisiones: cómo funciona el módulo en todos los casos, en
// palabras sencillas y con ejemplos con cifras.
//
// No trae datos de nadie: son las reglas y ejemplos inventados, así que la
// puede abrir cualquiera que haya iniciado sesión (los vendedores también
// quieren entender por qué les salió lo que les salió). Las reglas salen de
// ComisionController::enriquecer(), libroDelPool(), ComisionIndependientes y
// AnticiposComision: si cambia la forma de una regla allá, hay que cambiarla aquí.
//
// Las CIFRAS (el 5 %, el ÷ 1,19, el 50 %, el día 20…) y los ejemplos salen de
// las reglas que rigen hoy (Comisiones → Reglas, Fase 3 de
// docs/plan-comisiones-personalizables.md): con una regla cambiada la ayuda
// habría explicado una cuenta que ya no es. Sin conexión, las de siempre.
import { ref, computed, nextTick, onMounted } from 'vue'
import {
  DEFECTO, cargarReglasVigentes, num, divisor, cuentaVenta, cuandoSePaga, laParte, partePagada, mediosConFranquicia,
  poolDeSiempre, cuentaPool, bonoTexto,
} from '@/utils/reglasComision'
import { useRouter } from 'vue-router'
import {
  QuestionMarkCircleIcon, ChevronDownIcon, ChevronUpIcon, ArrowLeftIcon,
  BuildingStorefrontIcon, UserIcon, UsersIcon, WrenchScrewdriverIcon, CreditCardIcon,
  CalendarDaysIcon, BanknotesIcon, ArrowsRightLeftIcon, ClockIcon, LightBulbIcon,
  ReceiptPercentIcon, ChartBarIcon, DocumentTextIcon, ExclamationTriangleIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()

// ── Las reglas de hoy ─────────────────────────────────────────────────────────
const vigentes = ref(null)
onMounted(async () => { vigentes.value = await cargarReglasVigentes() })
const r = computed(() => ({ ...DEFECTO, ...(vigentes.value?.general ?? {}) }))
// Solo le llegan a quien tiene Comisiones (quién cobra distinto no es de todos).
const excepciones = computed(() => vigentes.value?.excepciones ?? [])

const cop = (v) => '$' + Math.round(Number(v) || 0).toLocaleString('es-CO')
const pct = (clave) => `${num(r.value[clave])} %`
const cuenta = (clave) => cuentaVenta(r.value, clave, { espacio: true })
const div = computed(() => divisor(r.value.iva_divisor))
const conIva = computed(() => !!r.value.iva_en_venta)
const mitadCobro = computed(() => laParte(r.value.req_pagado_pct, { espacio: true }))
const mitadMeta = computed(() => laParte(r.value.meta_req_pagado_pct, { espacio: true }))
const pagadaMeta = computed(() => partePagada(r.value.meta_req_pagado_pct, { espacio: true }))
const pagadaCobro = computed(() => partePagada(r.value.req_pagado_pct, { espacio: true }))
const mismoPago = computed(() => Number(r.value.req_pagado_pct) === Number(r.value.meta_req_pagado_pct))
const cuando = computed(() => cuandoSePaga(r.value))
const dia = computed(() => r.value.dia_pago)
const porDias = computed(() => r.value.reparto_pool !== 'iguales')
const medios = computed(() => mediosConFranquicia(r.value))

const sinIva = (v) => conIva.value ? v / r.value.iva_divisor : v
/** "→ ÷ 1,19 = $8.403.361 → × 5 % =", o "→ × 5 % =" sin IVA de por medio. */
const pasos = (v, clave) => conIva.value
  ? `→ ÷ ${div.value} = ${cop(sinIva(v))} → × ${pct(clave)} =`
  : `→ × ${pct(clave)} =`

/**
 * El pool mensual con las reglas de hoy, renglón por renglón: la misma cuenta
 * que ComisionController::desglosePool (tramos que suman + bono encima).
 */
function poolMensual(ventas, meta) {
  const R = r.value
  if (meta <= 0 || ventas < meta) return { lineas: [], total: 0 }
  const lineas = []
  if (R.formula_pool === 'tramos') {
    const tramos = R.tramos_pool ?? []
    tramos.forEach((t, i) => {
      const desde = meta * t.desde
      const hasta = tramos[i + 1] ? meta * tramos[i + 1].desde : null
      if (ventas <= desde) return
      const base = (hasta === null ? ventas : Math.min(ventas, hasta)) - desde
      lineas.push({ texto: `${cop(base)} ${hasta === null ? `por encima del ${num(t.desde)} %` : `entre el ${num(t.desde)} % y el ${num(tramos[i + 1].desde)} %`}${conIva.value ? ` ÷ ${div.value}` : ''} × ${num(t.pct)} %`, monto: sinIva(base) * t.pct })
    })
  } else {
    lineas.push({ texto: `${cop(ventas - meta)} ${pasos(ventas - meta, 'pct_pool').replace(/ =$/, '')}`, monto: sinIva(ventas - meta) * R.pct_pool })
  }
  if (Number(R.bono_meta) > 0) lineas.push({ texto: 'Bono por llegar a la meta', monto: Number(R.bono_meta) })
  return { lineas, total: lineas.reduce((s, l) => s + l.monto, 0) }
}

/** Los ejemplos, con las cifras de hoy. Los montos de entrada son siempre los mismos. */
const ej = computed(() => {
  const R = r.value
  // Meta $40 M y $45 M vendidos: con tramos o bono, la cuenta de esa forma.
  const ejPool   = poolMensual(45_000_000, 40_000_000)
  const pool5M   = ejPool.total
  const indiv10M = sinIva(10_000_000) * R.pct_individual
  const parteVend = R.compartida_parte_vendedor
  const restBase  = R.iva_en_restauracion ? 2_000_000 / R.iva_divisor : 2_000_000
  const rest2M    = restBase * R.pct_restauracion
  const indep10M  = sinIva(10_000_000) * R.indep_pct_venta
  const bolson    = 6_000_000 * R.indep_pct_bolson
  const bolsonCU  = R.bolson_modo === 'se_reparte' ? bolson / 2 : bolson
  const almacen   = sinIva(10_000_000) * R.almacen_pct
  const franquicia = 6_000_000 * R.franquicia_pct
  const baseTarjeta = 10_000_000 - franquicia
  return {
    pool5M, poolCU: pool5M / 3, ejPool,
    ejPool50: poolMensual(50_000_000, 40_000_000),
    indiv10M,
    parteVend, compartidaVend: 10_000_000 * parteVend, compartidaComp: 10_000_000 * (1 - parteVend),
    compVendCom: sinIva(10_000_000 * parteVend) * R.pct_individual,
    compCompCom: sinIva(10_000_000 * (1 - parteVend)) * R.pct_individual,
    rest2M,
    restAutor: rest2M * R.parte_autor_restauracion, restOtro: rest2M * (1 - R.parte_autor_restauracion),
    indep10M, bolson, bolsonCU, totalHenry: indep10M + bolsonCU,
    almacen, almacenCU: almacen / 3, almacenMeta: 10_000_000 * R.almacen_fraccion_meta,
    franquicia, baseTarjeta, tarjetaCom: sinIva(baseTarjeta) * R.pct_individual,
    fv2: 10_000_000 * R.pct_individual,
    tri10M: sinIva(10_000_000) * R.pct_pool,
    triDeuda: sinIva(5_000_000) * R.pct_pool,
    triSinDeuda: sinIva(20_000_000) * R.pct_pool,
    anticipoFalta: indiv10M - 200_000,
  }
})

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
  { id: 'abonos',        titulo: 'Abonos: pagar una parte',            icono: BanknotesIcon },
  { id: 'fuera',         titulo: 'Órdenes fuera del pool',             icono: ExclamationTriangleIcon },
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
            En una tienda con meta, el equipo se reparte {{ r.formula_pool === 'tramos' ? 'un porcentaje por tramos' : `el ${pct('pct_pool')}` }} de lo que la tienda vende <b>por encima de la meta</b><template v-if="conIva"> (sin IVA)</template><template v-if="Number(r.bono_meta) > 0">, más un bono de {{ bonoTexto(r) }}</template>.
            Sin meta, cada uno cobra el {{ pct('pct_individual') }} de lo suyo.
          </p>
        </div>
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Cuándo se cobra</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            <b>{{ cuando.replace(/^el /, 'El ') }}</b> a la venta, si el cliente ya pagó <b>{{ mitadCobro }}</b> de la orden<template v-if="r.req_pagado_restauracion">
            (también en las restauraciones)</template>.
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

    <!-- Reglas distintas para una tienda o una persona: la ayuda explica la
         regla general, y sin esto alguien de esa tienda leería una cuenta que
         no es la suya. Solo le llegan a quien tiene Comisiones. -->
    <div v-if="excepciones.length" class="bg-white rounded-xl shadow-sm p-4 space-y-1.5">
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Este mes hay reglas distintas para</p>
      <p v-for="(e, i) in excepciones" :key="i" class="text-[12px] text-gray-600">
        <b class="text-gray-800">{{ e.alcance_nombre }}</b> · {{ e.regla }}: <b class="text-gray-800">{{ e.valor_texto }}</b>
      </p>
      <p class="nota">Lo de abajo es la regla general. Se cambian en Comisiones → Reglas.</p>
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
            <div><dt>Pool</dt><dd>La plata que gana el equipo de una tienda cuando pasa la meta: {{ r.formula_pool === 'tramos' ? 'un porcentaje por tramos' : `el ${pct('pct_pool')}` }} de lo vendido por encima de la meta<template v-if="conIva">, sin IVA</template><template v-if="Number(r.bono_meta) > 0">, más un bono de {{ bonoTexto(r) }}</template>.</dd></div>
            <div v-if="conIva"><dt>÷ {{ div }}</dt><dd>Los precios traen IVA. Antes de sacar el porcentaje de una <b>venta</b> se le quita el IVA (se divide entre {{ div }}). A una restauración {{ r.iva_en_restauracion ? 'también' : 'no' }} se le quita.</dd></div>
            <div v-if="mismoPago"><dt>El {{ pct('req_pagado_pct') }}</dt><dd>Una venta solo cuenta (para la meta y para cobrar) cuando el cliente ya pagó al menos {{ mitadCobro }} de la orden.</dd></div>
            <div v-else><dt>Lo pagado</dt><dd>Una venta suma a la meta cuando el cliente pagó {{ mitadMeta }} de la orden, y se cobra cuando pagó {{ mitadCobro }}.</dd></div>
            <div><dt>Mes de venta</dt><dd>El mes en que se confirmó la orden, en hora de Colombia. Una cotización es venta del mes en que el cliente la aceptó.</dd></div>
            <div><dt>Día de pago</dt><dd>{{ cuando.replace(/^el /, 'El ') }} a la venta<template v-if="r.meses_espera === 1"> (venta de julio → {{ dia }} de agosto)</template>. En tiendas trimestrales, contando desde el cierre del trimestre.</dd></div>
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
                { caso: 'Trabajo en una tienda con meta y equipo (comparten)', como: porDias ? 'Parte del pool, igual para todos (por días)' : 'Parte del pool, en partes iguales', ir: 'meta' },
                { caso: 'Mi tienda no tiene meta o no comparte, o soy supervisor de Tienda Virtual / Vía Jardines', como: `${pct('pct_individual')} de lo mío${conIva ? ', sin IVA' : ''}`, ir: 'individual' },
                { caso: 'Vendí con un compañero', como: ej.parteVend === 0.5 ? 'Cada uno sobre la mitad' : 'Cada uno sobre su parte', ir: 'compartida' },
                { caso: 'Hice una restauración', como: `${pct('pct_restauracion')} del valor${r.iva_en_restauracion ? ', sin IVA' : ', sin quitar IVA'}`, ir: 'restauracion' },
                { caso: 'Soy independiente', como: `${pct('indep_pct_venta')} de mis ventas + el bolsón de restauraciones`, ir: 'independiente' },
                { caso: 'Un independiente me pasó un cliente', como: `La tienda cobra su ${pct('almacen_pct')}`, ir: 'almacen' },
                { caso: 'El cliente pagó con tarjeta o Addi', como: `Se descuenta la franquicia (${pct('franquicia_pct')})`, ir: 'tarjeta' },
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
          <div class="formula">Pool = (ventas de la tienda − meta) {{ cuentaPool(r, { espacio: true }) }}</div>
          <p v-if="r.formula_pool === 'tramos'">Por tramos: cada tramo paga su porcentaje solo sobre lo que se vendió dentro de él. Pasar a un tramo más alto no cambia lo que ya se ganó en los de abajo.</p>
          <p v-if="Number(r.bono_meta) > 0">El mes que la tienda llega a la meta, el equipo se lleva además un <b>bono de {{ bonoTexto(r) }}</b>, que se reparte igual que el pool.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Meta $40.000.000. La tienda vendió $45.000.000 (con {{ pagadaMeta }}).</p>
            <p v-if="poolDeSiempre(r)">Por encima de la meta: $5.000.000 {{ pasos(5_000_000, 'pct_pool') }} <b>{{ cop(ej.pool5M) }}</b> de pool.</p>
            <template v-else>
              <p v-for="(l, i) in ej.ejPool.lineas" :key="i">{{ l.texto }} = {{ cop(l.monto) }}</p>
              <p>Pool: <b>{{ cop(ej.pool5M) }}</b>.<template v-if="r.formula_pool === 'tramos'"> Con $50.000.000 serían {{ cop(ej.ejPool50.total) }}.</template></p>
            </template>
            <p>Tres personas en el equipo todo el mes: <b>{{ cop(ej.poolCU) }}</b> cada una.</p>
          </div>
          <p v-if="!poolDeSiempre(r)" class="nota">Los tramos y el bono son solo de las tiendas que liquidan cada mes. En las trimestrales el pool es {{ cuenta('pct_pool') }} sobre lo que pasa de la meta.</p>
          <p><b>Todos cobran lo mismo</b> ({{ porDias ? 'según los días que estuvieron' : 'en partes iguales, sin mirar los días' }}), venda cada uno mucho, poco o nada. Quien no vendió nada ese mes también cobra su parte: sale en un renglón "parte del equipo".</p>
          <p><b>Lo pagado se mira en la tienda, no en cada persona.</b> Para la meta y para el pool solo cuentan las ventas cuyo cliente ya pagó {{ mitadMeta }}, las venda quien las venda.</p>
          <div v-if="r.meta_req_pagado_pct === 0.5" class="ejemplo">
            <p class="ej-titulo">Ejemplo real</p>
            <p>El Edén, agosto: vendió $49,5 millones, pero con el 50 % pagado solo $39,6 millones. La meta era $40 millones: <b>no llegó y el pool es $0</b>, aunque "vendió más que la meta".</p>
            <p>Si después un cliente paga la mitad y la tienda pasa la meta, el pool aparece. Si alguien ya había cobrado, solo sale <b>la diferencia</b>.</p>
          </div>
          <p>Si la tienda no llega a la meta ese mes, el pool es <b>$0</b> y no hay nada que repartir (en las mensuales no se arrastra deuda; en las trimestrales sí, ver <a @click.prevent="ir('trimestral')" href="#">Tiendas trimestrales</a>).</p>
          <p class="nota">A la meta le suma el valor que comisiona cada venta: sin lo que se llevó la franquicia de la tarjeta, y solo su parte si la venta es compartida. Las restauraciones no le suman (salvo en una tienda donde se cambió esa regla). {{ laParte(r.almacen_fraccion_meta, { espacio: true }).replace(/^./, c => c.toUpperCase()) }} de lo que comparte un independiente sí (ver <a @click.prevent="ir('almacen')" href="#">Independiente que comparte</a>).</p>
          <p class="nota">Quien vende en la tienda sin estar en su equipo (ni cubriendo a nadie) cobra el {{ pct('pct_individual') }} de lo suyo por fuera del pool. Sus ventas sí empujan la meta de la tienda.</p>
        </template>

        <!-- ── Individual ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'individual'">
          <p>Cada quien cobra sobre lo que vendió, sin pool ni reparto, cuando:</p>
          <ul>
            <li>Su tienda <b>no tiene meta</b> ese mes (por ejemplo, Tienda Virtual o Vía Jardines, donde casi todos son supervisores).</li>
            <li>Su tienda está marcada para que <b>cada quien cobre lo suyo</b> (no comparte), aunque tenga meta.</li>
            <li>Vende en una tienda con meta sin ser de su equipo ni estar cubriendo a nadie.</li>
          </ul>
          <div class="formula">Comisión = valor de la venta {{ cuenta('pct_individual') }}</div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Venta de $10.000.000 {{ pasos(10_000_000, 'pct_individual') }} <b>{{ cop(ej.indiv10M) }}</b>.</p>
          </div>
          <p>No depende de ninguna meta: queda lista {{ cuando }} si el cliente pagó {{ mitadCobro }}.</p>
          <p class="nota">Lo que decide si es individual es la tienda de la persona (la de su perfil), no la tienda que se escogió en la orden.</p>
        </template>

        <!-- ── Compartida ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'compartida'">
          <p v-if="ej.parteVend === 0.5">Cuando dos asesores hacen la venta juntos, la orden se parte <b>por la mitad</b>: cada uno comisiona sobre la mitad, con las reglas de su propia tienda.</p>
          <p v-else>Cuando dos asesores hacen la venta juntos, quien la registró comisiona sobre el <b>{{ pct('compartida_parte_vendedor') }}</b> y el compañero sobre el resto, cada uno con las reglas de su propia tienda.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p v-if="ej.parteVend === 0.5">Venta compartida de $10.000.000: a cada uno le cuentan $5.000.000.</p>
            <p v-else>Venta compartida de $10.000.000: a quien la registró le cuentan {{ cop(ej.compartidaVend) }} y al compañero {{ cop(ej.compartidaComp) }}.</p>
            <p v-if="ej.parteVend === 0.5">Si los dos cobran el {{ pct('pct_individual') }} directo: $5.000.000 {{ cuenta('pct_individual') }} = <b>{{ cop(ej.compVendCom) }}</b> cada uno.</p>
            <p v-else>Si los dos cobran el {{ pct('pct_individual') }} directo: <b>{{ cop(ej.compVendCom) }}</b> y <b>{{ cop(ej.compCompCom) }}</b>.</p>
            <p>Si uno está en una tienda con pool, su parte suma a la meta de su tienda y cobra por el pool.</p>
          </div>
          <p class="nota">Lo pagado se mide contra la orden entera, no contra la parte de cada uno: el cliente tiene que haber pagado {{ mitadCobro }} de los $10.000.000.</p>
          <p class="nota">La parte de cada uno puede ser otra en algunas tiendas o para algunas personas (Comisiones → Reglas).</p>
        </template>

        <!-- ── Restauraciones ───────────────────────────────────────────── -->
        <template v-else-if="s.id === 'restauracion'">
          <p>Una restauración se paga distinto a una venta:</p>
          <ul>
            <li v-if="r.iva_en_restauracion"><b>{{ pct('pct_restauracion') }} del valor sin IVA.</b></li>
            <li v-else><b>{{ pct('pct_restauracion') }} del valor, sin quitar el IVA</b> (es mano de obra).</li>
            <li v-if="r.req_pagado_restauracion"><b>Espera el pago, igual que una venta:</b> queda lista {{ cuando }} si el cliente ya pagó {{ mitadCobro }} de la restauración. Si todavía no, queda pendiente hasta que lo pague.</li>
            <li v-else><b>No espera el pago del cliente:</b> queda lista {{ cuando }}.</li>
            <li><b>No suma a la meta ni entra al pool.</b></li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Restauración de $2.000.000<template v-if="r.iva_en_restauracion"> ÷ {{ div }}</template> → {{ pct('pct_restauracion') }} = <b>{{ cop(ej.rest2M) }}</b>.</p>
            <p v-if="r.reparto_restauracion === 'autor_primero'">En una tienda que comparte, quien la hizo se lleva el {{ pct('parte_autor_restauracion') }} de eso ({{ cop(ej.restAutor) }}) y el resto ({{ cop(ej.restOtro) }}) se reparte entre los demás que estaban en la tienda <b>ese día</b>.</p>
            <p v-else>En una tienda que comparte, ese {{ pct('pct_restauracion') }} es del equipo que estaba en la tienda <b>ese día</b>: con dos personas, {{ cop(ej.rest2M / 2) }} cada una.</p>
            <p>Quien trabaja por su cuenta (tienda que no comparte, sin meta) cobra los {{ cop(ej.rest2M) }} completos.</p>
          </div>
          <p class="nota">Toda restauración también le suma al bolsón de los independientes (ver <a @click.prevent="ir('independiente')" href="#">Independientes</a>). Eso no le quita nada a la tienda: la tienda cobra su {{ pct('pct_restauracion') }} por su lado.</p>
          <p class="nota">En una tienda donde se encendió "Las restauraciones cuentan como ventas", la restauración suma a la meta y entra al pool como una venta más, y ya no se paga aparte.</p>
          <p class="nota">Una orden cuenta como restauración cuando todos sus productos son restauración. Si mezcla venta y restauración, va como venta.</p>
        </template>

        <!-- ── Independientes ───────────────────────────────────────────── -->
        <template v-else-if="s.id === 'independiente'">
          <p>Los independientes no tienen meta ni pool. Cobran un porcentaje fijo:</p>
          <ul>
            <li><b>Sus ventas:</b> {{ pct('indep_pct_venta') }} de lo suyo<template v-if="conIva">, sin IVA</template>, solo para quien vendió.</li>
            <li v-if="r.bolson_modo === 'se_reparte'"><b>Restauraciones (bolsón):</b> se suman todas las restauraciones del mes, de cualquier independiente<template v-if="r.bolson_incluye_almacenes"> y también las de los almacenes</template>. El {{ pct('indep_pct_bolson') }} de ese bolsón <b>se reparte en partes iguales</b> entre los independientes que entran.</li>
            <li v-else><b>Restauraciones (bolsón):</b> se suman todas las restauraciones del mes, de cualquier independiente<template v-if="r.bolson_incluye_almacenes"> y también las de los almacenes</template>. <b>Cada</b> independiente cobra el {{ pct('indep_pct_bolson') }} de ese bolsón <b>completo</b> (no la mitad).</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Henry vendió $10.000.000 → cobra <b>{{ cop(ej.indep10M) }}</b> de sus ventas.</p>
            <p v-if="r.bolson_modo === 'se_reparte'">En el mes hubo $6.000.000 en restauraciones (de todos) → bolsón al {{ pct('indep_pct_bolson') }} = {{ cop(ej.bolson) }}, entre dos: Henry cobra <b>{{ cop(ej.bolsonCU) }}</b> y el otro independiente también.</p>
            <p v-else>En el mes hubo $6.000.000 en restauraciones (de todos) → bolsón al {{ pct('indep_pct_bolson') }} = {{ cop(ej.bolson) }}. Henry cobra <b>{{ cop(ej.bolsonCU) }}</b> y el otro independiente también {{ cop(ej.bolsonCU) }}.</p>
            <p>Total de Henry: {{ cop(ej.totalHenry) }}.</p>
          </div>
          <p>Se pagan <b>el mes completo desde su tarjeta</b>, no orden por orden. Las ventas y las restauraciones esperan {{ pagadaCobro }} y el día {{ dia }}. Al bolsón solo entran las restauraciones con {{ pagadaCobro }}.</p>
          <p class="nota">Si comparte la venta con un almacén, el independiente sigue cobrando el {{ pct('indep_pct_venta') }} sobre la venta <b>entera</b>; lo del almacén va aparte (siguiente tema).</p>
          <p class="nota">A un independiente se le puede sacar del bolsón, o darle otro porcentaje, en Comisiones → Reglas.</p>
        </template>

        <!-- ── Independiente + almacén ──────────────────────────────────── -->
        <template v-else-if="s.id === 'almacen'">
          <p>Cuando un independiente comparte una orden con un almacén (la tienda le pasó el cliente o lo atendió), pasan dos cosas:</p>
          <ul>
            <li><b>El almacén cobra su propio {{ pct('almacen_pct') }}</b> sobre el valor completo<template v-if="conIva"> (a la venta se le quita el IVA; a la restauración {{ r.iva_en_restauracion ? 'también' : 'no' }})</template>. Eso se reparte entre el equipo de la tienda ese día. Nunca va por el pool.</li>
            <li>A la <b>meta del almacén le suma {{ laParte(r.almacen_fraccion_meta, { espacio: true }) }}</b> de la venta. Una restauración compartida no le suma a la meta.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Henry vende $10.000.000 compartido con Norte (equipo de 3).</p>
            <p>Henry: <b>{{ cop(ej.indep10M) }}</b>. Norte: {{ pct('almacen_pct') }} de $10.000.000<template v-if="conIva"> ÷ {{ div }}</template> = {{ cop(ej.almacen) }}, o sea <b>{{ cop(ej.almacenCU) }}</b> para cada uno de los 3.</p>
            <p>A la meta de Norte le suman {{ cop(ej.almacenMeta) }}.</p>
          </div>
        </template>

        <!-- ── Tarjeta ──────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'tarjeta'">
          <p>De cada peso que se cobra con <b>{{ medios }}</b>, la franquicia se queda el <b>{{ pct('franquicia_pct') }}</b>. Esa plata nunca llega a la caja, así que nadie comisiona sobre ella.</p>
          <div class="formula">Base = valor de la orden − (pagado con {{ medios }} × {{ pct('franquicia_pct') }})</div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo (pago mixto)</p>
            <p>Orden de $10.000.000: el cliente pagó $4.000.000 en efectivo y $6.000.000 con tarjeta.</p>
            <p>Franquicia: $6.000.000 × {{ pct('franquicia_pct') }} = {{ cop(ej.franquicia) }} → base {{ cop(ej.baseTarjeta) }}.</p>
            <p>Con el {{ pct('pct_individual') }} directo: {{ cop(ej.baseTarjeta) }} {{ cuenta('pct_individual') }} = <b>{{ cop(ej.tarjetaCom) }}</b> (en vez de {{ cop(ej.indiv10M) }}).</p>
          </div>
          <p>Solo se descuenta lo que de verdad se pagó con tarjeta. Si el cliente todavía no ha pagado con tarjeta, no se descuenta nada; el día que lo haga, la comisión se ajusta sola.</p>
          <p class="nota">Esto también baja lo que la venta le suma a la meta de la tienda. Y aplica igual a independientes y a tiendas.</p>
          <p class="nota">Aparte de la comisión: pagar con tarjeta, Addi u "otro" hace perder el descuento condicionado de la orden (ese descuento solo vale en efectivo o transferencia). Eso cambia el total de la orden, y con él la comisión.</p>
        </template>

        <!-- ── FV2 ──────────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'fv2'">
          <p>Las FV2 (órdenes con descuento especial) llevan su propia numeración, pero comisionan con las mismas reglas que cualquier venta.</p>
          <p>Si una FV2 está marcada <b>"sin restar IVA"</b> y quien la vendió cobra el {{ pct('pct_individual') }} directo, el porcentaje se saca del valor <b>entero</b>:</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>FV2 de $10.000.000 sin restar IVA → {{ pct('pct_individual') }} = <b>{{ cop(ej.fv2) }}</b> (en vez de {{ cop(ej.indiv10M) }}).</p>
          </div>
          <p class="nota">La marca solo le sirve a quien vendió la orden y solo en el porcentaje directo. Si la venta va por el pool de una tienda, se mezcla con las demás y no cambia nada. Al covendedor o al almacén que ayudó se les paga con la regla de siempre.</p>
        </template>

        <!-- ── Trimestral ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'trimestral'">
          <p>Algunas tiendas (por ejemplo, Unicentro Pereira) no se miden cada mes sino cada <b>trimestre</b>: ene–mar, abr–jun, jul–sep, oct–dic. Se marca en Metas → "Se liquida".</p>
          <ul>
            <li>La meta es la misma cada mes y hay que cumplir <b>los 3 meses</b>.</li>
            <li>Lo que le sobra a un mes paga lo que le falta a otro del mismo trimestre.</li>
            <li v-if="r.arrastra_deuda_trimestre">Si el trimestre entero no llega, no comisiona y <b>la deuda pasa al trimestre siguiente</b>.</li>
            <li v-else>Si el trimestre entero no llega, no comisiona. <b>La deuda no pasa</b>: cada trimestre arranca de cero.</li>
            <li>Se cobra {{ cuando }} al cierre<template v-if="r.meses_espera === 1">: lo de jul–sep, el {{ dia }} de octubre</template>. Solo cuentan las ventas hechas dentro del trimestre; las de octubre son del trimestre nuevo.</li>
            <li>Si el trimestre <b>llegó a la meta</b>, hasta el día en que se paga siguen contando los abonos que completen {{ mitadMeta }} de una venta del trimestre, igual que en las tiendas mensuales. En cuanto se le paga a alguien, queda fijo.</li>
            <li>Si <b>no llegó a la meta</b>, el día de pago queda fijo<template v-if="r.arrastra_deuda_trimestre">: esa deuda pasa tal cual al trimestre siguiente</template>.</li>
            <li>El pool del trimestre se reparte {{ porDias ? 'por los días que estuvo cada quien en los 3 meses' : 'en partes iguales entre el equipo' }}.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo (meta $40 millones)</p>
            <p>Julio $60 M (sobran 20), agosto $20 M (faltan 20, los paga julio), septiembre $50 M (sobran 10).</p>
            <p>Comisiona lo que sobra al final: $10.000.000 {{ cuenta('pct_pool') }} = <b>{{ cop(ej.tri10M) }}</b>.</p>
          </div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo con deuda</p>
            <template v-if="r.arrastra_deuda_trimestre">
              <p>Jul–sep: $105 M contra $120 M → faltan $15 M → no comisiona; pasan $15 M de deuda.</p>
              <p>Oct–dic: $140 M contra $120 M → sobran $20 M − $15 M de deuda = $5 M → <b>{{ cop(ej.triDeuda) }}</b>.</p>
            </template>
            <template v-else>
              <p>Jul–sep: $105 M contra $120 M → faltan $15 M → no comisiona. La deuda no pasa.</p>
              <p>Oct–dic: $140 M contra $120 M → sobran $20 M → <b>{{ cop(ej.triSinDeuda) }}</b>.</p>
            </template>
          </div>
          <p class="nota">Solo cuentan las ventas con {{ pagadaMeta }}. Los anticipos de los 3 meses se descuentan juntos al pagar el trimestre.</p>
        </template>

        <!-- ── Equipo ───────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'equipo'">
          <p>Entre quiénes se reparte lo de una tienda lo dice el <b>equipo</b> de cada tienda, en Comisiones → Metas. Se arrastra de mes a mes hasta que alguien lo cambie. Tener la tienda como sede en el perfil no mete a nadie en el reparto.</p>
          <p v-if="porDias"><b>Reemplazos:</b> cuando alguien se va unos días (vacaciones, incapacidad) y otro lo cubre, el que llega ocupa su puesto. El pool se reparte por días: el que cubre pesa los días que cubrió y el cubierto pierde esos mismos días.</p>
          <p v-else><b>Reemplazos:</b> cuando alguien se va unos días (vacaciones, incapacidad) y otro lo cubre, el que llega entra al reparto. El pool va en partes iguales: cada uno de los que estuvieron ese mes se lleva lo mismo, sin mirar los días.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Pool de {{ cop(ej.pool5M) }} en Norte. Equipo: Marta, NN y Paola. Manuela cubre a Paola del 16 al 30 de septiembre.</p>
            <template v-if="porDias">
              <p>Días: Marta 30, NN 30, Paola 15, Manuela 15 (total 90).</p>
              <p>Marta y NN: <b>{{ cop(ej.pool5M * 30 / 90) }}</b> cada una. Paola y Manuela: <b>{{ cop(ej.pool5M * 15 / 90) }}</b> cada una.</p>
            </template>
            <p v-else>Son 4 en el mes: <b>{{ cop(ej.pool5M / 4) }}</b> cada una.</p>
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
                { caso: 'Venta en tienda con meta (pool)', req: [`El cliente pagó ${mitadCobro}`, `Llegó el ${dia}`, 'La tienda llegó a la meta'] },
                { caso: 'Parte del equipo (no vendió)', req: [`Llegó el ${dia}`, 'La tienda llegó a la meta'] },
                { caso: `Venta al ${pct('pct_individual')} directo`, req: [`El cliente pagó ${mitadCobro}`, `Llegó el ${dia}`] },
                { caso: 'Restauración', req: r.req_pagado_restauracion ? [`El cliente pagó ${mitadCobro}`, `Llegó el ${dia}`] : [`Llegó el ${dia}`] },
                { caso: 'Venta de independiente', req: [`El cliente pagó ${mitadCobro}`, `Llegó el ${dia}`] },
                { caso: 'Tienda trimestral', req: [`El cliente pagó ${mitadCobro}`, `Llegó el ${dia} después del cierre`, r.arrastra_deuda_trimestre ? 'El trimestre cubrió la meta y la deuda' : 'El trimestre cubrió la meta'] },
              ]" :key="c.caso" class="rounded-lg border border-gray-100 px-3 py-2">
              <p class="text-xs font-semibold text-gray-800">{{ c.caso }}</p>
              <div class="flex flex-wrap gap-1 mt-1">
                <span v-for="r in c.req" :key="r" class="chip bg-gray-100 text-gray-600">{{ r }}</span>
              </div>
            </div>
          </div>
          <p class="nota">Estas tres condiciones del pool (el pago del cliente, el día de pago y la meta) son reglas del negocio y no cambian.</p>
        </template>

        <!-- ── Pago ─────────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'pago'">
          <ul>
            <li>El <b>{{ dia }}</b> llega un aviso a los supervisores con acceso a comisiones: cuántas personas, el total, lo que se descuenta de anticipos y lo que hay que entregar.</li>
            <li>En <b>Comisiones → Listas</b> sale una tarjeta por persona con todo lo que tiene listo. Se paga <b>todo junto por persona</b>, no orden por orden.</li>
            <li>Al pagar, el sistema le descuenta los anticipos que deba y muestra un <b>recibo</b>: comisión, anticipo y lo que se entrega.</li>
            <li>Un pago marcado por error se puede <b>deshacer</b> desde la comisión pagada (pide motivo). Lo descontado de anticipos vuelve a deberse.</li>
            <li>Todo queda en el <b>historial</b>: quién pagó, quién deshizo, quién cambió metas, equipos o reemplazos.</li>
          </ul>
          <p class="nota"><b>Recalcular</b> pone todo al día en el momento. El sistema lo hace solo cada mañana a las 6:30.</p>
        </template>

        <!-- ── Atrasados ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'atrasados'">
          <p>Si un día de pago no se pagó, el mes siguiente se juntan dos meses. Listas los muestra juntos y separados:</p>
          <ul>
            <li>Arriba sale un aviso rojo con cuánto quedó sin pagar y desde qué fecha.</li>
            <li>Cada persona tiene un <b>bloque por mes</b>: "Atrasada · tocaba el {{ dia }} oct" o "Toca el {{ dia }} nov".</li>
            <li>Un botón paga todo, <b>siempre del mes más viejo al más nuevo</b>, y deja un recibo por mes.</li>
            <li>Si se quiere pagar <b>un mes y después el otro</b>, el botón <b>"Pagar solo (el mes más viejo)"</b> paga únicamente ese mes; lo demás sigue en Listas.</li>
            <li>No se puede pagar un mes si hay uno más viejo listo sin pagar.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">¿Por qué en orden?</p>
            <p>Los anticipos se descuentan "hasta el mes que se paga". Con $200.000 de anticipo al mes: pagando septiembre y luego octubre, cada mes descuenta su anticipo. Pagando octubre primero, a octubre se le descontaban los dos y lo entregado ese día cambiaba.</p>
          </div>
          <p class="nota">El aviso del día de pago también dice lo que quedó sin pagar de meses anteriores.</p>
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

        <!-- ── Abonos ───────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'abonos'">
          <p>A veces, cuando ya pasó el día de pago, a alguien se le da <b>una parte</b> de lo que tiene listo y el resto después. No es todos los meses: se anota el día que se da.</p>
          <ul>
            <li>En <b>Comisiones → Listas</b>, en la tarjeta de la persona, pulsa <b>Registrar abono</b> (solo supervisores).</li>
            <li>Escoge de qué mes es (si tiene varios), escribe cuánto se le dio y, si quieres, una nota.</li>
            <li>Listas sigue mostrando la comisión completa y, aparte, "abono ya dado". El botón de pagar dice <b>lo que falta por entregar</b>.</li>
            <li>Cuando se le paga el resto, el recibo muestra la comisión completa menos anticipos y abonos.</li>
            <li>No puede ser más de lo que falta por entregarle de ese mes.</li>
            <li>Si se anotó por error, se quita en <b>Metas → Anticipos de comisión</b> (botón Quitar), mientras ese mes no se haya pagado.</li>
          </ul>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Agosto: comisión de {{ cop(ej.indiv10M) }} y anticipo de $200.000 → faltan {{ cop(ej.anticipoFalta) }}. Se le dan $100.000 de abono → en Listas quedan <b>{{ cop(ej.anticipoFalta - 100_000) }}</b> por entregar. Al pagar: {{ cop(ej.indiv10M) }} − $200.000 − $100.000 = {{ cop(ej.anticipoFalta - 100_000) }}.</p>
          </div>
          <p class="nota">El abono no cambia cuánto gana nadie: la comisión del mes, el pool de la tienda y la parte de cada compañero se calculan igual. Solo cambia lo que se entrega el día del pago. En Finanzas cuenta como plata que salió el día en que se dio.</p>
        </template>

        <!-- ── Fuera del pool ───────────────────────────────────────────── -->
        <template v-else-if="s.id === 'fuera'">
          <p>A veces una venta hecha en la tienda no debe armar el pool del equipo: por ejemplo, la vendió alguien de fuera del equipo y ya se le pagó aparte, o es una FV2 de la gerencia.</p>
          <ul>
            <li>En el <b>detalle de la orden</b>, en el recuadro <b>Comisión</b>, un supervisor con acceso a comisiones pulsa <b>No sumar al pool</b> y escribe el motivo.</li>
            <li>Esa orden <b>no cuenta para la meta</b>, <b>no entra al pool</b> y <b>no paga comisión</b> a nadie (ni a quien la vendió, ni al reparto del equipo).</li>
            <li>Sigue siendo una venta para todo lo demás: reportes, caja, cartera, inventario y entrega.</li>
            <li>Como el pool es de toda la tienda, cambia lo de todos los del mes: el sistema recalcula solo en unos minutos.</li>
            <li>Se puede deshacer con <b>Volver a sumarla al pool</b>. Si alguna comisión de esa orden ya se pagó, no se puede cambiar: primero se deshace ese pago.</li>
            <li>Queda en el historial de Comisiones quién lo hizo y por qué.</li>
          </ul>
          <p class="nota">Es por orden, no una regla de la tienda: las demás ventas y las demás tiendas se calculan igual que siempre.</p>
        </template>

        <!-- ── Preguntas ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'preguntas'">
          <dl class="space-y-3">
            <div>
              <dt>Vendí más que la meta y me salió $0. ¿Por qué?</dt>
              <dd>Porque para la meta solo cuentan las ventas con {{ pagadaMeta }}. Revisa cuánto de lo vendido ya lo tiene: la pantalla lo muestra aparte ("vendido sin el {{ pct('meta_req_pagado_pct') }} pagado").</dd>
            </div>
            <div>
              <dt>¿Por qué mi compañero cobra lo mismo si vendió menos?</dt>
              <dd>En una tienda que comparte, la comisión es del equipo: se reparte igual {{ porDias ? 'por los días trabajados' : 'entre todos' }}. Si tu tienda prefiere que cada uno cobre lo suyo, se cambia en Metas (no comparte).</dd>
            </div>
            <div>
              <dt>Ya pasó el {{ dia }} y mi comisión sigue pendiente.</dt>
              <dd>Le falta otra condición: que el cliente pague {{ mitadCobro }} o que la tienda llegue a la meta. El detalle de la comisión dice cuál.</dd>
            </div>
            <div>
              <dt>El cliente pagó {{ mitadCobro }} después del {{ dia }}. ¿Pierdo la comisión?</dt>
              <dd>No. Queda lista apenas pague y se cobra en el siguiente pago. En las tiendas trimestrales, el trimestre queda fijo el día que se liquida.</dd>
            </div>
            <div>
              <dt>Se canceló una orden que ya me pagaron.</dt>
              <dd>Lo pagado no se cambia solo. Si hay que corregirlo, un supervisor deshace el pago y queda en el historial.</dd>
            </div>
            <div>
              <dt>¿Por qué me descontaron algo si el cliente pagó todo?</dt>
              <dd>Si pagó con {{ medios }}, se descuenta el {{ pct('franquicia_pct') }} de la franquicia sobre lo pagado así. O puede ser un anticipo que ya te habías llevado.</dd>
            </div>
            <div>
              <dt>¿Qué mes es una cotización que el cliente aceptó después?</dt>
              <dd>El mes en que la aceptó. Cotizada en julio y aceptada en agosto es venta de agosto<template v-if="r.meses_espera === 1">, y se cobra el {{ dia }} de septiembre</template>.</dd>
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
