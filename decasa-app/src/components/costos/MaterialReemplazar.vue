<script setup>
/**
 * Reemplazar un material por otro en las fichas que se escojan, sin eliminarlo.
 *
 * Pedido del usuario (2026-11-10): "creo un material nuevo para reemplazar otro en algunos
 * productos, para no tener que ir producto por producto". Se entra al material viejo, se
 * marcan las fichas donde se usa y se escoge el nuevo. Las fichas que no se marquen siguen
 * con el viejo, y el viejo se queda en el catálogo.
 */
import { ref, computed, onMounted } from 'vue'
import { XMarkIcon, ArrowsRightLeftIcon } from '@heroicons/vue/24/outline'
import api from '@/api'
import { reemplazarMaterial } from '@/api/materiales'
import { useToast } from '@/composables/useToast'
import IconoS from '@/components/common/IconoS.vue'
import PickerMaterial from './PickerMaterial.vue'
import { useCostos } from './useCostos'

const props = defineProps({ material: { type: Object, required: true } })
const emit  = defineEmits(['cerrar', 'reemplazado'])

const toast = useToast()
const { formatPeso, formatCantidad } = useCostos()

const usos         = ref([])
const cargando     = ref(true)
const reemplazo    = ref(null)
const picker       = ref(false)
const guardando    = ref(false)
const escogidas    = ref(new Set())

onMounted(async () => {
  try {
    const { data } = await api.get(`/materiales/${props.material.id}/usos`)
    usos.value = data.usos
  } catch {
    toast.error('No se pudo ver dónde se usa este material.')
  } finally {
    cargando.value = false
  }
})

// Una fila por ficha (una ficha puede usar el material en varias secciones)
const fichas = computed(() => {
  const porFicha = new Map()
  for (const u of usos.value) {
    if (!porFicha.has(u.ficha_id)) {
      porFicha.set(u.ficha_id, { id: u.ficha_id, nombre: u.ficha_nombre, categoria: u.ficha_categoria,
        costo: Number(u.ficha_costo) || 0, items: [] })
    }
    porFicha.get(u.ficha_id).items.push(u)
  }
  return [...porFicha.values()]
})

const todas = computed(() => fichas.value.length > 0 && escogidas.value.size === fichas.value.length)

function alternar(id) {
  const s = new Set(escogidas.value)
  s.has(id) ? s.delete(id) : s.add(id)
  escogidas.value = s
}
function alternarTodas() {
  escogidas.value = todas.value ? new Set() : new Set(fichas.value.map(f => f.id))
}

// Cuánto queda costando la ficha con el material nuevo (misma cantidad, precio del nuevo)
function diferencia(f) {
  if (!reemplazo.value) return 0
  const precio = Number(reemplazo.value.precio_unitario) || 0
  return f.items.reduce((s, u) => s + Number(u.cantidad) * precio - Number(u.subtotal), 0)
}
const diferenciaTotal = computed(() => fichas.value.filter(f => escogidas.value.has(f.id)).reduce((s, f) => s + diferencia(f), 0))

// Las cantidades no se convierten: 3 metros pasarían a ser 3 láminas
const norm = u => (u || '').trim().toUpperCase()
const unidadDistinta = computed(() => reemplazo.value
  && usos.value.some(u => escogidas.value.has(u.ficha_id) && norm(u.unidad || props.material.unidad) !== norm(reemplazo.value.unidad)))

async function reemplazar() {
  guardando.value = true
  try {
    const { data } = await reemplazarMaterial(props.material.id, {
      reemplazar_con_id: reemplazo.value.id,
      fichas: [...escogidas.value],
    })
    toast.success(`${data.fichas} ${data.fichas === 1 ? 'ficha pasó' : 'fichas pasaron'} a ${reemplazo.value.nombre}`)
    emit('reemplazado', { afectados: data.fichas })
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo reemplazar el material.')
  } finally {
    guardando.value = false
  }
}

