<script setup>
// Facturas de proveedores a crédito (30/60 días): el gasto es del mes de la
// factura y la plata sale cuando se paga, en uno o varios abonos. Salen en el
// calendario de pagos con su vencimiento.
import { ref, computed, onMounted } from 'vue'
import { PlusIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import InputPesos from '@/components/common/InputPesos.vue'
import api from '@/api'
import { getCuentasPorPagar, crearCuentaPorPagar, pagarCuentaPorPagar, anularCuentaPorPagar, getCategorias } from '@/api/finanzas'
import { pesos, pesosCorto, fechaCorta, hoyISO, METODOS } from '@/utils/finanzas'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['cambio'])
const toast = useToast()

const facturas = ref([])
const cargando = ref(true)
const nueva = ref(null)
const abonando = ref(null)
const proveedores = ref([])
const categorias = ref([])
const guardando = ref(false)

async function cargar() {
  cargando.value = true
  try { facturas.value = (await getCuentasPorPagar()).data } catch { toast.error('No se pudieron cargar las facturas') } finally { cargando.value = false }
}
onMounted(cargar)

const totalDebe = computed(() => facturas.value.reduce((s, f) => s + f.saldo, 0))

function sumarDias(fecha, dias) {
  const d = new Date(fecha + 'T00:00:00'); d.setDate(d.getDate() + dias)
  return d.toISOString().slice(0, 10)
}

async function abrirNueva() {
  if (!proveedores.value.length) {
    try {
      const [p, c] = await Promise.all([api.get('/proveedores'), getCategorias()])
      proveedores.value = p.data
      categorias.value = c.data
    } catch { /* se puede escribir el nombre a mano */ }
  }
  const hoy = hoyISO()
  nueva.value = {
    proveedor_id: null, proveedor_nombre: '', concepto: '', numero_factura: '',
    categoria_gasto_id: categorias.value.find(c => c.nombre === 'Insumos de taller')?.id ?? null,
    monto: null, fecha_factura: hoy, fecha_vencimiento: sumarDias(hoy, 30),
  }
}

async function guardarNueva() {
  guardando.value = true
  try {
    await crearCuentaPorPagar(nueva.value)
    toast.success('Factura registrada')
    nueva.value = null
    cargar(); emit('cambio')
  } catch (e) {
    const err = e.response?.data?.errors
    toast.error(err ? Object.values(err)[0][0] : 'No se pudo registrar')
  } finally { guardando.value = false }
}

function abrirAbono(f) {
  abonando.value = { f, monto: f.saldo, fecha_pago: hoyISO(), metodo_pago: 'transferencia' }
}

async function guardarAbono() {
  guardando.value = true
  try {
    const a = abonando.value
    await pagarCuentaPorPagar(a.f.id, { monto: a.monto, fecha_pago: a.fecha_pago, metodo_pago: a.metodo_pago })
    toast.success(`Pago de ${pesos(a.monto)} registrado`)
    abonando.value = null
    cargar(); emit('cambio')
  } catch (e) {
    const err = e.response?.data?.errors
    toast.error(err ? Object.values(err)[0][0] : 'No se pudo registrar el pago')
  } finally { guardando.value = false }
}

async function anular(f) {
  const motivo = prompt(`¿Por qué se anula la factura de ${f.proveedor} por ${pesos(f.monto)}?`)
  if (!motivo?.trim()) return
  try { await anularCuentaPorPagar(f.id, motivo.trim()); toast.success('Factura anulada'); cargar(); emit('cambio') }
  catch (e) { const err = e.response?.data?.errors; toast.error(err ? Object.values(err)[0][0] : 'No se pudo anular') }
}
</script>

