<script setup>
/**
 * El texto del anexo de garantías, para editarlo desde Gestión → Documentos.
 *
 * Se edita por secciones (como lo lee el cliente: cada una se marca como
 * leída) y un check list de preguntas sí/no. Cada renglón del texto es un
 * párrafo. El componente guarda su propio borrador y emite el contenido con
 * la forma que espera el servidor; para volver a cargar otro texto, el padre
 * le cambia la `key`.
 */
import { ref, watch } from 'vue'
import {
  ChevronDownIcon, ChevronUpIcon, ArrowUpIcon, ArrowDownIcon, TrashIcon, PlusIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue: { type: Object, required: true },   // { titulo, secciones: [{id,titulo,parrafos}], checklist: [{id,pregunta}] }
})
const emit = defineEmits(['update:modelValue'])

let uid = 0
const titulo    = ref(props.modelValue.titulo ?? '')
const secciones = ref((props.modelValue.secciones ?? []).map(s => ({
  key: ++uid, id: s.id, titulo: s.titulo, texto: (s.parrafos ?? []).join('\n\n'),
})))
const preguntas = ref((props.modelValue.checklist ?? []).map(q => ({ key: ++uid, id: q.id, pregunta: q.pregunta })))
const abierta   = ref(null)

function parrafos(texto) {
  return texto.split('\n').map(p => p.trim()).filter(Boolean)
}

watch([titulo, secciones, preguntas], () => {
  emit('update:modelValue', {
    titulo:    titulo.value.trim(),
    secciones: secciones.value.map(s => ({ ...(s.id ? { id: s.id } : {}), titulo: s.titulo.trim(), parrafos: parrafos(s.texto) })),
    checklist: preguntas.value.filter(q => q.pregunta.trim()).map(q => ({ ...(q.id ? { id: q.id } : {}), pregunta: q.pregunta.trim() })),
  })
}, { deep: true })

function alternar(s) {
  abierta.value = abierta.value === s.key ? null : s.key
}

function mover(lista, i, paso) {
  const j = i + paso
  if (j < 0 || j >= lista.length) return
  const copia = [...lista]
  ;[copia[i], copia[j]] = [copia[j], copia[i]]
  return copia
}

function subir(i, paso) {
  const nueva = mover(secciones.value, i, paso)
  if (nueva) secciones.value = nueva
}

function agregarSeccion() {
  const s = { key: ++uid, id: null, titulo: '', texto: '' }
  secciones.value = [...secciones.value, s]
  abierta.value = s.key
}

function quitarSeccion(s) {
  const tiene = s.titulo.trim() || s.texto.trim()
  if (tiene && !confirm(`¿Quitar la sección "${s.titulo || 'sin título'}"? Se va del anexo cuando guardes.`)) return
  secciones.value = secciones.value.filter(x => x.key !== s.key)
}

function agregarPregunta() {
  preguntas.value = [...preguntas.value, { key: ++uid, id: null, pregunta: '' }]
}

function quitarPregunta(q) {
  preguntas.value = preguntas.value.filter(x => x.key !== q.key)
}

/** Primeras palabras de la sección, para reconocerla plegada. */
function resumen(texto) {
  const t = texto.replace(/\s+/g, ' ').trim()
  return t.length > 90 ? t.slice(0, 90) + '…' : t
}
</script>

