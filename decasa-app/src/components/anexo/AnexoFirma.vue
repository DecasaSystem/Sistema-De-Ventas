<script setup>
/**
 * El anexo de garantías para leer y firmar: el mismo en la tienda (en el
 * teléfono del vendedor) y en la página que se le manda al cliente.
 *
 * Cada sección se marca como leída —y se pliega, para que la pantalla no
 * sea un muro de texto—, se responde el check list y se firma con el dedo.
 * El texto llega del servidor (AnexoGarantiaTexto): aquí no se escribe nada
 * del documento.
 */
import { ref, computed, nextTick } from 'vue'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import DeclaracionAprobar from '@/components/anexo/DeclaracionAprobar.vue'
import { useTamanoLetra } from '@/composables/useTamanoLetra'
import { CheckCircleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  contenido: { type: Object, required: true },   // { titulo, secciones, checklist }
  nombre:    { type: String, default: '' },
  documento: { type: String, default: '' },
  enviando:  { type: Boolean, default: false },
  textoBoton: { type: String, default: 'Acepto y firmo' },
  // La declaración de diseños especiales, si el pedido trae muebles fuera
  // del catálogo: se aprueba producto por producto y se firma con lo mismo.
  declaracion: { type: Object, default: null },
  puedePedirCambio: { type: Boolean, default: false },
})
const emit = defineEmits(['firmar', 'pedir-cambio'])

// Declaración: qué productos aprobó y cuáles están abiertos (el primero, para
// que empiece por ahí: es lo que es solo suyo).
const productos   = computed(() => props.declaracion?.productos ?? [])
const aprobados   = ref(new Set())
const abiertosDecl = ref(new Set(productos.value.length ? [0] : []))
const todoAprobado = computed(() => aprobados.value.size === productos.value.length)

function aprobar(i) {
  const a = new Set(aprobados.value)
  const o = new Set(abiertosDecl.value)
  if (a.has(i)) { a.delete(i); o.add(i) }
  else {
    a.add(i)
    o.delete(i)
    const sig = productos.value.findIndex((_, k) => !a.has(k))
    if (sig >= 0) o.add(sig)
  }
  aprobados.value = a
  abiertosDecl.value = o
}
function alternarProducto(i) {
  const o = new Set(abiertosDecl.value)
  o.has(i) ? o.delete(i) : o.add(i)
  abiertosDecl.value = o
}

// Tamaño de la letra: hay clientes que casi no ven. Compartido con el
// resumen del pedido de la página del cliente.
const { escala, esMinima, esMaxima, cambiar: cambiarLetra } = useTamanoLetra()

const leidas    = ref(new Set())
const abiertas  = ref(new Set([props.contenido.secciones[0]?.id]))
const checklist = ref({})
const nombreF   = ref(props.nombre)
const docF      = ref(props.documento)
const firmaBlob = ref(null)

const totalSecciones = computed(() => props.contenido.secciones.length)
const todoLeido      = computed(() => leidas.value.size === totalSecciones.value && todoAprobado.value)
const todoRespondido = computed(() => props.contenido.checklist.every(q => checklist.value[q.id]))
// El avance de arriba cuenta también los productos de la declaración.
const totalPasos = computed(() => totalSecciones.value + productos.value.length)
const pasosHechos = computed(() => leidas.value.size + aprobados.value.size)
const listo = computed(() =>
  todoLeido.value && todoRespondido.value && nombreF.value.trim() && docF.value.trim() && firmaBlob.value
)
/**
 * Lo primero que falta, en el orden en que se llena. Los clientes decían "ya
 * marqué todo" y no era así: la última sección se abre sola al marcar la
 * penúltima, se lee, y se baja a firmar sin tocar "Leí esta sección". Se veía
 * hecha. Ahora se dice cuál falta por su nombre y, al tocar firmar, se lleva
 * ahí.
 */
const pendiente = computed(() => {
  const i = productos.value.findIndex((_, k) => !aprobados.value.has(k))
  if (i >= 0) {
    const p = productos.value[i]
    return { clave: `p-${i}`, abrir: () => abiertosDecl.value = new Set([...abiertosDecl.value, i]),
      texto: `Falta aprobar el producto ${i + 1}: «${p.nombre}».` }
  }
  const secciones = props.contenido.secciones
  const s = secciones.find(x => !leidas.value.has(x.id))
  if (s) {
    const n = secciones.indexOf(s) + 1
    return { clave: `s-${s.id}`, abrir: () => abiertas.value = new Set([...abiertas.value, s.id]),
      texto: `Falta marcar como leída la sección ${n}: «${s.titulo}». Ábrela y toca "Leí esta sección".` }
  }
  const q = props.contenido.checklist.find(x => !checklist.value[x.id])
  if (q) return { clave: `q-${q.id}`, texto: `Falta responder Sí o No: «${q.pregunta}».` }
  if (!nombreF.value.trim() || !docF.value.trim()) return { clave: 'datos', texto: 'Escribe el nombre y el documento de quien firma.' }
  if (!firmaBlob.value) return { clave: 'firma', texto: 'Falta la firma.' }
  return null
})
const falta = computed(() => pendiente.value?.texto ?? '')

