<script setup>
/**
 * Editor de las plantillas de Gestión → Documentos que son una lista de campos
 * (datos de la empresa, plazos de garantía, cotización, acta de satisfacción,
 * mensajes de WhatsApp). El servidor manda el esquema (EsquemasPlantillas):
 * qué campos hay, de qué tipo y qué datos se pueden poner entre llaves.
 *
 * Guarda su propio borrador y emite el contenido con la forma del servidor;
 * para cargar otro texto, el padre le cambia la `key`.
 */
import { ref, watch } from 'vue'
import { rellenar } from '@/utils/textosNegocio'

const props = defineProps({
  modelValue: { type: Object, required: true },
  campos:     { type: Array, required: true },   // [{ campo, tipo, etiqueta, ayuda, max, min, requerido }]
  datos:      { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

// Los párrafos se editan como un texto, un renglón por párrafo.
const f = ref(Object.fromEntries(props.campos.map(c => {
  const v = props.modelValue[c.campo]
  return [c.campo, c.tipo === 'parrafos' ? (v ?? []).join('\n') : (v ?? '')]
})))

watch(f, () => {
  const out = {}
  for (const c of props.campos) {
    const v = f.value[c.campo]
    if (c.tipo === 'numero') out[c.campo] = v === '' || v === null ? '' : Number(v)
    else if (c.tipo === 'parrafos') out[c.campo] = String(v ?? '').split('\n').map(p => p.trim()).filter(Boolean)
    else if (c.tipo === 'multilinea') out[c.campo] = String(v ?? '').replace(/[ \t]+\n/g, '\n').trim()
    else out[c.campo] = String(v ?? '').trim()
  }
  emit('update:modelValue', out)
}, { deep: true })

// Con qué se ve el ejemplo de cada dato.
const EJEMPLO = {
  cliente: 'Ana', asesor: 'Laura Gómez', empresa: 'Decasa', empresa_completo: 'Decasa Muebles y Decoración',
  referencia: 'A-1520', enlace: 'https://…/firmar/abc123', valida_hasta: '30/10/2026',
}
const ejemplo = (t) => rellenar(t, Object.fromEntries(props.datos.map(d => [d, EJEMPLO[d] ?? d])))
const usaDatos = (c) => props.datos.length && ['texto', 'multilinea', 'parrafos'].includes(c.tipo)

const refs = {}
/** Pone {dato} donde está el cursor del campo. */
function insertar(c, d) {
  const el = refs[c.campo]
  const txt = `{${d}}`
  const actual = String(f.value[c.campo] ?? '')
  const ini = el?.selectionStart ?? actual.length
  const fin = el?.selectionEnd ?? ini
  f.value[c.campo] = actual.slice(0, ini) + txt + actual.slice(fin)
  requestAnimationFrame(() => { el?.focus(); el?.setSelectionRange(ini + txt.length, ini + txt.length) })
}

const claseInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
</script>

<template>
  <div class="space-y-4">
    <div v-for="c in campos" :key="c.campo">
      <label :for="`campo-${c.campo}`" class="block text-xs font-semibold text-gray-600 mb-1">
        {{ c.etiqueta }}<span v-if="c.requerido" class="text-red-500"> *</span>
      </label>

      <input
        v-if="c.tipo === 'numero'" :id="`campo-${c.campo}`"
        v-model.number="f[c.campo]" type="number" inputmode="numeric" :min="c.min" :max="c.max"
        :class="claseInput + ' max-w-[8rem]'"
      />
      <input
        v-else-if="c.tipo === 'texto'" :id="`campo-${c.campo}`" :ref="el => (refs[c.campo] = el)"
        v-model="f[c.campo]" :maxlength="c.max" :class="claseInput"
      />
      <textarea
        v-else :id="`campo-${c.campo}`" :ref="el => (refs[c.campo] = el)"
        v-model="f[c.campo]" :rows="c.tipo === 'parrafos' ? 5 : 4" :class="claseInput + ' leading-relaxed'"
      />

      <p v-if="c.ayuda" class="text-[11px] text-gray-400 mt-1">{{ c.ayuda }}</p>

      <template v-if="usaDatos(c)">
        <div class="flex flex-wrap items-center gap-1.5 mt-1">
          <span class="text-[11px] text-gray-400">Insertar dato:</span>
          <button
            v-for="d in datos" :key="d" type="button" @click="insertar(c, d)"
            class="rounded-full border border-gray-300 px-2 py-0.5 text-[11px] font-semibold text-gray-600 hover:bg-gray-100"
          >{{ '{' + d + '}' }}</button>
        </div>
        <!-- Cómo queda con datos de ejemplo -->
        <div v-if="String(f[c.campo] ?? '').includes('{')" class="mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2">
          <p class="text-[10px] font-semibold text-gray-400 uppercase mb-0.5">Así queda (ejemplo)</p>
          <p class="text-xs text-gray-700 whitespace-pre-line break-words">{{ ejemplo(String(f[c.campo])) }}</p>
        </div>
      </template>
    </div>
  </div>
</template>
