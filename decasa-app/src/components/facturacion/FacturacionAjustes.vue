<script setup>
/**
 * Facturación electrónica → Ajustes. Todo lo que cambia cómo se factura, sin
 * tocar código (dueño, 2026-11-09). Lo guarda ReglasFacturacion en el
 * servidor; lo cambia quien factura (supervisor o con la función prendida).
 *
 * Diseño (2026-11-13, "los select e inputs están feos"): secciones con su
 * ícono, interruptores como los de Gestión → Ajustes, opciones en tarjetas,
 * números con su unidad y una tarjeta plegable por tienda que dice qué tiene
 * escogido. Los datos y el guardado son los mismos de antes.
 */
import { ref, computed, onMounted } from 'vue'
import AppSpinner from '@/components/common/AppSpinner.vue'
import AjusteSeccion from './AjusteSeccion.vue'
import AjusteInterruptor from './AjusteInterruptor.vue'
import { useToast } from '@/composables/useToast'
import { reglasFE, guardarReglasFE, catalogosAlegra, itemsAlegra, contactosAlegra } from '@/api/facturacionElectronica'
import {
  PowerIcon, DocumentTextIcon, CalculatorIcon, UserIcon, LinkIcon, BuildingStorefrontIcon,
  CreditCardIcon, MagnifyingGlassIcon, ChevronDownIcon, CheckIcon, ExclamationTriangleIcon, ArrowPathIcon,
} from '@heroicons/vue/24/outline'

const emit  = defineEmits(['guardado'])
const toast = useToast()

const cargando  = ref(true)
const guardando = ref(false)
const r         = ref(null)
const meta      = ref({ metodos: [], medios_dian: {}, medios_pago: [], tiendas: [], conectado: false })
const cat       = ref(null)
const errorCat  = ref('')
const cargandoCat = ref(false)
const items     = ref([])
const contactos = ref([])
const buscarItem = ref('')
const buscarContacto = ref('')
const buscandoItem = ref(false)
const buscandoContacto = ref(false)
const nombreItem = ref('')
const nombreContacto = ref('')
const tiendaAbierta = ref(null)

// Clases de los campos: un solo lugar para que todos se vean iguales.
const CAMPO = 'w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:opacity-50'
const CAMPO_CHICO = 'w-full border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500'
const ETIQUETA = 'block text-xs font-semibold text-gray-600 mb-1'
const AYUDA = 'text-xs text-gray-400 mt-1'

const METODOS = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta', addi: 'Addi', otro: 'Otro' }
const MEDIOS_PAGO = { cash: 'Efectivo', transfer: 'Transferencia', deposit: 'Consignación', check: 'Cheque', 'credit-card': 'Tarjeta crédito', 'debit-card': 'Tarjeta débito' }

const MOMENTOS = [
  { valor: 'al_entregar',  titulo: 'Cuando la orden se entrega completa', detalle: 'Una factura con todo, el mismo día de la entrega.' },
  { valor: 'al_confirmar', titulo: 'Apenas entra el primer pago',         detalle: 'Una factura con todo, al principio.' },
  { valor: 'por_pago',     titulo: 'Una factura por cada pago',           detalle: 'Anticipo, abonos y saldo, cada uno por lo que pagó.' },
]

const numeraciones = computed(() => (cat.value?.numeraciones ?? []).filter(n => n.activa !== false))

