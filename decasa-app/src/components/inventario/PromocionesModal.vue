<script setup>
/**
 * Inventario → Promociones (supervisor).
 *
 * Se programa una vez: qué se rebaja (el producto entero o una versión suya,
 * como la cama Bali de 1.40), en qué tiendas, cuánto y de qué fecha a qué
 * fecha. El precio de lista no se toca: Nueva orden pone el de promoción
 * solo mientras corre, y al terminar vuelve el normal sin que nadie haga nada.
 */
import { ref, computed, watch } from 'vue'
import api from '@/api'
import { getPromociones, crearPromocion, actualizarPromocion, eliminarPromocion } from '@/api/promociones'
import { useToast } from '@/composables/useToast'
import { cloudinaryOpt } from '@/utils/cloudinary'
import { formatearPesos } from '@/utils/pesos'
import { textoRebaja } from '@/utils/promociones'
import InputPesos from '@/components/common/InputPesos.vue'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { MagnifyingGlassIcon, PhotoIcon, PlusIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show:    { type: Boolean, default: false },
  tiendas: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'cambio'])
const toast = useToast()

const pesos = (v) => '$' + formatearPesos(v ?? 0)

// ── Lista ─────────────────────────────────────────────────────────────────────
const promociones = ref([])
const cargando    = ref(false)
const pestana     = ref('activas')   // 'activas' | 'terminadas'

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getPromociones()
    promociones.value = data ?? []
  } catch {
    toast.error('No se pudieron cargar las promociones.')
  } finally {
    cargando.value = false
  }
}

watch(() => props.show, (abierto) => {
  if (!abierto) return
  formulario.value = null
  cargar()
})

const lista = computed(() => promociones.value.filter(p =>
  pestana.value === 'activas'
    ? ['vigente', 'programada'].includes(p.estado)
    : ['terminada', 'apagada'].includes(p.estado)
))
const cuantasActivas = computed(() => promociones.value.filter(p => ['vigente', 'programada'].includes(p.estado)).length)

const ESTADOS = {
  vigente:    { texto: 'Corriendo',  cls: 'bg-green-100 text-green-700' },
  programada: { texto: 'Programada', cls: 'bg-blue-100 text-blue-700' },
  terminada:  { texto: 'Terminó',    cls: 'bg-gray-100 text-gray-600' },
  apagada:    { texto: 'Apagada',    cls: 'bg-gray-100 text-gray-600' },
}

const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
function fechaCorta(f) {
  if (!f) return ''
  const [a, m, d] = String(f).slice(0, 10).split('-').map(Number)
  return `${d} ${MESES[m - 1]}${a !== new Date().getFullYear() ? ' ' + a : ''}`
}
/** "CAMA BALI", o "CAMA BALI, COMEDOR ROMA y 3 más" si lleva varios. */
function tituloDe(p) {
  const nombres = (p.productos ?? []).map(x => x.producto?.nombre).filter(Boolean)
  if (nombres.length <= 1) return p.producto?.nombre ?? 'Producto'
  if (nombres.length === 2) return `${nombres[0]} y ${nombres[1]}`
  return `${nombres[0]}, ${nombres[1]} y ${nombres.length - 2} más`
}
function nombreCorto(nombre) {
  return (nombre ?? '').replace(/^Decasa\s+/i, '').trim() || nombre
}

const ocupado = ref(null)   // id de la que se está apagando/borrando

async function alternar(p) {
  ocupado.value = p.id
  try {
    const { data } = await actualizarPromocion(p.id, { activa: !p.activa })
    Object.assign(p, data)
    toast.success(data.activa ? 'Promoción encendida.' : 'Promoción apagada: desde ya se vende a precio normal.')
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo cambiar.')
  } finally {
    ocupado.value = null
  }
}

async function borrar(p) {
  if (!confirm(`¿Borrar la promoción de ${p.producto?.nombre ?? 'este producto'}?`)) return
  ocupado.value = p.id
  try {
    await eliminarPromocion(p.id)
    promociones.value = promociones.value.filter(x => x.id !== p.id)
    toast.success('Promoción borrada.')
    emit('cambio')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo borrar.')
  } finally {
    ocupado.value = null
  }
}