const signo = n => (n > 0 ? '+' : n < 0 ? '−' : '') + formatPeso(Math.abs(n))
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="emit('cerrar')">
      <div role="dialog" aria-modal="true" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 shrink-0">
          <div class="min-w-0">
            <h3 class="text-base font-bold text-gray-800">Reemplazar en fichas</h3>
            <p class="text-xs text-gray-400 truncate">{{ material.nombre }} · {{ formatPeso(material.precio_unitario) }} por {{ material.unidad || 'unidad' }}</p>
          </div>
          <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 p-1" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
          <p v-if="cargando" class="text-sm text-gray-400">Buscando dónde se usa…</p>
          <p v-else-if="!fichas.length" class="text-sm text-gray-600">Ninguna ficha usa este material.</p>

          <template v-else>
            <div>
              <p class="text-sm font-semibold text-gray-700 mb-1.5">Pasar a</p>
              <button type="button" @click="picker = true"
                class="w-full flex items-center justify-between gap-2 rounded-lg border border-gray-300 px-3 py-2 text-left text-sm hover:bg-gray-50">
                <span v-if="reemplazo" class="min-w-0">
                  <span class="block font-medium text-gray-800 truncate">{{ reemplazo.nombre }}</span>
                  <span class="block text-xs text-gray-500">{{ formatPeso(reemplazo.precio_unitario) }} por {{ reemplazo.unidad || 'unidad' }}</span>
                </span>
                <span v-else class="text-gray-400">Elegir el material nuevo…</span>
                <ArrowsRightLeftIcon class="w-4 h-4 text-blue-600 flex-shrink-0" />
              </button>
            </div>

            <div>
              <div class="flex items-center justify-between gap-2 mb-1.5">
                <p class="text-sm font-semibold text-gray-700">¿En cuáles fichas?</p>
                <button type="button" @click="alternarTodas" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                  {{ todas ? 'Quitar todas' : `Todas (${fichas.length})` }}
                </button>
              </div>
              <ul class="rounded-xl border border-gray-200 divide-y divide-gray-100">
                <li v-for="f in fichas" :key="f.id">
                  <label class="flex items-start gap-3 px-3 py-2.5 cursor-pointer hover:bg-gray-50">
                    <input type="checkbox" :checked="escogidas.has(f.id)" @change="alternar(f.id)" class="mt-0.5 accent-blue-600 w-4 h-4" />
                    <span class="flex-1 min-w-0">
                      <span class="block text-sm text-gray-800 truncate">{{ f.nombre }}</span>
                      <span class="block text-xs text-gray-500 tabular-nums">
                        <template v-for="(u, i) in f.items" :key="u.item_id">{{ i ? ' + ' : '' }}{{ formatCantidad(u.cantidad) }} {{ u.unidad || 'und' }}</template>
                        · {{ formatPeso(f.costo) }}
                      </span>
                    </span>
                    <span v-if="reemplazo && escogidas.has(f.id) && Math.round(diferencia(f))"
                      :class="['text-xs font-semibold tabular-nums flex-shrink-0', diferencia(f) > 0 ? 'text-red-600' : 'text-green-600']">
                      {{ signo(diferencia(f)) }}
                    </span>
                  </label>
                </li>
              </ul>
            </div>

            <p v-if="unidadDistinta" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
              El material nuevo se compra por «{{ reemplazo.unidad || 'unidad' }}» y en esas fichas está por otra unidad.
              Las cantidades se quedan como están (no se convierten): revísalas después.
            </p>
            <p v-if="reemplazo && escogidas.size" class="text-xs text-gray-500">
              Cada ficha toma el nombre y el precio de {{ reemplazo.nombre }} con la misma cantidad.
              <template v-if="Math.round(diferenciaTotal)">En total: <strong :class="diferenciaTotal > 0 ? 'text-red-600' : 'text-green-600'">{{ signo(diferenciaTotal) }}</strong>.</template>
              Las fichas que no marques siguen con {{ material.nombre }}.
            </p>
          </template>
        </div>

        <div class="flex gap-2 px-5 py-4 border-t border-gray-200 shrink-0">
          <button @click="emit('cerrar')" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
          <button @click="reemplazar" :disabled="guardando || !reemplazo || !escogidas.size"
            class="flex-1 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-40 flex items-center justify-center gap-1.5">
            <IconoS v-if="guardando" class="w-4 h-4" />
            {{ guardando ? 'Reemplazando…' : escogidas.size ? `Reemplazar en ${escogidas.size}` : 'Reemplazar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <PickerMaterial v-if="picker" titulo="Reemplazar por" :subtitulo="`En lugar de ${material.nombre}`" :excluir="material.id"
    @elegir="m => { reemplazo = m; picker = false }" @cerrar="picker = false" />
</template>
