<script setup>
/**
 * Crear o editar un material del catálogo. Al cambiar el precio se recalculan
 * todas las fichas que lo usan, y el cambio queda en el historial de abajo.
 */
import { ref, computed, onMounted } from 'vue'
import { XMarkIcon, ArrowsRightLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import api from '@/api'
import { crearMaterial, actualizarMaterial, getHistorialMaterial } from '@/api/materiales'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import IconoS from '@/components/common/IconoS.vue'
import { formatPct } from '@/utils/descuentos'
import { useCostos } from './useCostos'
import { useColoresMaterial } from './coloresMaterial'

const props = defineProps({ material: { type: Object, default: null } })
const emit  = defineEmits(['cerrar', 'guardado', 'reemplazar', 'abrir'])

const toast = useToast()
const { formatPeso, formatFecha, formatCantidad } = useCostos()

const esNuevo = !props.material
const form = ref({
  nombre:          props.material?.nombre ?? '',
  unidad:          props.material?.unidad ?? '',
  descripcion:     props.material?.descripcion ?? '',
  precio_unitario: props.material ? Number(props.material.precio_unitario) || 0 : null,
  color:           props.material?.color ?? null,
})

// Marca de color ("falta actualizar el precio"…): qué significa cada una lo dice la leyenda
const { COLORES, significado, cargarLeyenda } = useColoresMaterial()
cargarLeyenda()
const guardando = ref(false)

const usos          = computed(() => Number(props.material?.usos) || 0)
const cambiaPrecio  = computed(() => !esNuevo && Number(form.value.precio_unitario) !== Number(props.material.precio_unitario))
const cambiaUnidad  = computed(() => !esNuevo && (form.value.unidad.trim().toUpperCase() || null) !== (props.material.unidad || null))

const historial         = ref([])
const cargandoHistorial = ref(false)

// Dónde se usa, sin tener que tocar Eliminar para verlo (pedido del usuario, 2026-11-10).
// Una fila por ficha: una ficha puede usar el material en varias secciones.
const usosFichas    = ref([])
const cargandoUsos  = ref(false)

async function cargarUsos() {
  cargandoUsos.value = true
  try {
    const { data } = await api.get(`/materiales/${props.material.id}/usos`)
    const porFicha = new Map()
    for (const u of data.usos) {
      if (!porFicha.has(u.ficha_id)) porFicha.set(u.ficha_id, { id: u.ficha_id, nombre: u.ficha_nombre, categoria: u.ficha_categoria, items: [], subtotal: 0 })
      const f = porFicha.get(u.ficha_id)
      f.items.push(u)
      f.subtotal += Number(u.subtotal) || 0
    }
    usosFichas.value = [...porFicha.values()]
  } catch {
    usosFichas.value = []
  } finally {
    cargandoUsos.value = false
  }
}

onMounted(async () => {
  if (esNuevo) return
  if (usos.value) cargarUsos()
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
    color:           form.value.color || null,
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

          <!-- Marca de color -->
          <fieldset>
            <legend class="block text-xs font-medium text-gray-600 mb-1.5">Color <span class="font-normal text-gray-400">(opcional)</span></legend>
            <div class="flex flex-wrap gap-1.5">
              <button type="button" @click="form.color = null" :aria-pressed="!form.color"
                :class="['px-2.5 py-1 rounded-full text-xs font-semibold border', !form.color ? 'border-gray-800 text-gray-800' : 'border-gray-200 text-gray-500']">
                Sin color
              </button>
              <button v-for="c in COLORES" :key="c.id" type="button" @click="form.color = c.id" :aria-pressed="form.color === c.id"
                :class="['flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border max-w-full',
                  form.color === c.id ? 'border-gray-800 text-gray-800' : 'border-gray-200 text-gray-500']">
                <span :class="['w-2.5 h-2.5 rounded-full flex-shrink-0', c.punto]" aria-hidden="true" />
                <span class="truncate">{{ significado(c.id) }}</span>
              </button>
            </div>
          </fieldset>

          <!-- Las cantidades de las fichas no se convierten: 3 metros pasarían a ser 3 láminas -->
          <p v-if="cambiaUnidad && usos" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Cambias la unidad de «{{ props.material.unidad || 'sin unidad' }}» a «{{ form.unidad.trim().toUpperCase() || 'sin unidad' }}».
            Las {{ usos }} {{ usos === 1 ? 'ficha que lo usa conserva su cantidad' : 'fichas que lo usan conservan su cantidad' }}
            (no se convierte): si la unidad es otra medida, revisa las cantidades de esas fichas.
          </p>
          <p v-if="cambiaPrecio && usos" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
            Al guardar se recalcula el costo de {{ usos }} {{ usos === 1 ? 'ficha que usa' : 'fichas que usan' }} este material.
          </p>
          <p v-else-if="!esNuevo && !(cambiaUnidad && usos)" class="text-xs text-gray-400">
            {{ usos ? `Se usa en ${usos} ${usos === 1 ? 'ficha' : 'fichas'}.` : 'Ninguna ficha lo usa todavía.' }}
          </p>

          <!-- Pasarlo a otro material en algunas fichas, sin ir ficha por ficha ni eliminarlo -->
          <button v-if="!esNuevo && usos" type="button" @click="emit('reemplazar', props.material)"
            class="w-full flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-blue-600 hover:bg-gray-50">
            <ArrowsRightLeftIcon class="w-4 h-4" />Reemplazar por otro material en fichas…
          </button>

          <!-- Dónde se usa: tocar una ficha la abre -->
          <div v-if="!esNuevo && usos" class="pt-2">
            <h4 class="text-sm font-semibold text-gray-700 mb-1.5">Dónde se usa</h4>
            <p v-if="cargandoUsos" class="text-xs text-gray-400">Buscando…</p>
            <ul v-else-if="usosFichas.length" class="rounded-xl border border-gray-200 divide-y divide-gray-100 max-h-56 overflow-y-auto">
              <li v-for="f in usosFichas" :key="f.id">
                <button type="button" @click="emit('abrir', f.id)"
                  class="w-full flex items-center gap-2 px-3 py-2 text-left hover:bg-gray-50">
                  <span class="flex-1 min-w-0">
                    <span class="block text-sm text-gray-800 truncate">{{ f.nombre }}</span>
                    <span class="block text-xs text-gray-500 tabular-nums truncate">
                      <template v-for="(u, i) in f.items" :key="u.item_id">{{ i ? ' + ' : '' }}{{ formatCantidad(u.cantidad) }} {{ u.unidad || 'und' }}<template v-if="u.seccion"> ({{ u.seccion }})</template></template>
                    </span>
                  </span>
                  <span class="text-xs font-semibold text-gray-700 tabular-nums flex-shrink-0">{{ formatPeso(f.subtotal) }}</span>
                  <ChevronRightIcon class="w-4 h-4 text-gray-300 flex-shrink-0" />
                </button>
              </li>
            </ul>
            <p v-else class="text-xs text-gray-400">No se pudo cargar la lista.</p>
          </div>

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
