<script setup>
/**
 * Cuánta tela lleva cada producto tapizado, y el interruptor para que las
 * ventas la aparten y la descuenten solas.
 *
 * Los metros se pueden cargar con la función apagada: la idea es dejar todo
 * listo y encenderla cuando esté completo. Encendida, cada venta para
 * fabricar (o personalizar, o retapizar) aparta los metros de la tela
 * elegida y el taller los descuenta al terminar la pieza.
 *
 * Se lista el producto y, si tiene medidas configurables, una fila por
 * medida. El color no aparece: un sofá gasta lo mismo en gris que en azul.
 */
import { ref, computed, onMounted } from 'vue'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import api from '@/api'

const auth  = useAuthStore()
const toast = useToast()

const cargando      = ref(true)
const activo        = ref(false)
const reservasVivas = ref(0)
const productos     = ref([])
const busqueda      = ref('')
const cambiando     = ref(false)
// Clave "productoId:configId" → 'guardando' | 'ok' | 'error'
const estadoCampo   = ref({})

const puedeEditar = computed(() => auth.isSupervisor)

const productosFiltrados = computed(() => {
  const q = busqueda.value.trim().toLowerCase()
  if (!q) return productos.value
  return productos.value.filter(p =>
    p.nombre?.toLowerCase().includes(q) || p.categoria?.toLowerCase().includes(q)
  )
})

const conConsumo = computed(() =>
  productos.value.filter(p => p.metros != null || p.medidas.some(m => m.metros != null)).length
)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/telas/consumo')
    activo.value        = data.activo
    reservasVivas.value = data.reservas_vivas ?? 0
    productos.value     = data.productos
  } catch {
    toast.error('No se pudo cargar el consumo de telas.')
  } finally {
    cargando.value = false
  }
}

async function cambiarActivo() {
  if (!puedeEditar.value || cambiando.value) return
  const nuevo = !activo.value
  cambiando.value = true
  try {
    const { data } = await api.put('/telas/consumo/activo', { activo: nuevo })
    activo.value        = data.activo
    reservasVivas.value = data.activo ? reservasVivas.value : 0
    if (data.activo) {
      toast.success('Encendido: desde ahora las ventas apartan y descuentan tela.')
    } else {
      toast.info(data.liberadas > 0
        ? `Apagado. Se soltaron ${data.liberadas} reserva(s) de tela.`
        : 'Apagado: las ventas ya no tocan el inventario de telas.')
    }
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo cambiar el interruptor.')
  } finally {
    cambiando.value = false
  }
}

// Metros con centímetros: "6.45" = 6 m 45 cm. Coma o punto, dos decimales.
function normalizarMetros(valor) {
  let v = String(valor ?? '').replace(',', '.').replace(/[^\d.]/g, '')
  const i = v.indexOf('.')
  if (i !== -1) v = v.slice(0, i + 1) + v.slice(i + 1).replace(/\./g, '').slice(0, 2)
  return v
}

function clave(productoId, configId) {
  return `${productoId}:${configId ?? 'base'}`
}

/**
 * Guarda un campo al salir de él. Si el valor no cambió no se manda nada;
 * vacío o 0 borra el consumo. La casilla se deja mostrando lo que quedó
 * guardado, para que "6,5abc" no se quede escrito como si valiera.
 */
async function guardar(producto, medida, input) {
  if (!puedeEditar.value) return
  const configId = medida?.config_id ?? null
  const actual   = medida ? medida.metros : producto.metros
  const texto    = normalizarMetros(input.value)
  const metros   = texto === '' ? null : Math.round(parseFloat(texto) * 100) / 100
  const nuevo    = metros && metros > 0 ? metros : null

  if ((actual ?? null) === nuevo) {
    input.value = valorCampo(actual)
    return
  }

  const k = clave(producto.id, configId)
  estadoCampo.value[k] = 'guardando'
  try {
    const { data } = await api.put('/telas/consumo', {
      producto_id: producto.id,
      config_id:   configId ?? undefined,
      metros:      nuevo,
    })
    if (medida) medida.metros = data.metros
    else        producto.metros = data.metros
    input.value = valorCampo(data.metros)
    estadoCampo.value[k] = 'ok'
    setTimeout(() => { if (estadoCampo.value[k] === 'ok') delete estadoCampo.value[k] }, 1500)
  } catch (e) {
    input.value = valorCampo(actual)
    estadoCampo.value[k] = 'error'
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
  }
}