// Cómo se ve cada resolución en la lista: con su estado, para no escoger una
// vencida (2026-11-11: FEU y FP PEREIRA estaban vencidas y salían como las demás).
const ESTADO_NUM = { vencida: 'VENCIDA', agotada: 'SIN NÚMEROS', inactiva: 'inactiva', por_vencer: 'vence pronto', pocos: 'quedan pocos' }
function fechaCorta(f) {
  return f ? new Date(f + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}
function textoNum(n) {
  const partes = [n.nombre + (n.prefijo && n.prefijo !== n.nombre ? ` (${n.prefijo})` : '')]
  if (n.vence) partes.push(`hasta ${fechaCorta(n.vence)}`)
  if (n.quedan != null) partes.push(`quedan ${n.quedan}`)
  if (ESTADO_NUM[n.estado]) partes.push(ESTADO_NUM[n.estado])
  return partes.join(' · ')
}
/** El aviso de la numeración escogida, si no está bien. */
function avisoNum(id) {
  const n = id ? numeraciones.value.find(x => x.id === id) : null
  if (!n || n.estado === 'vigente') return null
  return { texto: n.mensaje, grave: !n.sirve }
}
const impuestosIva = computed(() => (cat.value?.impuestos ?? []))
/** El porcentaje del impuesto escogido si no es el del IVA con que se calculan los precios. */
const ivaNoCuadra = computed(() => {
  const t = impuestosIva.value.find(x => x.id === r.value?.impuesto_iva_id)
  if (!t || t.porcentaje == null) return null
  return Math.abs(Number(t.porcentaje) - Number(r.value.iva_pct)) > 0.001 ? Number(t.porcentaje) : null
})

function nombreDe(lista, id) {
  return id ? ((cat.value?.[lista] ?? []).find(x => x.id === id)?.nombre ?? `id ${id}`) : null
}
/** Lo que tiene escogido una tienda, en una línea: para verlo sin abrirla. */
function resumenTienda(t) {
  const id = String(t.id)
  const partes = []
  const b = nombreDe('bodegas', r.value.bodegas[id])
  const c = nombreDe('centros_costo', r.value.centros_costo[id])
  if (b) partes.push(b)
  if (c) partes.push(c)
  const n = r.value.numeraciones[id] ? numeraciones.value.find(x => x.id === r.value.numeraciones[id]) : null
  if (n) partes.push(n.prefijo || n.nombre)
  const cuentas = Object.values(r.value.cuentas[id] ?? {}).filter(Boolean).length
  if (cuentas) partes.push(cuentas === 1 ? '1 caja propia' : `${cuentas} cajas propias`)
  return partes.length ? partes.join(' · ') : 'Usa lo general'
}
function tiendaConAviso(t) {
  return !!avisoNum(r.value.numeraciones[String(t.id)])?.grave
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await reglasFE()
    meta.value = data
    r.value = normalizar(data.reglas)
    if (data.conectado) cargarCatalogos()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron cargar los ajustes')
  } finally {
    cargando.value = false
  }
}

async function cargarCatalogos() {
  cargandoCat.value = true
  errorCat.value = ''
  try {
    const { data } = await catalogosAlegra()
    cat.value = data
  } catch (e) {
    errorCat.value = e.response?.data?.message ?? 'No se pudo leer Alegra.'
  } finally {
    cargandoCat.value = false
  }
}

async function buscarItems() {
  buscandoItem.value = true
  try { items.value = (await itemsAlegra(buscarItem.value)).data.data } catch (e) { toast.error(e.response?.data?.message ?? 'Alegra no respondió') }
  finally { buscandoItem.value = false }
}
async function buscarContactos() {
  buscandoContacto.value = true
  try { contactos.value = (await contactosAlegra(buscarContacto.value)).data.data } catch (e) { toast.error(e.response?.data?.message ?? 'Alegra no respondió') }
  finally { buscandoContacto.value = false }
}
function escogerItem(i) {
  r.value.item_producto_id = i.id
  nombreItem.value = i.nombre
  items.value = []
}
function escogerContacto(c) {
  r.value.consumidor_final_id = c.id
  nombreContacto.value = c.nombre
  contactos.value = []
}

/** Los mapas siempre completos: la pantalla escribe en ellos sin crear nada al pintar. */
function normalizar(reglas) {
  const x = JSON.parse(JSON.stringify(reglas))
  if (!x.numeraciones || Array.isArray(x.numeraciones)) x.numeraciones = {}
  if (!x.cuentas || Array.isArray(x.cuentas)) x.cuentas = {}
  if (!x.bodegas || Array.isArray(x.bodegas)) x.bodegas = {}
  if (!x.centros_costo || Array.isArray(x.centros_costo)) x.centros_costo = {}
  for (const t of ['*', ...meta.value.tiendas.map(t => String(t.id))]) x.cuentas[t] ??= {}
  return x
}

