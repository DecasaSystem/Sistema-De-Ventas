<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import { getVariantes } from '@/api/inventario'
import { updateCliente, CATEGORIAS_DISPONIBLES } from '@/api/clientes'
import { ArrowPathIcon, SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'
import { ArrowPathIcon as ArrowPathOutlineIcon, PhotoIcon, UserGroupIcon, ArrowPathIcon as ConvertIcon, ExclamationTriangleIcon, PencilIcon, MapPinIcon, SwatchIcon } from '@heroicons/vue/24/outline'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import BocetoCanvas from '@/components/BocetoCanvas.vue'
import DireccionColombia from '@/components/DireccionColombia.vue'

const router = useRouter()
const auth   = useAuthStore()
const toast  = useToast()

// ── Pasos ─────────────────────────────────────────────────────────────────────
const step = ref(1)

// ── Tiendas ───────────────────────────────────────────────────────────────────
const tiendas = ref([])
onMounted(async () => {
  const { data } = await api.get('/tiendas')
  tiendas.value = data
})

// ── Paso 1: Cliente ───────────────────────────────────────────────────────────
const clienteQuery     = ref('')
const clienteResultados = ref([])
const clienteSeleccionado = ref(null)
const buscandoCliente   = ref(false)
const modoNuevoCliente  = ref(false)
const nuevoCliente = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '', tipo: 'oficial', categorias_interes: [], notas_interes: '' })
const creandoCliente = ref(false)
const errCliente = ref('')
const convirtiendoCliente = ref(false)

async function buscarCliente() {
  if (!clienteQuery.value.trim()) return
  buscandoCliente.value = true
  try {
    const { data } = await api.get('/clientes', { params: { search: clienteQuery.value } })
    clienteResultados.value = data.data ?? []
  } finally {
    buscandoCliente.value = false
  }
}

function seleccionarCliente(c) {
  clienteSeleccionado.value = c
  clienteResultados.value = []
  clienteQuery.value = c.nombre

  // Si es cliente interesado, sugerir conversión
  if (c.tipo === 'interesado') {
    if (confirm(`El cliente "${c.nombre}" está marcado como "Interesado". ¿Convertir a cliente oficial ahora?`)) {
      convertirAOficial(c)
    }
  }
}

async function convertirAOficial(cliente) {
  convirtiendoCliente.value = true
  try {
    await updateCliente(cliente.id, { tipo: 'oficial' })
    clienteSeleccionado.value.tipo = 'oficial'
  } catch (e) {
    alert('Error al convertir cliente: ' + (e.response?.data?.message ?? 'Error desconocido'))
  } finally {
    convirtiendoCliente.value = false
  }
}

async function crearCliente() {
  errCliente.value = ''
  creandoCliente.value = true
  try {
    const { data } = await api.post('/clientes', nuevoCliente.value)
    seleccionarCliente(data)
    modoNuevoCliente.value = false
    nuevoCliente.value = { nombre: '', cedula: '', telefono: '', email: '', direccion: '', tipo: 'oficial', categorias_interes: [], notas_interes: '' }
  } catch (e) {
    errCliente.value = e.response?.data?.message ?? 'Error al crear cliente'
  } finally {
    creandoCliente.value = false
  }
}


// ── Paso 1: Tienda + Canal ────────────────────────────────────────────────────
const tiendaId = ref(auth.usuario?.tienda_default_id ?? '')
const canal = ref('fisica')

const canalesopts = [
  { value: 'fisica',     label: 'Física' },
  { value: 'whatsapp',   label: 'WhatsApp' },
  { value: 'red_social', label: 'Red social' },
  { value: 'otro',       label: 'Otro' },
]

function paso1Valido() {
  return clienteSeleccionado.value && tiendaId.value && canal.value
}

// ── Paso 2: Productos / Carrito ───────────────────────────────────────────────
const productoQuery = ref('')
const productoResultados = ref([])
const buscandoProducto = ref(false)
const items = ref([])
const tiendaBusqueda = ref(auth.usuario?.tienda_default_id ?? '')

// Producto no catalogado
const modoProductoCustom = ref(false)
const productoCustomForm = ref({ nombre: '', categoria: '', precio_unitario: 0, cantidad: 1 })

function agregarProductoCustom() {
  const f = productoCustomForm.value
  if (!f.nombre.trim() || !f.precio_unitario || f.cantidad < 1) return
  items.value.push({
    producto_id: null,
    variante_id: null,
    tienda_origen_id: null,
    nombre: f.nombre.trim(),
    nombre_custom: f.nombre.trim(),
    categoria: f.categoria.trim() || null,
    categoria_custom: f.categoria.trim() || null,
    variante_label: null,
    stock_libre: null,
    personalizable: false,
    cantidad: f.cantidad,
    precio_unitario: f.precio_unitario,
    es_personalizado: true,
    specs_descripcion: '',
    tienda_origen: null,
    fecha_entrega_prometida: null,
    boceto_blob: null,
    boceto_url: '',
    boceto_preview: null,
  })
  productoCustomForm.value = { nombre: '', categoria: '', precio_unitario: 0, cantidad: 1 }
  modoProductoCustom.value = false
}

async function buscarProducto() {
  if (!productoQuery.value.trim()) return
  buscandoProducto.value = true
  try {
    const { data } = await api.get('/productos', {
      params: { search: productoQuery.value, tienda_id: tiendaBusqueda.value || tiendaId.value },
    })
    productoResultados.value = data
  } finally {
    buscandoProducto.value = false
  }
}

