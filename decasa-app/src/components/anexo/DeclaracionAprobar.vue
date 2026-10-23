<script setup>
/**
 * La declaración de diseños especiales, del lado del cliente: cada mueble
 * fuera del catálogo como lo escribió el asesor (descripción, medidas, tipo)
 * con sus bocetos, para aprobarlo uno por uno. Después, la constancia que
 * firma ("suministré las medidas… los exonero…").
 *
 * Va dentro de AnexoFirma: es la misma firma. Aprobar es por producto a
 * propósito: lo que se acepta es ESE diseño, no "todo lo de arriba".
 */
import { useVisorFoto } from '@/composables/useVisorFoto'
import { CheckCircleIcon, ChevronDownIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  declaracion: { type: Object, required: true },   // { contenido, productos: [{ nombre, cantidad, tipo, grupos, bocetos }] }
  aprobados:   { type: Set, required: true },
  abiertos:    { type: Set, required: true },
  // En la página del cliente: "Algo no está bien" le escribe al asesor.
  puedePedirCambio: { type: Boolean, default: false },
})
const emit = defineEmits(['aprobar', 'alternar', 'pedir-cambio'])

const { abrirFoto } = useVisorFoto()
const ROMANOS = { descripcion: 'I', medidas: 'II', tipo: 'III' }
const ROTULOS = {
  descripcion: props.declaracion.contenido.rotulo_descripcion,
  medidas:     props.declaracion.contenido.rotulo_medidas,
  tipo:        props.declaracion.contenido.rotulo_tipo,
}
</script>

<template>
  <section class="rounded-xl border border-gray-200 bg-white overflow-hidden">
    <div class="px-4 py-3 border-b border-gray-100 space-y-1">
      <p class="text-[1.05em] font-bold text-gray-800 leading-snug">{{ declaracion.contenido.titulo }}</p>
      <p class="text-[0.9em] text-gray-600 whitespace-pre-line">{{ declaracion.contenido.introduccion }}</p>
    </div>

    <div class="p-3 space-y-3">
      <article
        v-for="(p, i) in declaracion.productos" :key="i"
        :data-pendiente="`p-${i}`"
        :class="['rounded-xl border transition-colors', aprobados.has(i) ? 'border-green-200 bg-green-50/40' : 'border-gray-200 bg-white']"
      >
        <button type="button" @click="emit('alternar', i)" class="w-full flex items-center gap-3 px-3 py-3 text-left">
          <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0',
            aprobados.has(i) ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-500']">
            <CheckCircleIcon v-if="aprobados.has(i)" class="w-4 h-4" />
            <template v-else>{{ i + 1 }}</template>
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-[1.05em] font-semibold text-gray-800">{{ p.nombre }}<span v-if="p.cantidad && p.cantidad != 1" class="font-normal text-gray-500"> × {{ p.cantidad }}</span></span>
            <span v-if="p.tipo" class="block text-[0.8em] font-semibold text-blue-700">{{ p.tipo }}</span>
          </span>
          <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', abiertos.has(i) ? 'rotate-180' : '']" />
        </button>

        <div v-if="abiertos.has(i)" class="px-3 pb-3 space-y-3">
          <div v-for="g in ['descripcion', 'medidas', 'tipo']" :key="g">
            <p class="text-[0.8em] font-bold uppercase tracking-wide text-gray-600">{{ ROMANOS[g] }}. {{ ROTULOS[g] }}</p>
            <dl v-if="p.grupos[g]?.length" class="mt-1 rounded-lg bg-gray-50 px-3 py-2 space-y-1 text-[0.95em]">
              <div v-for="d in p.grupos[g]" :key="d.campo" class="flex gap-2">
                <dt class="text-gray-600 flex-shrink-0 min-w-[5.5rem]">{{ d.label }}</dt>
                <dd class="text-gray-800 whitespace-pre-wrap break-words min-w-0">{{ d.value }}</dd>
              </div>
            </dl>
            <p v-else class="mt-1 text-[0.9em] text-gray-400">—</p>
          </div>

          <div v-if="p.bocetos?.length">
            <p class="text-[0.8em] font-bold uppercase tracking-wide text-gray-600">Bocetos</p>
            <div class="mt-1 flex flex-wrap gap-2">
              <button
                v-for="(b, j) in p.bocetos" :key="b" type="button"
                @click="abrirFoto(p.bocetos, { indice: j, nombre: `Boceto ${p.nombre}` })"
              >
                <img :src="b" alt="Boceto" class="w-24 h-24 rounded-lg object-cover border border-gray-200" />
              </button>
            </div>
            <p class="text-[0.8em] text-gray-600 mt-1">Toca un boceto para verlo en grande.</p>
          </div>

          <button
            type="button" @click="emit('aprobar', i)"
            :class="['w-full rounded-lg py-2.5 text-[1em] font-semibold border transition-colors',
              aprobados.has(i) ? 'bg-white border-gray-300 text-gray-600' : 'bg-green-600 border-green-600 text-white hover:bg-green-700']"
          >{{ aprobados.has(i) ? 'Quitar la aprobación' : `${declaracion.contenido.aprobacion} ✓` }}</button>
          <button
            v-if="puedePedirCambio && !aprobados.has(i)"
            type="button" @click="emit('pedir-cambio', p.nombre)"
            class="w-full flex items-center justify-center gap-1.5 rounded-lg py-2 text-[0.95em] font-semibold text-gray-600 border border-gray-300 bg-white"
          >
            <PencilSquareIcon class="w-4 h-4" /> Algo no está como lo quiero
          </button>
        </div>
      </article>

      <div class="rounded-lg bg-gray-50 px-3 py-3 space-y-2">
        <p class="text-[0.95em] leading-relaxed text-gray-700 whitespace-pre-line">{{ declaracion.contenido.constancia }}</p>
        <p v-if="declaracion.contenido.cierre" class="text-[0.95em] text-gray-700">{{ declaracion.contenido.cierre }}</p>
      </div>
    </div>
  </section>
</template>