async function guardar() {
  if (r.value.activo && r.value.modo === 'emitir'
    && !confirm('Con "Mandar a la DIAN" cada factura que se haga es legal y no se puede borrar. ¿Guardar así?')) return
  guardando.value = true
  try {
    // Lo vacío no se manda: una tienda sin numeración propia usa la general.
    const limpio = JSON.parse(JSON.stringify(r.value))
    limpio.numeraciones = Object.fromEntries(Object.entries(limpio.numeraciones).filter(([, v]) => v))
    limpio.bodegas = Object.fromEntries(Object.entries(limpio.bodegas).filter(([, v]) => v))
    limpio.centros_costo = Object.fromEntries(Object.entries(limpio.centros_costo).filter(([, v]) => v))
    limpio.cuentas = Object.fromEntries(Object.entries(limpio.cuentas)
      .map(([t, m]) => [t, Object.fromEntries(Object.entries(m).filter(([, v]) => v))])
      .filter(([, m]) => Object.keys(m).length))
    const { data } = await guardarReglasFE(limpio)
    r.value = normalizar(data.reglas)
    toast.success(data.message)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <AppSpinner v-if="cargando" />
  <div v-else-if="r" class="space-y-4 pb-2">

    <!-- ── Encendido ─────────────────────────────────────────────────── -->
    <AjusteSeccion :icono="PowerIcon" titulo="Encendido" detalle="Si se factura y qué tan en serio.">
      <template #extra>
        <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold shrink-0', r.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">
          {{ r.activo ? 'Prendida' : 'Apagada' }}
        </span>
      </template>

      <AjusteInterruptor v-model="r.activo" titulo="Facturación electrónica prendida"
        ayuda="Apagada, no sale nada en Por facturar." />

      <div>
        <p :class="ETIQUETA">Qué pasa al tocar "Facturar"</p>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" @click="r.modo = 'borrador'"
            :class="['text-left rounded-xl border p-3 transition-colors', r.modo === 'borrador' ? 'border-amber-500 ring-1 ring-amber-500' : 'border-gray-200 hover:border-gray-300']">
            <span class="flex items-center justify-between gap-2">
              <span :class="['text-sm font-semibold', r.modo === 'borrador' ? 'text-amber-700' : 'text-gray-700']">Solo borrador</span>
              <CheckIcon v-if="r.modo === 'borrador'" class="w-4 h-4 text-amber-600" />
            </span>
            <span class="block text-xs text-gray-400 mt-0.5">De prueba: no es legal y se puede borrar.</span>
          </button>
          <button type="button" @click="r.modo = 'emitir'"
            :class="['text-left rounded-xl border p-3 transition-colors', r.modo === 'emitir' ? 'border-blue-600 ring-1 ring-blue-600' : 'border-gray-200 hover:border-gray-300']">
            <span class="flex items-center justify-between gap-2">
              <span :class="['text-sm font-semibold', r.modo === 'emitir' ? 'text-blue-700' : 'text-gray-700']">Mandar a la DIAN</span>
              <CheckIcon v-if="r.modo === 'emitir'" class="w-4 h-4 text-blue-600" />
            </span>
            <span class="block text-xs text-gray-400 mt-0.5">Factura legal. No se puede borrar.</span>
          </button>
        </div>
        <p :class="AYUDA">Empiecen en borrador unas semanas y compárenlo con lo que hacen a mano.</p>
      </div>

      <div>
        <label :class="ETIQUETA" for="fe-desde">Facturar lo de esta fecha en adelante</label>
        <input id="fe-desde" v-model="r.desde" type="date" :class="CAMPO" />
        <p :class="AYUDA">Lo de antes ya se facturó a mano. Si lo prenden sin fecha, se pone hoy.</p>
      </div>
    </AjusteSeccion>

    <!-- ── Qué y cuándo ──────────────────────────────────────────────── -->
    <AjusteSeccion :icono="DocumentTextIcon" titulo="Qué se factura y cuándo">
      <div class="space-y-2" role="radiogroup" aria-label="Cuándo se factura">
        <button v-for="m in MOMENTOS" :key="m.valor" type="button" role="radio" :aria-checked="r.momento === m.valor"
          @click="r.momento = m.valor"
          :class="['w-full flex items-start gap-3 text-left rounded-xl border p-3 transition-colors',
            r.momento === m.valor ? 'border-blue-600 ring-1 ring-blue-600' : 'border-gray-200 hover:border-gray-300']">
          <span :class="['mt-0.5 w-4 h-4 rounded-full border-2 shrink-0 flex items-center justify-center',
            r.momento === m.valor ? 'border-blue-600' : 'border-gray-300']">
            <span v-if="r.momento === m.valor" class="w-2 h-2 rounded-full bg-blue-600" />
          </span>
          <span class="min-w-0">
            <span :class="['block text-sm font-semibold', r.momento === m.valor ? 'text-blue-700' : 'text-gray-700']">{{ m.titulo }}</span>
            <span class="block text-xs text-gray-400 mt-0.5">{{ m.detalle }}</span>
          </span>
        </button>
      </div>
      <p v-if="r.momento === 'por_pago'" class="flex gap-1.5 text-xs text-amber-700">
        <ExclamationTriangleIcon class="w-4 h-4 shrink-0" />
        Confírmenlo con el contador: facturar cada anticipo es válido pero cambia cómo se lleva el IVA de cada pago.
      </p>

      <div class="divide-y divide-gray-100">
        <AjusteInterruptor v-model="r.facturar_fv2" titulo="Facturar las órdenes FV2" />
        <AjusteInterruptor v-model="r.facturar_restauraciones" titulo="Facturar las restauraciones" />
        <AjusteInterruptor v-model="r.facturar_independientes" titulo="Facturar las ventas de independientes" />
        <AjusteInterruptor v-model="r.requiere_revision" titulo="El facturador revisa cada una antes de que salga"
          ayuda="Apagado, lo que no tenga ninguna advertencia sale solo cada 5 minutos." />
      </div>
    </AjusteSeccion>

    <!-- ── Valores ───────────────────────────────────────────────────── -->
    <AjusteSeccion :icono="CalculatorIcon" titulo="Valores y avisos" detalle="Cómo se calcula la factura y cuándo avisa el sistema.">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label :class="ETIQUETA" for="fe-iva">IVA</label>
          <div class="relative">
            <input id="fe-iva" v-model.number="r.iva_pct" type="number" min="0" max="100" inputmode="decimal" :class="[CAMPO, 'pr-8']" />
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">%</span>
          </div>
        </div>
        <div>
          <label :class="ETIQUETA" for="fe-dec">Decimales sin IVA</label>
          <input id="fe-dec" v-model.number="r.decimales" type="number" min="0" max="6" inputmode="numeric" :class="CAMPO" />
        </div>
      </div>

      <div>
        <label :class="ETIQUETA" for="fe-credito">Plazo si queda saldo</label>
        <div class="flex items-center gap-2">
          <div class="flex bg-gray-100 rounded-xl p-1 gap-1 flex-1">
            <button v-for="d in [1, 15, 30]" :key="d" type="button" @click="r.dias_credito = d"
              :class="['flex-1 py-1.5 text-xs font-semibold rounded-lg', r.dias_credito === d ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">
              {{ d }} {{ d === 1 ? 'día' : 'días' }}
            </button>
          </div>
          <div class="relative w-24 shrink-0">
            <input id="fe-credito" v-model.number="r.dias_credito" type="number" min="0" max="365" inputmode="numeric" :class="[CAMPO, 'pr-10']" aria-label="Otro plazo en días" />
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">días</span>
          </div>
        </div>
      </div>

      <div class="rounded-xl border border-gray-100 p-3 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Avisos</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label :class="ETIQUETA" for="fe-precio">Precio lejos de la lista</label>
            <div class="relative">
              <input id="fe-precio" v-model.number="r.alerta_precio_pct" type="number" min="0" max="100" inputmode="decimal" :class="[CAMPO, 'pr-8']" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">%</span>
            </div>
          </div>
          <div>
            <label :class="ETIQUETA" for="fe-horas">Plata cambiada hace</label>
            <div class="relative">
              <input id="fe-horas" v-model.number="r.horas_cambio_reciente" type="number" min="0" max="720" inputmode="numeric" :class="[CAMPO, 'pr-14']" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">horas</span>
            </div>
          </div>
          <div>
            <label :class="ETIQUETA" for="fe-numdias">Resolución vence en</label>
            <div class="relative">
              <input id="fe-numdias" v-model.number="r.aviso_numeracion_dias" type="number" min="0" max="365" inputmode="numeric" :class="[CAMPO, 'pr-12']" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">días</span>
            </div>
          </div>
          <div>
            <label :class="ETIQUETA" for="fe-numnum">…o le quedan</label>
            <div class="relative">
              <input id="fe-numnum" v-model.number="r.aviso_numeracion_numeros" type="number" min="0" inputmode="numeric" :class="[CAMPO, 'pr-[4.5rem]']" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">números</span>
            </div>
          </div>
        </div>
        <div>
          <label :class="ETIQUETA" for="fe-hora">Avisar lo entregado sin facturar a las</label>
          <input id="fe-hora" v-model="r.aviso_sin_facturar_hora" type="time" :class="CAMPO" />
          <p :class="AYUDA">Se factura el mismo día de la entrega. Una vez al día; vacío = no avisar.</p>
        </div>
      </div>

      <div class="divide-y divide-gray-100">
        <AjusteInterruptor v-model="r.iva_en_restauraciones" titulo="Las restauraciones llevan IVA" />
        <AjusteInterruptor v-model="r.registrar_pagos" titulo="Registrar en Alegra los pagos del cliente"
          aviso="Déjelo apagado: en Decasa cada pago se digita en Alegra como recibo (anticipo de clientes) al entrar y se cruza al facturar. Prenderlo registraría cada pago otra vez y la caja de Alegra quedaría doble." />
        <div v-if="r.registrar_pagos" class="py-3">
          <p :class="ETIQUETA">Fecha con que va cada pago a Alegra</p>
          <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
            <button type="button" @click="r.fecha_pagos = 'factura'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.fecha_pagos === 'factura' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">El día de la factura</button>
            <button type="button" @click="r.fecha_pagos = 'recibido'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.fecha_pagos === 'recibido' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">El día que entró la plata</button>
          </div>
          <p :class="AYUDA">En las notas del pago siempre queda el día real en que se recibió. Lo decide el contador.</p>
        </div>
      </div>
    </AjusteSeccion>

    <!-- ── Cliente y PDF ─────────────────────────────────────────────── -->
    <AjusteSeccion :icono="UserIcon" titulo="Cliente y PDF" detalle="Qué se le pide al cliente y qué dice la factura.">
      <div class="divide-y divide-gray-100">
        <AjusteInterruptor v-model="r.enviar_correo" titulo="Mandarle la factura al correo del cliente" ayuda="Lo manda Alegra." />
        <AjusteInterruptor v-model="r.exigir_correo" titulo="Exigir el correo del cliente para facturar" />
      </div>

      <div>
        <p :class="ETIQUETA">Cliente sin cédula</p>
        <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
          <button type="button" @click="r.sin_documento = 'bloquear'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.sin_documento === 'bloquear' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">Pedir la cédula</button>
          <button type="button" @click="r.sin_documento = 'consumidor_final'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.sin_documento === 'consumidor_final' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">Consumidor final</button>
        </div>
        <div v-if="r.sin_documento === 'consumidor_final'" class="mt-3 space-y-2">
          <div class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2">
            <span class="text-xs text-gray-500">Contacto en Alegra</span>
            <span :class="['text-xs font-semibold truncate', r.consumidor_final_id ? 'text-gray-800' : 'text-amber-700']">
              {{ r.consumidor_final_id ? (nombreContacto || `id ${r.consumidor_final_id}`) : 'Sin escoger' }}
            </span>
          </div>
          <form class="flex gap-2" @submit.prevent="buscarContactos">
            <div class="relative flex-1">
              <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input v-model="buscarContacto" placeholder="Buscar en Alegra (ej. consumidor)" :class="[CAMPO, 'pl-9']" />
            </div>
            <button type="submit" :disabled="buscandoContacto" class="px-4 bg-gray-100 text-gray-700 rounded-lg text-xs font-semibold disabled:opacity-50">
              {{ buscandoContacto ? 'Buscando…' : 'Buscar' }}
            </button>
          </form>
          <div v-if="contactos.length" class="rounded-lg border border-gray-200 divide-y divide-gray-100 max-h-56 overflow-y-auto">
            <button v-for="c in contactos" :key="c.id" type="button" @click="escogerContacto(c)"
              class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left hover:bg-gray-50">
              <span class="min-w-0">
                <span class="block text-sm text-gray-800 truncate">{{ c.nombre }}</span>
                <span class="block text-xs text-gray-400">{{ c.identificacion }} · id {{ c.id }}</span>
              </span>
              <CheckIcon v-if="r.consumidor_final_id === c.id" class="w-4 h-4 text-blue-600 shrink-0" />
            </button>
          </div>
        </div>
      </div>

      <div>
        <label :class="ETIQUETA" for="fe-nota">Notas del PDF</label>
        <textarea id="fe-nota" v-model="r.nota_factura" maxlength="480" rows="5" :class="[CAMPO, 'resize-y leading-relaxed']"></textarea>
        <p :class="[AYUDA, 'flex justify-between gap-2']">
          <span>{orden} = número de la orden. Va la nota del régimen ZESE que llevan hoy.</span>
          <span class="shrink-0 tabular-nums">{{ (r.nota_factura || '').length }}/480</span>
        </p>
      </div>
      <div>
        <label :class="ETIQUETA" for="fe-terminos">Términos y condiciones del PDF</label>
        <textarea id="fe-terminos" v-model="r.terminos_factura" maxlength="500" rows="3" :class="[CAMPO, 'resize-y leading-relaxed']"></textarea>
        <p :class="[AYUDA, 'text-right tabular-nums']">{{ (r.terminos_factura || '').length }}/500</p>
      </div>
    </AjusteSeccion>

    <!-- ── Alegra ────────────────────────────────────────────────────── -->
    <AjusteSeccion :icono="LinkIcon" titulo="Conexión con Alegra" detalle="Resolución, impuesto y productos con que sale cada factura.">
      <template #extra>
        <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold shrink-0', meta.conectado ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700']">
          {{ meta.conectado ? 'Conectado' : 'Sin conectar' }}
        </span>
      </template>

      <p v-if="!meta.conectado" class="text-xs text-gray-500 leading-relaxed">
        Falta poner en el servidor (Render) <b>ALEGRA_TOKEN</b>: el token JWT que se crea en Alegra → Configuración → "API - Integraciones con otros sistemas" (empieza por "eyJ"). Con el token clásico de "Credenciales de acceso" va también <b>ALEGRA_EMAIL</b>. Por seguridad no se escriben aquí.
      </p>
      <template v-else>
        <div v-if="cargandoCat" class="flex items-center gap-2 text-xs text-gray-400">
          <ArrowPathIcon class="w-4 h-4 animate-spin" /> Leyendo Alegra…
        </div>
        <div v-if="errorCat" class="flex items-start gap-2 rounded-lg border border-red-200 px-3 py-2 text-xs text-red-700">
          <ExclamationTriangleIcon class="w-4 h-4 shrink-0" />
          <span class="flex-1">{{ errorCat }}</span>
          <button type="button" @click="cargarCatalogos" class="font-semibold underline">Reintentar</button>
        </div>

        <div>
          <label :class="ETIQUETA" for="fe-num">Numeración (resolución DIAN) general</label>
          <select id="fe-num" v-model="r.numeraciones['*']" :class="CAMPO">
            <option :value="undefined">La preferida de Alegra</option>
            <option v-for="n in numeraciones" :key="n.id" :value="n.id" :disabled="!n.sirve && r.numeraciones['*'] !== n.id">{{ textoNum(n) }}</option>
          </select>
          <p v-if="avisoNum(r.numeraciones['*'])" :class="['text-xs mt-1', avisoNum(r.numeraciones['*']).grave ? 'text-red-600' : 'text-amber-700']">
            {{ avisoNum(r.numeraciones['*']).texto }}
          </p>
        </div>

        <div>
          <label :class="ETIQUETA" for="fe-imp">Impuesto de IVA</label>
          <select id="fe-imp" v-model="r.impuesto_iva_id" :class="CAMPO">
            <option :value="null">Sin escoger</option>
            <option v-for="t in impuestosIva" :key="t.id" :value="t.id">{{ t.nombre }} {{ t.porcentaje != null ? `(${t.porcentaje} %)` : '' }}</option>
          </select>
          <p v-if="ivaNoCuadra" class="text-xs text-red-600 mt-1">
            Ese impuesto es de {{ ivaNoCuadra }} % y los precios se calculan con {{ r.iva_pct }} %: la factura no cuadraría. Escoge el IVA de {{ r.iva_pct }} % (no "ARRENDAMIENTO").
          </p>
        </div>

        <div>
          <p :class="ETIQUETA">Cómo va cada mueble en la factura</p>
          <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
            <button type="button" @click="r.items_alegra = 'por_producto'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.items_alegra === 'por_producto' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">Cada mueble con su producto</button>
            <button type="button" @click="r.items_alegra = 'generico'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.items_alegra === 'generico' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">Un producto general</button>
          </div>
          <p :class="AYUDA">
            {{ r.items_alegra === 'por_producto'
              ? 'Como hoy (FEN1917): el ítem es el mueble ("SOFA TELAVID") y la descripción la variante, medidas y tela. Se busca en Alegra por el nombre; si no está, el facturador lo escoge una vez.'
              : 'Todo con el producto de abajo y el detalle del mueble en la descripción.' }}
          </p>
        </div>

        <div class="space-y-2">
          <p :class="ETIQUETA">
            {{ r.items_alegra === 'por_producto' ? 'Producto para diseños especiales (lo que no es del catálogo)' : 'Producto de Alegra para las ventas' }}
          </p>
          <div class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2">
            <span class="text-xs text-gray-500">Escogido</span>
            <span :class="['text-xs font-semibold truncate', r.item_producto_id ? 'text-gray-800' : 'text-amber-700']">
              {{ r.item_producto_id ? (nombreItem || `id ${r.item_producto_id}`) : 'Sin escoger' }}
            </span>
          </div>
          <form class="flex gap-2" @submit.prevent="buscarItems">
            <div class="relative flex-1">
              <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
              <input v-model="buscarItem" placeholder="Buscar producto (ej. diseño especial)" :class="[CAMPO, 'pl-9']" />
            </div>
            <button type="submit" :disabled="buscandoItem" class="px-4 bg-gray-100 text-gray-700 rounded-lg text-xs font-semibold disabled:opacity-50">
              {{ buscandoItem ? 'Buscando…' : 'Buscar' }}
            </button>
          </form>
          <div v-if="items.length" class="rounded-lg border border-gray-200 divide-y divide-gray-100 max-h-64 overflow-y-auto">
            <button v-for="i in items" :key="i.id" type="button" @click="escogerItem(i)"
              class="w-full flex items-center justify-between gap-2 px-3 py-2 text-left hover:bg-gray-50">
              <span class="min-w-0">
                <span class="block text-sm text-gray-800 truncate">{{ i.nombre }}</span>
                <span class="block text-xs text-gray-400">id {{ i.id }}</span>
                <span v-if="i.inventario && r.items_alegra === 'generico'" class="block text-xs text-amber-700">Lleva inventario en Alegra: mejor uno sin inventario.</span>
              </span>
              <CheckIcon v-if="r.item_producto_id === i.id" class="w-4 h-4 text-blue-600 shrink-0" />
              <span v-else class="text-xs font-semibold text-blue-600 shrink-0">Escoger</span>
            </button>
          </div>
        </div>
      </template>
    </AjusteSeccion>

    <template v-if="meta.conectado">
      <!-- ── Bodega y centro de costo ────────────────────────────────── -->
      <!-- FEN1917: bodega "3 - EDEN", centro de costo "EDEN". -->
      <AjusteSeccion :icono="BuildingStorefrontIcon" titulo="Bodega, centro de costo y numeración"
        detalle="Va la de la tienda que vendió: una venta por WhatsApp o redes es de la tienda del vendedor.">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label :class="ETIQUETA" for="fe-bod">Bodega general</label>
            <select id="fe-bod" v-model="r.bodegas['*']" :class="CAMPO">
              <option :value="undefined">La principal</option>
              <option v-for="b in cat?.bodegas ?? []" :key="b.id" :value="b.id">{{ b.nombre }}</option>
            </select>
          </div>
          <div>
            <label :class="ETIQUETA" for="fe-centro">Centro de costo general</label>
            <select id="fe-centro" v-model="r.centros_costo['*']" :class="CAMPO">
              <option :value="undefined">Ninguno</option>
              <option v-for="c in cat?.centros_costo ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
          </div>
        </div>
        <p v-if="cat && !(cat.bodegas?.length || cat.centros_costo?.length)" class="flex gap-1.5 text-xs text-amber-700">
          <ExclamationTriangleIcon class="w-4 h-4 shrink-0" />
          Alegra no mostró bodegas ni centros de costo: al token le falta el permiso de ver "Almacenes" y "Centros de costos" (Alegra → API → editar el token).
        </p>
        <p class="text-xs text-gray-400">Lo de Tienda Virtual lo maneja la fábrica: a Tienda Virtual escógele la bodega y el centro de costo de Fábrica.</p>

        <div class="space-y-2">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Por tienda</p>
          <div v-for="t in meta.tiendas" :key="t.id" class="rounded-xl border border-gray-200 overflow-hidden">
            <button type="button" @click="tiendaAbierta = tiendaAbierta === t.id ? null : t.id" :aria-expanded="tiendaAbierta === t.id"
              class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-gray-50">
              <span class="flex-1 min-w-0">
                <span class="flex items-center gap-1.5">
                  <span class="text-sm font-semibold text-gray-800">{{ t.nombre }}</span>
                  <ExclamationTriangleIcon v-if="tiendaConAviso(t)" class="w-4 h-4 text-red-600" />
                </span>
                <span class="block text-xs text-gray-400 truncate">{{ resumenTienda(t) }}</span>
              </span>
              <ChevronDownIcon :class="['w-4 h-4 text-gray-400 shrink-0 transition-transform', tiendaAbierta === t.id && 'rotate-180']" />
            </button>
            <div v-if="tiendaAbierta === t.id" class="px-3 pb-3 pt-1 space-y-3 border-t border-gray-100">
              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label :class="ETIQUETA">Bodega</label>
                  <select v-model="r.bodegas[String(t.id)]" :class="CAMPO_CHICO">
                    <option :value="undefined">La general</option>
                    <option v-for="b in cat?.bodegas ?? []" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                  </select>
                </div>
                <div>
                  <label :class="ETIQUETA">Centro de costo</label>
                  <select v-model="r.centros_costo[String(t.id)]" :class="CAMPO_CHICO">
                    <option :value="undefined">El general</option>
                    <option v-for="c in cat?.centros_costo ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                  </select>
                </div>
              </div>
              <div>
                <label :class="ETIQUETA">Numeración</label>
                <select v-model="r.numeraciones[String(t.id)]" :class="CAMPO_CHICO">
                  <option :value="undefined">La general</option>
                  <option v-for="n in numeraciones" :key="n.id" :value="n.id" :disabled="!n.sirve && r.numeraciones[String(t.id)] !== n.id">{{ textoNum(n) }}</option>
                </select>
                <p v-if="avisoNum(r.numeraciones[String(t.id)])" :class="['text-xs mt-1', avisoNum(r.numeraciones[String(t.id)]).grave ? 'text-red-600' : 'text-amber-700']">
                  {{ avisoNum(r.numeraciones[String(t.id)]).texto }}
                </p>
              </div>
              <div>
                <p :class="ETIQUETA">Caja o banco de cada medio de pago</p>
                <div class="grid grid-cols-2 gap-2">
                  <div v-for="m in meta.metodos" :key="m">
                    <label class="block text-[11px] text-gray-400 mb-0.5">{{ METODOS[m] ?? m }}</label>
                    <select v-model="r.cuentas[String(t.id)][m]" :class="CAMPO_CHICO">
                      <option :value="undefined">La general</option>
                      <option v-for="c in cat?.cuentas ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </AjusteSeccion>

      <!-- ── Medios de pago ──────────────────────────────────────────── -->
      <AjusteSeccion :icono="CreditCardIcon" titulo="Medios de pago en Alegra"
        detalle="Cada forma de pago del sistema: dónde entra la plata y cómo la ve la DIAN.">
        <div class="space-y-2">
          <div v-for="m in meta.metodos" :key="m" class="rounded-xl border border-gray-200 p-3 space-y-2">
            <p class="text-sm font-semibold text-gray-800">{{ METODOS[m] ?? m }}</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-[11px] text-gray-400 mb-0.5">Caja o banco</label>
                <select v-model="r.cuentas['*'][m]" :class="CAMPO_CHICO">
                  <option :value="undefined">Sin escoger</option>
                  <option v-for="c in cat?.cuentas ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[11px] text-gray-400 mb-0.5">Medio para la DIAN</label>
                <select v-model="r.medios_dian[m]" :class="CAMPO_CHICO">
                  <option v-for="(l, k) in meta.medios_dian" :key="k" :value="k">{{ l }}</option>
                </select>
              </div>
              <div class="col-span-2">
                <label class="block text-[11px] text-gray-400 mb-0.5">Cómo se registra el pago en Alegra</label>
                <select v-model="r.medios_pago[m]" :class="CAMPO_CHICO">
                  <option v-for="k in meta.medios_pago" :key="k" :value="k">{{ MEDIOS_PAGO[k] ?? k }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </AjusteSeccion>
    </template>

    <!-- Guardar: siempre a la mano, encima de la barra de abajo (App.vue). -->
    <div class="sticky bottom-[5.25rem] z-10">
      <button type="button" @click="guardar" :disabled="guardando"
        class="w-full py-3 bg-blue-600 text-white rounded-xl text-sm font-semibold shadow-lg disabled:opacity-50 hover:bg-blue-700 transition-colors">
        {{ guardando ? 'Guardando…' : 'Guardar ajustes' }}
      </button>
    </div>
  </div>
</template>
