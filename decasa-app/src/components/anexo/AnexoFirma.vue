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
import { ref, computed } from 'vue'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import { useTamanoLetra } from '@/composables/useTamanoLetra'
import { CheckCircleIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  contenido: { type: Object, required: true },   // { titulo, secciones, checklist }
  nombre:    { type: String, default: '' },
  documento: { type: String, default: '' },
  enviando:  { type: Boolean, default: false },
  textoBoton: { type: String, default: 'Acepto y firmo' },
})
const emit = defineEmits(['firmar'])

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
const todoLeido      = computed(() => leidas.value.size === totalSecciones.value)
const todoRespondido = computed(() => props.contenido.checklist.every(q => checklist.value[q.id]))
const listo = computed(() =>
  todoLeido.value && todoRespondido.value && nombreF.value.trim() && docF.value.trim() && firmaBlob.value
)
const falta = computed(() => {
  if (!todoLeido.value) return `Marca como leídas las secciones (${leidas.value.size} de ${totalSecciones.value}).`
  if (!todoRespondido.value) return 'Responde el check list.'
  if (!nombreF.value.trim() || !docF.value.trim()) return 'Escribe el nombre y el documento de quien firma.'
  if (!firmaBlob.value) return 'Falta la firma.'
  return ''
})

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
  if (!listo.value || props.enviando) return
  emit('firmar', {
    secciones: [...leidas.value],
    checklist: { ...checklist.value },
    nombre:    nombreF.value.trim(),
    documento: docF.value.trim(),
    firma:     await aDataUrl(firmaBlob.value),
    firmaBlob: firmaBlob.value,
  })
}
</script>

<template>
  <div class="space-y-4" :style="{ fontSize: `${14 * escala}px` }">
    <!-- Cuánto falta por leer, y el tamaño de la letra -->
    <div class="sticky top-0 z-10 -mx-4 px-4 py-2 bg-white/95 backdrop-blur border-b border-gray-100">
      <div class="flex items-center justify-between gap-3 mb-1" style="font-size: 13px;">
        <span class="font-medium text-gray-600">
          Leído {{ leidas.size }} de {{ totalSecciones }}
          <span v-if="todoLeido" class="text-green-700 ml-1">Listo ✓</span>
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
        <div class="h-full bg-green-600 transition-all" :style="{ width: `${(leidas.size / totalSecciones) * 100}%` }" />
      </div>
    </div>

    <!-- Secciones -->
    <section
      v-for="(s, i) in contenido.secciones" :key="s.id"
      :class="['rounded-xl border transition-colors', leidas.has(s.id) ? 'border-green-200 bg-green-50/40' : 'border-gray-200 bg-white']"
    >
      <button type="button" @click="alternar(s.id)" class="w-full flex items-center gap-3 px-4 py-3 text-left">
        <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0',
          leidas.has(s.id) ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-500']">
          <CheckCircleIcon v-if="leidas.has(s.id)" class="w-4 h-4" />
          <template v-else>{{ i + 1 }}</template>
        </span>
        <span class="flex-1 text-[1.05em] font-semibold text-gray-800">{{ s.titulo }}</span>
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

    <!-- Check list -->
    <section class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
      <p class="text-[1.05em] font-bold text-gray-800">Check list</p>
      <div v-for="q in contenido.checklist" :key="q.id" class="space-y-1.5">
        <p class="text-[1em] text-gray-700">{{ q.pregunta }}</p>
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
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <div>
          <label class="text-[0.85em] text-gray-500">Nombre</label>
          <input v-model="nombreF" class="input text-[1em]" autocomplete="name" />
        </div>
        <div>
          <label class="text-[0.85em] text-gray-500">N° de documento</label>
          <input v-model="docF" class="input text-[1em]" inputmode="numeric" />
        </div>
      </div>
      <div :class="!todoLeido || !todoRespondido ? 'opacity-50 pointer-events-none' : ''">
        <FirmaCanvas v-model="firmaBlob" />
      </div>
      <p v-if="!todoLeido || !todoRespondido" class="text-[0.85em] text-gray-500">La firma se habilita cuando todo esté leído y respondido.</p>
    </section>

    <button
      type="button"
      @click="firmar"
      :disabled="!listo || enviando"
      class="w-full rounded-xl py-3.5 text-[1.1em] font-semibold bg-green-600 text-white hover:bg-green-700 disabled:opacity-40 transition-colors"
    >{{ enviando ? 'Guardando…' : textoBoton }}</button>
    <p v-if="falta" class="text-[0.85em] text-center text-gray-500 -mt-2">{{ falta }}</p>
  </div>
</template>
