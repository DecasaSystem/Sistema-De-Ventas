<script setup>
/**
 * Nómina → Taller: lo que hizo cada trabajador del taller en un periodo, sacado
 * de Producción (quién quedó en cada paso terminado), para imprimirle su hoja.
 * Antes la llenaba él a mano y se podía poner de más (usuario, 2026-11-27).
 *
 * Sección aparte: no toca pagos ni la bonificación por producción. El periodo
 * lo calcula el servidor con la regla de los ciclos de nómina (el de cada 20
 * días tiene un ancla), así que aquí solo se pide "el ciclo que contiene esta
 * fecha" y, para ir al anterior o al siguiente, el día de antes o de después.
 */
import { ref, onMounted } from 'vue'
import { ChevronLeftIcon, ChevronRightIcon, PrinterIcon, ChevronDownIcon } from '@heroicons/vue/24/outline'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { getTrabajoTaller, pdfTrabajoTaller } from '@/api/nomina'
import { hoyBogota } from '@/utils/fechas'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const TIPOS = [
  { id: 'quincenal', label: 'Quincena' },
  { id: 'mensual', label: 'Mes' },
  { id: '20_dias', label: '20 días' },
  { id: 'semanal', label: 'Semana' },
  { id: 'personalizado', label: 'Otro' },
]

const tipo      = ref('quincenal')
const fecha     = ref(hoyBogota())
const desdeMano = ref(hoyBogota().slice(0, 8) + '01')
const hastaMano = ref(hoyBogota())

const datos     = ref(null)
const cargando  = ref(false)
const abierto   = ref(null)
const bajando   = ref(null)   // 'todas' o el id de la persona

function params() {
  return tipo.value === 'personalizado'
    ? { desde: desdeMano.value, hasta: hastaMano.value }
    : { periodicidad: tipo.value, fecha: fecha.value }
}

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getTrabajoTaller(params())
    datos.value = data
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo cargar el trabajo del taller')
  } finally {
    cargando.value = false
  }
}

function sumarDias(ymd, n) {
  const d = new Date(`${ymd}T12:00:00`)
  d.setDate(d.getDate() + n)
  return d.toISOString().slice(0, 10)
}

function mover(paso) {
  if (!datos.value) return
  fecha.value = paso < 0 ? sumarDias(datos.value.desde, -1) : sumarDias(datos.value.hasta, 1)
  abierto.value = null
  cargar()
}

function escoger(id) {
  tipo.value = id
  abierto.value = null
  if (id !== 'personalizado') cargar()
}

const cant = (n) => Number(n).toLocaleString('es-CO', { maximumFractionDigits: 2 })

