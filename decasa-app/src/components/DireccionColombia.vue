<script setup>
import { ref, computed, watch } from 'vue'
import { COLOMBIA } from '@/data/colombia'
import { MapPinIcon } from '@heroicons/vue/24/outline'

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
  <div class="space-y-2">
    <div class="flex items-center gap-1.5 mb-0.5">
      <MapPinIcon class="w-4 h-4 text-blue-500" />
      <span class="text-sm font-medium text-gray-700">Dirección de envío</span>
      <span v-if="!requerida" class="text-xs text-gray-400">(opcional)</span>
    </div>

    <!-- Departamento -->
    <select
      :value="deptLocal"
      @change="onDept"
      class="input text-sm"
    >
      <option value="">Seleccionar departamento...</option>
      <option v-for="d in COLOMBIA" :key="d.departamento" :value="d.departamento">
        {{ d.departamento }}
      </option>
    </select>

    <!-- Municipio -->
    <select
      :value="ciudadLocal"
      @change="onCiudad"
      :disabled="!deptLocal"
      class="input text-sm disabled:opacity-50 disabled:cursor-not-allowed"
    >
      <option value="">{{ deptLocal ? 'Seleccionar municipio...' : 'Primero elige el departamento' }}</option>
      <option v-for="m in municipios" :key="m" :value="m">{{ m }}</option>
    </select>

    <!-- Dirección específica -->
    <div>
      <input
        :value="dirLocal"
        @input="onDir"
        type="text"
        :placeholder="requerida
          ? 'Barrio, calle, número, piso, apto... *'
          : 'Barrio, calle, número, piso, apto... (opcional)'"
        class="input text-sm"
        maxlength="300"
      />
      <p v-if="deptLocal && ciudadLocal && !dirLocal && requerida"
        class="text-xs text-red-500 mt-0.5">
        La dirección específica es obligatoria
      </p>
    </div>

    <!-- Resumen -->
    <div v-if="deptLocal && ciudadLocal"
      class="flex items-start gap-2 bg-blue-50 border border-blue-100 rounded-lg px-3 py-2">
      <MapPinIcon class="w-4 h-4 text-blue-500 flex-shrink-0 mt-0.5" />
      <p class="text-xs text-blue-700">
        <span class="font-medium">{{ ciudadLocal }}, {{ deptLocal }}</span>
        <span v-if="dirLocal"> · {{ dirLocal }}</span>
      </p>
    </div>
  </div>
</template>
