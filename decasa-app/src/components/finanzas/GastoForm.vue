<script setup>
// Registrar un gasto en diez segundos, desde el celular: el monto primero,
// la categoría en un toque, y lo demás solo si hace falta. Sirve también para
// pagar un periodo de una plantilla (llega en `obligacion`).
import { ref, computed, onMounted } from 'vue'
import { XMarkIcon, CameraIcon, TrashIcon } from '@heroicons/vue/24/outline'
import InputPesos from '@/components/common/InputPesos.vue'
import { getCategorias, crearGasto, crearRecurrente, subirRecibo } from '@/api/finanzas'
import { iconoPorNombre } from '@/constants/iconos'
import { getTiendas } from '@/api/ordenes'
import { hoyISO, sumarMeses, nombreMes, FRECUENCIAS, METODOS, pesos } from '@/utils/finanzas'
import { useToast } from '@/composables/useToast'

const props = defineProps({ obligacion: { type: Object, default: null } })
const emit = defineEmits(['cerrar', 'guardado'])
const toast = useToast()

const categorias = ref([])
const tiendas = ref([])
const verTodas = ref(false)
const guardando = ref(false)
const subiendo = ref(false)
const masOpciones = ref(false)

const esPlantilla = computed(() => !!props.obligacion?.gasto_recurrente_id)

const form = ref({
  monto: props.obligacion?.monto ?? null,
  categoria_gasto_id: props.obligacion?.categoria_id ?? null,
  concepto: props.obligacion?.nombre ?? props.obligacion?.titulo ?? '',
  tienda_id: props.obligacion?.tienda_id ?? null,
  fecha_pago: hoyISO(),
  metodo_pago: props.obligacion?.metodo_pago ?? 'transferencia',
  corresponde: 'pago',          // 'pago' | 'anterior' | 'varios'
  prorratear_meses: 12,
  fotos: [],
  notas: '',
  repetir: false,
  frecuencia: 'mensual',
  // De qué canal es (publicidad de Instagram…): para la rentabilidad por canal.
  canal: null,
})

const CANALES = [
  { value: 'instagram', label: 'Instagram' }, { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'facebook', label: 'Facebook' }, { value: 'pagina', label: 'Página web' }, { value: 'fisica', label: 'Tienda física' },
]
// Se pregunta el canal cuando el gasto es de publicidad.
const esPublicidad = computed(() => /publicidad/i.test(categorias.value.find(c => c.id === form.value.categoria_gasto_id)?.nombre ?? ''))

onMounted(async () => {
  try {
    const [c, t] = await Promise.all([getCategorias(), getTiendas()])
    categorias.value = c.data
    tiendas.value = (t.data?.data ?? t.data ?? []).filter(x => x.activa !== false && !x.es_independientes)
  } catch {
    toast.error('No se pudieron cargar las categorías')
  }
})

// Las 8 primeras a la vista; el resto con "Más".
const visibles = computed(() => verTodas.value ? categorias.value : categorias.value.slice(0, 8))
const mesPago = computed(() => form.value.fecha_pago.slice(0, 7))

async function agregarFotos(e) {
  const archivos = [...(e.target.files ?? [])]
  e.target.value = ''
  if (!archivos.length) return
  subiendo.value = true
  try {
    for (const f of archivos.slice(0, 10 - form.value.fotos.length)) {
      form.value.fotos.push(await subirRecibo(f))
    }
  } catch {
    toast.error('Una foto no se pudo subir; inténtalo de nuevo')
  } finally {
    subiendo.value = false
  }
}

