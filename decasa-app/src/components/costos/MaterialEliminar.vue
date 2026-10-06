<script setup>
/**
 * Eliminar un material del catálogo. Si alguna ficha lo usa, hay que decidir qué
 * pasa con esos ítems: pasarlos a otro material (recomendado) o dejarlos vacíos.
 */
import { ref, onMounted } from 'vue'
import { XMarkIcon, ArrowsRightLeftIcon } from '@heroicons/vue/24/outline'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import IconoS from '@/components/common/IconoS.vue'
import PickerMaterial from './PickerMaterial.vue'
import { useCostos } from './useCostos'

const props = defineProps({ material: { type: Object, required: true } })
const emit  = defineEmits(['cerrar', 'eliminado'])

const toast = useToast()
const { formatPeso, formatCantidad } = useCostos()

const usos       = ref([])
const cargando   = ref(true)
const modo       = ref('reemplazar')   // 'reemplazar' | 'vaciar'
const reemplazo  = ref(null)
const picker     = ref(false)
const eliminando = ref(false)

onMounted(async () => {
  try {
    const { data } = await api.get(`/materiales/${props.material.id}/usos`)
    usos.value = data.usos
  } finally {
    cargando.value = false
  }
})

async function eliminar() {
  eliminando.value = true
  try {
    await api.delete(`/materiales/${props.material.id}`, {
      data: { reemplazar_con_id: usos.value.length && modo.value === 'reemplazar' ? reemplazo.value?.id ?? null : null },
    })
    toast.success('Material eliminado')
    emit('eliminado', { id: props.material.id, afectados: usos.value.length })
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo eliminar el material.')
  } finally {
    eliminando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="emit('cerrar')">
      <div role="dialog" aria-modal="true" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 shrink-0">
          <div class="min-w-0">
            <h3 class="text-base font-bold text-gray-800">Eliminar material</h3>
            <p class="text-xs text-gray-400 truncate">{{ material.nombre }}</p>
          </div>
          <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 p-1" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
          <p v-if="cargando" class="text-sm text-gray-400">Buscando dónde se usa…</p>

          <p v-else-if="!usos.length" class="text-sm text-gray-600">Ninguna ficha usa este material. Se puede eliminar sin afectar ningún costo.</p>

          <template v-else>
            <div>
              <p class="text-sm text-gray-700 mb-1.5">Lo usan {{ usos.length }} {{ usos.length === 1 ? 'ítem' : 'ítems' }} de fichas técnicas:</p>
              <ul class="rounded-xl border border-gray-200 divide-y divide-gray-100 max-h-40 overflow-y-auto">
                <li v-for="u in usos" :key="u.item_id" class="flex items-center justify-between gap-2 px-3 py-2 text-xs">
                  <span class="text-gray-700 truncate">{{ u.ficha_nombre }}</span>
                  <span class="text-gray-500 flex-shrink-0 tabular-nums">{{ formatCantidad(u.cantidad) }} {{ u.unidad || 'und' }} · {{ formatPeso(u.subtotal) }}</span>
                </li>
              </ul>
            </div>

            <fieldset class="space-y-2">
              <legend class="text-sm font-semibold text-gray-700 mb-1.5">¿Qué pasa con esos ítems?</legend>
              <label :class="['flex gap-3 rounded-xl border-2 px-3 py-2.5 cursor-pointer', modo === 'reemplazar' ? 'border-blue-500' : 'border-gray-200']">
                <input v-model="modo" type="radio" value="reemplazar" class="mt-0.5 accent-blue-600" />
                <span class="flex-1 min-w-0">
                  <span class="block text-sm font-medium text-gray-800">Pasarlos a otro material</span>
                  <span class="block text-xs text-gray-500">Toman su nombre y su precio, y el costo se recalcula.</span>
                  <button v-if="modo === 'reemplazar'" type="button" @click="picker = true"
                    class="mt-2 w-full flex items-center justify-between gap-2 rounded-lg border border-gray-300 px-3 py-2 text-left text-sm hover:bg-gray-50">
                    <span v-if="reemplazo" class="min-w-0">
                      <span class="block font-medium text-gray-800 truncate">{{ reemplazo.nombre }}</span>
                      <span class="block text-xs text-gray-500">{{ formatPeso(reemplazo.precio_unitario) }} por {{ reemplazo.unidad || 'unidad' }}</span>
                    </span>
                    <span v-else class="text-gray-400">Elegir material…</span>
                    <ArrowsRightLeftIcon class="w-4 h-4 text-blue-600 flex-shrink-0" />
                  </button>
                </span>
              </label>
              <label :class="['flex gap-3 rounded-xl border-2 px-3 py-2.5 cursor-pointer', modo === 'vaciar' ? 'border-red-400' : 'border-gray-200']">
                <input v-model="modo" type="radio" value="vaciar" class="mt-0.5 accent-red-600" />
                <span>
                  <span class="block text-sm font-medium text-gray-800">Dejarlos vacíos</span>
                  <span class="block text-xs text-gray-500">Quedan sin nombre ni precio y las fichas aparecen para revisar.</span>
                </span>
              </label>
            </fieldset>
          </template>
        </div>

        <div class="flex gap-2 px-5 py-4 border-t border-gray-200 shrink-0">
          <button @click="emit('cerrar')" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
          <button @click="eliminar"
            :disabled="eliminando || cargando || (usos.length && modo === 'reemplazar' && !reemplazo)"
            class="flex-1 py-2.5 rounded-xl bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-40 flex items-center justify-center gap-1.5">
            <IconoS v-if="eliminando" class="w-4 h-4" />
            {{ eliminando ? 'Eliminando…' : 'Eliminar material' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <PickerMaterial v-if="picker" titulo="Pasar a otro material" :subtitulo="`En lugar de ${material.nombre}`" :excluir="material.id"
    @elegir="m => { reemplazo = m; picker = false }" @cerrar="picker = false" />
</template>