// Después de intentar firmar, lo que falta se marca en ámbar en la pantalla.
const intento = ref(false)
const raiz = ref(null)

async function irAPendiente() {
  const p = pendiente.value
  if (!p) return
  intento.value = true
  p.abrir?.()
  await nextTick()
  const el = raiz.value?.querySelector(`[data-pendiente="${p.clave}"]`)
  if (!el) return
  el.scrollIntoView({ behavior: 'smooth', block: 'center' })
  // Un destello para que el ojo lo encuentre.
  el.classList.add('ring-4', 'ring-amber-400')
  setTimeout(() => el.classList.remove('ring-4', 'ring-amber-400'), 2200)
}

function marcarLeida(s) {
  const l = new Set(leidas.value)
  const a = new Set(abiertas.value)
  if (l.has(s.id)) { l.delete(s.id); a.add(s.id) }
  else {
    l.add(s.id)
    a.delete(s.id)
    // Abre la siguiente que falte: se lee de corrido, sin buscarla.
    const sig = props.contenido.secciones.find(x => !l.has(x.id))
    if (sig) a.add(sig.id)
  }
  leidas.value = l
  abiertas.value = a
}

function alternar(id) {
  const a = new Set(abiertas.value)
  a.has(id) ? a.delete(id) : a.add(id)
  abiertas.value = a
}

function aDataUrl(blob) {
  return new Promise((res, rej) => {
    const r = new FileReader()
    r.onload = () => res(r.result)
    r.onerror = rej
    r.readAsDataURL(blob)
  })
}

async function firmar() {
  if (props.enviando) return
  if (!listo.value) return irAPendiente()
  emit('firmar', {
    secciones: [...leidas.value],
    checklist: { ...checklist.value },
    declaracion_aprobados: [...aprobados.value],
    nombre:    nombreF.value.trim(),
    documento: docF.value.trim(),
    firma:     await aDataUrl(firmaBlob.value),
    firmaBlob: firmaBlob.value,
  })
}
</script>

