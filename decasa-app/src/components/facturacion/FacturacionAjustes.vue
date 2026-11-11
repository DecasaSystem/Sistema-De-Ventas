<script setup>
/**
 * Facturación electrónica → Ajustes. Todo lo que cambia cómo se factura, sin
 * tocar código (dueño, 2026-11-09). Lo guarda ReglasFacturacion en el
 * servidor; solo un supervisor lo cambia.
 */
import { ref, computed, onMounted } from 'vue'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { useToast } from '@/composables/useToast'
import { reglasFE, guardarReglasFE, catalogosAlegra, itemsAlegra, contactosAlegra } from '@/api/facturacionElectronica'

const emit  = defineEmits(['guardado'])
const toast = useToast()

const cargando  = ref(true)
const guardando = ref(false)
const r         = ref(null)
const meta      = ref({ metodos: [], medios_dian: {}, medios_pago: [], tiendas: [], conectado: false })
const cat       = ref(null)
const errorCat  = ref('')
const cargandoCat = ref(false)
const items     = ref([])
const contactos = ref([])
const buscarItem = ref('')
const buscarContacto = ref('')
const verPorTienda = ref(false)

const METODOS = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta', addi: 'Addi', otro: 'Otro' }
const MEDIOS_PAGO = { cash: 'Efectivo', transfer: 'Transferencia', deposit: 'Consignación', check: 'Cheque', 'credit-card': 'Tarjeta crédito', 'debit-card': 'Tarjeta débito' }

const numeraciones = computed(() => (cat.value?.numeraciones ?? []).filter(n => n.activa !== false))