function stockLibre(p) {
  return (p.stock_disponible ?? 0) - (p.stock_reservado ?? 0)
}

function nombreTiendaBusqueda() {
  return tiendas.value.find(t => t.id == tiendaBusqueda.value)?.nombre ?? ''
}

// ── Selector de variante ──────────────────────────────────────────────────────
const mostrarVariantePicker = ref(false)
const productoParaVariante = ref(null)
const variantesDisponibles = ref([])
const cargandoVariantes = ref(false)
const varianteSeleccionada = ref(null)

async function agregarItem(producto) {
  // Si tiene variantes en la tienda de búsqueda, abrir picker
  const tiendaConsulta = tiendaBusqueda.value || tiendaId.value
  if (producto.variantes?.length > 0) {
    productoParaVariante.value = producto
    varianteSeleccionada.value = null
    cargandoVariantes.value = true
    mostrarVariantePicker.value = true
    try {
      const { data } = await getVariantes(producto.id, tiendaConsulta)
      variantesDisponibles.value = data
    } finally {
      cargandoVariantes.value = false
    }
    return
  }
  _pushItem(producto, null)
}

function confirmarVariante() {
  _pushItem(productoParaVariante.value, varianteSeleccionada.value)
  mostrarVariantePicker.value = false
}

function _pushItem(producto, variante) {
  const esOtraTienda = tiendaBusqueda.value && tiendaBusqueda.value != tiendaId.value
  const stockL = variante
    ? (variante.stock_libre ?? 0)
    : stockLibre(producto)

  const varianteLabel = variante
    ? [variante.marca, variante.marca_tela, variante.nombre_color].filter(Boolean).join(' · ')
    : null

  const existe = items.value.find((i) =>
    i.producto_id === producto.id && i.variante_id === (variante?.id ?? null)
  )
  if (existe) { existe.cantidad++; return }

  items.value.push({
    producto_id: producto.id,
    variante_id: variante?.id ?? null,
    tienda_origen_id: esOtraTienda ? (tiendaBusqueda.value ?? null) : null,
    nombre: producto.nombre,
    categoria: producto.categoria,
    variante_label: varianteLabel,
    stock_libre: stockL,
    personalizable: producto.personalizable ?? false,
    cantidad: 1,
    precio_unitario: producto.precio_base ?? 0,
    es_personalizado: false,
    specs_descripcion:       '',
    tienda_origen: esOtraTienda ? nombreTiendaBusqueda() : null,
    fecha_entrega_prometida: null,
    boceto_blob: null,
    boceto_url: '',
    boceto_preview: null,
  })
  productoResultados.value = []
  productoQuery.value = ''
}

function quitarItem(idx) {
  const item = items.value[idx]
  if (item.boceto_preview) URL.revokeObjectURL(item.boceto_preview)
  items.value.splice(idx, 1)
}

function onBocetoUpdate(item, blob) {
  if (item.boceto_preview) URL.revokeObjectURL(item.boceto_preview)
  item.boceto_blob    = blob
  item.boceto_url     = ''
  item.boceto_preview = blob ? URL.createObjectURL(blob) : null
}

// ── Paso 3: Pago ──────────────────────────────────────────────────────────────
const anticipo_pct         = ref(50)
const anticipo_monto       = ref(0)
const anticipo_metodo      = ref('efectivo')
const anticipo_referencia  = ref('')
const notas                = ref('')
const submitting           = ref(false)
const cooldown             = ref(0)   // segundos restantes antes de poder reintentar
let   cooldownTimer        = null

const facturaFotoFile      = ref(null)
const facturaFotoUrl       = ref('')
const facturaFotoPreview   = ref('')
const subiendoFactura      = ref(false)

const firmaBlob            = ref(null)
const firmaUrl             = ref('')
watch(firmaBlob, () => { firmaUrl.value = '' })

watch(facturaFotoFile, (file, oldFile) => {
  if (facturaFotoPreview.value) URL.revokeObjectURL(facturaFotoPreview.value)
  facturaFotoPreview.value = file ? URL.createObjectURL(file) : ''
})

const departamentoEnvio    = ref('')
const ciudadEnvio          = ref('')
const direccionEnvio       = ref('')

// Fecha mínima = hoy (para el date-picker de los ítems)
const hoy = new Date().toISOString().split('T')[0]

const fotoModal    = ref(false)
const fotoProducto = ref(null)

function verFoto(p) {
  fotoProducto.value = p
  fotoModal.value = true
}

const metodosOpts = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta',       label: 'Tarjeta' },
  { value: 'otro',          label: 'Otro' },
]

const valorTotal = computed(() =>
  items.value.reduce((s, i) => s + i.cantidad * i.precio_unitario, 0)
)

const minimoAnticipo = computed(() =>
  Math.ceil(valorTotal.value * anticipo_pct.value / 100)
)

function irAPaso3() {
  anticipo_monto.value = minimoAnticipo.value
  step.value = 3
}