<template>
  <div class="bg-white rounded-xl shadow-sm p-4">
    <div class="flex items-center justify-between">
      <div>
        <p class="text-sm font-semibold text-gray-800">Facturas de proveedores</p>
        <p class="text-xs text-gray-400">{{ facturas.length ? `Se deben ${pesosCorto(totalDebe)}` : 'Lo que se compra a crédito (30, 60 días)' }}</p>
      </div>
      <button @click="abrirNueva" class="bg-blue-600 text-white text-xs font-semibold rounded-lg px-3 py-2 flex items-center gap-1">
        <PlusIcon class="w-4 h-4" /> Factura
      </button>
    </div>
    <ul class="divide-y divide-gray-100 mt-2">
      <li v-for="f in facturas" :key="f.id" class="py-2 flex items-center gap-2.5">
        <div class="min-w-0 flex-1">
          <p class="text-sm text-gray-800 truncate">{{ f.proveedor }} · {{ f.concepto }}</p>
          <p class="text-[11px] truncate" :class="f.vencida ? 'text-red-600 font-semibold' : 'text-gray-400'">
            {{ f.vencida ? 'Vencida · ' : 'Vence ' }}{{ fechaCorta(f.fecha_vencimiento) }}<template v-if="f.numero_factura"> · {{ f.numero_factura }}</template>
            <template v-if="f.pagado"> · abonado {{ pesosCorto(f.pagado) }}</template>
          </p>
        </div>
        <div class="text-right shrink-0">
          <p class="text-sm font-semibold text-gray-800">{{ pesosCorto(f.saldo) }}</p>
          <div class="flex gap-2 justify-end">
            <button v-if="!f.pagado" @click="anular(f)" class="text-[11px] text-gray-400">Anular</button>
            <button @click="abrirAbono(f)" class="text-[11px] font-semibold text-blue-600">Pagar</button>
          </div>
        </div>
      </li>
    </ul>

    <!-- Nueva factura -->
    <Teleport to="body">
      <div v-if="nueva" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="nueva = null">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto p-5 space-y-3">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-gray-800">Factura a crédito</p>
            <button @click="nueva = null" class="text-gray-400" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Monto</label>
            <InputPesos v-model="nueva.monto" class="text-xl font-bold" />
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">Proveedor</label>
            <select v-model="nueva.proveedor_id" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
              <option :value="null">Otro (escribir)</option>
              <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
            </select>
            <input v-if="!nueva.proveedor_id" v-model="nueva.proveedor_nombre" placeholder="Nombre del proveedor" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2 mt-1.5" />
          </div>
          <div class="grid grid-cols-2 gap-2">
            <input v-model="nueva.concepto" placeholder="Qué se compró" class="text-sm border border-gray-200 rounded-lg px-2 py-2" />
            <input v-model="nueva.numero_factura" placeholder="N.º de factura" class="text-sm border border-gray-200 rounded-lg px-2 py-2" />
          </div>
          <select v-model="nueva.categoria_gasto_id" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2">
            <option :value="null" disabled>Categoría…</option>
            <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
          </select>
          <div class="grid grid-cols-2 gap-2 text-xs">
            <label>Fecha de la factura<input v-model="nueva.fecha_factura" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2 mt-0.5" /></label>
            <label>Vence<input v-model="nueva.fecha_vencimiento" type="date" class="w-full text-sm border border-gray-200 rounded-lg px-2 py-2 mt-0.5" /></label>
          </div>
          <div class="flex gap-1.5">
            <button v-for="d in [15, 30, 60, 90]" :key="d" @click="nueva.fecha_vencimiento = sumarDias(nueva.fecha_factura, d)"
              class="flex-1 rounded-full text-xs font-semibold py-1.5 bg-gray-100 text-gray-600">{{ d }} días</button>
          </div>
          <button @click="guardarNueva" :disabled="guardando" class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">Registrar</button>
        </div>
      </div>
    </Teleport>

    <!-- Abono -->
    <Teleport to="body">
      <div v-if="abonando" class="fixed inset-0 bg-black/40 z-50 flex items-end sm:items-center justify-center" @click.self="abonando = null">
        <div class="bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-gray-800">Pagar a {{ abonando.f.proveedor }}</p>
            <button @click="abonando = null" class="text-gray-400" aria-label="Cerrar"><XMarkIcon class="w-5 h-5" /></button>
          </div>
          <p class="text-xs text-gray-500">Se deben {{ pesos(abonando.f.saldo) }}. Puede ser un abono.</p>
          <InputPesos v-model="abonando.monto" class="text-xl font-bold" />
          <div class="grid grid-cols-2 gap-2">
            <input v-model="abonando.fecha_pago" type="date" class="text-sm border border-gray-200 rounded-lg px-2 py-2" />
            <select v-model="abonando.metodo_pago" class="text-sm border border-gray-200 rounded-lg px-2 py-2">
              <option v-for="m in METODOS" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <button @click="guardarAbono" :disabled="guardando" class="w-full bg-blue-600 text-white text-sm font-semibold rounded-xl py-2.5 disabled:opacity-50">Registrar pago</button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
