<script setup>
/**
 * La ficha del mueble especial: lo que el cliente va a ver, aprobar y firmar
 * en la declaración de diseños especiales. Va en el lenguaje del cliente
 * ("cedro", "laca blanca mate", "240 cm"), no en el del taller.
 *
 * Los campos son los del papel: I. descripción (madera, tela, relleno,
 * pintura), II. medidas (ancho, largo, alto, profundidad) y III. tipo de
 * mueble. Ver utils/declaracionEspeciales.js.
 */
import { computed } from 'vue'
import { CAMPOS_FICHA, faltaEnFicha, fichaDesdeSpecs } from '@/utils/declaracionEspeciales'
import { DocumentCheckIcon, ArrowDownTrayIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  modelValue: { type: Object, default: null },
  // Para "Traer de las especificaciones".
  specs:      { type: Object, default: null },
  nombre:     { type: String, default: '' },
  bocetos:    { type: Number, default: 0 },
  // Ya firmada: avisar que cambiarla pide firmar otra vez.
  firmada:    { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const ficha = computed(() => props.modelValue ?? {})
const falta = computed(() => faltaEnFicha(ficha.value))

const descripcion = CAMPOS_FICHA.filter(c => c.grupo === 'descripcion' && c.key !== 'detalles')
const medidas     = CAMPOS_FICHA.filter(c => c.grupo === 'medidas')
const tipo        = CAMPOS_FICHA.find(c => c.key === 'tipo_mueble')
const detalles    = CAMPOS_FICHA.find(c => c.key === 'detalles')

function poner(key, v) {
  emit('update:modelValue', { ...ficha.value, [key]: v })
}

const hayQueTraer = computed(() => {
  const nueva = fichaDesdeSpecs(ficha.value, props.specs, props.nombre)
  return Object.keys(nueva).some(k => nueva[k] !== ficha.value[k])
})
function traer() {
  emit('update:modelValue', fichaDesdeSpecs(ficha.value, props.specs, props.nombre))
}
</script>

<template>
  <div class="rounded-xl border border-blue-200 bg-white p-3 space-y-3">
    <div class="flex items-start gap-2">
      <DocumentCheckIcon class="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5" />
      <div class="flex-1 min-w-0">
        <p class="text-xs font-bold text-gray-800">Ficha para el cliente · declaración de diseño especial</p>
        <p class="text-[11px] text-gray-500 leading-snug">
          El cliente ve esto con los bocetos, lo aprueba y lo firma. Escríbelo claro y completo: es lo que se acordó.
        </p>
      </div>
      <button
        v-if="hayQueTraer"
        type="button" @click="traer"
        class="flex items-center gap-1 text-[11px] font-semibold text-blue-600 whitespace-nowrap"
        title="Llenar con lo que ya está en las especificaciones"
      >
        <ArrowDownTrayIcon class="w-3.5 h-3.5" /> Traer specs
      </button>
    </div>

    <p v-if="firmada" class="text-[11px] text-amber-700 bg-amber-50 rounded-lg px-2 py-1.5">
      El cliente ya firmó esta ficha. Si la cambias, tiene que aprobarla y firmarla otra vez.
    </p>

    <div>
      <label class="text-[11px] font-semibold text-gray-600">III. {{ tipo.label }} *</label>
      <input
        :value="ficha[tipo.key] ?? ''" @input="poner(tipo.key, $event.target.value)"
        :placeholder="tipo.placeholder" maxlength="150" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm mt-0.5"
      />
    </div>

    <div>
      <p class="text-[11px] font-semibold text-gray-600">I. Descripción (al menos una) *</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-0.5">
        <div v-for="c in descripcion" :key="c.key">
          <label class="text-[11px] text-gray-500">{{ c.label }}</label>
          <input
            :value="ficha[c.key] ?? ''" @input="poner(c.key, $event.target.value)"
            :placeholder="c.placeholder" maxlength="200" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
          />
        </div>
      </div>
    </div>

    <div>
      <p class="text-[11px] font-semibold text-gray-600">II. Medidas (al menos dos) *</p>
      <div class="grid grid-cols-2 gap-2 mt-0.5">
        <div v-for="c in medidas" :key="c.key">
          <label class="text-[11px] text-gray-500">{{ c.label }}</label>
          <input
            :value="ficha[c.key] ?? ''" @input="poner(c.key, $event.target.value)"
            :placeholder="c.placeholder" maxlength="60" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
          />
        </div>
      </div>
    </div>

    <div>
      <label class="text-[11px] font-semibold text-gray-600">{{ detalles.label }}</label>
      <textarea
        :value="ficha[detalles.key] ?? ''" @input="poner(detalles.key, $event.target.value)"
        :placeholder="detalles.placeholder" rows="3" maxlength="3000" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm resize-y"
      />
    </div>

    <p :class="['text-[11px]', falta ? 'text-amber-700' : 'text-green-700']">
      <template v-if="falta">Falta {{ falta }}.</template>
      <template v-else>Ficha completa ✓<span v-if="!bocetos" class="text-gray-500"> · Si tienes un boceto, súbelo: el cliente también lo aprueba.</span></template>
    </p>
  </div>
</template>