// ── Formulario ────────────────────────────────────────────────────────────────
function hoy() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Una promoción lleva uno o varios productos con las mismas reglas. Lo normal
// es uno; "Agregar otro producto" es para no programar la misma rebaja cinco
// veces. Por cada producto con versiones se pregunta a cuáles aplica.
//   entrada = { producto, versiones, cargando, alcance: 'todas'|'algunas'|'sin_versiones', marcadas: [clave] }
const formulario = ref(null)   // { id?, entradas, buscandoOtro, tipo, valor, fecha_inicio, fecha_fin, tiendas, nombre }
const guardando  = ref(false)
const errorForm  = ref('')

const ALCANCES = [
  { v: 'todas',         l: 'Todas',        d: 'Todas sus medidas, telas y opciones, cada una sobre su propio precio' },
  { v: 'algunas',       l: 'Solo algunas', d: 'Escoge cuáles' },
  { v: 'sin_versiones', l: 'Ninguna',      d: 'Solo cuando se vende sin escoger medida, tela ni opción' },
]

function nueva() {
  errorForm.value = ''
  busqueda.value = ''
  resultados.value = []
  formulario.value = {
    id: null, entradas: [], buscandoOtro: false,
    tipo: 'porcentaje', valor: null,
    fecha_inicio: hoy(), fecha_fin: '',
    tiendas: [], nombre: '',
  }
}

async function editar(p) {
  errorForm.value = ''
  busqueda.value = ''
  resultados.value = []
  const productos = p.productos?.length
    ? p.productos
    : [{ producto_id: p.producto_id, producto: p.producto, alcance: 'todas', config_ids: [], variante_ids: [] }]
  formulario.value = {
    id: p.id,
    entradas: productos.map(x => ({
      producto: { ...x.producto, id: x.producto_id },
      versiones: [], cargando: true,
      alcance: x.alcance ?? 'todas',
      marcadas: [...(x.config_ids ?? []).map(id => `c${id}`), ...(x.variante_ids ?? []).map(id => `v${id}`)],
    })),
    buscandoOtro: false,
    tipo: p.tipo, valor: p.valor,
    fecha_inicio: p.fecha_inicio, fecha_fin: p.fecha_fin,
    tiendas: p.tiendas.map(t => t.id), nombre: p.nombre ?? '',
  }
  await Promise.all(formulario.value.entradas.map(cargarVersiones))
}

// Buscar el producto
const busqueda   = ref('')
const resultados = ref([])
const buscando   = ref(false)
let temporizador = null
watch(busqueda, (q) => {
  clearTimeout(temporizador)
  if (q.trim().length < 2) { resultados.value = []; return }
  temporizador = setTimeout(buscar, 300)
})
async function buscar() {
  buscando.value = true
  try {
    const { data } = await api.get('/productos', { params: { search: busqueda.value.trim(), limit: 20 } })
    resultados.value = data ?? []
  } catch {
    resultados.value = []
  } finally {
    buscando.value = false
  }
}
// Lo que ya está en la promoción no se ofrece otra vez.
const resultadosLibres = computed(() => {
  const ya = new Set((formulario.value?.entradas ?? []).map(e => Number(e.producto.id)))
  return resultados.value.filter(r => !ya.has(Number(r.id)))
})
const mostrarBuscador = computed(() => !!formulario.value && (!formulario.value.entradas.length || formulario.value.buscandoOtro))

// Las versiones de un producto: el producto entero, cada opción (medida,
// "con baúl"…) y cada tela/medida de las variantes. Con el precio de lista
// que pone Nueva orden.
async function cargarVersiones(entrada) {
  entrada.cargando = true
  try {
    const productoId = entrada.producto.id
    const [prod, configs, variantes] = await Promise.all([
      api.get(`/productos/${productoId}`).then(r => r.data).catch(() => null),
      api.get(`/productos/${productoId}/variante-configs`).then(r => r.data).catch(() => []),
      api.get(`/productos/${productoId}/variantes`).then(r => r.data).catch(() => []),
    ])
    const base = Number(prod?.precio_base ?? entrada.producto.precio_base ?? 0)
    if (prod) entrada.producto = { ...entrada.producto, ...prod }
    const lista = [{ clave: 'todo', texto: 'Todo el producto', grupo: null, config_id: null, variante_id: null, precio: base }]
    for (const g of configs ?? []) {
      for (const o of g.items ?? []) {
        lista.push({
          clave: `c${o.id}`, texto: o.opcion_nombre, grupo: g.tipo?.nombre ?? 'Opciones',
          config_id: o.id, variante_id: null,
          precio: Number(o.precio_adicional) > 0 ? Number(o.precio_adicional) : base,
        })
      }
    }
    for (const v of Array.isArray(variantes) ? variantes : []) {
      if (v._config_id) continue   // combinaciones tela × opción: van por la opción
      lista.push({
        clave: `v${v.id}`,
        texto: v.medida || [v.marca, v.marca_tela, v.nombre_color].filter(Boolean).join(' · ') || `Variante ${v.id}`,
        grupo: v.medida ? 'Medidas' : 'Telas',
        config_id: null, variante_id: v.id,
        precio: v.precio_variante != null ? Number(v.precio_variante) : base,
      })
    }
    entrada.versiones = lista
    // Una versión marcada que ya no existe (la borraron) no se manda.
    const existen = new Set(lista.map(v => v.clave))
    entrada.marcadas = entrada.marcadas.filter(c => existen.has(c))
    if (lista.length <= 1) entrada.alcance = 'todas'
  } finally {
    entrada.cargando = false
  }
}

