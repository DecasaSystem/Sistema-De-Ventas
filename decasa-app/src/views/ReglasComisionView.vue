<script setup>
// Comisiones → Reglas: las cifras con que se calculan las comisiones (el 5 %,
// el ÷ 1,19, el 50 % pagado, el día 20…), que antes solo se cambiaban en el
// código. Fase 2 de docs/plan-comisiones-personalizables.md.
//
// Decisiones del dueño (2026-10-27): las ve quien tiene Comisiones, las cambia
// solo quien tiene el permiso "Reglas de comisión"; para todos, una tienda o
// una persona (gana la más específica); hacia un mes pasado solo si no tiene
// pagos y confirmando. Antes de guardar se puede simular cuánto le cambia a
// cada uno. El backend vuelve a validar todo (ReglaComisionController).
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  ArrowLeftIcon, AdjustmentsHorizontalIcon, PencilIcon, XMarkIcon, TrashIcon,
  BeakerIcon, ExclamationTriangleIcon, LockClosedIcon,
} from '@heroicons/vue/24/outline'
import api from '@/api'
import { useToast } from '@/composables/useToast'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { pesos } from '@/utils/pesos'
// Los textos de Comisiones (Ayuda, desglose, Excel) guardan las reglas del
// mes mientras la app está abierta: con una regla nueva hay que olvidarlas.
import { olvidarReglasVigentes } from '@/utils/reglasComision'

const router = useRouter()
const toast  = useToast()

const cargando = ref(true)
const datos    = ref(null)