<template>
  <div class="space-y-4">
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1">Título del documento</label>
      <textarea
        v-model="titulo" rows="2" maxlength="300"
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
      />
    </div>

    <!-- Secciones -->
    <div class="space-y-2">
      <div class="flex items-end justify-between gap-2">
        <div>
          <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Secciones ({{ secciones.length }})</p>
          <p class="text-[11px] text-gray-400">El cliente marca cada una como leída. Cada renglón es un párrafo.</p>
        </div>
      </div>

      <div
        v-for="(s, i) in secciones" :key="s.key"
        class="bg-white rounded-xl border border-gray-200 shadow-sm"
      >
        <button type="button" @click="alternar(s)" class="w-full flex items-start gap-3 px-3 py-3 text-left">
          <span class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 text-xs font-bold flex items-center justify-center shrink-0">{{ i + 1 }}</span>
          <span class="flex-1 min-w-0">
            <span :class="['block text-sm font-semibold', s.titulo.trim() ? 'text-gray-800' : 'text-red-600']">
              {{ s.titulo.trim() || 'Sin título' }}
            </span>
            <span v-if="abierta !== s.key" class="block text-xs text-gray-500 mt-0.5 line-clamp-2">
              {{ resumen(s.texto) || 'Sin texto todavía' }}
            </span>
          </span>
          <component :is="abierta === s.key ? ChevronUpIcon : ChevronDownIcon" class="w-4 h-4 text-gray-400 shrink-0 mt-1" />
        </button>

        <div v-if="abierta === s.key" class="px-3 pb-3 space-y-2">
          <input
            v-model="s.titulo" maxlength="200" placeholder="Título de la sección"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <textarea
            v-model="s.texto" rows="10" placeholder="Escribe el texto. Cada renglón es un párrafo."
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <div class="flex items-center gap-1">
            <button type="button" @click="subir(i, -1)" :disabled="i === 0" class="w-9 h-9 rounded-lg flex items-center justify-center text-gray-500 hover:bg-gray-100 disabled:opacity-30" aria-label="Subir sección">
              <ArrowUpIcon class="w-4 h-4" />
            </button>
            <button type="button" @click="subir(i, 1)" :disabled="i === secciones.length - 1" class="w-9 h-9 rounded-lg flex items-center justify-center text-gray-500 hover:bg-gray-100 disabled:opacity-30" aria-label="Bajar sección">
              <ArrowDownIcon class="w-4 h-4" />
            </button>
            <span class="flex-1 text-[11px] text-gray-400 text-right pr-1">{{ parrafos(s.texto).length }} párrafo(s)</span>
            <button type="button" @click="quitarSeccion(s)" class="h-9 px-2 rounded-lg flex items-center gap-1 text-xs font-semibold text-red-600 hover:bg-red-50">
              <TrashIcon class="w-4 h-4" /> Quitar
            </button>
          </div>
        </div>
      </div>

      <button
        type="button" @click="agregarSeccion"
        class="w-full rounded-xl border border-dashed border-gray-300 py-2.5 text-xs font-semibold text-blue-600 hover:bg-gray-50 flex items-center justify-center gap-1"
      >
        <PlusIcon class="w-4 h-4" /> Agregar sección
      </button>
    </div>

    <!-- Check list -->
    <div class="space-y-2">
      <div>
        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Check list ({{ preguntas.length }})</p>
        <p class="text-[11px] text-gray-400">Preguntas de sí o no. Si responde "No", el vendedor lo ve para explicárselo.</p>
      </div>
      <div v-for="(q, i) in preguntas" :key="q.key" class="flex items-start gap-2">
        <span class="text-xs text-gray-400 w-5 pt-2.5 text-right shrink-0">{{ i + 1 }}.</span>
        <textarea
          v-model="q.pregunta" rows="2" maxlength="300" placeholder="¿Le explicaron…?"
          class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
        />
        <button type="button" @click="quitarPregunta(q)" class="w-9 h-9 mt-0.5 rounded-lg flex items-center justify-center text-gray-400 hover:bg-red-50 hover:text-red-500 shrink-0" aria-label="Quitar pregunta">
          <TrashIcon class="w-4 h-4" />
        </button>
      </div>
      <button
        type="button" @click="agregarPregunta"
        class="w-full rounded-xl border border-dashed border-gray-300 py-2.5 text-xs font-semibold text-blue-600 hover:bg-gray-50 flex items-center justify-center gap-1"
      >
        <PlusIcon class="w-4 h-4" /> Agregar pregunta
      </button>
    </div>
  </div>
</template>