<template>
  <div ref="raiz" class="space-y-4" :style="{ fontSize: `${14 * escala}px` }">
    <!-- Cuánto falta por leer, y el tamaño de la letra -->
    <div class="sticky top-0 z-10 -mx-4 px-4 py-2 bg-white/95 backdrop-blur border-b border-gray-100">
      <div class="flex items-center justify-between gap-3 mb-1" style="font-size: 13px;">
        <span class="font-medium text-gray-600">
          {{ productos.length ? 'Revisado' : 'Leído' }} {{ pasosHechos }} de {{ totalPasos }}
          <span v-if="todoLeido" class="text-green-700 ml-1">Listo ✓</span>
          <!-- Desde arriba también se llega a lo que falta. -->
          <button v-else type="button" @click="irAPendiente" class="ml-1 text-amber-700 font-semibold underline">
            Ver qué falta
          </button>
        </span>
        <!-- Para quien no ve bien: agranda todo el documento. -->
        <div class="flex items-center gap-1" role="group" aria-label="Tamaño de la letra">
          <button
            type="button" @click="cambiarLetra(-1)" :disabled="esMinima"
            class="w-10 h-9 rounded-lg border border-gray-300 bg-white font-bold text-gray-700 disabled:opacity-30"
            aria-label="Letra más pequeña"
          ><span style="font-size: 13px;">A−</span></button>
          <button
            type="button" @click="cambiarLetra(1)" :disabled="esMaxima"
            class="w-10 h-9 rounded-lg border border-gray-300 bg-white font-bold text-gray-900 disabled:opacity-30"
            aria-label="Letra más grande"
          ><span style="font-size: 18px;">A+</span></button>
        </div>
      </div>
      <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
        <div class="h-full bg-green-600 transition-all" :style="{ width: `${totalPasos ? (pasosHechos / totalPasos) * 100 : 0}%` }" />
      </div>
    </div>

    <!-- Declaración de diseños especiales: primero, porque es lo suyo -->
    <DeclaracionAprobar
      v-if="productos.length"
      :declaracion="declaracion"
      :aprobados="aprobados"
      :abiertos="abiertosDecl"
      :puede-pedir-cambio="puedePedirCambio"
      @aprobar="aprobar"
      @alternar="alternarProducto"
      @pedir-cambio="emit('pedir-cambio', $event)"
    />
    <p v-if="productos.length" class="text-[1.05em] font-bold text-gray-800 pt-1">{{ contenido.titulo }}</p>

    <!-- Secciones -->
    <section
      v-for="(s, i) in contenido.secciones" :key="s.id"
      :data-pendiente="`s-${s.id}`"
      :class="['rounded-xl border transition-colors', leidas.has(s.id) ? 'border-green-200 bg-green-50/40'
        : intento ? 'border-amber-400 bg-white' : 'border-gray-200 bg-white']"
    >
      <button type="button" @click="alternar(s.id)" class="w-full flex items-center gap-3 px-4 py-3 text-left">
        <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0',
          leidas.has(s.id) ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-500']">
          <CheckCircleIcon v-if="leidas.has(s.id)" class="w-4 h-4" />
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span class="flex-1 min-w-0">
          <span class="block text-[1.05em] font-semibold text-gray-800">{{ s.titulo }}</span>
          <span v-if="intento && !leidas.has(s.id)" class="block text-[0.8em] font-semibold text-amber-700">Falta marcarla como leída</span>
        </span>
        <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', abiertas.has(s.id) ? 'rotate-180' : '']" />
      </button>
      <div v-if="abiertas.has(s.id)" class="px-4 pb-4 space-y-2">
        <p v-for="(p, j) in s.parrafos" :key="j" class="text-[1em] leading-relaxed text-gray-700">{{ p }}</p>
        <button
          type="button"
          @click="marcarLeida(s)"
          :class="['mt-2 w-full rounded-lg py-2.5 text-[1em] font-semibold border transition-colors',
            leidas.has(s.id) ? 'bg-white border-gray-300 text-gray-600' : 'bg-green-600 border-green-600 text-white hover:bg-green-700']"
        >{{ leidas.has(s.id) ? 'Quitar la marca' : 'Leí esta sección ✓' }}</button>
      </div>
    </section>

    <!-- Check list (el texto de Gestión → Documentos puede no traer preguntas) -->
    <section v-if="contenido.checklist.length" class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
      <p class="text-[1.05em] font-bold text-gray-800">Check list</p>
      <div v-for="q in contenido.checklist" :key="q.id" :data-pendiente="`q-${q.id}`" class="space-y-1.5 rounded-lg">
        <p class="text-[1em] text-gray-700">{{ q.pregunta }}</p>
        <p v-if="intento && !checklist[q.id]" class="text-[0.8em] font-semibold text-amber-700">Falta responder</p>
        <div class="grid grid-cols-2 gap-2">
          <button
            v-for="op in [{ v: 'si', l: 'Sí' }, { v: 'no', l: 'No' }]" :key="op.v"
            type="button"
            @click="checklist = { ...checklist, [q.id]: op.v }"
            :class="['rounded-lg py-2 text-[1em] font-semibold border transition-colors',
              checklist[q.id] === op.v
                ? (op.v === 'si' ? 'bg-green-600 border-green-600 text-white' : 'bg-amber-500 border-amber-500 text-white')
                : 'bg-white border-gray-300 text-gray-600']"
          >{{ op.l }}</button>
        </div>
      </div>
    </section>

    <!-- Quién firma y la firma -->
    <section class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
      <p class="text-[1.05em] font-bold text-gray-800">Firma del cliente</p>
      <div data-pendiente="datos" class="grid grid-cols-1 sm:grid-cols-2 gap-2 rounded-lg">
        <div>
          <label class="text-[0.85em] text-gray-500">Nombre</label>
          <input v-model="nombreF" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-[1em]" autocomplete="name" />
        </div>
        <div>
          <label class="text-[0.85em] text-gray-500">N° de documento</label>
          <input v-model="docF" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-[1em]" inputmode="numeric" />
        </div>
      </div>
      <!-- Firmar sin haber terminado: tocar la firma lleva a lo que falta
           (antes no hacía nada y parecía dañada). -->
      <div data-pendiente="firma" class="relative rounded-lg">
        <div :class="!todoLeido || !todoRespondido ? 'opacity-40 pointer-events-none' : ''">
          <FirmaCanvas v-model="firmaBlob" />
        </div>
        <button
          v-if="!todoLeido || !todoRespondido"
          type="button" @click="irAPendiente"
          class="absolute inset-0 flex items-center justify-center p-4 text-center"
        >
          <span class="rounded-lg bg-white border border-amber-300 shadow-sm px-3 py-2 text-[0.9em] font-semibold text-amber-800">
            Antes de firmar falta algo. Toca aquí para ir.
          </span>
        </button>
      </div>
    </section>

    <button
      type="button"
      @click="firmar"
      :disabled="enviando"
      :class="['w-full rounded-xl py-3.5 text-[1.1em] font-semibold bg-green-600 text-white hover:bg-green-700 disabled:opacity-40 transition-colors',
        listo ? '' : 'opacity-60']"
    >{{ enviando ? 'Guardando…' : textoBoton }}</button>
    <p v-if="falta" :class="['text-[0.85em] text-center -mt-2', intento ? 'text-amber-700 font-semibold' : 'text-gray-500']">{{ falta }}</p>
  </div>
</template>