// Cómo se ve cada resolución en la lista: con su estado, para no escoger una
// vencida (2026-11-11: FEU y FP PEREIRA estaban vencidas y salían como las demás).
const ESTADO_NUM = { vencida: 'VENCIDA', agotada: 'SIN NÚMEROS', inactiva: 'inactiva', por_vencer: 'vence pronto', pocos: 'quedan pocos' }
function fechaCorta(f) {
  return f ? new Date(f + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
}
function textoNum(n) {
  const partes = [n.nombre + (n.prefijo && n.prefijo !== n.nombre ? ` (${n.prefijo})` : '')]
  if (n.vence) partes.push(`hasta ${fechaCorta(n.vence)}`)
  if (n.quedan != null) partes.push(`quedan ${n.quedan}`)
  if (ESTADO_NUM[n.estado]) partes.push(ESTADO_NUM[n.estado])
  return partes.join(' · ')
}
/** El aviso de la numeración escogida, si no está bien. */
function avisoNum(id) {
  const n = id ? numeraciones.value.find(x => x.id === id) : null
  if (!n || n.estado === 'vigente') return null
  return { texto: n.mensaje, grave: !n.sirve }
}
const impuestosIva = computed(() => (cat.value?.impuestos ?? []))
/** El porcentaje del impuesto escogido si no es el del IVA con que se calculan los precios. */
const ivaNoCuadra = computed(() => {
  const t = impuestosIva.value.find(x => x.id === r.value?.impuesto_iva_id)
  if (!t || t.porcentaje == null) return null
  return Math.abs(Number(t.porcentaje) - Number(r.value.iva_pct)) > 0.001 ? Number(t.porcentaje) : null
})

async function cargar() {
  cargando.value = true
  try {
    const { data } = await reglasFE()
    meta.value = data
    r.value = normalizar(data.reglas)
    if (data.conectado) cargarCatalogos()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron cargar los ajustes')
  } finally {
    cargando.value = false
  }
}

async function cargarCatalogos() {
  cargandoCat.value = true
  errorCat.value = ''
  try {
    const { data } = await catalogosAlegra()
    cat.value = data
  } catch (e) {
    errorCat.value = e.response?.data?.message ?? 'No se pudo leer Alegra.'
  } finally {
    cargandoCat.value = false
  }
}

async function buscarItems() {
  try { items.value = (await itemsAlegra(buscarItem.value)).data.data } catch (e) { toast.error(e.response?.data?.message ?? 'Alegra no respondió') }
}
async function buscarContactos() {
  try { contactos.value = (await contactosAlegra(buscarContacto.value)).data.data } catch (e) { toast.error(e.response?.data?.message ?? 'Alegra no respondió') }
}

/** Los mapas siempre completos: la pantalla escribe en ellos sin crear nada al pintar. */
function normalizar(reglas) {
  const x = JSON.parse(JSON.stringify(reglas))
  if (!x.numeraciones || Array.isArray(x.numeraciones)) x.numeraciones = {}
  if (!x.cuentas || Array.isArray(x.cuentas)) x.cuentas = {}
  for (const t of ['*', ...meta.value.tiendas.map(t => String(t.id))]) x.cuentas[t] ??= {}
  return x
}

async function guardar() {
  if (r.value.activo && r.value.modo === 'emitir'
    && !confirm('Con "Mandar a la DIAN" cada factura que se haga es legal y no se puede borrar. ¿Guardar así?')) return
  guardando.value = true
  try {
    // Lo vacío no se manda: una tienda sin numeración propia usa la general.
    const limpio = JSON.parse(JSON.stringify(r.value))
    limpio.numeraciones = Object.fromEntries(Object.entries(limpio.numeraciones).filter(([, v]) => v))
    limpio.cuentas = Object.fromEntries(Object.entries(limpio.cuentas)
      .map(([t, m]) => [t, Object.fromEntries(Object.entries(m).filter(([, v]) => v))])
      .filter(([, m]) => Object.keys(m).length))
    const { data } = await guardarReglasFE(limpio)
    r.value = normalizar(data.reglas)
    toast.success(data.message)
    emit('guardado')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

onMounted(cargar)
</script>

<template>
  <AppSpinner v-if="cargando" />
  <div v-else-if="r" class="space-y-4">
    <!-- Encendido -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <h3 class="text-sm font-bold text-gray-800">Encendido</h3>
      <label class="flex items-center justify-between gap-3 text-sm text-gray-700">
        <span>Facturación electrónica prendida</span>
        <input v-model="r.activo" type="checkbox" class="w-5 h-5" />
      </label>
      <div>
        <p class="text-xs text-gray-500 mb-1">Qué pasa al tocar "Facturar"</p>
        <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
          <button @click="r.modo = 'borrador'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.modo === 'borrador' ? 'bg-white shadow-sm text-amber-700' : 'text-gray-500']">
            Solo borrador (prueba)
          </button>
          <button @click="r.modo = 'emitir'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.modo === 'emitir' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">
            Mandar a la DIAN
          </button>
        </div>
        <p class="text-xs text-gray-400 mt-1">
          Empiecen en borrador unas semanas y compárenlo con lo que hacen a mano. Un borrador no es legal y se puede borrar.
        </p>
      </div>
      <label class="block text-xs text-gray-500">Facturar lo de esta fecha en adelante
        <input v-model="r.desde" type="date" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
        <span class="text-gray-400">Lo de antes ya se facturó a mano. Si lo prenden sin fecha, se pone hoy.</span>
      </label>
    </div>

    <!-- Qué y cuándo -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <h3 class="text-sm font-bold text-gray-800">Qué se factura y cuándo</h3>
      <div class="space-y-1.5 text-sm text-gray-700">
        <label class="flex items-start gap-2"><input v-model="r.momento" type="radio" value="al_entregar" class="mt-1" />
          <span>Cuando la orden se entrega completa <span class="text-xs text-gray-400">(una factura con todo)</span></span></label>
        <label class="flex items-start gap-2"><input v-model="r.momento" type="radio" value="al_confirmar" class="mt-1" />
          <span>Apenas entra el primer pago <span class="text-xs text-gray-400">(una factura con todo, al principio)</span></span></label>
        <label class="flex items-start gap-2"><input v-model="r.momento" type="radio" value="por_pago" class="mt-1" />
          <span>Una factura por cada pago <span class="text-xs text-gray-400">(anticipo, abonos y saldo, cada uno por lo que pagó)</span></span></label>
      </div>
      <p v-if="r.momento === 'por_pago'" class="text-xs text-amber-700">
        Confírmenlo con el contador: facturar cada anticipo es válido pero cambia cómo se lleva el IVA de cada pago.
      </p>
      <div class="border-t border-gray-100 pt-3 space-y-2 text-sm text-gray-700">
        <label class="flex items-center justify-between gap-3"><span>Facturar las órdenes FV2</span><input v-model="r.facturar_fv2" type="checkbox" class="w-5 h-5" /></label>
        <label class="flex items-center justify-between gap-3"><span>Facturar las restauraciones</span><input v-model="r.facturar_restauraciones" type="checkbox" class="w-5 h-5" /></label>
        <label class="flex items-center justify-between gap-3"><span>Facturar las ventas de independientes</span><input v-model="r.facturar_independientes" type="checkbox" class="w-5 h-5" /></label>
        <label class="flex items-center justify-between gap-3">
          <span>El facturador revisa cada una antes de que salga
            <span class="block text-xs text-gray-400">Apagado, lo que no tenga ninguna advertencia sale solo cada 5 minutos.</span></span>
          <input v-model="r.requiere_revision" type="checkbox" class="w-5 h-5" />
        </label>
      </div>
    </div>

    <!-- Plata -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <h3 class="text-sm font-bold text-gray-800">Valores</h3>
      <div class="grid grid-cols-2 gap-2">
        <label class="text-xs text-gray-500">IVA (%)
          <input v-model.number="r.iva_pct" type="number" min="0" max="100" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-500">Decimales del precio sin IVA
          <input v-model.number="r.decimales" type="number" min="0" max="6" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-500">Días de plazo si queda saldo
          <input v-model.number="r.dias_credito" type="number" min="0" max="365" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-500">Avisar si el precio se aleja de la lista más de (%)
          <input v-model.number="r.alerta_precio_pct" type="number" min="0" max="100" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-500 col-span-2">Pedir mirar otra vez si la plata cambió hace menos de (horas)
          <input v-model.number="r.horas_cambio_reciente" type="number" min="0" max="720" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-500">Avisar cuando a la resolución DIAN le falten (días)
          <input v-model.number="r.aviso_numeracion_dias" type="number" min="0" max="365" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
        <label class="text-xs text-gray-500">…o le queden (números)
          <input v-model.number="r.aviso_numeracion_numeros" type="number" min="0" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
      </div>
      <div class="space-y-2 text-sm text-gray-700">
        <label class="flex items-center justify-between gap-3"><span>Las restauraciones llevan IVA</span><input v-model="r.iva_en_restauraciones" type="checkbox" class="w-5 h-5" /></label>
        <label class="flex items-center justify-between gap-3">
          <span>Registrar en Alegra los pagos del cliente
            <span class="block text-xs text-amber-700">Déjelo apagado: en Decasa cada pago se digita en Alegra como recibo (anticipo de clientes) al entrar y se cruza al facturar. Prenderlo registraría cada pago otra vez y la caja de Alegra quedaría doble.</span></span>
          <input v-model="r.registrar_pagos" type="checkbox" class="w-5 h-5" />
        </label>
        <div v-if="r.registrar_pagos">
          <p class="text-xs text-gray-500 mb-1">Fecha con que va cada pago a Alegra</p>
          <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
            <button @click="r.fecha_pagos = 'factura'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.fecha_pagos === 'factura' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">El día de la factura</button>
            <button @click="r.fecha_pagos = 'recibido'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.fecha_pagos === 'recibido' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">El día que entró la plata</button>
          </div>
          <p class="text-xs text-gray-400 mt-1">En las notas del pago siempre queda el día real en que se recibió. Lo decide el contador.</p>
        </div>
        <label class="flex items-center justify-between gap-3"><span>Mandarle la factura al correo del cliente desde Alegra</span><input v-model="r.enviar_correo" type="checkbox" class="w-5 h-5" /></label>
      </div>
      <div>
        <p class="text-xs text-gray-500 mb-1">Cliente sin cédula</p>
        <div class="flex bg-gray-100 rounded-xl p-1 gap-1">
          <button @click="r.sin_documento = 'bloquear'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.sin_documento === 'bloquear' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">Pedir la cédula</button>
          <button @click="r.sin_documento = 'consumidor_final'" :class="['flex-1 py-2 text-xs font-semibold rounded-lg', r.sin_documento === 'consumidor_final' ? 'bg-white shadow-sm text-blue-600' : 'text-gray-500']">Consumidor final</button>
        </div>
        <div v-if="r.sin_documento === 'consumidor_final'" class="mt-2 space-y-1">
          <p class="text-xs text-gray-500">Contacto "Consumidor final" de Alegra: <b>{{ r.consumidor_final_id || 'sin escoger' }}</b></p>
          <div class="flex gap-2">
            <input v-model="buscarContacto" placeholder="Buscar en Alegra (ej. consumidor)" class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
            <button @click="buscarContactos" class="px-3 bg-gray-100 rounded-lg text-xs font-semibold">Buscar</button>
          </div>
          <button v-for="c in contactos" :key="c.id" @click="r.consumidor_final_id = c.id"
            :class="['block w-full text-left text-xs px-2 py-1 rounded-lg', r.consumidor_final_id === c.id ? 'bg-blue-50 text-blue-700' : 'text-gray-600']">
            {{ c.nombre }} · {{ c.identificacion }} (id {{ c.id }})
          </button>
        </div>
      </div>
      <label class="block text-xs text-gray-500">Nota que sale en el PDF ({orden} = número de la orden)
        <input v-model="r.nota_factura" maxlength="400" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" /></label>
    </div>

    <!-- Alegra -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-gray-800">Conexión con Alegra</h3>
        <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold', meta.conectado ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700']">
          {{ meta.conectado ? 'Conectado' : 'Sin conectar' }}
        </span>
      </div>
      <p v-if="!meta.conectado" class="text-xs text-gray-500">
        Falta poner en el servidor (Render) <b>ALEGRA_TOKEN</b>: el token JWT que se crea en Alegra → Configuración → "API - Integraciones con otros sistemas" (empieza por "eyJ"). Con el token clásico de "Credenciales de acceso" va también <b>ALEGRA_EMAIL</b>. Por seguridad no se escriben aquí.
      </p>
      <template v-else>
        <AppSpinner v-if="cargandoCat" />
        <p v-if="errorCat" class="text-xs text-red-600">{{ errorCat }} <button @click="cargarCatalogos" class="underline">Reintentar</button></p>

        <label class="block text-xs text-gray-500">Numeración (resolución DIAN) general
          <select v-model="r.numeraciones['*']" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white">
            <option :value="undefined">La preferida de Alegra</option>
            <option v-for="n in numeraciones" :key="n.id" :value="n.id" :disabled="!n.sirve && r.numeraciones['*'] !== n.id">{{ textoNum(n) }}</option>
          </select></label>
        <p v-if="avisoNum(r.numeraciones['*'])" :class="['text-xs', avisoNum(r.numeraciones['*']).grave ? 'text-red-600' : 'text-amber-700']">
          {{ avisoNum(r.numeraciones['*']).texto }}
        </p>

        <label class="block text-xs text-gray-500">Impuesto de IVA
          <select v-model="r.impuesto_iva_id" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white">
            <option :value="null">Sin escoger</option>
            <option v-for="t in impuestosIva" :key="t.id" :value="t.id">{{ t.nombre }} {{ t.porcentaje != null ? `(${t.porcentaje} %)` : '' }}</option>
          </select></label>
        <p v-if="ivaNoCuadra" class="text-xs text-red-600">
          Ese impuesto es de {{ ivaNoCuadra }} % y los precios se calculan con {{ r.iva_pct }} %: la factura no cuadraría. Escoge el IVA de {{ r.iva_pct }} % (no "ARRENDAMIENTO").
        </p>

        <div class="space-y-1">
          <p class="text-xs text-gray-500">Producto de Alegra para las ventas: <b>{{ r.item_producto_id || 'sin escoger' }}</b> · para restauraciones: <b>{{ r.item_servicio_id || 'el mismo' }}</b></p>
          <p class="text-xs text-gray-400">Cada línea de la factura lleva el nombre del producto de la orden; en Alegra se usa este producto genérico (ej. "Venta de muebles").</p>
          <div class="flex gap-2">
            <input v-model="buscarItem" placeholder="Buscar producto en Alegra" class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
            <button @click="buscarItems" class="px-3 bg-gray-100 rounded-lg text-xs font-semibold">Buscar</button>
          </div>
          <div v-for="i in items" :key="i.id" class="flex items-center justify-between text-xs text-gray-600 px-1">
            <span>{{ i.nombre }} (id {{ i.id }})
              <span v-if="i.inventario" class="block text-amber-700">Lleva inventario en Alegra: mejor uno sin inventario.</span></span>
            <span class="flex gap-2">
              <button @click="r.item_producto_id = i.id" class="text-blue-600 font-semibold">Ventas</button>
              <button @click="r.item_servicio_id = i.id" class="text-blue-600 font-semibold">Restauraciones</button>
            </span>
          </div>
        </div>

        <div class="space-y-2 border-t border-gray-100 pt-3">
          <p class="text-xs font-semibold text-gray-600">Cada medio de pago del sistema en Alegra</p>
          <div v-for="m in meta.metodos" :key="m" class="grid grid-cols-3 gap-2 items-center">
            <span class="text-sm text-gray-700">{{ METODOS[m] ?? m }}</span>
            <select v-model="r.cuentas['*'][m]" class="border border-gray-200 rounded-lg px-1 py-1 text-xs bg-white" title="Caja o banco donde entra">
              <option :value="undefined">Caja/banco…</option>
              <option v-for="c in cat?.cuentas ?? []" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
            <select v-model="r.medios_dian[m]" class="border border-gray-200 rounded-lg px-1 py-1 text-xs bg-white" title="Medio de pago para la DIAN">
              <option v-for="(l, k) in meta.medios_dian" :key="k" :value="k">{{ l }}</option>
            </select>
            <span></span>
            <select v-model="r.medios_pago[m]" class="col-span-2 border border-gray-200 rounded-lg px-1 py-1 text-xs bg-white" title="Cómo se registra el pago en Alegra">
              <option v-for="k in meta.medios_pago" :key="k" :value="k">Pago en Alegra: {{ MEDIOS_PAGO[k] ?? k }}</option>
            </select>
          </div>
        </div>

        <button @click="verPorTienda = !verPorTienda" class="text-xs font-semibold text-blue-600">
          {{ verPorTienda ? 'Ocultar' : 'Numeración y cajas distintas por tienda' }}
        </button>
        <div v-if="verPorTienda" class="space-y-3">
          <div v-for="t in meta.tiendas" :key="t.id" class="border border-gray-100 rounded-lg p-2 space-y-1.5">
            <p class="text-xs font-semibold text-gray-700">{{ t.nombre }}</p>
            <select v-model="r.numeraciones[String(t.id)]" class="w-full border border-gray-200 rounded-lg px-1 py-1 text-xs bg-white">
              <option :value="undefined">Numeración: la general</option>
              <option v-for="n in numeraciones" :key="n.id" :value="n.id" :disabled="!n.sirve && r.numeraciones[String(t.id)] !== n.id">{{ textoNum(n) }}</option>
            </select>
            <p v-if="avisoNum(r.numeraciones[String(t.id)])" :class="['text-xs', avisoNum(r.numeraciones[String(t.id)]).grave ? 'text-red-600' : 'text-amber-700']">
              {{ avisoNum(r.numeraciones[String(t.id)]).texto }}
            </p>
            <div class="grid grid-cols-2 gap-1.5">
              <select v-for="m in meta.metodos" :key="m" v-model="r.cuentas[String(t.id)][m]" class="border border-gray-200 rounded-lg px-1 py-1 text-xs bg-white">
                <option :value="undefined">{{ METODOS[m] }}: la general</option>
                <option v-for="c in cat?.cuentas ?? []" :key="c.id" :value="c.id">{{ METODOS[m] }}: {{ c.nombre }}</option>
              </select>
            </div>
          </div>
        </div>
      </template>
    </div>

    <button @click="guardar" :disabled="guardando" class="w-full py-3 bg-blue-600 text-white rounded-lg text-sm font-semibold disabled:opacity-50">
      {{ guardando ? 'Guardando…' : 'Guardar ajustes' }}
    </button>
  </div>
</template>
