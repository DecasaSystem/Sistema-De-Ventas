<script setup>
/**
 * Un producto de Inventario visto desde Costos: su receta, o la de cada medida.
 *
 * El producto manda el nombre, la foto, la categoría y el precio de venta: aquí solo se
 * arma el costo de fabricarlo. Sin receta está "en espera"; se crea de cero o se usa una
 * ficha que ya existía (las del Excel).
 *
 * Con medidas (o cualquier opción que cambie el precio), CADA una lleva su receta: una
 * cama de 1.60 no cuesta lo mismo que una de 1.40 (usuario, 2026-11-06). La que no la
 * tiene sigue en espera; lo más rápido es copiar la de otra medida y ajustar lo que
 * cambia. La receta general es opcional: plantilla y referencia del cotizador.
 */
import { ref, computed } from 'vue'
import { PlusIcon, DocumentDuplicateIcon, LinkIcon, ExclamationTriangleIcon, ChevronRightIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { duplicarFicha, vincularProducto } from '@/api/fichas'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import IconoS from '@/components/common/IconoS.vue'
import PanelCostos from './PanelCostos.vue'
import PickerFicha from './PickerFicha.vue'
import { useCostos } from './useCostos'

const props = defineProps({
  producto: { type: Object, required: true },
  sueltas:  { type: Array, default: () => [] },
})
const emit = defineEmits(['cerrar', 'abrir', 'crear', 'cambio'])

const toast = useToast()
const { formatPeso, formatFactor, margenDe, COLOR_MARGEN, TEXTO_MARGEN, porRevisar } = useCostos()

const conMedidas = computed(() => props.producto.versiones.length > 0)
const hechas     = computed(() => props.producto.versiones.filter(v => v.fichas.length).length)
const ocupado    = ref(null)          // clave del botón que está trabajando
const picker     = ref(null)          // { version } para enlazar una ficha existente
const eligiendo  = ref(null)          // config_id de la medida que está escogiendo de cuál copiar

function margen(f, precio) { return f ? margenDe(f.costo_total, precio) : null }

// De dónde se puede copiar: las medidas que ya tienen receta y la general
const fuentes = computed(() => [
  ...props.producto.versiones.filter(v => v.fichas[0]).map(v => ({ clave: `v${v.config_id}`, nombre: v.opcion, ficha: v.fichas[0] })),
  ...props.producto.fichas.slice(0, 1).map(f => ({ clave: 'general', nombre: 'la general', ficha: f })),
])

function crearDeCero(version = null) {
  emit('crear', { producto: props.producto, version, categoria_ficha: props.producto.categoria_ficha })
}

function pedirCopia(version) {
  if (fuentes.value.length === 1) copiar(version, fuentes.value[0])
  else eligiendo.value = eligiendo.value === version.config_id ? null : version.config_id
}

async function copiar(version, fuente) {
  ocupado.value = `copia-${version.config_id}`
  eligiendo.value = null
  try {
    // El nombre lo pone el servidor: el del producto con la medida
    const { data } = await duplicarFicha(fuente.ficha.id, null, {
      producto_id: props.producto.id, variante_config_id: version.config_id,
    })
    toast.success(`Receta de ${version.opcion} copiada de ${fuente.nombre}: ajusta lo que cambia`)
    emit('cambio')
    emit('abrir', data.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo copiar la receta.')
  } finally {
    ocupado.value = null
  }
}

async function enlazar(ficha) {
  const version = picker.value?.version ?? null
  ocupado.value = 'enlazar'
  try {
    await vincularProducto(ficha.id, props.producto.id, version?.config_id ?? null)
    toast.success(version ? `Ficha enlazada a ${version.opcion}` : 'Ficha enlazada al producto')
    picker.value = null
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo enlazar la ficha.')
  } finally {
    ocupado.value = null
  }
}

// Las fichas sueltas que se parecen a este producto (y medida) van primero
function sugeridasPara(version) {
  return props.sueltas
    .filter(f => (f.sugerencias ?? []).some(s => s.producto_id === props.producto.id
      && (version ? s.config_id === version.config_id : !s.config_id)))
    .map(f => f.id)
}
</script>

<template>
  <PanelCostos :titulo="producto.nombre" :subtitulo="producto.categoria?.toLowerCase()" @cerrar="emit('cerrar')">
    <div class="p-4 space-y-5 pb-10">
      <!-- Producto -->
      <div class="flex items-center gap-3">
        <img v-if="producto.foto_url" :src="cloudinaryOpt(producto.foto_url, 160)" alt=""
          class="w-16 h-16 rounded-xl object-cover border border-gray-200 flex-shrink-0" />
        <div v-else class="w-16 h-16 rounded-xl bg-gray-100 flex items-center justify-center text-gray-300 flex-shrink-0">
          <PhotoIcon class="w-7 h-7" />
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-xs text-gray-500">Precio de venta (con ganancia)</p>
          <p class="text-lg font-bold text-gray-900 tabular-nums">
            <template v-if="conMedidas">según la medida</template>
            <template v-else>{{ formatPeso(producto.precio_base) }}</template>
          </p>
          <p class="text-xs text-gray-400">Viene de Inventario. Aquí se arma el costo de fabricarlo.</p>
        </div>
      </div>

      <!-- Medidas: cada una con su receta -->
      <section v-if="conMedidas">
        <div class="flex items-baseline justify-between mb-0.5 px-1">
          <h3 class="text-sm font-semibold text-gray-700">Medidas</h3>
          <span :class="['text-xs font-semibold', hechas === producto.versiones.length ? 'text-green-700' : 'text-gray-500']">
            {{ hechas }} de {{ producto.versiones.length }} con receta
          </span>
        </div>
        <p class="text-xs text-gray-400 mb-1.5 px-1">
          Cada una lleva su receta: no todas cuestan lo mismo. Copia la de otra medida y ajusta lo que cambia.
        </p>

        <div class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
          <div v-for="v in producto.versiones" :key="v.config_id" class="px-3 py-3 space-y-2">
            <div class="flex items-center gap-3">
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-800">{{ v.opcion }} <span class="text-xs font-normal text-gray-400">{{ v.tipo.toLowerCase() }}</span></p>
                <p class="text-xs text-gray-500">venta {{ formatPeso(v.precio) }}</p>
              </div>
              <button v-if="v.fichas.length" @click="emit('abrir', v.fichas[0].id)" class="text-right flex items-center gap-2">
                <span>
                  <span class="block text-[11px] text-gray-400 leading-none">fabricar</span>
                  <span class="block text-sm font-bold text-gray-900 tabular-nums">{{ formatPeso(v.fichas[0].costo_total) }}</span>
                  <span v-if="porRevisar(v.fichas[0]) || !v.fichas[0].costo_total"
                    class="inline-flex items-center gap-0.5 text-[11px] font-semibold rounded-full px-1.5 bg-amber-100 text-amber-800 mr-1"
                    title="Tiene ítems para revisar">
                    <ExclamationTriangleIcon class="w-3 h-3" />
                  </span>
                  <span v-if="margen(v.fichas[0], v.precio)"
                    :class="['text-[11px] font-semibold rounded-full px-1.5 tabular-nums', COLOR_MARGEN[margen(v.fichas[0], v.precio).nivel]]"
                    :title="TEXTO_MARGEN[margen(v.fichas[0], v.precio).nivel]">
                    {{ formatFactor(margen(v.fichas[0], v.precio).factor) }}
                  </span>
                </span>
                <ChevronRightIcon class="w-4 h-4 text-gray-300" />
              </button>
              <span v-else class="text-[11px] font-semibold rounded-full px-2 py-0.5 bg-gray-100 text-gray-500">En espera</span>
            </div>

            <p v-if="v.repetida" class="flex items-center gap-1 text-xs text-amber-700">
              <ExclamationTriangleIcon class="w-3.5 h-3.5" />Tiene {{ v.fichas.length }} recetas: deja una sola.
            </p>

            <template v-if="!v.fichas.length">
              <div class="flex flex-wrap gap-x-4 gap-y-1">
                <button v-if="fuentes.length" @click="pedirCopia(v)" :disabled="!!ocupado"
                  class="flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 disabled:opacity-50">
                  <IconoS v-if="ocupado === `copia-${v.config_id}`" class="w-3.5 h-3.5" />
                  <DocumentDuplicateIcon v-else class="w-3.5 h-3.5" />
                  {{ fuentes.length === 1 ? `Copiar de ${fuentes[0].nombre}` : 'Copiar de otra' }}
                </button>
                <button @click="crearDeCero(v)" class="flex items-center gap-1 text-xs font-semibold text-gray-600 hover:text-gray-800">
                  <PlusIcon class="w-3.5 h-3.5" />Empezar de cero
                </button>
                <button v-if="sueltas.length" @click="picker = { version: v }" class="flex items-center gap-1 text-xs font-semibold text-gray-600 hover:text-gray-800">
                  <LinkIcon class="w-3.5 h-3.5" />Usar una existente
                </button>
              </div>
              <!-- De cuál copiar, cuando hay varias -->
              <div v-if="eligiendo === v.config_id" class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs text-gray-500">Copiar de:</span>
                <button v-for="f in fuentes" :key="f.clave" @click="copiar(v, f)"
                  class="px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700 hover:bg-gray-200">
                  {{ f.nombre }} · {{ formatPeso(f.ficha.costo_total) }}
                </button>
              </div>
            </template>
          </div>
        </div>
      </section>

      <!-- Receta general (sin medidas: la receta del producto) -->
      <section>
        <h3 class="text-sm font-semibold text-gray-700 mb-0.5 px-1">
          {{ conMedidas ? 'Receta general (opcional)' : 'Receta del producto' }}
        </h3>
        <p v-if="conMedidas" class="text-xs text-gray-400 mb-1.5 px-1">
          No es el costo de ninguna medida. Sirve para copiarla a las medidas y de referencia al cotizar cuando no se sabe la medida.
        </p>

        <div v-if="producto.repetida" class="flex gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 mb-2">
          <ExclamationTriangleIcon class="w-4 h-4 flex-shrink-0 mt-0.5" />
          <span>Tiene {{ producto.fichas.length }} recetas. Deja una sola: Finanzas las promedia y Márgenes las cuenta dos veces.
            Abre la que sobra y quítale el producto.</span>
        </div>

        <div v-if="producto.fichas.length" class="bg-white rounded-xl border border-gray-200 divide-y divide-gray-100 overflow-hidden">
          <button v-for="f in producto.fichas" :key="f.id" @click="emit('abrir', f.id)"
            class="w-full flex items-center gap-3 px-3 py-3 hover:bg-gray-50 text-left">
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-800 truncate">{{ f.nombre }}</p>
              <p v-if="porRevisar(f)" class="flex items-center gap-1 text-xs text-amber-700 mt-0.5">
                <ExclamationTriangleIcon class="w-3.5 h-3.5" />{{ porRevisar(f) }} ítem(s) para revisar
              </p>
              <p v-else-if="!f.costo_total" class="text-xs text-amber-700 mt-0.5">Sin costo todavía</p>
            </div>
            <div class="text-right flex-shrink-0">
              <p class="text-[11px] text-gray-400 leading-none">{{ conMedidas ? 'general' : 'costo de fabricar' }}</p>
              <p class="text-sm font-bold text-gray-900 tabular-nums">{{ formatPeso(f.costo_total) }}</p>
              <!-- Con medidas no hay un solo precio de venta: sin margen aquí -->
              <span v-if="!conMedidas && margen(f, producto.precio_base)"
                :class="['text-[11px] font-semibold rounded-full px-1.5 tabular-nums', COLOR_MARGEN[margen(f, producto.precio_base).nivel]]"
                :title="TEXTO_MARGEN[margen(f, producto.precio_base).nivel]">
                {{ formatFactor(margen(f, producto.precio_base).factor) }}
              </span>
            </div>
            <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
          </button>
        </div>

        <div v-else-if="conMedidas" class="flex flex-wrap gap-x-4 gap-y-1 px-1">
          <button @click="crearDeCero()" class="flex items-center gap-1 text-xs font-semibold text-gray-600 hover:text-gray-800">
            <PlusIcon class="w-3.5 h-3.5" />Crear receta general
          </button>
          <button v-if="sueltas.length" @click="picker = { version: null }" class="flex items-center gap-1 text-xs font-semibold text-gray-600 hover:text-gray-800">
            <LinkIcon class="w-3.5 h-3.5" />Usar una existente
          </button>
        </div>

        <div v-else class="rounded-xl border border-dashed border-gray-300 p-4 space-y-3">
          <p class="text-sm text-gray-600">
            En espera: todavía no se sabe cuánto cuesta fabricarlo. Ponle sus materiales y su mano de obra.
          </p>
          <div class="flex flex-wrap gap-2">
            <button @click="crearDeCero()"
              class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold hover:bg-blue-700">
              <PlusIcon class="w-3.5 h-3.5" />Crear receta
            </button>
            <button v-if="sueltas.length" @click="picker = { version: null }"
              class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200">
              <LinkIcon class="w-3.5 h-3.5" />Usar una ficha que ya existe
            </button>
          </div>
        </div>
      </section>
    </div>
  </PanelCostos>

  <PickerFicha v-if="picker" :fichas="sueltas" :primero="sugeridasPara(picker.version)" :ocupado="ocupado === 'enlazar'"
    :subtitulo="picker.version ? `Para ${producto.nombre} · ${picker.version.opcion}` : `Para ${producto.nombre}`"
    @elegir="enlazar" @cerrar="picker = null" />
</template>
