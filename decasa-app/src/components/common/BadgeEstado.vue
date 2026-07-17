<script setup>
const props = defineProps({
  estado: {
    type: String,
    required: true,
  },
})

const map = {
  borrador:             { label: 'Borrador',             cls: 'bg-gray-100 text-gray-500'   },
  pendiente_cotizacion: { label: 'Pendiente cotización', cls: 'bg-violet-100 text-violet-800' },
  pendiente_anticipo: { label: 'En espera', cls: 'bg-yellow-100 text-yellow-800' },
  en_produccion: { label: 'En producción', cls: 'bg-blue-100 text-blue-800' },
  listo_entrega: { label: 'Listo entrega', cls: 'bg-emerald-100 text-emerald-800' },
  en_camino:   { label: 'En camino',   cls: 'bg-purple-100 text-purple-800' },
  entregado: { label: 'Entregado', cls: 'bg-gray-100 text-gray-600' },
  cancelado: { label: 'Cancelado', cls: 'bg-red-100 text-red-800' },
  pendiente: { label: 'Pendiente', cls: 'bg-orange-100 text-orange-800' },
  asignado:   { label: 'Asignado',  cls: 'bg-purple-100 text-purple-800' },
  en_ruta:    { label: 'En ruta',   cls: 'bg-indigo-100 text-indigo-800' },
  completado: { label: 'Completado', cls: 'bg-emerald-100 text-emerald-800' },
}

const cfg = map[props.estado] ?? { label: props.estado, cls: 'bg-gray-100 text-gray-600' }
</script>

<template>
  <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', cfg.cls]">
    {{ cfg.label }}
  </span>
</template>
