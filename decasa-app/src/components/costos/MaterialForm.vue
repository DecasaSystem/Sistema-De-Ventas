<script setup>
/**
 * Crear o editar un material del catálogo. Al cambiar el precio se recalculan
 * todas las fichas que lo usan, y el cambio queda en el historial de abajo.
 */
import { ref, computed, onMounted } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { crearMaterial, actualizarMaterial, getHistorialMaterial } from '@/api/materiales'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import IconoS from '@/components/common/IconoS.vue'
import { formatPct } from '@/utils/descuentos'
import { useCostos } from './useCostos'

const props = defineProps({ material: { type: Object, default: null } })
const emit  = defineEmits(['cerrar', 'guardado'])

const toast = useToast()
const { formatPeso, formatFecha } = useCostos()

const esNuevo = !props.material
const form = ref({
  nombre:          props.material?.nombre ?? '',
  unidad:          props.material?.unidad ?? '',
  descripcion:     props.material?.descripcion ?? '',
  precio_unitario: props.material ? Number(props.material.precio_unitario) || 0 : null,
})
const guardando = ref(false)

const usos          = computed(() => Number(props.material?.usos) || 0)
const cambiaPrecio  = computed(() => !esNuevo && Number(form.value.precio_unitario) !== Number(props.material.precio_unitario))

const historial         = ref([])
const cargandoHistorial = ref(false)

onMounted(async () => {
  if (esNuevo) return
  cargandoHistorial.value = true
  try {
    const { data } = await getHistorialMaterial(props.material.id)
    historial.value = data
  } catch {
    historial.value = []
  } finally {
    cargandoHistorial.value = false
  }
})

async function guardar() {
  const nombre = form.value.nombre.trim().toUpperCase()
  if (!nombre) { toast.error('El material necesita un nombre.'); return }
  if (form.value.precio_unitario === null || form.value.precio_unitario === '') { toast.error('Ponle un precio.'); return }

  const payload = {
    nombre,
    unidad:          form.value.unidad.trim().toUpperCase() || null,
    descripcion:     form.value.descripcion.trim() || null,
    precio_unitario: Number(form.value.precio_unitario) || 0,
  }

  guardando.value = true
  try {
    const { data } = esNuevo ? await crearMaterial(payload) : await actualizarMaterial(props.material.id, payload)
    const afectados = data.productos_afectados ?? 0
    toast.success(esNuevo ? 'Material creado'
      : afectados ? `Material guardado · ${afectados} ${afectados === 1 ? 'ficha recalculada' : 'fichas recalculadas'}`
      : 'Material guardado')
    emit('guardado', { material: data, afectados })
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar el material.')
  } finally {
    guardando.value = false
  }
}

const subio = h => Number(h.precio_nuevo) > Number(h.precio_anterior)
const variacion = h => ((h.precio_nuevo - h.precio_anterior) / (Number(h.precio_anterior) || 1)) * 100
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40" @click.self="emit('cerrar')">
      <div role="dialog" aria-modal="true" class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl max-h-[90vh] flex flex-col">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200 shrink-0">
          <h3 class="text-base font-bold text-gray-800">{{ esNuevo ? 'Nuevo material' : 'Editar material' }}</h3>
          <button @click="emit('cerrar')" class="text-gray-400 hover:text-gray-600 p-1" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
        </div>

        <div class="overflow-y-auto flex-1 px-5 py-4 space-y-3">
          <div>
            <label for="mat-nombre" class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
            <input id="mat-nombre" v-model="form.nombre" type="text" placeholder="Triplex 15 mm"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <p v-if="!esNuevo && usos" class="text-xs text-gray-400 mt-1">Si lo renombras, las fichas que lo usan cambian de nombre también.</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label for="mat-unidad" class="block text-xs font-medium text-gray-600 mb-1">Se compra por</label>
              <input id="mat-unidad" v-model="form.unidad" type="text" placeholder="Lámina, metro…"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label for="mat-precio" class="block text-xs font-medium text-gray-600 mb-1">Precio por unidad</label>
              <InputPesos id="mat-precio" v-model="form.precio_unitario" permite-vacio placeholder="0"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
          </div>
          <div>
            <label for="mat-desc" class="block text-xs font-medium text-gray-600 mb-1">Descripción <span class="font-normal text-gray-400">(opcional)</span></label>
            <input id="mat-desc" v-model="form.descripcion" type="text" placeholder="Medidas, proveedor, calidad…"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>

          <p v-if="cambiaPrecio && usos" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Al guardar se recalcula el costo de {{ usos }} {{ usos === 1 ? 'ficha que usa' : 'fichas que usan' }} este material.
          </p>
          <p v-else-if="!esNuevo" class="text-xs text-gray-400">
            {{ usos ? `Se usa en ${usos} ${usos === 1 ? 'ficha' : 'fichas'}.` : 'Ninguna ficha lo usa todavía.' }}
          </p>

          <!-- Historial de precios -->
          <div v-if="!esNuevo" class="pt-2">
            <h4 class="text-sm font-semibold text-gray-700 mb-1.5">Cambios de precio</h4>
            <p v-if="cargandoHistorial" class="text-xs text-gray-400">Cargando…</p>
            <p v-else-if="!historial.length" class="text-xs text-gray-400">Sin cambios registrados todavía.</p>
            <ol v-else class="rounded-xl border border-gray-200 divide-y divide-gray-100 max-h-48 overflow-y-auto">
              <li v-for="h in historial" :key="h.id" class="px-3 py-2">
                <div class="flex items-center justify-between gap-2 text-sm">
                  <span class="text-gray-600 tabular-nums">
                    {{ formatPeso(h.precio_anterior) }} → <strong class="text-gray-800">{{ formatPeso(h.precio_nuevo) }}</strong>
                  </span>
                  <span :class="['text-xs font-semibold tabular-nums', subio(h) ? 'text-red-600' : 'text-green-600']">
                    {{ subio(h) ? '+' : '' }}{{ formatPct(variacion(h)) }}%
                  </span>
                </div>
                <p class="text-xs text-gray-400 mt-0.5">
                  {{ formatFecha(h.created_at) }}<template v-if="h.usuario">, {{ h.usuario }}</template>.
                  <template v-if="h.productos_afectados">
                    {{ h.productos_afectados }} {{ h.productos_afectados === 1 ? 'ficha' : 'fichas' }}
                    <template v-if="Number(h.impacto_total)">({{ Number(h.impacto_total) > 0 ? '+' : '' }}{{ formatPeso(h.impacto_total) }} en total)</template>
                  </template>
                </p>
              </li>
            </ol>
          </div>
        </div>

        <div class="flex gap-2 px-5 py-4 border-t border-gray-200 shrink-0">
          <button @click="emit('cerrar')" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
          <button @click="guardar" :disabled="guardando"
            class="flex-1 py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 flex items-center justify-center gap-1.5">
            <IconoS v-if="guardando" class="w-4 h-4" />
            {{ guardando ? 'Guardando…' : esNuevo ? 'Crear material' : 'Guardar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
