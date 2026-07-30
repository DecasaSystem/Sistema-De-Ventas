<script setup>
/**
 * Selector de tela: Marca → Tipo → Color, con foto del color y solo lo que
 * tiene metros en inventario.
 *
 * La cascada estaba copiada en nueva orden, en editar orden y —al principio—
 * ni existía al completar un borrador. Vive aquí una sola vez.
 *
 * `seleccion` es el objeto { marca, tipo, color } del padre y se muta en sitio:
 * así cada pantalla sigue guardando la tela donde ya la guardaba.
 */
import { computed } from 'vue'
import ComboInput from '@/components/common/ComboInput.vue'
import { useTelas } from '@/composables/useTelas'
import { useTelaFotos } from '@/composables/useTelaFotos'

const props = defineProps({
  seleccion: { type: Object, required: true },
  // Tela que el ítem ya tenía guardada, para no dejar al vendedor a ciegas.
  actual:    { type: String, default: '' },
  // Texto del resumen ("Tapizado / tela: Marca · Tipo · Color").
  etiqueta:  { type: String, default: 'Tela' },
})

const { cargarTelas, marcasConStock, tiposConStock, coloresConStock, metrosDeTela } = useTelas()
const { cargarFotosTela, fotosPorColor } = useTelaFotos()

cargarTelas()
cargarFotosTela()

const marcas  = computed(() => marcasConStock())
const tipos   = computed(() => props.seleccion.marca ? tiposConStock(props.seleccion.marca) : [])
const colores = computed(() =>
  props.seleccion.marca && props.seleccion.tipo
    ? coloresConStock(props.seleccion.marca, props.seleccion.tipo)
    : []
)
const imagenes = computed(() => fotosPorColor(props.seleccion.marca, props.seleccion.tipo, colores.value))

const resumen = computed(() => {
  const s = props.seleccion
  return s.marca && s.tipo && s.color ? [s.marca, s.tipo, s.color].join(' · ') : ''
})

const metros = computed(() => {
  const s = props.seleccion
  return resumen.value ? metrosDeTela(s.marca, s.tipo, s.color) : 0
})

const sinTelas = computed(() => !marcas.value.length)

function setMarca(v) {
  props.seleccion.marca = v
  props.seleccion.tipo  = ''
  props.seleccion.color = ''
}
function setTipo(v) {
  props.seleccion.tipo  = v
  props.seleccion.color = ''
}
</script>

<template>
  <div class="space-y-1">
    <p v-if="actual" class="text-xs text-gray-500">
      Actual: <span class="font-medium text-gray-700">{{ actual }}</span>
    </p>

    <!-- Se avisa, pero no se bloquea: el vendedor puede escribir una tela que
         todavía no esté cargada en inventario. -->
    <p v-if="sinTelas" class="text-xs text-red-600 italic">
      No hay telas con metros disponibles en inventario.
    </p>

    <ComboInput
      :model-value="seleccion.marca"
      :options="marcas"
      placeholder="Buscar marca..."
      @update:model-value="setMarca"
    />
    <ComboInput
      v-if="seleccion.marca"
      :model-value="seleccion.tipo"
      :options="tipos"
      placeholder="Buscar tipo de tela..."
      @update:model-value="setTipo"
    />
    <ComboInput
      v-if="seleccion.tipo"
      :model-value="seleccion.color"
      :options="colores"
      :images="imagenes"
      placeholder="Buscar color..."
      @update:model-value="v => seleccion.color = v"
    />

    <p v-if="resumen" class="text-xs text-purple-600 font-medium">
      {{ etiqueta }}: {{ resumen }}
      <span v-if="metros" class="text-gray-400 font-normal">· {{ metros }} m libres</span>
    </p>
  </div>
</template>
