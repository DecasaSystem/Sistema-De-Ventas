<script setup>
// Ayuda de Carga de trabajo: qué es cada número del tablero, de dónde sale y
// qué hacer con él, en palabras sencillas y con ejemplos.
//
// Pedido del dueño (2026-10-28): "un apartado de ayuda explicando cómo
// funciona el módulo, qué representa cada cosa, para que sea entendible".
//
// Las reglas salen de App\Services\CargaDeTrabajo (qué cuenta, los 28 días del
// ritmo, los 90 de la duración, las 8 semanas del flujo) y del umbral de "el
// más cargado" en CargaTrabajoView (7 días, sin despacho). Si cambia una regla
// allá, hay que cambiarla aquí.
import { ref, nextTick, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  QuestionMarkCircleIcon, ChevronDownIcon, ChevronUpIcon, ArrowLeftIcon,
  LightBulbIcon, Squares2X2Icon, ClipboardDocumentListIcon, WrenchScrewdriverIcon,
  QueueListIcon, CalendarDaysIcon, NoSymbolIcon, HandRaisedIcon, ChatBubbleLeftRightIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const route  = useRoute()

const SECCIONES = [
  { id: 'basico',    titulo: 'Lo básico: las palabras del tablero', icono: LightBulbIcon },
  { id: 'resumen',   titulo: 'Pestaña Resumen',                     icono: Squares2X2Icon },
  { id: 'ordenes',   titulo: 'Pestaña Órdenes',                     icono: ClipboardDocumentListIcon },
  { id: 'taller',    titulo: 'Pestaña Taller',                      icono: WrenchScrewdriverIcon },
  { id: 'pasos',     titulo: 'Pestaña Pasos',                       icono: QueueListIcon },
  { id: 'fechas',    titulo: 'Fechas: vencidas, esta semana…',      icono: CalendarDaysIcon },
  { id: 'fuera',     titulo: 'Lo que NO cuenta',                    icono: NoSymbolIcon },
  { id: 'usar',      titulo: 'Cómo usarlo en el día a día',         icono: HandRaisedIcon },
  { id: 'preguntas', titulo: 'Preguntas frecuentes',                icono: ChatBubbleLeftRightIcon },
]

// Lo básico abierto de entrada: es lo que hay que leer primero.
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

// Desde cada pestaña del tablero se llega con ?tema=pasos: se abre ese tema.
onMounted(() => {
  const tema = String(route.query.tema ?? '')
  if (SECCIONES.some(s => s.id === tema)) ir(tema)
})
</script>

<template>
  <div class="ayuda-carga p-4 max-w-2xl mx-auto space-y-4 pb-24">
    <div class="flex items-center gap-2">
      <button type="button" @click="router.back()" class="text-gray-400 hover:text-gray-600" aria-label="Volver">
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
        <QuestionMarkCircleIcon class="w-6 h-6 text-blue-600" />
        Cómo funciona Carga de trabajo
      </h2>
    </div>

    <!-- En 30 segundos -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">En 30 segundos</p>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Qué es</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            Una foto de <b>todo el trabajo pendiente</b>: lo que hay que entregar, lo que está en el
            taller y cuánto le espera a cada paso. Junta en una pantalla lo de Órdenes y Producción.
          </p>
        </div>
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Para qué sirve</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            Saber <b>cuánto trabajo tenemos y vamos a tener</b>, qué está atrasado, qué no ha
            arrancado y qué paso del taller está frenando todo.
          </p>
        </div>
        <div class="rounded-lg border border-gray-100 p-3">
          <p class="text-xs font-semibold text-gray-800">Qué no hace</p>
          <p class="text-[11px] text-gray-500 mt-1 leading-snug">
            <b>Solo mira</b>: no cambia ninguna orden ni ninguna pieza, y no muestra plata. Para
            actuar, toca el número y te lleva a la lista que lo explica.
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
          <component :is="s.icono" class="w-5 h-5 text-blue-600 shrink-0" />
          <span class="text-sm font-semibold text-gray-800">{{ s.titulo }}</span>
        </span>
        <ChevronUpIcon v-if="abiertas.has(s.id)" class="w-4 h-4 text-gray-400 shrink-0" />
        <ChevronDownIcon v-else class="w-4 h-4 text-gray-400 shrink-0" />
      </button>

      <div v-if="abiertas.has(s.id)" class="ayuda px-4 pb-4 space-y-3 text-[13px] text-gray-600 leading-relaxed">

        <!-- ── Lo básico ─────────────────────────────────────────────── -->
        <template v-if="s.id === 'basico'">
          <p>Palabras que salen en todo el tablero:</p>
          <dl class="space-y-2">
            <div><dt>Orden abierta</dt><dd>Una venta que todavía tiene trabajo por delante: esperando precio, en espera, en producción, lista para entregar, en camino o devuelta. Las entregadas, canceladas, cotizaciones y borradores no.</dd></div>
            <div><dt>Pieza</dt><dd>Cada producto que pasa por el taller: lo que se fabrica, se personaliza, se diseña, se retapiza, se restaura o se arregla por garantía. También lo que se fabrica para la Reserva de Fábrica, sin orden.</dd></div>
            <div><dt>Piezas y unidades</dt><dd>Una pieza puede llevar varias unidades: "6 sillas Viena" es <b>1 pieza</b> y <b>6 unidades</b>. Por eso casi siempre se muestran las dos cifras.</dd></div>
            <div><dt>Paso</dt><dd>Cada proceso que le toca a una pieza (ebanistería, tapicería, laca… y despacho de último). Los procesos los crea el taller en Producción → Procesos.</dd></div>
            <div><dt>Sin arrancar</dt><dd>La pieza ya existe pero <b>nadie le ha puesto los pasos</b>. Está esperando a que quien gestiona el taller la arranque en Producción.</dd></div>
            <div><dt>Empezada</dt><dd>Ya tiene pasos y va en alguno: en el taller o en despacho.</dd></div>
            <div><dt>En despacho</dt><dd>Terminó todo lo del taller y está en su último paso, esperando salir.</dd></div>
            <div><dt>Fecha prometida</dt><dd>La que se le dio al cliente en la orden. La pieza del taller trabaja contra su <b>fecha de compromiso</b>, que nace igual a la prometida (y si no tiene, se usa la prometida).</dd></div>
          </dl>
          <p class="nota">El selector de tienda (solo supervisores) muestra lo de una tienda. Al filtrar por tienda, lo de la Reserva de Fábrica no sale, porque no es de ninguna tienda.</p>
        </template>

        <!-- ── Resumen ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'resumen'">
          <p>Lo primero que hay que mirar. Las cuatro cifras grandes:</p>
          <dl class="space-y-2">
            <div><dt>Órdenes abiertas</dt><dd>Todas las órdenes con trabajo pendiente. Debajo: cuántas tienen algo que pasa por el taller.</dd></div>
            <div><dt>Para entregar</dt><dd>Órdenes que tienen <b>algo que se puede llevar hoy</b> (ver <a @click="ir('ordenes')">Pestaña Órdenes</a>). Debajo: cuántas están completas y cuántas ya van en el camión.</dd></div>
            <div><dt>En el taller</dt><dd>Piezas que siguen en el taller (sin arrancar + empezadas), con sus unidades.</dd></div>
            <div><dt>Sin arrancar</dt><dd>Piezas a las que nadie les ha puesto pasos. En naranja si hay alguna.</dd></div>
          </dl>

          <p><b>Para atender</b> es una lista de alertas. Solo sale lo que hay:</p>
          <ul>
            <li>Piezas que llevan <b>7 días o más</b> sin arrancar (desde que se creó la pieza).</li>
            <li>Piezas del taller que ya pasaron su fecha de compromiso.</li>
            <li>Órdenes con la entrega vencida.</li>
            <li>Piezas en un paso donde <b>nadie está apuntado</b> como responsable (despacho no cuenta).</li>
            <li>Órdenes devueltas que esperan decisión.</li>
            <li>Órdenes esperando que el taller ponga precio.</li>
          </ul>
          <p class="nota">Cada alerta se puede tocar: lleva a la lista o pestaña donde se ve el detalle.</p>

          <p><b>El paso más cargado</b>: sale cuando un proceso tiene encima <b>más de una semana de trabajo</b> al ritmo al que viene sacando. Es la señal de un cuello de botella. Despacho no compite (ver <a @click="ir('preguntas')">Preguntas frecuentes</a>).</p>

          <p><b>Lo que viene</b> reparte por fechas, con dos barras por tramo: la oscura son las <b>órdenes</b> (por fecha prometida al cliente) y la azul las <b>piezas del taller</b> (por fecha de compromiso). El número de la derecha es "órdenes · piezas". Ver <a @click="ir('fechas')">Fechas</a>.</p>

          <p><b>¿Se acumula o se despeja?</b> compara, semana por semana (de lunes a domingo, las últimas 8), las piezas que <b>entraron</b> al taller (barra amarilla) contra las que <b>salieron</b> terminadas (barra verde).</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Últimas 4 semanas: entraron 38 piezas y salieron 29.</p>
            <p>Sale: "El trabajo se está acumulando (9 de más)". Si sigue así, cada mes el taller queda más atrás aunque todos estén trabajando.</p>
          </div>
        </template>

        <!-- ── Órdenes ───────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'ordenes'">
          <p><b>Por estado.</b> Cada cuadro es un estado de la orden; al tocarlo se abre Órdenes con ese filtro:</p>
          <dl class="space-y-2">
            <div><dt>Esperando precio</dt><dd>Lleva algo personalizado en $0 y el taller tiene que cotizarlo.</dd></div>
            <div><dt>En espera</dt><dd>Sin anticipo o todavía sin arrancar.</dd></div>
            <div><dt>En producción</dt><dd>Algo de la orden se está fabricando.</dd></div>
            <div><dt>Listas para entregar</dt><dd>Todo está hecho; falta llevarlo.</dd></div>
            <div><dt>En camino</dt><dd>Ya salió en el camión.</dd></div>
            <div><dt>Devueltas</dt><dd>Volvió algo en el camión y hay que decidir qué pasa (en Producción).</dd></div>
          </dl>

          <p><b>Entrega vencida</b>: órdenes con <b>algún producto que falta entregar</b> y cuya fecha prometida ya pasó. Es la misma regla de "Retrasadas" en Órdenes.</p>

          <p><b>Órdenes con algo por fabricar</b>, separadas por tipo. Una orden puede salir en varios tipos (un sofá personalizado y una restauración), así que la suma puede dar más que el total:</p>
          <dl class="space-y-2">
            <div><dt>De catálogo, a fabricar</dt><dd>Producto normal que no había en stock y se mandó a hacer.</dd></div>
            <div><dt>Personalizados</dt><dd>Producto del catálogo con cambios (medidas, tela, acabado).</dd></div>
            <div><dt>Diseños nuevos</dt><dd>No existe en el catálogo: se diseña y se fabrica desde cero.</dd></div>
            <div><dt>Cambio de tela / arreglo</dt><dd>Mueble de stock que pasa por la fábrica antes de entregarse.</dd></div>
            <div><dt>Restauraciones</dt><dd>Muebles del cliente que se arreglan.</dd></div>
          </dl>
          <p class="nota">Las unidades de cada tipo son las que <b>faltan por entregar</b>, no las de toda la orden.</p>

          <p><b>Se pueden entregar hoy</b>: órdenes (en espera, en producción o listas) con al menos un producto que ya se puede llevar: lo de stock, apartado en la tienda, o lo del taller que ya quedó listo. Una orden que espera precio no sale aunque traiga stock.</p>
          <div class="flex flex-wrap gap-1.5">
            <span class="chip bg-emerald-100 text-emerald-700">Completa: todo lo que falta ya está listo</span>
            <span class="chip bg-blue-100 text-blue-700">Parcial: se puede llevar una parte</span>
          </div>
          <p class="nota">"4 ya llevan entrega parcial" son órdenes a las que ya se les entregó algo y todavía les falta. "1 de 2 listas" son unidades.</p>

          <p><b>Órdenes esperando al taller</b>: tienen al menos una pieza <b>sin arrancar</b>. Son las que hay que pasarle a quien gestiona Producción.</p>
          <p><b>Por tienda</b>: la misma cuenta separada por tienda (solo cuando se ven todas). "Entregar" = con algo listo para llevar, "Fabricar" = con algo en el taller, "Vencidas" = con la entrega vencida.</p>
        </template>

        <!-- ── Taller ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'taller'">
          <p>Todas las piezas que siguen en el taller, de órdenes abiertas y de la Reserva de Fábrica.</p>
          <dl class="space-y-2">
            <div><dt>Sin arrancar</dt><dd>No tienen pasos todavía.</dd></div>
            <div><dt>En proceso</dt><dd>Van en algún paso del taller.</dd></div>
            <div><dt>En despacho</dt><dd>Terminaron el taller y esperan salir.</dd></div>
          </dl>
          <p>Las etiquetas grises dicen <b>de qué son</b> las piezas: a fabricar, personalizado, diseño nuevo, cambio de tela, restauración, para Reserva o garantía (un arreglo de algo ya entregado).</p>
          <p>El selector de abajo filtra la lista: <b>Todas</b>, <b>Sin arrancar</b>, <b>Empezadas</b> o <b>Vencidas</b> (pasaron su fecha de compromiso). La lista va de lo que se vence primero a lo que no tiene fecha.</p>

          <p><b>Cómo leer una tarjeta:</b></p>
          <ul>
            <li>Arriba: producto, cuántas unidades (× 6), la tela o medida, y de qué orden y cliente es (se toca para abrir la orden). Si dice "Para la Reserva de Fábrica", no tiene cliente.</li>
            <li>A la derecha: cuánto falta para la fecha de compromiso. Rojo si ya venció, ámbar si faltan 3 días o menos.</li>
            <li>Si no ha arrancado: hace cuántos días está esperando.</li>
            <li>Si ya arrancó: el paso en que va, <b>hace cuánto</b> entró a ese paso y "paso 2 de 3".</li>
            <li>La barra: <span class="text-green-600 font-semibold">verde</span> = pasos hechos, <span class="text-blue-600 font-semibold">azul</span> = el de ahora, gris = los que faltan.</li>
            <li>Abajo: quién está apuntado en el paso (en ámbar "Nadie apuntado" si no hay nadie) y qué paso sigue.</li>
          </ul>
          <p class="nota">"Abrir en Producción" lleva al tablero de Producción, donde sí se arrancan las piezas y se cambian los estados.</p>
        </template>

        <!-- ── Pasos ─────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'pasos'">
          <p>Una tarjeta por cada proceso del taller, en el orden en que los tiene el taller (despacho al final). Responde: <b>¿cuánto tiene encima cada paso y cuánto le va a llegar?</b></p>
          <dl class="space-y-2">
            <div><dt>En proceso</dt><dd>Piezas que están <b>ahora mismo</b> en ese paso (y sus unidades). Al tocarlo se ve la lista de esas piezas.</dd></div>
            <div><dt>Le llegan</dt><dd>Piezas que ya tienen ese paso puesto en su flujo pero <b>todavía no llegan</b> a él. Es el trabajo que viene en camino.</dd></div>
            <div><dt>"… llegan apenas terminen el paso actual"</dt><dd>De las que le llegan, cuántas lo tienen como el <b>siguiente</b> paso: son las primeras en caer.</dd></div>
            <div><dt>La barra</dt><dd>Azul fuerte = en proceso, azul claro = le llegan. Es más larga mientras más carga tenga el paso comparado con los demás.</dd></div>
            <div><dt>Personas</dt><dd>Cuántos trabajadores activos tienen asignado ese proceso (en Usuarios).</dd></div>
            <div><dt>Ritmo</dt><dd>Cuántos pasos de ese proceso se cerraron por semana, contando las <b>últimas 4 semanas</b>.</dd></div>
            <div><dt>Normalmente tarda</dt><dd>Lo que tardó el paso típico en los <b>últimos 3 meses</b>, desde que empezó hasta que se cerró. Se usa la mitad de la lista (mediana): un paso que se olvidó abierto un mes no vuelve "lento" a todo el proceso.</dd></div>
            <div><dt>Llevan en el paso</dt><dd>Cuánto llevan en ese paso las piezas que están ahí ahora: el promedio y la que más lleva. Sale en <b>ámbar</b> si el promedio pasa de una vez y media lo normal.</dd></div>
            <div><dt>Para ponerse al día</dt><dd>Cuántos días de trabajo tiene encima el paso: (en proceso + le llegan) dividido por lo que saca al día. Sin ritmo no se puede estimar y lo dice. "Al día" si no tiene nada.</dd></div>
          </dl>
          <div class="formula">Días para ponerse al día = (en proceso + le llegan) ÷ (cerrados en 28 días ÷ 28)</div>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Tapicería tiene 11 piezas en proceso y le llegan 12: son 23.</p>
            <p>En las últimas 4 semanas cerró 18 pasos → 4,5 por semana → 0,64 por día.</p>
            <p>23 ÷ 0,64 ≈ <b>36 días</b> de trabajo. Como pasa de 7, sale como <b>"El más cargado"</b> (borde rojo) y en el Resumen.</p>
          </div>
          <p>Debajo, en ámbar: cuántas piezas están en ese paso <b>sin nadie apuntado</b>. Se apunta en Mis pasos o en Producción.</p>
          <p class="nota">Es una estimación con el ritmo de las últimas semanas: si se contrata a alguien o alguien sale a vacaciones, el número se ajusta solo a medida que cambia el ritmo.</p>
        </template>

        <!-- ── Fechas ────────────────────────────────────────────────── -->
        <template v-else-if="s.id === 'fechas'">
          <p>Las fechas se cuentan desde <b>hoy en hora de Colombia</b>:</p>
          <dl class="space-y-2">
            <div><dt>Vencidas</dt><dd>La fecha ya pasó.</dd></div>
            <div><dt>Próximos 7 días</dt><dd>De hoy a 6 días.</dd></div>
            <div><dt>La otra semana</dt><dd>De 7 a 13 días.</dd></div>
            <div><dt>Este mes</dt><dd>De 14 a 30 días.</dd></div>
            <div><dt>Más adelante</dt><dd>Más de 30 días.</dd></div>
            <div><dt>Sin fecha</dt><dd>Nadie le puso fecha. Conviene ponérsela: sin fecha no se puede planear.</dd></div>
          </dl>
          <p>Para una <b>orden</b>, la fecha es la del producto que falte <b>más lejano</b>: el cliente lo recibe todo cuando esté lo último. Es la misma fecha de "Por entregar" en Órdenes. Las órdenes que ya van en camino no entran en esta cuenta.</p>
          <div class="ejemplo">
            <p class="ej-titulo">Ejemplo</p>
            <p>Orden con un cojín para el 30 de octubre y un sofá para el 2 de noviembre: cae en "Próximos 7 días" por el 2 de noviembre.</p>
            <p>Pero si el 30 pasa sin entregar el cojín, la orden ya cuenta como <b>entrega vencida</b>: para eso basta un producto vencido.</p>
          </div>
        </template>

        <!-- ── Lo que NO cuenta ──────────────────────────────────────── -->
        <template v-else-if="s.id === 'fuera'">
          <ul>
            <li>Órdenes <b>entregadas</b> y <b>canceladas</b>, y sus piezas (aunque una pieza haya quedado a medias en el taller).</li>
            <li><b>Cotizaciones</b> y <b>borradores</b>: todavía no son ventas.</li>
            <li>Productos <b>devueltos para cambio</b>: ya no son de la orden.</li>
            <li>Unidades que ya se entregaron.</li>
            <li>Piezas que el taller ya terminó y están <b>listas</b>: ya no son trabajo del taller, se cuentan en <b>Para entregar</b> de las órdenes.</li>
            <li>Piezas canceladas.</li>
          </ul>
          <p class="nota">Por eso el tablero puede mostrar menos que la lista de Producción sin filtros: esa lista también trae lo entregado y lo cancelado.</p>
        </template>

        <!-- ── Cómo usarlo ───────────────────────────────────────────── -->
        <template v-else-if="s.id === 'usar'">
          <p>Una rutina sencilla para la mañana:</p>
          <ol class="pasos-lista">
            <li><b>Resumen → Para atender.</b> Resolver primero lo vencido y lo que lleva más de una semana sin arrancar.</li>
            <li><b>Órdenes → Se pueden entregar hoy.</b> Con esa lista se arma la ruta del día en Despacho. Lo parcial se puede ir llevando.</li>
            <li><b>Órdenes → Esperando al taller.</b> Pasarle esas órdenes a quien gestiona Producción para que les ponga pasos.</li>
            <li><b>Pasos.</b> Mirar el más cargado y las piezas sin nadie apuntado: ahí conviene mover gente o pedir ayuda.</li>
            <li><b>¿Se acumula o se despeja?</b> Una vez a la semana: si entra más de lo que sale varias semanas seguidas, hay que prometer fechas más largas o reforzar el taller.</li>
          </ol>
          <p class="nota">Casi todo se puede tocar: las cifras, las alertas, las órdenes y las piezas llevan a su lista o a su detalle.</p>
        </template>

        <!-- ── Preguntas frecuentes ──────────────────────────────────── -->
        <template v-else-if="s.id === 'preguntas'">
          <dl class="space-y-3">
            <div>
              <dt>¿Cada cuánto se actualiza?</dt>
              <dd>Al abrir la pantalla, al tocar el botón de actualizar (las flechas) y sola, por detrás, cada vez que alguien mueve una pieza en el taller. Arriba dice la hora de las cifras.</dd>
            </div>
            <div>
              <dt>¿Por qué Despacho tiene una cola tan grande y nunca sale como "el más cargado"?</dt>
              <dd>Porque despacho es el último paso de casi todas las piezas: todo lo que está en el taller "le llega". Esa cola es trabajo de los pasos de antes, no de despacho, así que no se marca como cuello de botella.</dd>
            </div>
            <div>
              <dt>¿Por qué un paso dice "Sin ritmo para estimar"?</dt>
              <dd>Porque en las últimas 4 semanas no cerró ningún paso de ese proceso (es nuevo o casi no se usa). Sin saber a qué ritmo saca, no se inventan días.</dd>
            </div>
            <div>
              <dt>¿Por qué los números no cuadran exactos con la lista de Producción?</dt>
              <dd>Porque el tablero deja fuera lo que ya no es trabajo (ver <a @click="ir('fuera')">Lo que NO cuenta</a>) y la lista de Producción, sin filtros, lo muestra todo.</dd>
            </div>
            <div>
              <dt>¿Por qué una orden sale en "Para entregar" si sigue en producción?</dt>
              <dd>Porque tiene algo que sí se puede llevar ya (por ejemplo un producto de stock) mientras lo demás se termina. Sale como <b>Parcial</b>.</dd>
            </div>
            <div>
              <dt>Una pieza dice "Nadie apuntado" pero alguien la está haciendo.</dt>
              <dd>Falta apuntarlo en el paso (Mis pasos o Producción → la pieza → trabajadores). Así el tablero y la hoja de vida del taller quedan bien.</dd>
            </div>
            <div>
              <dt>¿Quién puede ver esta pantalla?</dt>
              <dd>Los supervisores y quien tiene acceso a Producción. No muestra plata.</dd>
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
.ayuda .pasos-lista { list-style: decimal; padding-left: 1.1rem; display: grid; gap: 0.35rem; }
.ayuda dt { font-size: 12px; font-weight: 600; color: rgb(31 41 55); }
.ayuda dd { font-size: 12px; color: rgb(107 114 128); margin-top: 1px; }
.ayuda a { color: rgb(37 99 235); font-weight: 600; cursor: pointer; }
.chip { font-size: 10px; font-weight: 600; border-radius: 9999px; padding: 2px 8px; }
.formula {
  font-size: 12px; font-weight: 600; color: rgb(31 41 55);
  border: 1px solid rgb(229 231 235); border-radius: 0.5rem; padding: 0.5rem 0.75rem; text-align: center;
}
.ejemplo {
  border-left: 3px solid rgb(59 130 246); border-radius: 0; padding: 0.25rem 0 0.25rem 0.75rem;
  font-size: 12px; display: grid; gap: 0.2rem;
}
.ej-titulo { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: rgb(37 99 235); }
.nota { font-size: 11px; color: rgb(107 114 128); }
</style>

<style>
/* Modo oscuro (sin scoped, igual que la Ayuda de Comisiones). */
html.dark .ayuda-carga .ayuda b,
html.dark .ayuda-carga .ayuda dt,
html.dark .ayuda-carga .formula { color: #e2e8f0; }
html.dark .ayuda-carga .ayuda dd,
html.dark .ayuda-carga .nota { color: #94a3b8; }
html.dark .ayuda-carga .formula { border-color: #334155; }
html.dark .ayuda-carga .ayuda a { color: #60a5fa; }
</style>