async function elegirProducto(p) {
  const f = formulario.value
  f.entradas.push({ producto: p, versiones: [], cargando: true, alcance: 'todas', marcadas: [] })
  f.buscandoOtro = false
  resultados.value = []
  busqueda.value = ''
  // El objeto reactivo (el que está en la lista), no el de antes de meterlo.
  await cargarVersiones(f.entradas[f.entradas.length - 1])
}

function quitarEntrada(i) {
  formulario.value.entradas.splice(i, 1)
}

function gruposDe(entrada) {
  const grupos = []
  for (const v of entrada.versiones.slice(1)) {
    let g = grupos.find(x => x.nombre === v.grupo)
    if (!g) grupos.push(g = { nombre: v.grupo, items: [] })
    g.items.push(v)
  }
  return grupos
}
const tieneVersiones = (entrada) => entrada.versiones.length > 1

function alternarVersion(entrada, clave) {
  entrada.marcadas = entrada.marcadas.includes(clave)
    ? entrada.marcadas.filter(c => c !== clave)
    : [...entrada.marcadas, clave]
}
function marcarTodasLasVersiones(entrada) {
  const todas = entrada.versiones.slice(1).map(v => v.clave)
  entrada.marcadas = entrada.marcadas.length === todas.length ? [] : todas
}

// Con versiones de distinto precio, "precio final" a todo el producto las
// dejaría iguales: el servidor lo frena, aquí ni se ofrece.
function versionesConPrecioDistinto(entrada) {
  const base = entrada.versiones[0]?.precio ?? 0
  return entrada.versiones.slice(1).some(v => v.precio !== base)
}
const puedePrecioFinal = computed(() => {
  const f = formulario.value
  return !!f?.entradas.length && f.entradas.every(e => e.alcance !== 'todas' || !versionesConPrecioDistinto(e))
})
watch(puedePrecioFinal, (si) => {
  if (!si && formulario.value?.tipo === 'precio') formulario.value.tipo = 'porcentaje'
})

function alternarTienda(id) {
  const t = formulario.value.tiendas
  formulario.value.tiendas = t.includes(id) ? t.filter(x => x !== id) : [...t, id]
}
const todasMarcadas = computed(() => props.tiendas.length > 0 && props.tiendas.every(t => formulario.value?.tiendas.includes(t.id)))
function marcarTodas() {
  formulario.value.tiendas = todasMarcadas.value ? [] : props.tiendas.map(t => t.id)
}

// Cómo queda: lo mismo que Promocion::precioCon.
function precioCon(lista) {
  const f = formulario.value
  const valor = Number(f?.valor) || 0
  if (!f || !valor) return null
  let queda = lista
  if (f.tipo === 'porcentaje') queda = lista - Math.round(lista * valor / 100)
  else if (f.tipo === 'monto') queda = lista - valor
  else queda = Math.min(lista, valor)
  return Math.max(0, Math.min(lista, queda))
}
/** Lo que se rebaja de esa entrada, para mostrar lista → promoción. */
function filasDePrecio(entrada) {
  if (entrada.alcance === 'algunas') return entrada.versiones.filter(v => entrada.marcadas.includes(v.clave))
  return entrada.versiones.slice(0, 1)
}

