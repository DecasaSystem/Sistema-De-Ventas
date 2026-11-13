<script setup>
/**
 * Avisos de datos del cliente mal escritos (empresa con cédula, NIT con el
 * dígito pegado, "gmail.con"…) con un botón que los corrige. La regla está en
 * utils/revisarCliente.js. Quien lo usa decide qué hacer con el arreglo
 * (@aplicar): llenarlo en el formulario o guardarlo en el cliente.
 */
import { computed } from 'vue'
import { revisarCliente } from '@/utils/revisarCliente'
import { ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  cliente:   { type: Object, default: null },
  ocupado:   { type: String, default: '' }, // código del aviso que se está guardando
})
const emit = defineEmits(['aplicar'])

const avisos = computed(() => props.cliente ? revisarCliente(props.cliente) : [])
</script>

<template>
  <div v-if="avisos.length" class="space-y-1.5">
    <div v-for="a in avisos" :key="a.codigo"
      :class="['rounded-lg border px-3 py-2 text-xs',
        a.tipo === 'bloqueo' ? 'border-red-200 text-red-700' : a.tipo === 'advertencia' ? 'border-amber-200 text-amber-800' : 'border-gray-200 text-gray-600']">
      <p class="flex gap-1.5">
        <component :is="a.tipo === 'info' ? InformationCircleIcon : ExclamationTriangleIcon" class="w-4 h-4 shrink-0" />
        <span>{{ a.mensaje }}</span>
      </p>
      <button v-if="a.arreglo" type="button" @click="emit('aplicar', a)" :disabled="ocupado === a.codigo"
        :class="['mt-1.5 ml-[22px] px-2.5 py-1 rounded-lg text-xs font-semibold text-white disabled:opacity-50',
          a.tipo === 'bloqueo' ? 'bg-red-600' : a.tipo === 'advertencia' ? 'bg-amber-600' : 'bg-blue-600']">
        {{ ocupado === a.codigo ? 'Corrigiendo…' : `Corregir: ${a.arreglo.texto}` }}
      </button>
    </div>
  </div>
</template>