async function cargar() {
  cargando.value = true
  try {
    const { data } = await api.get('/comisiones/reglas')
    datos.value = data
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudieron cargar las reglas.')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

const catalogo = computed(() => Object.fromEntries((datos.value?.catalogo ?? []).map(r => [r.clave, r])))
const puedeEditar = computed(() => !!datos.value?.puede_editar)

/** Las filas de una regla: primero las de todos, luego tiendas y personas; de la más nueva a la más vieja. */
function filasDe(clave) {
  const orden = { general: 0, tienda: 1, usuario: 2 }
  return (datos.value?.reglas ?? [])
    .filter(f => f.clave === clave)
    .sort((a, b) => (orden[a.alcance] - orden[b.alcance])
      || a.alcance_nombre.localeCompare(b.alcance_nombre)
      || b.desde_mes.localeCompare(a.desde_mes))
}

function esCambiada(r) {
  return JSON.stringify(r.vigente) !== JSON.stringify(r.defecto)
}

// ── Cómo se muestra cada valor ───────────────────────────────────────────────

const METODOS = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta', addi: 'Addi', otro: 'Otro' }

function numeroCo(v, decimales = 2) {
  return Number(v).toLocaleString('es-CO', { maximumFractionDigits: decimales })
}

function fmtValor(r, v) {
  if (v === null || v === undefined) return 'Sin excepción'
  switch (r.tipo) {
    case 'fraccion': return `${numeroCo(v * 100)} %`
    case 'numero':   return numeroCo(v, 4)
    case 'entero':   return r.clave === 'dia_pago' ? `Día ${v}` : `${v} ${v === 1 ? 'mes' : 'meses'}`
    case 'si_no':    return v ? 'Sí' : 'No'
    case 'lista':    return v.length ? v.map(m => METODOS[m] ?? m).join(', ') : 'Ninguno'
    case 'opcion':   return r.opciones?.find(o => o.valor === v)?.nombre ?? String(v)
    case 'pesos':    return Number(v) > 0 ? pesos(v) : 'Sin bono'
    case 'tramos':   return v.map(t => `desde ${numeroCo(t.desde * 100)} %: ${numeroCo(t.pct * 100)} %`).join(' · ')
    default:         return String(v)
  }
}

function fmtMes(mes) {
  if (!mes) return ''
  const [a, m] = mes.split('-').map(Number)
  return new Date(a, m - 1, 1).toLocaleDateString('es-CO', { month: 'short', year: 'numeric' })
}

const ALCANCES = { general: 'Para todos', tienda: 'Una tienda', usuario: 'Una persona' }

// ── Cambiar una regla ────────────────────────────────────────────────────────

const form       = ref(null)   // la hoja abierta
const guardando  = ref(false)
const confirmar  = ref(null)   // mensaje del servidor cuando pide confirmar
const simulacion = ref(null)
const simulando  = ref(false)

/** El mes anterior al actual: el último completo, el que se suele simular. */
function mesAnterior(mes) {
  const [a, m] = mes.split('-').map(Number)
  const d = new Date(a, m - 2, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

function abrir(clave) {
  const r = catalogo.value[clave]
  form.value = {
    clave,
    alcance: 'general',
    alcance_id: null,
    sinExcepcion: false,
    // Lo que se escribe, según el tipo. Arranca con lo que rige hoy.
    texto: r.tipo === 'fraccion' ? numeroCo(r.vigente * 100) : (['numero', 'entero'].includes(r.tipo) ? numeroCo(r.vigente, 4) : ''),
    si: r.tipo === 'si_no' ? !!r.vigente : false,
    lista: r.tipo === 'lista' ? [...r.vigente] : [],
    opcion: r.tipo === 'opcion' ? r.vigente : null,
    pesos: r.tipo === 'pesos' ? numeroCo(r.vigente, 0) : '',
    // Los tramos en %: "desde el 120 % de la meta paga el 6 %". El primero es siempre el 100 %.
    tramos: r.tipo === 'tramos' ? r.vigente.map(t => ({ desde: numeroCo(t.desde * 100), pct: numeroCo(t.pct * 100) })) : [],
    desde_mes: datos.value.mes_actual,
    nota: '',
    mesSimular: mesAnterior(datos.value.mes_actual),
  }
  confirmar.value  = null
  simulacion.value = null
}

/** Un tramo más arriba del último: 20 puntos más de la meta, mismo porcentaje + 1. */
function agregarTramo() {
  const t = form.value.tramos
  const ultimo = t[t.length - 1]
  const desde = (leerNumero(ultimo?.desde) ?? 100) + 20
  const pct = (leerNumero(ultimo?.pct) ?? 5) + 1
  t.push({ desde: numeroCo(desde), pct: numeroCo(pct) })
  simulacion.value = null
}

function cerrar() {
  form.value = null
  confirmar.value = null
  simulacion.value = null
}

const reglaForm = computed(() => form.value ? catalogo.value[form.value.clave] : null)

/** "5,5" o "5.5" → 5.5 */
function leerNumero(texto) {
  const n = Number(String(texto ?? '').trim().replace(/\s/g, '').replace(',', '.'))
  return Number.isFinite(n) && String(texto ?? '').trim() !== '' ? n : null
}

/** El valor para el servidor, o undefined si no se puede leer. */
function valorDelForm() {
  const f = form.value
  const r = reglaForm.value
  if (f.alcance !== 'general' && f.sinExcepcion) return null
  switch (r.tipo) {
    case 'fraccion': { const n = leerNumero(f.texto); return n === null ? undefined : Math.round(n * 1000) / 100000 }
    case 'numero':   { const n = leerNumero(f.texto); return n === null ? undefined : n }
    case 'entero':   { const n = leerNumero(f.texto); return n === null || !Number.isInteger(n) ? undefined : n }
    case 'si_no':    return f.si
    case 'lista':    return f.lista
    case 'opcion':   return f.opcion ?? undefined
    case 'pesos':    { const n = leerNumero(String(f.pesos).replace(/\./g, '')); return n === null || n < 0 ? undefined : Math.round(n) }
    case 'tramos': {
      const out = f.tramos.map(t => ({ desde: leerNumero(t.desde), pct: leerNumero(t.pct) }))
      if (!out.length || out.some(t => t.desde === null || t.pct === null)) return undefined
      return out.map(t => ({ desde: Math.round(t.desde * 100) / 10000, pct: Math.round(t.pct * 1000) / 100000 }))
    }
    default:         return undefined
  }
}

function cuerpo(extra = {}) {
  const f = form.value
  const valor = valorDelForm()
  if (valor === undefined) { toast.error('Revisa el valor: no se entiende.'); return null }
  if (f.alcance !== 'general' && !f.alcance_id) {
    toast.error(f.alcance === 'tienda' ? 'Escoge la tienda.' : 'Escoge la persona.')
    return null
  }
  return {
    clave: f.clave, alcance: f.alcance, alcance_id: f.alcance === 'general' ? null : f.alcance_id,
    valor, desde_mes: f.desde_mes, nota: f.nota.trim() || null, ...extra,
  }
}

async function simular() {
  const datosRegla = cuerpo({ mes: form.value.mesSimular })
  if (!datosRegla) return
  simulando.value = true
  try {
    const { data } = await api.post('/comisiones/reglas/simular', datosRegla)
    simulacion.value = data
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo simular.')
  } finally {
    simulando.value = false
  }
}

async function guardar(confirmado = false) {
  const datosRegla = cuerpo(confirmado ? { confirmar: true } : {})
  if (!datosRegla) return
  guardando.value = true
  try {
    await api.post('/comisiones/reglas', datosRegla)
    olvidarReglasVigentes()
    toast.success('Regla guardada. Las comisiones se recalculan en unos minutos.')
    cerrar()
    await cargar()
  } catch (e) {
    if (e.response?.status === 409 && e.response.data?.requiere_confirmacion) {
      confirmar.value = e.response.data.message
    } else {
      toast.error(e.response?.data?.message || 'No se pudo guardar.')
    }
  } finally {
    guardando.value = false
  }
}

async function quitar(fila) {
  const r = catalogo.value[fila.clave]
  if (!confirm(`¿Quitar "${r.nombre}" (${fila.alcance_nombre}, desde ${fmtMes(fila.desde_mes)})? Vuelve a regir lo de antes.`)) return
  try {
    await api.delete(`/comisiones/reglas/${fila.id}`)
  } catch (e) {
    if (e.response?.status === 409 && e.response.data?.requiere_confirmacion) {
      if (!confirm(e.response.data.message + ' ¿Quitarla de todos modos?')) return
      try {
        await api.delete(`/comisiones/reglas/${fila.id}`, { data: { confirmar: true } })
      } catch (e2) {
        toast.error(e2.response?.data?.message || 'No se pudo quitar.')
        return
      }
    } else {
      toast.error(e.response?.data?.message || 'No se pudo quitar.')
      return
    }
  }
  olvidarReglasVigentes()
  toast.success('Regla quitada. Las comisiones se recalculan en unos minutos.')
  await cargar()
}

const opcionesAlcance = computed(() => (reglaForm.value?.alcances ?? []).map(a => ({ id: a, nombre: ALCANCES[a] })))
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-24">
    <div class="flex items-center gap-2">
      <button type="button" @click="router.back()" class="text-gray-400 hover:text-gray-600" aria-label="Volver">
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
        <AdjustmentsHorizontalIcon class="w-6 h-6 text-green-600" />
        Reglas de comisión
      </h2>
    </div>

    <AppSpinner v-if="cargando && !datos" />

    <template v-else-if="datos">
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-2">
        <p class="text-sm text-gray-700 leading-snug">
          Las cifras con que se calculan las comisiones. Un cambio rige <b>desde el mes que escojas</b>:
          los meses anteriores no se mueven y lo ya pagado nunca cambia.
        </p>
        <p class="text-xs text-gray-500 leading-snug">
          Se puede cambiar para todos, para una tienda o para una persona: gana la más específica.
        </p>
        <p v-if="!puedeEditar" class="flex items-center gap-1.5 text-xs font-semibold text-amber-700">
          <LockClosedIcon class="w-4 h-4 shrink-0" />
          Solo lectura: cambiarlas necesita el permiso "Reglas de comisión".
        </p>
      </div>

      <section v-for="g in datos.grupos" :key="g.id" class="space-y-2">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide px-1">{{ g.titulo }}</p>

        <div v-for="clave in g.claves" :key="clave" class="bg-white rounded-xl shadow-sm p-4 space-y-2">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-800">{{ catalogo[clave].nombre }}</p>
              <p class="text-xs text-gray-500 mt-0.5 leading-snug">{{ catalogo[clave].explica }}</p>
            </div>
            <div class="text-right shrink-0 max-w-[45%]">
              <p
                class="font-bold text-gray-800 tabular-nums leading-tight"
                :class="['opcion', 'lista', 'tramos'].includes(catalogo[clave].tipo) ? 'text-sm' : 'text-base'"
              >{{ fmtValor(catalogo[clave], catalogo[clave].vigente) }}</p>
              <span
                v-if="esCambiada(catalogo[clave])"
                class="inline-block mt-0.5 rounded-full text-[10px] font-semibold px-2 py-0.5 border border-blue-200 text-blue-700"
              >Cambiada</span>
            </div>
          </div>

          <p v-if="esCambiada(catalogo[clave])" class="text-[11px] text-gray-400">
            Antes de cambiarla: {{ fmtValor(catalogo[clave], catalogo[clave].defecto) }}
          </p>

          <!-- Los cambios hechos: para todos, por tienda y por persona -->
          <ul v-if="filasDe(clave).length" class="divide-y divide-gray-100 border-t border-gray-100">
            <li v-for="f in filasDe(clave)" :key="f.id" class="py-2 flex items-start justify-between gap-2">
              <div class="min-w-0 text-xs">
                <p class="text-gray-700">
                  <span class="font-semibold">{{ f.alcance_nombre }}</span>
                  · desde {{ fmtMes(f.desde_mes) }}
                  · <span class="font-semibold tabular-nums">{{ fmtValor(catalogo[clave], f.valor) }}</span>
                </p>
                <p v-if="f.nota" class="text-gray-500 mt-0.5">{{ f.nota }}</p>
                <p class="text-[10px] text-gray-400 mt-0.5">{{ f.creado_por || '—' }}</p>
              </div>
              <button
                v-if="puedeEditar"
                type="button"
                @click="quitar(f)"
                class="text-gray-400 hover:text-red-600 shrink-0 p-1"
                :aria-label="`Quitar ${catalogo[clave].nombre} de ${f.alcance_nombre}`"
              >
                <TrashIcon class="w-4 h-4" />
              </button>
            </li>
          </ul>

          <div v-if="puedeEditar" class="flex justify-end">
            <button
              type="button"
              @click="abrir(clave)"
              class="flex items-center gap-1 text-xs font-semibold text-gray-600 border border-gray-200 bg-white rounded-lg px-2.5 py-1.5 hover:bg-gray-50"
            >
              <PencilIcon class="w-3.5 h-3.5" />
              Cambiar
            </button>
          </div>
        </div>
      </section>
    </template>

    <!-- Hoja para cambiar una regla -->
    <Teleport to="body">
      <div v-if="form" class="fixed inset-0 bg-black/40 z-[60] flex items-end sm:items-center justify-center" @click.self="cerrar">
        <div class="bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl p-4 space-y-3 max-h-[90vh] overflow-y-auto">
          <div class="flex items-start justify-between gap-2">
            <div>
              <h3 class="text-base font-bold text-gray-800">{{ reglaForm.nombre }}</h3>
              <p class="text-xs text-gray-500 mt-0.5">Hoy: {{ fmtValor(reglaForm, reglaForm.vigente) }}</p>
            </div>
            <button type="button" @click="cerrar" class="text-gray-400 hover:text-gray-600" aria-label="Cerrar">
              <XMarkIcon class="w-5 h-5" />
            </button>
          </div>

          <!-- Para quién -->
          <div v-if="opcionesAlcance.length > 1" class="bg-gray-100 rounded-xl p-1 flex gap-1">
            <button
              v-for="o in opcionesAlcance" :key="o.id" type="button"
              @click="form.alcance = o.id; form.alcance_id = null; simulacion = null"
              class="flex-1 text-xs font-semibold rounded-lg py-1.5"
              :class="form.alcance === o.id ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500'"
            >{{ o.nombre }}</button>
          </div>

          <label v-if="form.alcance === 'tienda'" class="block">
            <span class="text-xs font-semibold text-gray-600">Tienda</span>
            <select v-model.number="form.alcance_id" class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
              <option :value="null" disabled>Escoge la tienda</option>
              <option v-for="t in datos.tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
            </select>
          </label>
          <label v-if="form.alcance === 'usuario'" class="block">
            <span class="text-xs font-semibold text-gray-600">Persona</span>
            <select v-model.number="form.alcance_id" class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white">
              <option :value="null" disabled>Escoge la persona</option>
              <option v-for="p in datos.personas" :key="p.id" :value="p.id">{{ p.nombre }}{{ p.tienda ? ` · ${p.tienda}` : '' }}</option>
            </select>
          </label>

          <label v-if="form.alcance !== 'general'" class="flex items-start gap-2 text-xs text-gray-600">
            <input type="checkbox" v-model="form.sinExcepcion" class="mt-0.5 rounded border-gray-300 text-blue-600" />
            <span>Sin excepción desde ese mes: vuelve a regir la regla de arriba.</span>
          </label>

          <!-- El valor -->
          <div v-if="form.alcance === 'general' || !form.sinExcepcion">
            <span class="text-xs font-semibold text-gray-600">Nuevo valor</span>
            <div v-if="['fraccion', 'numero', 'entero'].includes(reglaForm.tipo)" class="mt-1 flex items-center gap-2">
              <input
                v-model="form.texto" inputmode="decimal"
                class="w-32 border border-gray-200 rounded-lg px-3 py-2 text-sm text-right tabular-nums bg-white"
                @input="simulacion = null"
              />
              <span class="text-sm text-gray-500">
                {{ reglaForm.tipo === 'fraccion' ? '%' : (reglaForm.clave === 'dia_pago' ? 'del mes' : (reglaForm.clave === 'meses_espera' ? 'meses después' : '')) }}
              </span>
            </div>
            <div v-else-if="reglaForm.tipo === 'si_no'" class="mt-1 bg-gray-100 rounded-xl p-1 flex gap-1 w-40">
              <button
                v-for="o in [{ v: true, n: 'Sí' }, { v: false, n: 'No' }]" :key="o.n" type="button"
                @click="form.si = o.v; simulacion = null"
                class="flex-1 text-xs font-semibold rounded-lg py-1.5"
                :class="form.si === o.v ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500'"
              >{{ o.n }}</button>
            </div>
            <div v-else-if="reglaForm.tipo === 'lista'" class="mt-1 flex flex-wrap gap-2">
              <label
                v-for="m in datos.metodos_pago" :key="m"
                class="flex items-center gap-1.5 rounded-full border border-gray-200 px-3 py-1 text-xs font-semibold text-gray-700"
              >
                <input type="checkbox" :value="m" v-model="form.lista" class="rounded border-gray-300 text-blue-600" @change="simulacion = null" />
                {{ METODOS[m] ?? m }}
              </label>
            </div>
            <div v-else-if="reglaForm.tipo === 'opcion'" class="mt-1 space-y-2">
              <button
                v-for="o in reglaForm.opciones" :key="o.valor" type="button"
                @click="form.opcion = o.valor; simulacion = null"
                class="w-full text-left rounded-xl border px-3 py-2"
                :class="form.opcion === o.valor ? 'border-blue-500 ring-1 ring-blue-500' : 'border-gray-200'"
              >
                <p class="text-xs font-semibold text-gray-800">{{ o.nombre }}</p>
                <p class="text-[11px] text-gray-500 mt-0.5 leading-snug">{{ o.explica }}</p>
              </button>
            </div>
            <div v-else-if="reglaForm.tipo === 'pesos'" class="mt-1 flex items-center gap-2">
              <span class="text-sm text-gray-500">$</span>
              <input
                v-model="form.pesos" inputmode="numeric"
                class="w-40 border border-gray-200 rounded-lg px-3 py-2 text-sm text-right tabular-nums bg-white"
                @input="simulacion = null"
              />
              <span class="text-xs text-gray-400">0 = sin bono</span>
            </div>
            <!-- Tramos: el primero empieza en la meta (por debajo no hay pool). -->
            <div v-else-if="reglaForm.tipo === 'tramos'" class="mt-1 space-y-2">
              <div v-for="(t, i) in form.tramos" :key="i" class="flex items-center gap-1.5 text-xs text-gray-600 flex-wrap">
                <span>Desde el</span>
                <input
                  v-model="t.desde" inputmode="decimal" :disabled="i === 0"
                  class="w-16 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right tabular-nums bg-white disabled:bg-gray-100 disabled:text-gray-500"
                  @input="simulacion = null"
                />
                <span>% de la meta paga</span>
                <input
                  v-model="t.pct" inputmode="decimal"
                  class="w-14 border border-gray-200 rounded-lg px-2 py-1.5 text-sm text-right tabular-nums bg-white"
                  @input="simulacion = null"
                />
                <span>%</span>
                <button
                  v-if="i > 0" type="button" @click="form.tramos.splice(i, 1); simulacion = null"
                  class="ml-auto text-[11px] font-semibold text-red-600"
                >Quitar</button>
              </div>
              <button
                v-if="form.tramos.length < 6" type="button" @click="agregarTramo"
                class="text-[11px] font-semibold text-blue-600"
              >+ Agregar tramo</button>
              <p class="text-[11px] text-gray-400 leading-snug">
                Cada tramo paga su porcentaje solo sobre lo vendido dentro de él. Ej.: desde el 100 % al 5 % y desde
                el 120 % al 6 %: lo que va de la meta al 120 % paga 5 % y lo de más arriba, 6 %.
              </p>
            </div>
            <p v-if="reglaForm.rango && reglaForm.tipo !== 'pesos'" class="text-[11px] text-gray-400 mt-1">Entre {{ reglaForm.rango[0] }} y {{ reglaForm.rango[1] }}.</p>
          </div>

          <label class="block">
            <span class="text-xs font-semibold text-gray-600">Desde el mes</span>
            <input v-model="form.desde_mes" type="month" class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" />
            <span v-if="form.desde_mes < datos.mes_actual" class="block text-[11px] text-amber-700 mt-1">
              Es un mes que ya pasó: solo se puede si no tiene comisiones pagadas, y te lo va a confirmar.
            </span>
          </label>

          <label class="block">
            <span class="text-xs font-semibold text-gray-600">Por qué (opcional)</span>
            <input v-model="form.nota" maxlength="200" placeholder="Ej.: acuerdo con el equipo de Norte" class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" />
          </label>

          <!-- Simulador -->
          <div class="border border-gray-200 rounded-xl p-3 space-y-2">
            <div class="flex items-end gap-2">
              <label class="flex-1">
                <span class="text-xs font-semibold text-gray-600">Simular con el mes</span>
                <input v-model="form.mesSimular" type="month" class="mt-1 w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" @change="simulacion = null" />
              </label>
              <button
                type="button" @click="simular" :disabled="simulando"
                class="flex items-center gap-1 text-xs font-semibold text-gray-600 border border-gray-200 bg-white rounded-lg px-2.5 py-2 hover:bg-gray-50 disabled:opacity-50"
              >
                <BeakerIcon class="w-3.5 h-3.5" />
                {{ simulando ? 'Calculando…' : 'Simular' }}
              </button>
            </div>
            <p class="text-[11px] text-gray-400 leading-snug">
              Cómo habría quedado ese mes con este valor. No guarda nada.
            </p>

            <template v-if="simulacion">
              <p v-if="simulacion.aviso" class="flex items-start gap-1.5 text-[11px] text-amber-700">
                <ExclamationTriangleIcon class="w-4 h-4 shrink-0" />{{ simulacion.aviso }}
              </p>
              <p class="text-xs text-gray-700 flex flex-wrap items-center gap-x-1 gap-y-1">
                <span>{{ fmtMes(simulacion.mes) }}:</span>
                <b class="tabular-nums whitespace-nowrap">{{ pesos(simulacion.antes) }}</b> →
                <b class="tabular-nums whitespace-nowrap">{{ pesos(simulacion.despues) }}</b>
                <span
                  class="rounded-full text-[10px] font-semibold px-2 py-0.5 border tabular-nums whitespace-nowrap"
                  :class="simulacion.diferencia > 0 ? 'border-green-300 text-green-700' : simulacion.diferencia < 0 ? 'border-red-300 text-red-700' : 'border-gray-200 text-gray-500'"
                >{{ simulacion.diferencia > 0 ? '+' : '' }}{{ pesos(simulacion.diferencia) }}</span>
              </p>
              <p v-if="!simulacion.cambian && !simulacion.aviso" class="text-xs text-gray-500">A nadie le cambia la comisión ese mes.</p>
              <ul v-else class="divide-y divide-gray-100 max-h-56 overflow-y-auto">
                <li v-for="p in simulacion.personas.filter(x => x.diferencia !== 0)" :key="p.vendedor_id + '_' + p.tienda" class="py-1.5 flex items-center justify-between gap-2 text-xs">
                  <div class="min-w-0">
                    <p class="font-semibold text-gray-700 truncate">{{ p.vendedor }}</p>
                    <p class="text-[10px] text-gray-400 truncate">{{ p.tienda }}</p>
                  </div>
                  <div class="text-right tabular-nums shrink-0">
                    <p class="text-gray-500">{{ pesos(p.antes) }} → {{ pesos(p.despues) }}</p>
                    <p class="font-semibold" :class="p.diferencia > 0 ? 'text-green-700' : 'text-red-700'">{{ p.diferencia > 0 ? '+' : '' }}{{ pesos(p.diferencia) }}</p>
                  </div>
                </li>
              </ul>
            </template>
          </div>

          <!-- El servidor pidió confirmar (mes pasado sin pagos) -->
          <div v-if="confirmar" class="border border-amber-300 rounded-xl p-3 space-y-2">
            <p class="text-xs text-amber-800">{{ confirmar }}</p>
            <button
              type="button" @click="guardar(true)" :disabled="guardando"
              class="w-full bg-amber-600 text-white rounded-lg text-xs font-semibold py-2 disabled:opacity-50"
            >Sí, aplicar desde {{ fmtMes(form.desde_mes) }}</button>
          </div>

          <button
            v-else
            type="button" @click="guardar(false)" :disabled="guardando"
            class="w-full bg-blue-600 text-white rounded-lg text-xs font-semibold py-2.5 disabled:opacity-50"
          >{{ guardando ? 'Guardando…' : 'Guardar' }}</button>
        </div>
      </div>
    </Teleport>
  </div>
</template>