async function imprimir(persona = null) {
  bajando.value = persona ? persona.usuario_id : 'todas'
  try {
    const p = { ...(datos.value ? { desde: datos.value.desde, hasta: datos.value.hasta } : params()) }
    if (persona) p.usuario_id = persona.usuario_id
    const { data } = await pdfTrabajoTaller(p)
    const url = URL.createObjectURL(data)
    const a = document.createElement('a')
    a.href = url
    a.download = persona
      ? `Trabajo ${persona.nombre} ${datos.value?.periodo || ''}.pdf`
      : `Trabajo del taller ${datos.value?.periodo || ''}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    // La respuesta viene como blob: el mensaje hay que leerlo.
    let msg = 'No se pudo sacar la hoja'
    try { msg = JSON.parse(await e.response?.data?.text())?.message || msg } catch { /* sin mensaje */ }
    toast.error(msg)
  } finally {
    bajando.value = null
  }
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <p class="text-xs text-gray-500">
      Lo que cada trabajador terminó en Producción. Se imprime su hoja con eso, y abajo
      escribe a mano lo que no pasó por el sistema.
    </p>

    <!-- Periodo -->
    <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
      <button
        v-for="t in TIPOS" :key="t.id" type="button" @click="escoger(t.id)"
        :class="['flex-1 text-xs font-semibold rounded-lg py-1.5 whitespace-nowrap transition-colors',
                 tipo === t.id ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >{{ t.label }}</button>
    </div>

    <div v-if="tipo === 'personalizado'" class="bg-white rounded-xl shadow-sm p-3 flex items-end gap-2">
      <label class="flex-1 text-xs text-gray-500">
        Desde
        <input v-model="desdeMano" type="date" class="mt-1 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800 bg-white" />
      </label>
      <label class="flex-1 text-xs text-gray-500">
        Hasta
        <input v-model="hastaMano" type="date" class="mt-1 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-gray-800 bg-white" />
      </label>
      <button type="button" @click="cargar" class="bg-blue-600 text-white rounded-lg text-xs font-semibold px-3 py-2">Ver</button>
    </div>

    <div class="flex items-center gap-2">
      <button
        v-if="tipo !== 'personalizado'" type="button" @click="mover(-1)" :disabled="cargando"
        class="p-1.5 rounded-lg bg-white shadow-sm text-gray-600 disabled:opacity-40" aria-label="Periodo anterior"
      ><ChevronLeftIcon class="w-4 h-4" /></button>
      <p class="flex-1 text-center text-sm font-semibold text-gray-800">{{ datos?.periodo || '…' }}</p>
      <button
        v-if="tipo !== 'personalizado'" type="button" @click="mover(1)" :disabled="cargando"
        class="p-1.5 rounded-lg bg-white shadow-sm text-gray-600 disabled:opacity-40" aria-label="Periodo siguiente"
      ><ChevronRightIcon class="w-4 h-4" /></button>
    </div>

    <AppSpinner v-if="cargando && !datos" />

    <template v-else-if="datos">
      <div v-if="!datos.personas.length" class="bg-white rounded-xl shadow-sm p-6 text-center text-sm text-gray-500">
        Nadie terminó pasos en el taller en este periodo.
      </div>

      <template v-else>
        <div class="flex items-center justify-between">
          <p class="text-xs text-gray-500">{{ datos.personas.length }} {{ datos.personas.length === 1 ? 'persona' : 'personas' }}</p>
          <button
            type="button" @click="imprimir()" :disabled="bajando !== null"
            class="flex items-center gap-1 bg-blue-600 text-white rounded-lg text-xs font-semibold px-3 py-1.5 disabled:opacity-50"
          >
            <PrinterIcon class="w-4 h-4" />
            {{ bajando === 'todas' ? 'Armando…' : 'Imprimir todas' }}
          </button>
        </div>

        <div :class="['space-y-2 transition-opacity', cargando ? 'opacity-50' : '']">
          <div v-for="p in datos.personas" :key="p.usuario_id" class="bg-white rounded-xl shadow-sm">
            <button
              type="button" @click="abierto = abierto === p.usuario_id ? null : p.usuario_id"
              class="w-full flex items-center gap-2 px-4 py-3 text-left"
            >
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-gray-800 truncate">{{ p.nombre }}</p>
                <p class="text-xs text-gray-500">
                  {{ cant(p.piezas) }} {{ p.piezas === 1 ? 'pieza' : 'piezas' }} ·
                  {{ p.trabajos }} {{ p.trabajos === 1 ? 'trabajo' : 'trabajos' }}
                </p>
              </div>
              <ChevronDownIcon :class="['w-4 h-4 text-gray-400 transition-transform', abierto === p.usuario_id ? 'rotate-180' : '']" />
            </button>

            <div v-if="abierto === p.usuario_id" class="px-4 pb-3 border-t border-gray-100">
              <div v-for="g in p.grupos" :key="g.proceso" class="mt-2">
                <p class="text-[11px] font-semibold text-blue-600 uppercase tracking-wide">{{ g.proceso }}</p>
                <div
                  v-for="(r, i) in g.renglones" :key="i"
                  class="flex justify-between gap-3 text-xs py-1 border-b border-gray-100 last:border-0"
                >
                  <span class="text-gray-700 min-w-0">
                    {{ r.producto }}<span v-if="r.version" class="text-gray-500"> · {{ r.version }}</span>
                    <span v-if="r.marca" class="ml-1 rounded-full text-[10px] font-semibold px-1.5 py-0.5 bg-amber-100 text-amber-700">{{ r.marca }}</span>
                  </span>
                  <span class="font-semibold text-gray-800 shrink-0">{{ cant(r.cantidad) }}</span>
                </div>
              </div>
              <button
                type="button" @click="imprimir(p)" :disabled="bajando !== null"
                class="mt-3 w-full flex items-center justify-center gap-1 bg-blue-600 text-white rounded-lg text-xs font-semibold py-2 disabled:opacity-50"
              >
                <PrinterIcon class="w-4 h-4" />
                {{ bajando === p.usuario_id ? 'Armando…' : `Imprimir la hoja de ${p.nombre}` }}
              </button>
            </div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>
