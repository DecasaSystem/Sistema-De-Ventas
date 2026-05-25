<script setup>
import { ref, computed, watch } from 'vue'
import { COLOMBIA } from '@/data/colombia'
import { MapPinIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  departamento:  { type: String, default: '' },
  ciudad:        { type: String, default: '' },
  direccion:     { type: String, default: '' },
  requerida:     { type: Boolean, default: false },
})

const emit = defineEmits(['update:departamento', 'update:ciudad', 'update:direccion'])

const deptLocal   = ref(props.departamento)
const ciudadLocal = ref(props.ciudad)
const dirLocal    = ref(props.direccion)

watch(() => props.departamento, v => { deptLocal.value = v })
watch(() => props.ciudad,       v => { ciudadLocal.value = v })
watch(() => props.direccion,    v => { dirLocal.value = v })

const municipios = computed(() => {
  if (!deptLocal.value) return []
  return COLOMBIA.find(d => d.departamento === deptLocal.value)?.municipios ?? []
})

function onDept(e) {
  deptLocal.value   = e.target.value
  ciudadLocal.value = ''
  emit('update:departamento', deptLocal.value)
  emit('update:ciudad', '')
}

function onCiudad(e) {
  ciudadLocal.value = e.target.value
  emit('update:ciudad', ciudadLocal.value)
}

function onDir(e) {
  dirLocal.value = e.target.value
  emit('update:direccion', dirLocal.value)
}
</script>

<template>
  <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 space-y-3">

    <!-- Encabezado -->
    <div class="flex items-center gap-1.5">
      <MapPinIcon class="w-4 h-4 text-blue-500 flex-shrink-0" />
      <span class="text-sm font-semibold text-gray-700">Dirección de envío</span>
      <span v-if="!requerida" class="ml-auto text-xs text-gray-400 font-normal">opcional</span>
    </div>

    <!-- Departamento + Municipio en fila -->
    <div class="grid grid-cols-2 gap-2">

      <!-- Departamento -->
      <div class="space-y-1">
        <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Departamento</label>
        <div class="relative">
          <select
            :value="deptLocal"
            @change="onDept"
            class="w-full appearance-none rounded-lg border border-gray-300 bg-white px-3 py-2 pr-8 text-sm text-gray-800 shadow-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 transition"
          >
            <option value="">Elegir...</option>
            <option v-for="d in COLOMBIA" :key="d.departamento" :value="d.departamento">
              {{ d.departamento }}
            </option>
          </select>
          <ChevronDownIcon class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        </div>
      </div>

      <!-- Municipio -->
      <div class="space-y-1">
        <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Municipio</label>
        <div class="relative">
          <select
            :value="ciudadLocal"
            @change="onCiudad"
            :disabled="!deptLocal"
            class="w-full appearance-none rounded-lg border border-gray-300 bg-white px-3 py-2 pr-8 text-sm text-gray-800 shadow-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 transition disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed"
          >
            <option value="">{{ deptLocal ? 'Elegir...' : '—' }}</option>
            <option v-for="m in municipios" :key="m" :value="m">{{ m }}</option>
          </select>
          <ChevronDownIcon class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
        </div>
      </div>

    </div>

    <!-- Dirección específica -->
    <div class="space-y-1">
      <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">
        Dirección específica<span v-if="requerida" class="text-red-500 ml-0.5">*</span>
      </label>
      <input
        :value="dirLocal"
        @input="onDir"
        type="text"
        placeholder="Barrio, calle, número, piso, apartamento..."
        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 transition"
        maxlength="300"
      />
      <p v-if="deptLocal && ciudadLocal && !dirLocal && requerida"
        class="text-xs text-red-500 flex items-center gap-1">
        <span>La dirección específica es obligatoria</span>
      </p>
    </div>

    <!-- Resumen -->
    <div v-if="deptLocal && ciudadLocal"
      class="flex items-start gap-2 rounded-lg bg-blue-50 border border-blue-100 px-3 py-2">
      <MapPinIcon class="w-3.5 h-3.5 text-blue-500 flex-shrink-0 mt-0.5" />
      <p class="text-xs text-blue-700 leading-snug">
        <span class="font-semibold">{{ ciudadLocal }}, {{ deptLocal }}</span>
        <span v-if="dirLocal" class="text-blue-500"> · {{ dirLocal }}</span>
      </p>
    </div>

  </div>
</template>