async function guardar() {
  const f = form.value
  if (!f.monto || f.monto <= 0) return toast.error('Pon el monto')
  if (!esPlantilla.value && !f.categoria_gasto_id) return toast.error('Elige la categoría')
  if (!esPlantilla.value && !f.concepto.trim()) return toast.error('Escribe qué se pagó')

  guardando.value = true
  try {
    const base = {
      monto: f.monto, fecha_pago: f.fecha_pago, metodo_pago: f.metodo_pago,
      tienda_id: f.tienda_id, comprobante_fotos: f.fotos, notas: f.notas || null,
      canal: esPublicidad.value ? f.canal : null,
    }

    if (esPlantilla.value) {
      await crearGasto({ ...base, gasto_recurrente_id: props.obligacion.gasto_recurrente_id, periodo: props.obligacion.periodo })
    } else if (f.repetir) {
      // Se vuelve plantilla y este mismo pago queda como su primer periodo.
      const { data: plantilla } = await crearRecurrente({
        nombre: f.concepto, categoria_gasto_id: f.categoria_gasto_id, tienda_id: f.tienda_id,
        monto: f.monto, frecuencia: f.frecuencia, dia_pago: Number(f.fecha_pago.slice(8, 10)),
        desde: f.fecha_pago.slice(0, 8) + '01', metodo_pago: f.metodo_pago,
        prorratear: !['mensual', 'quincenal', 'semanal'].includes(f.frecuencia),
      })
      await crearGasto({ ...base, gasto_recurrente_id: plantilla.id, periodo: f.fecha_pago.slice(0, 8) + '01' })
        .catch(() => crearGasto({ ...base, categoria_gasto_id: f.categoria_gasto_id, concepto: f.concepto }))
    } else {
      await crearGasto({
        ...base, categoria_gasto_id: f.categoria_gasto_id, concepto: f.concepto,
        corresponde_a: f.corresponde === 'anterior' ? sumarMeses(mesPago.value, -1) : null,
        prorratear_meses: f.corresponde === 'varios' ? f.prorratear_meses : null,
      })
    }
    toast.success(`Gasto de ${pesos(f.monto)} registrado`)
    emit('guardado')
  } catch (e) {
    const errores = e.response?.data?.errors
    toast.error(errores ? Object.values(errores)[0][0] : (e.response?.data?.message || 'No se pudo guardar'))
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white flex items-center justify-between px-5 py-4 border-b border-gray-100">
          <div class="min-w-0">
            <p class="font-semibold text-gray-800">{{ esPlantilla ? 'Pagar' : 'Nuevo gasto' }}</p>
            <p v-if="esPlantilla" class="text-xs text-gray-400 truncate">{{ form.concepto }}</p>
          </div>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100" aria-label="Cerrar">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="p-5 space-y-4">
          <!-- 1. El monto, grande -->
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">¿Cuánto?</label>
            <InputPesos v-model="form.monto" class="text-2xl font-bold" placeholder="0" autofocus />
            <p v-if="obligacion?.monto_estimado" class="text-[11px] text-gray-400 mt-1">Sugerido con el promedio de los últimos recibos: cámbialo por el del recibo.</p>
          </div>

          <template v-if="!esPlantilla">
            <!-- 2. Categoría en un toque -->
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿De qué es?</label>
              <div class="flex flex-wrap gap-1.5">
                <button v-for="c in visibles" :key="c.id" type="button" @click="form.categoria_gasto_id = c.id"
                  :class="['flex items-center gap-1 rounded-full text-xs font-semibold px-2.5 py-1.5 border transition-colors',
                    form.categoria_gasto_id === c.id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']">
                  <component v-if="iconoPorNombre(c.icono)" :is="iconoPorNombre(c.icono)" class="w-3.5 h-3.5" />
                  {{ c.nombre }}
                </button>
                <button v-if="categorias.length > 8" type="button" @click="verTodas = !verTodas"
                  class="rounded-full text-xs font-semibold px-2.5 py-1.5 text-blue-600">
                  {{ verTodas ? 'Menos' : 'Más…' }}
                </button>
              </div>
            </div>

            <!-- Publicidad: ¿de qué canal? (rentabilidad por canal) -->
            <div v-if="esPublicidad">
              <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Para qué canal?</label>
              <div class="flex flex-wrap gap-1.5">
                <button v-for="c in CANALES" :key="c.value" type="button" @click="form.canal = form.canal === c.value ? null : c.value"
                  :class="['rounded-full text-xs font-semibold px-2.5 py-1.5 border', form.canal === c.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']">
                  {{ c.label }}
                </button>
              </div>
            </div>

            <!-- 3. Concepto -->
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Concepto</label>
              <input v-model="form.concepto" type="text" maxlength="160" placeholder="Ej. Recibo de luz Norte"
                class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
          </template>

          <!-- 4. Tienda -->
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿De qué tienda?</label>
            <div class="flex flex-wrap gap-1.5">
              <button type="button" @click="form.tienda_id = null"
                :class="['rounded-full text-xs font-semibold px-2.5 py-1.5 border', form.tienda_id === null ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']">
                General
              </button>
              <button v-for="t in tiendas" :key="t.id" type="button" @click="form.tienda_id = t.id"
                :class="['rounded-full text-xs font-semibold px-2.5 py-1.5 border', form.tienda_id === t.id ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']">
                {{ t.nombre.replace('Decasa ', '') }}
              </button>
            </div>
          </div>

          <!-- 5. Fecha y medio -->
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Fecha de pago</label>
              <input v-model="form.fecha_pago" type="date" :max="hoyISO()"
                class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>
            <div>
              <label class="block text-xs font-semibold text-gray-500 mb-1">Medio</label>
              <select v-model="form.metodo_pago" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option v-for="m in METODOS" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
            </div>
          </div>

          <!-- 6. ¿A qué mes pertenece? (solo gastos sueltos) -->
          <div v-if="!esPlantilla">
            <button type="button" @click="masOpciones = !masOpciones" class="text-xs font-semibold text-blue-600">
              {{ masOpciones ? 'Menos opciones' : '¿Corresponde a otro mes o se repite?' }}
            </button>
            <div v-if="masOpciones" class="mt-2 space-y-3 rounded-lg border border-gray-100 p-3">
              <div class="space-y-1.5">
                <label class="flex items-center gap-2 text-sm text-gray-700">
                  <input v-model="form.corresponde" type="radio" value="pago" /> Es de {{ nombreMes(mesPago) }}
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                  <input v-model="form.corresponde" type="radio" value="anterior" /> Es de {{ nombreMes(sumarMeses(mesPago, -1)) }} (se pagó después)
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                  <input v-model="form.corresponde" type="radio" value="varios" /> Cubre varios meses
                  <input v-if="form.corresponde === 'varios'" v-model.number="form.prorratear_meses" type="number" min="2" max="36"
                    class="w-16 text-sm border border-gray-200 rounded-lg px-2 py-1" />
                </label>
                <p class="text-[11px] text-gray-400">Así un seguro anual no hace ver un mes en pérdida: se reparte en los meses que cubre.</p>
              </div>
              <label class="flex items-start gap-2 text-sm text-gray-700">
                <input v-model="form.repetir" type="checkbox" class="mt-1 rounded" />
                <span>Se repite (gasto fijo)
                  <span class="block text-[11px] text-gray-400">Queda como plantilla y cada periodo aparece por pagar, con aviso.</span>
                </span>
              </label>
              <select v-if="form.repetir" v-model="form.frecuencia" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
                <option v-for="fr in FRECUENCIAS" :key="fr.value" :value="fr.value">{{ fr.label }}</option>
              </select>
            </div>
          </div>

          <!-- 7. Fotos del recibo -->
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">Recibo (opcional)</label>
            <div class="flex flex-wrap gap-2">
              <div v-for="(url, i) in form.fotos" :key="url" class="relative w-16 h-16">
                <img :src="url" alt="Recibo" class="w-16 h-16 object-cover rounded-lg" />
                <button type="button" @click="form.fotos.splice(i, 1)" class="absolute -top-1.5 -right-1.5 bg-white rounded-full shadow p-0.5" aria-label="Quitar foto">
                  <TrashIcon class="w-3.5 h-3.5 text-red-500" />
                </button>
              </div>
              <label class="w-16 h-16 rounded-lg border-2 border-dashed border-gray-200 flex items-center justify-center cursor-pointer text-gray-400">
                <span v-if="subiendo" class="w-4 h-4 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
                <CameraIcon v-else class="w-6 h-6" />
                <input type="file" accept="image/*" multiple class="hidden" @change="agregarFotos" />
              </label>
            </div>
          </div>

          <textarea v-model="form.notas" rows="2" maxlength="2000" placeholder="Notas (opcional)"
            class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
        </div>

        <div class="sticky bottom-0 bg-white flex gap-2.5 px-5 py-4 border-t border-gray-100">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl py-2.5">Cancelar</button>
          <button @click="guardar" :disabled="guardando || subiendo"
            class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50 flex items-center justify-center gap-1.5">
            <span v-if="guardando" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
