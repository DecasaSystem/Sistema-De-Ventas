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
import { computed, ref, watch } from 'vue'
import api from '@/api'
import ComboInput from '@/components/common/ComboInput.vue'
import { useTelas } from '@/composables/useTelas'
import { useTelaFotos } from '@/composables/useTelaFotos'

const props = defineProps({
  seleccion: { type: Object, required: true },
  // Tela que el ítem ya tenía guardada, para no dejar al vendedor a ciegas.
  actual:    { type: String, default: '' },
  // Texto del resumen ("Tapizado / tela: Marca · Tipo · Color").
  etiqueta:  { type: String, default: 'Tela' },
  // Qué se va a tapizar con esta tela (y cuántos). Con esto, y el descuento
  // automático encendido en Telas, se dice al elegirla si los metros
  // alcanzan — no al final, cuando ya se armó toda la orden.
  productoId: { type: [Number, String], default: null },
  configId:   { type: [Number, String], default: null },
  cantidad:   { type: [Number, String], default: 1 },
})

// { metros_necesarios, metros, suficiente } de la tela elegida, o null si el
// servidor no sabe cuánto gasta el producto (función apagada o sin metros).
const necesidad = ref(null)
let   pedido    = 0

async function consultarNecesidad() {
  const s = props.seleccion
  const completa = s.marca && s.tipo && s.color && s.marca !== 'Otro' && s.tipo !== 'Otro' && s.color !== 'Otro'
  if (!completa || !props.productoId) { necesidad.value = null; return }
  const n = ++pedido
  try {
    const { data } = await api.get('/inventario-telas/validar', {
      params: {
        marca: s.marca, tipo: s.tipo, color: s.color,
        producto_id: props.productoId, config_id: props.configId || undefined,
        cantidad: Math.max(1, Number(props.cantidad) || 1),
      },
    })
    // Solo vale la última consulta: cambiar rápido de color no debe dejar
    // pintado el aviso de la anterior.
    if (n !== pedido) return
    necesidad.value = data.metros_necesarios != null ? data : null
  } catch {
    if (n === pedido) necesidad.value = null
  }
}

watch(
  () => [props.seleccion.marca, props.seleccion.tipo, props.seleccion.color, props.productoId, props.configId, props.cantidad],
  consultarNecesidad,
  { immediate: true },
)

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

    <!-- Cuánto gasta el producto contra lo que hay. En rojo si no alcanza:
         el vendedor lo ve al elegir la tela, no al final al crear la orden. -->
    <p
      v-if="resumen && necesidad"
      :class="['text-xs font-semibold rounded-lg px-2.5 py-1.5 border', necesidad.suficiente
        ? 'bg-green-50 border-green-200 text-green-700'
        : 'bg-red-50 border-red-200 text-red-700']"
    >
      <template v-if="necesidad.suficiente">
        ✓ Necesita {{ necesidad.metros_necesarios }} m y hay {{ necesidad.metros }} m libres.
      </template>
      <template v-else>
        ✕ No alcanza: necesita {{ necesidad.metros_necesarios }} m y solo hay {{ necesidad.metros }} m libres.
        Elige otra tela o recarga el inventario de telas.
      </template>
    </p>
  </div>
</template>