async function submit() {
  if (submitting.value || subiendoFactura.value || cooldown.value > 0) return
  submitting.value = true
  try {
    // Subir foto de factura si se seleccionó
    if (facturaFotoFile.value && !facturaFotoUrl.value) {
      subiendoFactura.value = true
      const fd = new FormData()
      fd.append('foto', facturaFotoFile.value)
      fd.append('folder', 'facturas')
      const { data: uploadData } = await api.post('/upload/foto', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      facturaFotoUrl.value = uploadData.url
      subiendoFactura.value = false
    }

    // Bocetos de ítems personalizados: subir los que tengan blob pendiente
    for (const item of items.value) {
      if (item.es_personalizado && item.boceto_blob && !item.boceto_url) {
        const fd = new FormData()
        fd.append('foto', item.boceto_blob, 'boceto.png')
        fd.append('folder', 'bocetos')
        const { data: uploadData } = await api.post('/upload/foto', fd, {
          headers: { 'Content-Type': 'multipart/form-data' },
        })
        item.boceto_url = uploadData.url
      }
    }

    // Firma del cliente: subir el blob dibujado en el canvas
    if (firmaBlob.value && !firmaUrl.value) {
      const fd = new FormData()
      fd.append('foto', firmaBlob.value, 'firma.png')
      fd.append('folder', 'firmas')
      const { data: uploadData } = await api.post('/upload/foto', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      firmaUrl.value = uploadData.url
    }

    const payload = {
      cliente_id:           clienteSeleccionado.value.id,
      tienda_id:            tiendaId.value,
      canal:                canal.value,
      anticipo_pct:         anticipo_pct.value,
      anticipo_monto:       anticipo_monto.value,
      anticipo_metodo:      anticipo_metodo.value,
      anticipo_referencia:  anticipo_referencia.value || undefined,
      notas:                notas.value || undefined,
      factura_foto_url:     facturaFotoUrl.value || undefined,
      firma_url:            firmaUrl.value || undefined,
      departamento_envio:   departamentoEnvio.value || undefined,
      ciudad_envio:         ciudadEnvio.value || undefined,
      direccion_envio:      direccionEnvio.value || undefined,
      items: items.value.map((i) => ({
        producto_id:             i.producto_id || undefined,
        nombre_custom:           i.nombre_custom || undefined,
        categoria_custom:        i.categoria_custom || undefined,
        variante_id:             i.variante_id || undefined,
        tienda_origen_id:        i.tienda_origen_id || undefined,
        cantidad:                i.cantidad,
        precio_unitario:         i.precio_unitario,
        es_personalizado:        i.es_personalizado,
        fecha_entrega_prometida: i.fecha_entrega_prometida || undefined,
        specs_personalizacion:   i.es_personalizado && i.specs_descripcion
          ? { descripcion: i.specs_descripcion }
          : undefined,
        boceto_url:              i.es_personalizado && i.boceto_url ? i.boceto_url : undefined,
      })),
    }

    const { data } = await api.post('/ordenes', payload)
    // Si el backend detectó duplicado (409), redirigir a la orden existente
    if (data?.orden_id) {
      router.push({ name: 'orden-detalle', params: { id: data.orden_id } })
    } else {
      router.push({ name: 'ordenes' })
    }
  } catch (e) {
    const status = e.response?.status
    if (status === 409 && e.response?.data?.orden_id) {
      // Orden ya creada — ir a ella en vez de mostrar error
      router.push({ name: 'orden-detalle', params: { id: e.response.data.orden_id } })
      return
    }
    toast.error(e.response?.data?.message ?? 'Error al crear la orden')
    // Cooldown de 4 segundos para evitar doble envío accidental
    cooldown.value = 4
    clearInterval(cooldownTimer)
    cooldownTimer = setInterval(() => {
      cooldown.value--
      if (cooldown.value <= 0) clearInterval(cooldownTimer)
    }, 1000)
  } finally {
    submitting.value = false
    subiendoFactura.value = false
  }
}

function onFacturaFotoChange(e) {
  const file = e.target.files[0]
  if (file) {
    facturaFotoFile.value = file
    facturaFotoUrl.value = '' // Reset URL para forzar nueva subida
  }
}

function removeFacturaFoto() {
  facturaFotoFile.value = null
  facturaFotoUrl.value = ''
}
</script>

<template>
  <div>
    <div class="p-4 max-w-lg mx-auto space-y-4 pb-8">

    <!-- Cabecera + progreso -->
    <div class="flex items-center gap-3">
      <button
        v-if="step > 1"
        @click="step--"
        class="text-blue-600 text-sm font-medium"
      >← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">Nueva Orden</h2>
      <span class="text-xs text-gray-400">{{ step }}/3</span>
    </div>

    <!-- Barra de pasos -->
    <div class="flex gap-1">
      <div v-for="n in 3" :key="n"
        :class="['h-1 flex-1 rounded-full transition-colors',
          n <= step ? 'bg-blue-600' : 'bg-gray-200']"
      />
    </div>

    <!-- Firma del vendedor requerida -->
    <div
      v-if="!auth.usuario?.firma_url"
      class="bg-amber-50 border border-amber-300 rounded-xl p-4 flex flex-col gap-3"
    >
      <div class="flex items-start gap-3">
        <ExclamationTriangleIcon class="w-6 h-6 text-amber-500 flex-shrink-0" />
        <div>
          <p class="font-semibold text-amber-800 text-sm">Registra tu firma antes de crear órdenes</p>
          <p class="text-xs text-amber-700 mt-0.5">Tu firma aparece en la cotización del cliente. Es obligatoria para poder generar órdenes.</p>
        </div>
      </div>
      <button
        @click="router.push({ name: 'perfil' })"
        class="w-full bg-amber-500 hover:bg-amber-600 text-white rounded-lg py-2.5 text-sm font-semibold transition-colors"
      >
        Ir a Mi Perfil → Registrar firma
      </button>
    </div>

    <!-- ═══════════════════════════════════════════════════════ PASO 1 ══ -->
    <template v-if="step === 1">

      <!-- Tienda -->
      <div>
        <label class="label">Tienda</label>
        <select v-if="auth.isSupervisor" v-model="tiendaId" class="input">
          <option value="">Seleccionar...</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
        <div v-else class="input bg-gray-50 text-gray-700 cursor-default select-none">
          {{ tiendas.find(t => t.id == tiendaId)?.nombre ?? 'Cargando...' }}
        </div>
      </div>

      <!-- Canal -->
      <div>
        <label class="label">Canal de venta</label>
        <div class="flex gap-2 flex-wrap">
          <button
            v-for="c in canalesopts"
            :key="c.value"
            @click="canal = c.value"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              canal === c.value
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ c.label }}</button>
        </div>
      </div>

      <!-- Búsqueda de cliente -->
      <div>
        <label class="label">Cliente</label>
        <div class="flex gap-2">
          <input
            v-model="clienteQuery"
            @keyup.enter="buscarCliente"
            placeholder="Nombre, cédula o teléfono..."
            class="input flex-1"
            :disabled="!!clienteSeleccionado"
          />
          <button
            v-if="!clienteSeleccionado"
            @click="buscarCliente"
            :disabled="buscandoCliente"
            class="btn-primary px-3"
          >Buscar</button>
          <button
            v-else
            @click="clienteSeleccionado = null; clienteQuery = ''"
            class="text-xs text-red-500 font-medium px-2"
          >Cambiar</button>
        </div>

        <!-- Resultados -->
        <ul v-if="clienteResultados.length" class="mt-1 bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
          <li
            v-for="c in clienteResultados"
            :key="c.id"
            @click="seleccionarCliente(c)"
            class="px-4 py-3 hover:bg-blue-50 cursor-pointer flex items-center justify-between gap-2"
          >
            <div class="flex items-center gap-2 min-w-0 flex-1">
              <span class="font-medium text-sm text-gray-800 truncate">{{ c.nombre }}</span>
              <span
                v-if="c.tipo === 'interesado'"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 flex-shrink-0"
              >
                <UserGroupIcon class="w-3 h-3" />
                Interesado
              </span>
            </div>
            <span class="text-xs text-gray-400 flex-shrink-0">{{ c.telefono }}</span>
          </li>
        </ul>

        <!-- Sin resultados -->
        <div
          v-else-if="clienteQuery && !buscandoCliente && !clienteSeleccionado && clienteResultados.length === 0"
          class="mt-2 text-sm text-gray-500"
        >
          No encontrado.
          <button @click="modoNuevoCliente = true" class="text-blue-600 font-medium ml-1">Crear nuevo</button>
        </div>

        <!-- Cliente seleccionado -->
        <div v-if="clienteSeleccionado" class="mt-2 bg-blue-50 rounded-lg px-3 py-2 text-sm space-y-1">
          <div class="flex items-center gap-2">
            <span class="font-semibold text-blue-700">{{ clienteSeleccionado.nombre }}</span>
            <span class="text-blue-500">{{ clienteSeleccionado.telefono }}</span>
            <span
              v-if="clienteSeleccionado.tipo === 'interesado'"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
            >
              <UserGroupIcon class="w-3 h-3" />
              Interesado
            </span>
          </div>
          <button
            v-if="clienteSeleccionado.tipo === 'interesado'"
            @click="convertirAOficial(clienteSeleccionado)"
            :disabled="convirtiendoCliente"
            class="text-xs bg-amber-500 text-white px-2 py-1 rounded-lg hover:bg-amber-600 disabled:opacity-50 flex items-center gap-1"
          >
            <ConvertIcon v-if="convirtiendoCliente" class="w-3 h-3 animate-spin" />
            Convertir a oficial
          </button>
        </div>
      </div>

      <!-- Formulario nuevo cliente -->
      <div v-if="modoNuevoCliente" class="bg-gray-50 rounded-xl p-4 space-y-3">
        <p class="text-sm font-semibold text-gray-700">Nuevo cliente</p>

        <!-- Tipo -->
        <div>
          <label class="text-xs text-gray-500 mb-1">Tipo</label>
          <div class="flex gap-2">
            <button
              type="button"
              @click="nuevoCliente.tipo = 'oficial'"
              :class="[
                'flex-1 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                nuevoCliente.tipo === 'oficial'
                  ? 'bg-blue-600 text-white border-blue-600'
                  : 'bg-white text-gray-700 border-gray-300'
              ]"
            >Oficial</button>
            <button
              type="button"
              @click="nuevoCliente.tipo = 'interesado'"
              :class="[
                'flex-1 py-1.5 rounded-lg text-xs font-medium border transition-colors',
                nuevoCliente.tipo === 'interesado'
                  ? 'bg-amber-500 text-white border-amber-500'
                  : 'bg-white text-gray-700 border-gray-300'
              ]"
            >Interesado</button>
          </div>
        </div>

        <input v-model="nuevoCliente.nombre"    class="input" placeholder="Nombre completo *" />
        <input v-model="nuevoCliente.cedula"    class="input" placeholder="Cédula / NIT (empresa)" />
        <input v-model="nuevoCliente.telefono"  class="input" placeholder="Teléfono" type="tel" />
        <input v-model="nuevoCliente.email"     class="input" placeholder="Email" type="email" />
        <input v-model="nuevoCliente.direccion" class="input" placeholder="Dirección" />

        <!-- Campos para interesado -->
        <template v-if="nuevoCliente.tipo === 'interesado'">
          <div>
            <label class="text-xs text-gray-500 mb-1">
              Categorías de interés
              <span class="text-gray-400 font-normal">(mantén presionado para varias)</span>
            </label>
            <select
              v-model="nuevoCliente.categorias_interes"
              multiple
              size="5"
              class="input text-sm"
            >
              <option v-for="cat in CATEGORIAS_DISPONIBLES" :key="cat" :value="cat">{{ cat }}</option>
            </select>
            <div v-if="nuevoCliente.categorias_interes.length > 0" class="flex flex-wrap gap-1 mt-1.5">
              <span
                v-for="cat in nuevoCliente.categorias_interes"
                :key="cat"
                class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"
              >{{ cat }}</span>
            </div>
          </div>
          <div>
            <label class="text-xs text-gray-500 mb-1">Notas de interés</label>
            <textarea v-model="nuevoCliente.notas_interes" rows="2" class="input text-sm resize-none" placeholder="¿En qué está interesado?"></textarea>
          </div>
        </template>

        <p v-if="errCliente" class="text-xs text-red-600">{{ errCliente }}</p>
        <div class="flex gap-2">
          <button @click="modoNuevoCliente = false" class="btn-secondary flex-1">Cancelar</button>
          <button @click="crearCliente" :disabled="creandoCliente || !nuevoCliente.nombre" class="btn-primary flex-1">
            {{ creandoCliente ? 'Guardando...' : 'Guardar' }}
          </button>
        </div>
      </div>

      <button
        @click="step = 2"
        :disabled="!paso1Valido() || !auth.usuario?.firma_url"
        class="btn-primary w-full mt-2"
      >Continuar → Productos</button>
    </template>

    <!-- ═══════════════════════════════════════════════════════ PASO 2 ══ -->
    <template v-else-if="step === 2">

      <!-- Selector tienda de búsqueda -->
      <div>
        <label class="label">Buscar en tienda</label>
        <select v-model="tiendaBusqueda" @change="productoResultados = []" class="input text-sm">
          <option v-for="t in tiendas" :key="t.id" :value="t.id">
            {{ t.nombre }}{{ t.id == tiendaId ? ' (tu tienda)' : '' }}
          </option>
        </select>
        <p v-if="tiendaBusqueda && tiendaBusqueda != tiendaId" class="mt-1 text-xs text-amber-600 font-medium">
          Consultando stock de otra tienda — la orden se registra en {{ tiendas.find(t => t.id == tiendaId)?.nombre }}
        </p>
      </div>

      <!-- Buscador de productos -->
      <div class="flex gap-2">
        <input
          v-model="productoQuery"
          @keyup.enter="buscarProducto"
          placeholder="Buscar producto..."
          class="input flex-1"
        />
        <button @click="buscarProducto" :disabled="buscandoProducto" class="btn-primary px-3">
          Buscar
        </button>
      </div>

      <!-- Resultados de productos -->
      <ul v-if="productoResultados.length" class="space-y-2">
        <li
          v-for="p in productoResultados"
          :key="p.id"
          class="bg-white rounded-xl shadow-sm p-3 flex items-center gap-3"
        >
          <!-- Thumbnail -->
          <button
            @click="p.foto_url && verFoto(p)"
            :class="[
              'flex-shrink-0 w-12 h-12 rounded-lg overflow-hidden bg-gray-100 flex items-center justify-center',
              p.foto_url ? 'cursor-pointer hover:opacity-75 transition-opacity' : 'cursor-default'
            ]"
            :title="p.foto_url ? 'Ver foto' : 'Sin foto'"
          >
            <img v-if="p.foto_url" :src="p.foto_url" :alt="p.nombre" class="w-full h-full object-cover" />
            <PhotoIcon v-else class="w-6 h-6 text-gray-300" />
          </button>

          <div class="flex-1 min-w-0">
            <p class="font-medium text-sm text-gray-800 truncate">{{ p.nombre }}</p>
            <p class="text-xs text-gray-400">
              {{ p.categoria }}
              <span v-if="tiendaBusqueda && tiendaBusqueda != tiendaId"
                class="ml-1.5 bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium">
                <MapPinIcon class="w-3.5 h-3.5 inline-block mr-0.5 -mt-0.5" />{{ nombreTiendaBusqueda() }}
              </span>
            </p>
            <p class="text-xs mt-0.5"
              :class="stockLibre(p) > 0 ? 'text-green-600' : 'text-orange-500'"
            >
              Stock libre: {{ stockLibre(p) }}
              <span v-if="p.personalizable" class="ml-2 text-purple-500 flex items-center gap-0.5 inline-flex"><SparklesIcon class="w-3 h-3" /> personalizable</span>
            </p>
          </div>
          <div class="flex flex-col items-end gap-1">
            <span class="text-sm font-semibold text-gray-700">
              ${{ Number(p.precio_base).toLocaleString('es-CO') }}
            </span>
            <button
              @click="agregarItem(p)"
              :disabled="!p.personalizable && stockLibre(p) === 0"
              class="btn-primary text-xs px-2 py-1 disabled:opacity-40"
            >+ Agregar</button>
          </div>
        </li>
      </ul>

      <!-- Producto no catalogado -->
      <div>
        <button
          v-if="!modoProductoCustom"
          @click="modoProductoCustom = true"
          class="w-full text-sm text-purple-600 border border-dashed border-purple-300 rounded-xl py-2.5 hover:bg-purple-50 transition-colors font-medium"
        >
          + Producto no está en el catálogo
        </button>

        <div v-else class="bg-purple-50 border border-purple-200 rounded-xl p-4 space-y-3">
          <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-purple-800">Producto personalizado</p>
            <button @click="modoProductoCustom = false" class="text-purple-400 hover:text-purple-600">
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>
          <input
            v-model="productoCustomForm.nombre"
            class="input text-sm"
            placeholder="Nombre del producto *"
          />
          <input
            v-model="productoCustomForm.categoria"
            class="input text-sm"
            placeholder="Categoría (ej: comedor, silla, sofá...)"
          />
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">Precio unitario *</label>
              <input v-model.number="productoCustomForm.precio_unitario" type="number" min="0" class="input text-sm" />
            </div>
            <div>
              <label class="text-xs text-gray-500">Cantidad *</label>
              <input v-model.number="productoCustomForm.cantidad" type="number" min="1" class="input text-sm" />
            </div>
          </div>
          <div class="flex gap-2">
            <button @click="modoProductoCustom = false" class="btn-secondary flex-1 text-sm">Cancelar</button>
            <button
              @click="agregarProductoCustom"
              :disabled="!productoCustomForm.nombre.trim() || !productoCustomForm.precio_unitario || productoCustomForm.cantidad < 1"
              class="btn-primary flex-1 text-sm disabled:opacity-40"
            >Agregar al carrito</button>
          </div>
        </div>
      </div>

      <!-- Carrito -->
      <div v-if="items.length" class="space-y-3">
        <p class="text-sm font-semibold text-gray-600">Carrito ({{ items.length }} ítem{{ items.length > 1 ? 's' : '' }})</p>

        <div
          v-for="(item, idx) in items"
          :key="idx"
          class="bg-white rounded-xl shadow-sm p-3 space-y-2"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <!-- Número de orden de compra -->
              <p class="text-[10px] font-bold text-blue-500 tracking-wide mb-0.5">ÍTEM #{{ idx + 1 }}</p>
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.nombre }}</p>
              <div class="flex flex-wrap items-center gap-1 mt-0.5">
                <span v-if="item.variante_label"
                  class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded-full text-xs font-medium">
                  <SwatchIcon class="w-3 h-3 inline-block mr-0.5 -mt-0.5" />{{ item.variante_label }}
                </span>
                <span v-if="item.tienda_origen"
                  class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded-full font-medium text-xs">
                  <MapPinIcon class="w-3.5 h-3.5 inline-block mr-0.5 -mt-0.5" />{{ item.tienda_origen }}
                </span>
                <span v-if="!item.variante_label && !item.tienda_origen" class="text-xs text-gray-400">
                  {{ item.categoria }}
                </span>
              </div>
            </div>
            <button @click="quitarItem(idx)" class="text-red-400 hover:text-red-600 ml-2"><XMarkIcon class="w-5 h-5" /></button>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">Cantidad</label>
              <input
                v-model.number="item.cantidad"
                type="number" min="1"
                :max="item.es_personalizado ? undefined : item.stock_libre"
                class="input text-sm"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">Precio unitario</label>
              <input
                v-model.number="item.precio_unitario"
                type="number" min="0"
                class="input text-sm"
              />
            </div>
          </div>

          <!-- Personalizado flag -->
          <label :class="['flex items-center gap-2 text-sm text-gray-600', item.producto_id === null ? 'opacity-60 cursor-default' : 'cursor-pointer']">
            <input type="checkbox" v-model="item.es_personalizado" :disabled="item.producto_id === null" class="rounded" />
            {{ item.producto_id === null ? 'Producto personalizado (sin catálogo)' : 'Ítem personalizado' }}
          </label>

          <textarea
            v-if="item.es_personalizado"
            v-model="item.specs_descripcion"
            placeholder="Especificaciones y medidas (tela, dimensiones, color...)"
            rows="3"
            class="input text-sm resize-none"
          />

          <!-- Boceto del producto personalizado -->
          <div v-if="item.es_personalizado" class="space-y-1.5">
            <div class="flex items-center justify-between">
              <p class="text-xs font-medium text-purple-700">
                Boceto del producto
                <span class="text-gray-400 font-normal">(opcional)</span>
              </p>
              <button
                v-if="item.boceto_preview"
                type="button"
                @click="onBocetoUpdate(item, null)"
                class="text-xs text-red-500 hover:underline"
              >Quitar boceto</button>
            </div>

            <!-- Preview del boceto ya guardado -->
            <div v-if="item.boceto_preview" class="relative">
              <img
                :src="item.boceto_preview"
                alt="Boceto"
                class="w-full rounded-lg border-2 border-purple-300 object-contain bg-white"
                style="max-height: 200px;"
              />
              <button
                type="button"
                @click="onBocetoUpdate(item, null)"
                class="absolute bottom-2 right-2 text-xs text-gray-500 bg-white border border-gray-200 rounded-md px-2 py-1 hover:bg-gray-50 shadow-sm"
              >Re-dibujar</button>
            </div>

            <!-- Canvas para dibujar (solo si no hay boceto aún) -->
            <BocetoCanvas
              v-else
              :modelValue="item.boceto_blob"
              @update:modelValue="onBocetoUpdate(item, $event)"
            />
          </div>

          <p class="text-xs text-right text-gray-500">
            Subtotal: <strong class="text-gray-800">
              ${{ (item.cantidad * item.precio_unitario).toLocaleString('es-CO') }}
            </strong>
          </p>
        </div>

        <!-- Total -->
        <div class="bg-blue-50 rounded-xl px-4 py-3 flex justify-between items-center">
          <span class="font-semibold text-gray-700">Total</span>
          <span class="text-lg font-bold text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <div v-else class="text-center py-6 text-gray-400 text-sm">
        Busca y agrega productos al carrito.
      </div>

      <button
        @click="irAPaso3"
        :disabled="items.length === 0"
        class="btn-primary w-full"
      >Continuar → Pago</button>
    </template>

    <!-- ═══════════════════════════════════════════════════════ PASO 3 ══ -->
    <template v-else-if="step === 3">


      <!-- Resumen de orden -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-1">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Resumen</p>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Cliente</span>
          <span class="font-medium text-gray-800">{{ clienteSeleccionado.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Tienda</span>
          <span class="font-medium text-gray-800">{{ tiendas.find(t => t.id == tiendaId)?.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Ítems</span>
          <span class="font-medium text-gray-800">{{ items.length }}</span>
        </div>
        <div class="flex justify-between text-sm font-bold border-t border-gray-100 pt-2 mt-2">
          <span>Total</span>
          <span class="text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <!-- Anticipo % -->
      <div>
        <label class="label">Porcentaje mínimo anticipo</label>
        <div class="flex gap-2">
          <button v-for="pct in [30, 50, 70, 100]" :key="pct"
            @click="anticipo_pct = pct; anticipo_monto = minimoAnticipo"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              anticipo_pct === pct
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ pct }}%</button>
        </div>
      </div>

      <!-- Anticipo monto -->
      <div>
        <label class="label">
          Monto anticipo
          <span class="text-gray-400 font-normal ml-1">(mínimo ${{ minimoAnticipo.toLocaleString('es-CO') }})</span>
        </label>
        <input
          v-model.number="anticipo_monto"
          type="number"
          :min="minimoAnticipo"
          class="input"
        />
      </div>

      <!-- Método pago -->
      <div>
        <label class="label">Método de pago</label>
        <div class="flex gap-2 flex-wrap">
          <button
            v-for="m in metodosOpts"
            :key="m.value"
            @click="anticipo_metodo = m.value"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              anticipo_metodo === m.value
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ m.label }}</button>
        </div>
      </div>

      <!-- Referencia (para transferencia/tarjeta) -->
      <div v-if="anticipo_metodo !== 'efectivo'">
        <label class="label">Referencia / número transacción</label>
        <input v-model="anticipo_referencia" class="input" placeholder="Opcional" />
      </div>

      <!-- Notas -->
      <div>
        <label class="label">Notas (opcional)</label>
        <textarea v-model="notas" rows="2" class="input resize-none" placeholder="Observaciones de la orden..." />
      </div>

      <!-- Dirección de envío -->
      <DireccionColombia
        v-model:departamento="departamentoEnvio"
        v-model:ciudad="ciudadEnvio"
        v-model:direccion="direccionEnvio"
      />

      <!-- Foto de factura -->
      <div>
        <label class="label">Foto de la factura (opcional)</label>
        <div v-if="facturaFotoFile" class="space-y-2">
          <div class="relative">
            <img
              :src="facturaFotoUrl || facturaFotoPreview"
              alt="Vista previa factura"
              class="w-full rounded-xl border-2 border-gray-200 object-contain bg-gray-50"
              style="max-height: 240px;"
            />
            <button
              @click="removeFacturaFoto"
              class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1.5 shadow-lg"
            >
              <XMarkIcon class="w-4 h-4" />
            </button>
          </div>
          <p class="text-xs text-gray-400 truncate">{{ facturaFotoFile.name }}</p>
          <p v-if="subiendoFactura" class="text-xs text-blue-600">Subiendo imagen...</p>
        </div>
        <label v-else class="flex flex-col items-center gap-2 border-2 border-dashed border-gray-300 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:bg-blue-50 transition-colors">
          <PhotoIcon class="w-8 h-8 text-gray-300" />
          <span class="text-sm text-gray-500">Toca para adjuntar foto de factura</span>
          <span class="text-xs text-gray-400">JPG, PNG — máx 5 MB</span>
          <input
            type="file"
            accept="image/*"
            @change="onFacturaFotoChange"
            class="hidden"
          />
        </label>
      </div>

      <!-- Firma del cliente -->
      <div>
        <label class="label">
          Firma del cliente
          <span class="text-red-500 ml-0.5">*</span>
        </label>
        <FirmaCanvas v-model="firmaBlob" />
        <p v-if="!firmaBlob" class="text-xs text-amber-600 flex items-center gap-1 mt-1">
          <ExclamationTriangleIcon class="w-4 h-4 text-amber-500 inline-block mr-1" />Se requiere la firma del cliente para confirmar la orden
        </p>
      </div>

       <button
         @click="submit"
         :disabled="submitting || subiendoFactura || cooldown > 0 || anticipo_monto < minimoAnticipo || !firmaBlob"
         class="btn-primary w-full text-base py-3 flex items-center justify-center gap-2"
       >
         <ArrowPathOutlineIcon v-if="submitting || subiendoFactura" class="w-5 h-5 animate-spin" />
         {{ subiendoFactura ? 'Subiendo foto...' : submitting ? 'Guardando...' : cooldown > 0 ? `Reintentar en ${cooldown}s...` : 'Crear orden' }}
       </button>
    </template>

  </div>

  <!-- Modal picker de variante -->
  <Transition name="fade">
    <div v-if="mostrarVariantePicker" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center" @click.self="mostrarVariantePicker = false">
      <div class="absolute inset-0 bg-black/50" @click="mostrarVariantePicker = false" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-sm p-5 space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-base font-bold text-gray-800">Seleccionar variante</h3>
            <p class="text-xs text-gray-500 mt-0.5 truncate">{{ productoParaVariante?.nombre }}</p>
          </div>
          <button @click="mostrarVariantePicker = false" class="text-gray-400 text-2xl leading-none">&times;</button>
        </div>

        <div v-if="cargandoVariantes" class="text-center py-6 text-gray-400 text-sm">Cargando variantes...</div>

        <div v-else class="space-y-2">
          <!-- Opción sin variante -->
          <button
            @click="varianteSeleccionada = null"
            :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
              varianteSeleccionada === null
                ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium'
                : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50']"
          >
            Sin especificar tela
            <span class="text-xs text-gray-400 ml-1">(stock base: {{ stockLibre(productoParaVariante) }})</span>
          </button>

          <!-- Variantes disponibles -->
          <button
            v-for="v in variantesDisponibles"
            :key="v.id"
            @click="varianteSeleccionada = v"
            :disabled="!v.personalizable && v.stock_libre <= 0"
            :class="['w-full text-left px-3 py-2.5 rounded-xl border text-sm transition-colors',
              varianteSeleccionada?.id === v.id
                ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium'
                : v.stock_libre > 0
                  ? 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                  : 'border-gray-100 bg-gray-50 text-gray-300 cursor-not-allowed']"
          >
            <template v-if="v.marca">
              <span class="text-xs text-gray-400">{{ v.marca }}</span>
              <span class="text-gray-300 mx-1">·</span>
            </template>
            <span class="font-medium">{{ v.marca_tela }}</span>
            <span class="text-gray-400 mx-1">·</span>
            {{ v.nombre_color }}
            <span :class="['text-xs ml-2 font-semibold', v.stock_libre > 0 ? 'text-green-600' : 'text-red-400']">
              {{ v.stock_libre > 0 ? `${v.stock_libre} disponible${v.stock_libre > 1 ? 's' : ''}` : 'Sin stock' }}
            </span>
          </button>

          <p v-if="!variantesDisponibles.length" class="text-xs text-gray-400 text-center py-2">
            No hay variantes registradas para esta tienda.
          </p>
        </div>

        <button
          @click="confirmarVariante"
          class="w-full bg-blue-600 text-white rounded-xl py-2.5 text-sm font-semibold hover:bg-blue-700"
        >
          Agregar al carrito
        </button>
      </div>
    </div>
  </Transition>

  <!-- Lightbox foto producto -->
  <Transition name="fade">
    <div
      v-if="fotoModal"
      class="fixed inset-0 z-[60] flex items-center justify-center p-6"
      @click.self="fotoModal = false"
    >
      <div class="absolute inset-0 bg-black/85" @click="fotoModal = false" />
      <div class="relative w-full max-w-sm">
        <button
          @click="fotoModal = false"
          class="absolute -top-3 -right-3 z-10 bg-white rounded-full p-1.5 shadow-lg"
        >
          <XMarkIcon class="w-5 h-5 text-gray-700" />
        </button>
        <div class="bg-white rounded-2xl overflow-hidden shadow-2xl">
          <img
            :src="fotoProducto?.foto_url"
            :alt="fotoProducto?.nombre"
            class="w-full object-contain max-h-72"
          />
          <div class="px-4 py-3 border-t border-gray-100">
            <p class="text-sm font-semibold text-gray-800 text-center">{{ fotoProducto?.nombre }}</p>
            <p v-if="fotoProducto?.categoria" class="text-xs text-gray-400 text-center mt-0.5">{{ fotoProducto?.categoria }}</p>
          </div>
        </div>
      </div>
    </div>
  </Transition>
  </div>
</template>

<style scoped>
.label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
  margin-bottom: 0.25rem;
}
.input {
  width: 100%;
  border-radius: 0.5rem;
  border: 1px solid #d1d5db;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  background: white;
}
.input:focus {
  outline: none;
  --tw-ring-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
  box-shadow: var(--tw-ring-shadow);
}
.btn-primary {
  background: #2563eb;
  color: white;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  transition: background-color 0.15s;
}
.btn-primary:hover {
  background: #1d4ed8;
}
.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.btn-secondary {
  background: #f3f4f6;
  color: #374151;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  transition: background-color 0.15s;
}
.btn-secondary:hover {
  background: #e5e7eb;
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
