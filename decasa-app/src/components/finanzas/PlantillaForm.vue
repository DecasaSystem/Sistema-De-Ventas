<script setup>
// Un gasto fijo (plantilla): se crea una vez y cada periodo aparece por
// pagar, con aviso al celular unos días antes. Cambiar el monto mueve lo que
// no se ha pagado; lo pagado no cambia.
import { ref, computed, onMounted } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import InputPesos from '@/components/common/InputPesos.vue'
import { getCategorias, crearRecurrente, editarRecurrente } from '@/api/finanzas'
import { getTiendas } from '@/api/ordenes'
import { hoyISO, FRECUENCIAS, METODOS } from '@/utils/finanzas'
import { useToast } from '@/composables/useToast'

const props = defineProps({ plantilla: { type: Object, required: true } })
const emit = defineEmits(['cerrar', 'guardado'])
const toast = useToast()

const esNueva = computed(() => !props.plantilla.id)
const categorias = ref([])
const tiendas = ref([])
const guardando = ref(false)

const sug = props.plantilla.sugerida
const form = ref({
  nombre: props.plantilla.nombre ?? sug?.nombre ?? '',
  categoria_gasto_id: props.plantilla.categoria_gasto_id ?? null,
  tienda_id: props.plantilla.tienda_id ?? null,
  monto: props.plantilla.monto ?? null,
  monto_estimado: props.plantilla.monto_estimado ?? !!sug?.estimado,
  frecuencia: props.plantilla.frecuencia ?? 'mensual',
  dia_pago: props.plantilla.dia_pago ?? 5,
  desde: props.plantilla.desde ?? hoyISO().slice(0, 8) + '01',
  hasta: props.plantilla.hasta ?? null,
  prorratear: props.plantilla.prorratear ?? true,
  metodo_pago: props.plantilla.metodo_pago ?? 'transferencia',
  avisar_dias_antes: props.plantilla.avisar_dias_antes ?? 3,
  notas: props.plantilla.notas ?? '',
})

onMounted(async () => {
  try {
    const [c, t] = await Promise.all([getCategorias(), getTiendas()])
    categorias.value = c.data
    tiendas.value = (t.data?.data ?? t.data ?? []).filter(x => x.activa !== false && !x.es_independientes)
    if (sug && !form.value.categoria_gasto_id) {
      form.value.categoria_gasto_id = categorias.value.find(x => x.nombre === sug.categoria)?.id ?? null
    }
  } catch {
    toast.error('No se pudieron cargar las categorías')
  }
})

const porMeses = computed(() => !['mensual', 'quincenal', 'semanal'].includes(form.value.frecuencia))

async function guardar(cambios = null) {
  const f = form.value
  if (!cambios) {
    if (!f.nombre.trim()) return toast.error('Ponle un nombre (ej. "Internet Norte")')
    if (!f.categoria_gasto_id) return toast.error('Elige la categoría')
    if (!f.monto) return toast.error('Pon el monto')
  }
  guardando.value = true
  try {
    const payload = cambios ?? { ...f, hasta: f.hasta || null }
    if (esNueva.value) await crearRecurrente(payload)
    else await editarRecurrente(props.plantilla.id, payload)
    toast.success(cambios ? 'Gasto fijo desactivado' : 'Gasto fijo guardado')
    emit('guardado')
  } catch (e) {
    const errores = e.response?.data?.errors
    toast.error(errores ? Object.values(errores)[0][0] : (e.response?.data?.message || 'No se pudo guardar'))
  } finally {
    guardando.value = false
  }
}

function desactivar() {
  if (!confirm(`¿Dejar de pagar "${props.plantilla.nombre}"?\n\nNo aparece más por pagar. Lo ya pagado se queda.`)) return
  guardar({ activo: false })
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <p class="font-semibold text-gray-800">{{ esNueva ? 'Nuevo gasto fijo' : 'Editar gasto fijo' }}</p>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100" aria-label="Cerrar">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Nombre</label>
            <input v-model="form.nombre" type="text" maxlength="120" placeholder="Ej. Internet Norte — Claro"
              class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Categoría</label>
              <select v-model="form.categoria_gasto_id" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option :value="null" disabled>Elige…</option>
                <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Tienda</label>
              <select v-model="form.tienda_id" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option :value="null">General</option>
                <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Monto</label>
            <InputPesos v-model="form.monto" class="text-xl font-bold" />
            <label class="flex items-start gap-2 mt-2 text-sm text-gray-700">
              <input v-model="form.monto_estimado" type="checkbox" class="mt-1 rounded" />
              <span>El monto cambia cada vez (luz, agua)
                <span class="block text-[11px] text-gray-400">Se sugiere el promedio de los últimos tres recibos.</span>
              </span>
            </label>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Cada cuánto</label>
              <select v-model="form.frecuencia" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option v-for="fr in FRECUENCIAS" :key="fr.value" :value="fr.value">{{ fr.label }}</option>
              </select>
            </div>
            <div v-if="!['semanal', 'quincenal'].includes(form.frecuencia)">
              <label class="block text-xs font-semibold text-gray-500 mb-1">Día de pago</label>
              <input v-model.number="form.dia_pago" type="number" min="1" max="31" inputmode="numeric"
                class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
          </div>
          <p v-if="form.dia_pago >= 29" class="text-[11px] text-gray-400 -mt-2">En los meses más cortos se toma el último día.</p>

          <label v-if="porMeses" class="flex items-start gap-2 text-sm text-gray-700">
            <input v-model="form.prorratear" type="checkbox" class="mt-1 rounded" />
            <span>Repartirlo en los meses que cubre
              <span class="block text-[11px] text-gray-400">Una licencia anual de $1.200.000 cuenta $100.000 cada mes en los resultados (de la caja sale entera el día que se paga).</span>
            </span>
          </label>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Desde</label>
              <input v-model="form.desde" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Hasta (opcional)</label>
              <input v-model="form.hasta" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Se paga con</label>
              <select v-model="form.metodo_pago" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option v-for="m in METODOS" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Avisar días antes</label>
              <input v-model.number="form.avisar_dias_antes" type="number" min="0" max="30" inputmode="numeric"
                class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2" />
            </div>
          </div>
        </div>

        <div class="sticky bottom-0 bg-white flex gap-2.5 px-5 py-4 border-t border-gray-100">
          <button v-if="!esNueva" @click="desactivar" :disabled="guardando" class="bg-gray-100 text-red-600 text-sm font-semibold rounded-xl px-4 py-2.5">Desactivar</button>
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl py-2.5">Cancelar</button>
          <button @click="guardar()" :disabled="guardando" class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