function valorCampo(metros) {
  return metros == null ? '' : String(metros)
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-4">
    <!-- Interruptor -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-800">Descontar tela automáticamente</p>
          <p class="text-xs text-gray-500 mt-0.5">
            Encendido, cada venta para fabricar aparta los metros de la tela elegida y
            se descuentan cuando el taller termina la pieza. Si se cancela, se sueltan.
          </p>
        </div>
        <button
          v-if="puedeEditar"
          @click="cambiarActivo"
          :disabled="cambiando || cargando"
          :class="[
            'relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors disabled:opacity-50',
            activo ? 'bg-green-600' : 'bg-gray-200'
          ]"
          :title="activo ? 'Apagar' : 'Encender'"
        >
          <span :class="['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', activo ? 'translate-x-6' : 'translate-x-1']" />
        </button>
        <span
          v-else
          :class="['text-xs font-semibold px-2 py-1 rounded-full whitespace-nowrap', activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']"
        >
          {{ activo ? 'Encendido' : 'Apagado' }}
        </span>
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
        <span :class="activo ? 'text-green-700 font-semibold' : 'text-gray-400'">
          {{ activo ? '● Encendido' : '○ Apagado' }}
        </span>
        <span>{{ conConsumo }} / {{ productos.length }} productos con metros cargados</span>
        <span v-if="activo && reservasVivas">{{ reservasVivas }} reserva(s) de tela vivas</span>
      </div>

      <p v-if="!activo && puedeEditar" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
        Puedes cargar los metros desde ya. Enciende el interruptor cuando estén completos:
        lo que se venda antes no apartará tela.
      </p>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
      <input
        v-model="busqueda"
        type="search"
        placeholder="Buscar producto tapizado..."
        class="w-full pl-9 pr-3 py-2.5 rounded-xl border border-gray-200 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <div v-if="cargando" class="flex justify-center py-12">
      <AppSpinner />
    </div>

    <div v-else-if="!productosFiltrados.length" class="text-center py-12 text-sm text-gray-400">
      {{ busqueda ? 'Sin resultados.' : 'No hay productos marcados como tapizados. Márcalos desde Inventario → Gestionar.' }}
    </div>

    <div v-else class="space-y-2">
      <div
        v-for="p in productosFiltrados"
        :key="p.id"
        class="bg-white rounded-xl shadow-sm p-4 space-y-3"
      >
        <div class="flex items-center gap-3">
          <img
            v-if="p.foto_url"
            :src="cloudinaryOpt(p.foto_url, 96)"
            class="w-12 h-12 rounded-lg object-cover border border-gray-200 flex-shrink-0"
          />
          <div v-else class="w-12 h-12 rounded-lg bg-gray-100 flex-shrink-0" />
          <div class="flex-1 min-w-0">
            <p class="font-semibold text-sm text-gray-800 truncate">{{ p.nombre }}</p>
            <p v-if="p.categoria" class="text-xs text-gray-400 capitalize">{{ p.categoria }}</p>
          </div>
          <span
            v-if="p.metros == null && !p.medidas.some(m => m.metros != null)"
            class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 whitespace-nowrap"
          >
            Sin metros
          </span>
        </div>

        <!-- Consumo base -->
        <div class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs font-medium text-gray-700">Metros por unidad</p>
            <p v-if="p.medidas.length" class="text-[11px] text-gray-400">Se usa cuando la medida no tiene metros propios</p>
          </div>
          <div class="flex items-center gap-1.5">
            <span
              v-if="estadoCampo[clave(p.id, null)]"
              :class="['text-[10px]', estadoCampo[clave(p.id, null)] === 'error' ? 'text-red-500' : 'text-gray-400']"
            >
              {{ estadoCampo[clave(p.id, null)] === 'guardando' ? 'Guardando…' : estadoCampo[clave(p.id, null)] === 'ok' ? 'Guardado' : 'Error' }}
            </span>
            <input
              :value="valorCampo(p.metros)"
              :disabled="!puedeEditar"
              inputmode="decimal"
              placeholder="0.00"
              class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-500"
              @change="e => guardar(p, null, e.target)"
              @keyup.enter="e => e.target.blur()"
            />
            <span class="text-xs text-gray-400">m</span>
          </div>
        </div>

        <!-- Por medida -->
        <div v-if="p.medidas.length" class="border-t border-gray-100 pt-2 space-y-1.5">
          <div
            v-for="m in p.medidas"
            :key="m.config_id"
            class="flex items-center justify-between gap-3"
          >
            <p class="text-xs text-gray-600 truncate">
              <span v-if="m.tipo" class="text-gray-400">{{ m.tipo }} · </span>{{ m.nombre }}
            </p>
            <div class="flex items-center gap-1.5">
              <span
                v-if="estadoCampo[clave(p.id, m.config_id)]"
                :class="['text-[10px]', estadoCampo[clave(p.id, m.config_id)] === 'error' ? 'text-red-500' : 'text-gray-400']"
              >
                {{ estadoCampo[clave(p.id, m.config_id)] === 'guardando' ? 'Guardando…' : estadoCampo[clave(p.id, m.config_id)] === 'ok' ? 'Guardado' : 'Error' }}
              </span>
              <input
                :value="valorCampo(m.metros)"
                :disabled="!puedeEditar"
                inputmode="decimal"
                :placeholder="p.metros != null ? `= ${p.metros}` : '0.00'"
                class="w-24 rounded-lg border border-gray-300 px-2 py-1.5 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-50 disabled:text-gray-500"
                @change="e => guardar(p, m, e.target)"
                @keyup.enter="e => e.target.blur()"
              />
              <span class="text-xs text-gray-400">m</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
