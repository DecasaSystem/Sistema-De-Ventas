<script setup>
// Los ajustes de Finanzas: decisiones del negocio y del contador, no del
// código (IVA, franquicia, cómo se reparte lo general, umbrales del semáforo)
// y las categorías de gasto.
import { ref, onMounted } from 'vue'
import { XMarkIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { getAjustes, guardarAjustes, getCategorias, crearCategoria, editarCategoria } from '@/api/finanzas'
import { NOMBRE_AREA } from '@/utils/finanzas'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['cerrar', 'guardado'])
const toast = useToast()

const cfg = ref(null)
const categorias = ref([])
const guardando = ref(false)
const pestana = ref('general')
const nueva = ref(null)

onMounted(async () => {
  try {
    const [a, c] = await Promise.all([getAjustes(), getCategorias(true)])
    cfg.value = a.data
    categorias.value = c.data
  } catch {
    toast.error('No se pudieron cargar los ajustes')
  }
})

async function guardar() {
  guardando.value = true
  try {
    cfg.value = (await guardarAjustes(cfg.value)).data
    toast.success('Ajustes guardados')
    emit('guardado')
  } catch (e) {
    const errores = e.response?.data?.errors
    toast.error(errores ? Object.values(errores)[0][0] : 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

async function cambiarCategoria(c, cambios) {
  try {
    const { data } = await editarCategoria(c.id, cambios)
    Object.assign(c, data)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar la categoría')
  }
}

async function crear() {
  if (!nueva.value?.nombre?.trim()) return toast.error('Ponle un nombre')
  try {
    const { data } = await crearCategoria(nueva.value)
    categorias.value.push(data)
    nueva.value = null
    toast.success('Categoría creada')
  } catch (e) {
    const errores = e.response?.data?.errors
    toast.error(errores ? Object.values(errores)[0][0] : 'No se pudo crear')
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white px-5 pt-4 pb-3 border-b border-gray-100 space-y-3">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-gray-800">Ajustes de Finanzas</p>
            <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100" aria-label="Cerrar">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>
          <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
            <button v-for="p in [['general', 'General'], ['categorias', 'Categorías']]" :key="p[0]" @click="pestana = p[0]"
              :class="['flex-1 text-xs font-semibold rounded-lg py-1.5', pestana === p[0] ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']">
              {{ p[1] }}
            </button>
          </div>
        </div>

        <div v-if="!cfg" class="flex justify-center py-10"><AppSpinner /></div>

        <div v-else-if="pestana === 'general'" class="p-5 space-y-4">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">IVA de las ventas (%)</label>
              <input v-model.number="cfg.iva_pct" type="number" step="0.1" min="0" max="100" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Se declara</label>
              <select v-model="cfg.periodo_iva" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option value="bimestral">Cada 2 meses</option>
                <option value="cuatrimestral">Cada 4 meses</option>
              </select>
            </div>
          </div>
          <label class="flex items-start gap-2 text-sm text-gray-700">
            <input v-model="cfg.fv2_sin_iva_no_gravada" type="checkbox" class="mt-1 rounded" />
            <span>La FV2 "sin descontar IVA" no lleva IVA
              <span class="block text-[11px] text-gray-400">Confírmalo con el contador.</span></span>
          </label>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Franquicia de tarjeta y Addi (%)</label>
            <input v-model.number="cfg.franquicia_pct" type="number" step="0.1" min="0" max="100" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            <p class="text-[11px] text-gray-400 mt-1">Se cuenta sola como gasto financiero sobre lo cobrado así.</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Lo general (taller, administración) se reparte entre tiendas</label>
            <select v-model="cfg.reparto_generales" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
              <option value="ventas">Según lo que vende cada una</option>
              <option value="partes_iguales">En partes iguales</option>
              <option value="ninguno">No repartir</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Alerta si la nómina pasa del (%)</label>
              <input v-model.number="cfg.umbral_nomina_pct" type="number" min="0" max="100" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Margen operativo sano desde (%)</label>
              <input v-model.number="cfg.umbral_margen_pct" type="number" min="-100" max="100" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
          </div>

          <label class="flex items-start gap-2 text-sm text-gray-700">
            <input v-model="cfg.usar_costo_fichas" type="checkbox" class="mt-1 rounded" />
            <span>Estimar el costo de materiales con las fichas técnicas
              <span class="block text-[11px] text-gray-400">Así se ve el margen bruto. Es una estimación: la ficha es la receta de referencia, no lo que se gastó.</span></span>
          </label>

          <button @click="guardar" :disabled="guardando" class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">
            {{ guardando ? 'Guardando…' : 'Guardar ajustes' }}
          </button>
        </div>

        <div v-else class="p-5 space-y-3">
          <p class="text-[11px] text-gray-400">
            "Fijo" = el mismo monto cada vez. "Sube con las ventas" sirve para el punto de equilibrio (publicidad, fletes, insumos).
            El código PUC es para el Excel del contador.
          </p>
          <div v-for="c in categorias" :key="c.id" class="rounded-lg border border-gray-100 p-3" :class="!c.activo && 'opacity-50'">
            <div class="flex items-center justify-between gap-2">
              <input :value="c.nombre" @change="cambiarCategoria(c, { nombre: $event.target.value })"
                class="flex-1 min-w-0 text-sm font-semibold text-gray-800 border-0 p-0 focus:ring-0 bg-transparent" />
              <button @click="cambiarCategoria(c, { activo: !c.activo })" class="text-[11px] font-semibold shrink-0" :class="c.activo ? 'text-gray-400' : 'text-blue-600'">
                {{ c.activo ? 'Desactivar' : 'Activar' }}
              </button>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-2">
              <select :value="c.naturaleza" @change="cambiarCategoria(c, { naturaleza: $event.target.value })" class="text-xs border border-gray-200 rounded-lg px-2 py-1.5">
                <option value="fijo">Fijo</option><option value="variable">Variable</option>
              </select>
              <select :value="c.area" @change="cambiarCategoria(c, { area: $event.target.value })" class="text-xs border border-gray-200 rounded-lg px-2 py-1.5">
                <option v-for="(n, k) in NOMBRE_AREA" :key="k" :value="k">{{ n }}</option>
              </select>
            </div>
            <div class="flex items-center justify-between gap-2 mt-2">
              <label class="flex items-center gap-1.5 text-xs text-gray-600">
                <input type="checkbox" :checked="c.varia_con_ventas" @change="cambiarCategoria(c, { varia_con_ventas: $event.target.checked })" class="rounded" />
                Sube con las ventas
              </label>
              <input :value="c.codigo_puc" @change="cambiarCategoria(c, { codigo_puc: $event.target.value || null })" placeholder="PUC"
                class="w-20 text-xs border border-gray-200 rounded-lg px-2 py-1" />
            </div>
          </div>

          <div v-if="nueva" class="rounded-lg border border-blue-200 p-3 space-y-2">
            <input v-model="nueva.nombre" placeholder="Nombre de la categoría" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-1.5" />
            <div class="grid grid-cols-2 gap-2">
              <select v-model="nueva.naturaleza" class="text-xs border border-gray-200 rounded-lg px-2 py-1.5">
                <option value="fijo">Fijo</option><option value="variable">Variable</option>
              </select>
              <select v-model="nueva.area" class="text-xs border border-gray-200 rounded-lg px-2 py-1.5">
                <option v-for="(n, k) in NOMBRE_AREA" :key="k" :value="k">{{ n }}</option>
              </select>
            </div>
            <button @click="crear" class="w-full bg-blue-600 text-white text-xs font-semibold rounded-lg py-2">Crear</button>
          </div>
          <button v-else @click="nueva = { nombre: '', naturaleza: 'variable', area: 'administracion' }" class="flex items-center gap-1 text-xs font-semibold text-blue-600">
            <PlusIcon class="w-4 h-4" /> Nueva categoría
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