const entradaIncompleta = (e) => e.cargando || (e.alcance === 'algunas' && !e.marcadas.length)
const puedeGuardar = computed(() => {
  const f = formulario.value
  return f && f.entradas.length && !f.entradas.some(entradaIncompleta)
    && Number(f.valor) > 0 && f.fecha_inicio && f.fecha_fin && f.tiendas.length
})

async function guardar() {
  const f = formulario.value
  errorForm.value = ''
  if (f.fecha_fin < f.fecha_inicio) { errorForm.value = 'La fecha final no puede ser antes de la inicial.'; return }
  guardando.value = true
  try {
    const payload = {
      nombre:       f.nombre.trim() || null,
      productos:    f.entradas.map(e => {
        const marcadas = e.alcance === 'algunas' ? e.versiones.filter(v => e.marcadas.includes(v.clave)) : []
        return {
          producto_id:  e.producto.id,
          alcance:      e.alcance,
          config_ids:   marcadas.filter(v => v.config_id).map(v => v.config_id),
          variante_ids: marcadas.filter(v => v.variante_id).map(v => v.variante_id),
        }
      }),
      tipo:         f.tipo,
      valor:        Number(f.valor),
      fecha_inicio: f.fecha_inicio,
      fecha_fin:    f.fecha_fin,
      tiendas:      f.tiendas,
    }
    if (f.id) await actualizarPromocion(f.id, payload)
    else await crearPromocion(payload)
    toast.success(f.id ? 'Promoción actualizada.' : 'Promoción programada.')
    formulario.value = null
    pestana.value = 'activas'
    emit('cambio')
    await cargar()
  } catch (e) {
    const errores = e.response?.data?.errors
    errorForm.value = (errores && Object.values(errores)[0]?.[0]) || e.response?.data?.message || 'No se pudo guardar.'
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Transition name="fade">
    <!-- z-[60]: por encima del botón del asistente (z-50), que tapaba el formulario. -->
    <div v-if="show" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="emit('close')">
      <div class="absolute inset-0 bg-black/40" @click="emit('close')" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg max-h-[90vh] flex flex-col">
        <!-- Encabezado -->
        <div class="flex items-center justify-between px-5 pt-5 pb-3 border-b border-gray-100 flex-shrink-0">
          <div class="min-w-0">
            <h3 class="text-lg font-bold text-gray-800">
              {{ formulario ? (formulario.id ? 'Editar promoción' : 'Nueva promoción') : 'Promociones' }}
            </h3>
            <p class="text-xs text-gray-500 mt-0.5">
              Precio rebajado por tienda y por fechas. El precio de lista no cambia.
            </p>
          </div>
          <button @click="emit('close')" class="text-gray-400 text-2xl leading-none ml-3">&times;</button>
        </div>

        <!-- ── Lista ─────────────────────────────────────────────────────── -->
        <template v-if="!formulario">
          <div class="px-5 pt-3 flex items-center gap-2 flex-shrink-0">
            <div class="flex-1 bg-gray-100 rounded-xl p-1 flex text-xs font-semibold">
              <button
                v-for="t in [{ v: 'activas', l: `Activas${cuantasActivas ? ' (' + cuantasActivas + ')' : ''}` }, { v: 'terminadas', l: 'Terminadas' }]"
                :key="t.v" type="button" @click="pestana = t.v"
                :class="['flex-1 py-1.5 rounded-lg transition-colors', pestana === t.v ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
              >{{ t.l }}</button>
            </div>
            <button
              type="button" @click="nueva"
              class="flex items-center gap-1 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-lg hover:bg-blue-700 transition-colors whitespace-nowrap"
            >
              <PlusIcon class="w-4 h-4" /> Nueva
            </button>
          </div>

          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-2.5">
            <div v-if="cargando" class="flex justify-center py-8"><AppSpinner /></div>
            <p v-else-if="!lista.length" class="text-sm text-gray-400 text-center py-8">
              {{ pestana === 'activas' ? 'No hay promociones corriendo ni programadas.' : 'Todavía no ha terminado ninguna.' }}
            </p>
            <div
              v-for="p in lista" :key="p.id"
              class="border border-gray-200 rounded-xl p-3 space-y-2"
            >
              <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center flex-shrink-0">
                  <img v-if="p.producto?.foto_url" :src="cloudinaryOpt(p.producto.foto_url, 80)" class="w-full h-full object-cover" alt="" />
                  <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
                </div>
                <div class="flex-1 min-w-0">
                  <!-- Con varios productos el "y 3 más" no se puede cortar: dos renglones. -->
                  <p :class="['text-sm font-semibold text-gray-800', (p.productos?.length ?? 1) > 1 ? 'line-clamp-2' : 'truncate']">{{ tituloDe(p) }}</p>
                  <p class="text-xs text-gray-500 truncate">
                    <template v-if="(p.productos?.length ?? 1) > 1">{{ p.productos.length }} productos</template>
                    <template v-else>{{ p.version ? p.version : 'Todo el producto' }}</template>
                    <span v-if="p.nombre"> · {{ p.nombre }}</span>
                  </p>
                </div>
                <span :class="['rounded-full text-xs font-semibold px-2 py-0.5 flex-shrink-0', ESTADOS[p.estado]?.cls]">
                  {{ ESTADOS[p.estado]?.texto }}
                </span>
              </div>

              <div class="flex items-baseline gap-1.5 flex-wrap text-xs">
                <span class="bg-rose-100 text-rose-700 rounded-full font-semibold px-2 py-0.5">{{ textoRebaja(p) }}</span>
                <template v-if="p.precio_lista != null && (p.version || p.tipo === 'precio' || p.precio_promo !== p.precio_lista)">
                  <span class="text-gray-400 line-through">{{ pesos(p.precio_lista) }}</span>
                  <span class="text-gray-400">→</span>
                  <span class="font-bold text-gray-800">{{ pesos(p.precio_promo) }}</span>
                </template>
                <span class="text-gray-500 ml-auto">{{ fechaCorta(p.fecha_inicio) }} – {{ fechaCorta(p.fecha_fin) }}</span>
              </div>

              <div class="flex flex-wrap gap-1">
                <span v-for="t in p.tiendas" :key="t.id" class="bg-gray-100 text-gray-600 rounded-full text-xs font-medium px-2 py-0.5">
                  {{ nombreCorto(t.nombre) }}
                </span>
              </div>

              <div class="flex items-center gap-3 pt-0.5 text-xs font-semibold">
                <span v-if="p.usos" class="text-gray-400 font-normal">{{ p.usos }} {{ p.usos === 1 ? 'venta' : 'ventas' }} con ella</span>
                <div class="ml-auto flex items-center gap-3">
                  <button v-if="p.estado !== 'terminada'" type="button" @click="editar(p)" class="text-blue-600 hover:text-blue-800">Editar</button>
                  <button
                    v-if="p.estado !== 'terminada'"
                    type="button" :disabled="ocupado === p.id" @click="alternar(p)"
                    class="text-gray-600 hover:text-gray-800 disabled:opacity-50"
                  >{{ p.activa ? 'Apagar' : 'Encender' }}</button>
                  <button
                    v-if="!p.usos"
                    type="button" :disabled="ocupado === p.id" @click="borrar(p)"
                    class="text-red-500 hover:text-red-700 disabled:opacity-50"
                  >Borrar</button>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- ── Formulario ────────────────────────────────────────────────── -->
        <template v-else>
          <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
            <!-- 1. Productos (uno, o varios con la misma promoción) -->
            <div>
              <label class="block text-xs font-medium text-gray-500 mb-1">
                {{ formulario.entradas.length > 1 ? `Productos (${formulario.entradas.length})` : 'Producto' }}
              </label>

              <div class="space-y-2">
                <div
                  v-for="(e, i) in formulario.entradas" :key="e.producto.id"
                  class="border border-gray-200 rounded-xl p-2.5 space-y-2"
                >
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center flex-shrink-0">
                      <img v-if="e.producto.foto_url" :src="cloudinaryOpt(e.producto.foto_url, 80)" class="w-full h-full object-cover" alt="" />
                      <PhotoIcon v-else class="w-5 h-5 text-gray-300" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-semibold text-gray-800 truncate">{{ e.producto.nombre }}</p>
                      <p class="text-xs text-gray-400 truncate">{{ e.producto.categoria }}</p>
                    </div>
                    <button type="button" @click="quitarEntrada(i)" class="text-xs font-semibold text-red-500">Quitar</button>
                  </div>

                  <div v-if="e.cargando" class="flex justify-center py-2"><AppSpinner /></div>
                  <template v-else>
                    <!-- ¿A cuáles versiones? Solo si tiene. -->
                    <div v-if="tieneVersiones(e)">
                      <p class="text-xs text-gray-500 mb-1">¿A cuáles versiones aplica?</p>
                      <div class="bg-gray-100 rounded-xl p-1 flex text-xs font-semibold">
                        <button
                          v-for="a in ALCANCES" :key="a.v" type="button" @click="e.alcance = a.v"
                          :class="['flex-1 py-1.5 rounded-lg transition-colors', e.alcance === a.v ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
                        >{{ a.l }}</button>
                      </div>
                      <p class="text-xs text-gray-400 mt-1">{{ ALCANCES.find(a => a.v === e.alcance)?.d }}</p>

                      <div v-if="e.alcance === 'algunas'" class="mt-2 space-y-2">
                        <div v-for="g in gruposDe(e)" :key="g.nombre">
                          <p class="text-xs text-gray-400 mb-1">{{ g.nombre }}</p>
                          <div class="flex flex-wrap gap-1.5">
                            <button
                              v-for="v in g.items" :key="v.clave" type="button" @click="alternarVersion(e, v.clave)"
                              :class="['rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                                e.marcadas.includes(v.clave) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300']"
                            >{{ v.texto }} <span class="font-normal opacity-75">{{ pesos(v.precio) }}</span></button>
                          </div>
                        </div>
                        <div class="flex items-center justify-between">
                          <p v-if="!e.marcadas.length" class="text-xs text-amber-600">Marca al menos una.</p>
                          <button type="button" @click="marcarTodasLasVersiones(e)" class="text-xs font-semibold text-blue-600 ml-auto">
                            {{ e.marcadas.length === e.versiones.length - 1 ? 'Desmarcar todas' : 'Marcar todas' }}
                          </button>
                        </div>
                      </div>
                    </div>

                    <!-- Cómo queda -->
                    <div v-if="Number(formulario.valor) > 0" class="space-y-0.5">
                      <div v-for="v in filasDePrecio(e)" :key="v.clave" class="text-xs flex items-baseline gap-1.5 flex-wrap">
                        <span v-if="e.alcance === 'algunas'" class="text-gray-500">{{ v.texto }}</span>
                        <span class="text-gray-400 line-through">{{ pesos(v.precio) }}</span>
                        <span class="text-gray-400">→</span>
                        <span class="font-bold text-gray-800">{{ pesos(precioCon(v.precio)) }}</span>
                        <span v-if="e.alcance === 'todas' && tieneVersiones(e)" class="text-gray-400">(y cada versión sobre su precio)</span>
                      </div>
                    </div>
                  </template>
                </div>
              </div>

              <!-- Buscar: el primero, u otro con la misma promoción -->
              <div v-if="mostrarBuscador" :class="formulario.entradas.length ? 'mt-2' : ''">
                <div class="relative">
                  <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                  <input
                    v-model="busqueda" placeholder="Buscar en el inventario…" autofocus
                    class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div v-if="buscando" class="flex justify-center py-3"><AppSpinner /></div>
                <div v-else-if="resultadosLibres.length" class="mt-2 border border-gray-200 rounded-xl divide-y divide-gray-100 max-h-60 overflow-y-auto">
                  <button
                    v-for="r in resultadosLibres" :key="r.id" type="button" @click="elegirProducto(r)"
                    class="w-full text-left px-3 py-2 flex items-center gap-2 hover:bg-gray-50"
                  >
                    <span class="flex-1 min-w-0">
                      <span class="block text-sm font-medium text-gray-800 truncate">{{ r.nombre }}</span>
                      <span class="block text-xs text-gray-400 truncate">{{ r.categoria }}</span>
                    </span>
                    <span class="text-xs font-semibold text-gray-600 flex-shrink-0">{{ pesos(r.precio_base) }}</span>
                  </button>
                </div>
                <p v-else-if="resultados.length" class="text-xs text-gray-400 mt-2">Lo que coincide con “{{ busqueda.trim() }}” ya está en esta promoción.</p>
                <p v-else-if="busqueda.trim().length >= 2" class="text-xs text-gray-400 mt-2">No se encontró nada con “{{ busqueda.trim() }}”.</p>
                <button
                  v-if="formulario.entradas.length" type="button"
                  @click="formulario.buscandoOtro = false; busqueda = ''"
                  class="text-xs font-semibold text-gray-500 mt-2"
                >Cancelar</button>
              </div>
              <button
                v-else-if="formulario.entradas.length" type="button" @click="formulario.buscandoOtro = true"
                class="mt-2 flex items-center gap-1 text-xs font-semibold text-blue-600"
              >
                <PlusIcon class="w-4 h-4" /> Agregar otro producto con esta misma promoción
              </button>
            </div>

            <!-- 2. Tiendas -->
            <div v-if="formulario.entradas.length">
              <div class="flex items-center justify-between mb-1">
                <label class="text-xs font-medium text-gray-500">¿En qué tiendas?</label>
                <button type="button" @click="marcarTodas" class="text-xs font-semibold text-blue-600">
                  {{ todasMarcadas ? 'Quitar todas' : 'Todas' }}
                </button>
              </div>
              <div class="flex flex-wrap gap-1.5">
                <button
                  v-for="t in tiendas" :key="t.id" type="button" @click="alternarTienda(t.id)"
                  :class="['rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                    formulario.tiendas.includes(t.id) ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300']"
                >{{ nombreCorto(t.nombre) }}</button>
              </div>
            </div>

            <!-- 3. Cuánto -->
            <div v-if="formulario.entradas.length">
              <label class="block text-xs font-medium text-gray-500 mb-1">¿Cuánto?</label>
              <div class="bg-gray-100 rounded-xl p-1 flex text-xs font-semibold mb-2">
                <button
                  v-for="t in [{ v: 'porcentaje', l: '% menos' }, { v: 'monto', l: '$ menos' }, { v: 'precio', l: 'Precio final' }]"
                  :key="t.v" type="button"
                  :disabled="t.v === 'precio' && !puedePrecioFinal"
                  :title="t.v === 'precio' && !puedePrecioFinal ? 'Un producto con todas sus versiones tiene precios distintos: escoge cuáles para dejarles un precio final' : ''"
                  @click="formulario.tipo = t.v; formulario.valor = null"
                  :class="['flex-1 py-1.5 rounded-lg transition-colors disabled:opacity-40',
                    formulario.tipo === t.v ? 'bg-white shadow-sm text-gray-800' : 'text-gray-500']"
                >{{ t.l }}</button>
              </div>
              <div class="flex items-center gap-2">
                <div v-if="formulario.tipo === 'porcentaje'" class="relative w-28">
                  <input
                    v-model.number="formulario.valor" type="number" min="1" max="99" step="1" placeholder="15"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-7 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                  <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">%</span>
                </div>
                <InputPesos
                  v-else v-model="formulario.valor"
                  :placeholder="formulario.tipo === 'precio' ? 'Precio de promoción' : 'Cuánto se le quita'"
                  class="w-40 rounded-lg border border-gray-300 px-3 py-2 text-sm text-right focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <p class="text-xs text-gray-400 mt-1">
                {{ formulario.entradas.length > 1 ? 'La misma rebaja para todos los productos de arriba; ahí ves cómo queda cada uno.' : 'Arriba ves cómo queda el precio.' }}
                Si una medida tiene su propia promoción, vale la de la medida.
              </p>
            </div>

            <!-- 4. Fechas -->
            <div v-if="formulario.entradas.length" class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Desde</label>
                <input v-model="formulario.fecha_inicio" type="date"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 mb-1">Hasta (incluido)</label>
                <input v-model="formulario.fecha_fin" type="date" :min="formulario.fecha_inicio"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
            </div>

            <!-- 5. Nombre -->
            <div v-if="formulario.entradas.length">
              <label class="block text-xs font-medium text-gray-500 mb-1">Nombre (opcional)</label>
              <input v-model="formulario.nombre" maxlength="120" placeholder="Ej.: Feria de octubre"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            </div>

            <p v-if="errorForm" class="text-xs text-red-600">{{ errorForm }}</p>
          </div>

          <div class="px-5 py-3 border-t border-gray-100 flex gap-2 flex-shrink-0">
            <button type="button" @click="formulario = null"
              class="flex-1 border border-gray-300 text-gray-700 text-sm font-semibold py-2 rounded-lg hover:bg-gray-50">
              Volver
            </button>
            <button type="button" :disabled="!puedeGuardar || guardando" @click="guardar"
              class="flex-1 bg-blue-600 text-white text-sm font-semibold py-2 rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {{ guardando ? 'Guardando…' : (formulario.id ? 'Guardar cambios' : 'Programar') }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </Transition>
</template>
