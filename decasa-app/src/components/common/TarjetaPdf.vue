<script setup>
/**
 * Un PDF adjunto (comprobante de pago, anexo escaneado) donde iría la foto.
 * No se puede pintar con <img>: se muestra como tarjeta y, si hay enlace, se
 * abre en otra pestaña (`data-sin-visor`: no es una foto para el visor).
 */
import { DocumentTextIcon } from '@heroicons/vue/24/outline'

defineProps({
  href:      { type: String, default: '' },   // enlace subido o blob: local
  nombre:    { type: String, default: '' },
  compacta:  { type: Boolean, default: false }, // en una grilla de varias
})
</script>

<template>
  <component
    :is="href ? 'a' : 'div'"
    :href="href || undefined"
    :target="href ? '_blank' : undefined"
    :rel="href ? 'noopener' : undefined"
    :data-sin-visor="href ? '' : undefined"
    :class="['flex items-center w-full rounded-xl border-2 border-gray-200 bg-white px-3',
             compacta ? 'h-32 flex-col justify-center text-center gap-1.5 py-2' : 'gap-3 py-4']"
  >
    <span class="shrink-0 w-10 h-10 rounded-lg bg-red-600 text-white flex items-center justify-center">
      <DocumentTextIcon class="w-6 h-6" />
    </span>
    <span class="min-w-0 max-w-full">
      <span class="block text-sm font-semibold text-gray-700 truncate">{{ nombre || 'Documento PDF' }}</span>
      <span class="block text-xs text-gray-400 truncate">PDF{{ href ? ' · toca para abrirlo' : '' }}</span>
    </span>
  </component>
</template>
