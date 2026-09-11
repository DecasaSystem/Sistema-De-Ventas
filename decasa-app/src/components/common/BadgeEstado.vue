<script setup>
import { computed } from 'vue'

const props = defineProps({
  estado: {
    type: String,
    required: true,
  },
  /**
   * Cómo va la entrega ({ total, entregados, parcial }). Si viene y va por
   * la mitad, se muestra al lado: "Entrega parcial 1/2". No es un estado
   * nuevo —la orden sigue en el suyo—, es lo que deja ver que el reloj ya
   * se lo llevaron y el mueble no.
   */
  entrega: {
    type: Object,
    default: null,
  },
})

const map = {
  borrador:             { label: 'Borrador',             cls: 'bg-gray-100 text-gray-500'   },
  pendiente_cotizacion: { label: 'Pendiente costo', cls: 'bg-violet-100 text-violet-800' },
  pendiente_anticipo: { label: 'En espera', cls: 'bg-yellow-100 text-yellow-800' },
  en_produccion: { label: 'En producción', cls: 'bg-blue-100 text-blue-800' },
  listo_entrega: { label: 'Listo entrega', cls: 'bg-emerald-100 text-emerald-800' },
  en_camino:   { label: 'En camino',   cls: 'bg-purple-100 text-purple-800' },
  // Volvió algo en el camión y falta decidir si se arregla o se cancela. En
  // naranja fuerte a propósito: es de lo poco que exige que alguien haga algo.
  devuelto:    { label: 'Devuelto',    cls: 'bg-orange-100 text-orange-800' },
  entregado: { label: 'Entregado', cls: 'bg-gray-100 text-gray-600' },
  cancelado: { label: 'Cancelado', cls: 'bg-red-100 text-red-800' },
  pendiente: { label: 'Pendiente', cls: 'bg-orange-100 text-orange-800' },
  asignado:   { label: 'Asignado',  cls: 'bg-purple-100 text-purple-800' },
  en_ruta:    { label: 'En ruta',   cls: 'bg-indigo-100 text-indigo-800' },
  completado: { label: 'Completado', cls: 'bg-emerald-100 text-emerald-800' },
}

const cfg = computed(() => map[props.estado] ?? { label: props.estado, cls: 'bg-gray-100 text-gray-600' })

const parcial = computed(() =>
  props.entrega?.parcial && !['entregado', 'cancelado'].includes(props.estado) ? props.entrega : null
)
</script>

<template>
  <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', cfg.cls]">
    {{ cfg.label }}
  </span>
  <span
    v-if="parcial"
    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-teal-100 text-teal-800"
    :title="`${parcial.entregados} de ${parcial.total} productos ya entregados`"
  >
    Entrega parcial {{ parcial.entregados }}/{{ parcial.total }}
  </span>
</template>
