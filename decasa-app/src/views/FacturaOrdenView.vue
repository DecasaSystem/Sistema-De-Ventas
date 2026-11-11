<script setup>
/**
 * Revisar y facturar una orden (o un pago). El facturador ve todo lo que va a
 * salir en la factura —cliente, productos sin IVA, IVA, pagos— y las pruebas de
 * la orden (comprobantes, fotos de entrega, cambios de plata). Lo que se ve
 * aquí es exactamente lo que se manda: lo arma el servidor (ArmarFactura).
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppSpinner from '@/components/common/AppSpinner.vue'
import TarjetaPdf from '@/components/common/TarjetaPdf.vue'
import DireccionColombia from '@/components/DireccionColombia.vue'
import { useToast } from '@/composables/useToast'
import { useVisorFoto } from '@/composables/useVisorFoto'
import { esUrlDePdf, cloudinaryOpt } from '@/utils/cloudinary'
import { pesos } from '@/utils/pesos'
import {
  detalleFE, guardarClienteFE, facturarFE, externaFE, actualizarFE, diferenciaResueltaFE, registrarPagosFE, ESTADOS_FE,
} from '@/api/facturacionElectronica'
import { ArrowLeftIcon, ExclamationTriangleIcon, InformationCircleIcon, CheckCircleIcon, NoSymbolIcon } from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const toast  = useToast()
const { abrirFoto } = useVisorFoto()

const ordenId = computed(() => Number(route.params.id))
const pagoId  = computed(() => (route.query.pago ? Number(route.query.pago) : null))

const datos     = ref(null)
const cargando  = ref(true)
const error     = ref('')
const enviando  = ref(false)
const revisado  = ref(false)
const aceptadas = ref([])
const editandoCliente = ref(false)
const guardandoCliente = ref(false)
const cliente   = ref({})
const externa   = ref({ abierta: false, numero: '', nota: '' })
const notaDif   = ref('')

const armado  = computed(() => datos.value?.armado)
const factura = computed(() => datos.value?.factura)
const orden   = computed(() => datos.value?.orden)
const hecha   = computed(() => ['emitida', 'pendiente_dian', 'externa'].includes(factura.value?.estado))
const bloqueos     = computed(() => (armado.value?.alertas ?? []).filter(a => a.tipo === 'bloqueo'))
const advertencias = computed(() => (armado.value?.alertas ?? []).filter(a => a.tipo === 'advertencia'))
const informativas = computed(() => (armado.value?.alertas ?? []).filter(a => a.tipo === 'info'))
const faltaAceptar = computed(() => advertencias.value.some(a => !aceptadas.value.includes(a.codigo)))
const puedeFacturar = computed(() =>
  armado.value?.se_puede && !hecha.value && revisado.value && !faltaAceptar.value && !enviando.value)
const textoBoton = computed(() => armado.value?.modo === 'emitir' ? 'Facturar en Alegra (DIAN)' : 'Crear borrador en Alegra')

const TIPOS_DOC = [
  ['CC', 'Cédula de ciudadanía'], ['NIT', 'NIT'], ['CE', 'Cédula de extranjería'], ['PP', 'Pasaporte'],
  ['TI', 'Tarjeta de identidad'], ['PEP', 'Permiso especial de permanencia'], ['DIE', 'Documento extranjero'],
]
const METODOS = { efectivo: 'Efectivo', transferencia: 'Transferencia', tarjeta: 'Tarjeta', addi: 'Addi', otro: 'Otro' }
const TIPOS_PAGO = { anticipo: 'Anticipo', abono: 'Abono', saldo_final: 'Saldo' }

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await detalleFE(ordenId.value, pagoId.value)
    datos.value = data
    const c = data.armado.cliente
    cliente.value = {
      nombre: c.nombre ?? '', cedula: c.documento ?? '', tipo_documento: c.tipo_documento ?? 'CC', dv: c.dv ?? '',
      tipo_persona: c.tipo_persona ?? 'natural', regimen: c.regimen ?? 'no_responsable', email: c.email ?? '',
      telefono: c.telefono ?? '', direccion: c.direccion ?? '', ciudad: c.ciudad ?? '', departamento: c.departamento ?? '',
    }
    if (c.faltan?.length) editandoCliente.value = true
    // Lo que ya se aceptó en un intento anterior se olvida: se revisa otra vez.
    aceptadas.value = aceptadas.value.filter(a => data.armado.advertencias.includes(a))
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la orden.'
  } finally {
    cargando.value = false
  }
}

async function guardarCliente() {
  if (!armado.value?.cliente?.id) return
  guardandoCliente.value = true
  try {
    await guardarClienteFE(armado.value.cliente.id, cliente.value)
    toast.success('Datos del cliente guardados')
    editandoCliente.value = false
    revisado.value = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron guardar')
  } finally {
    guardandoCliente.value = false
  }
}

async function facturar() {
  if (!puedeFacturar.value) return
  enviando.value = true
  try {
    const { data } = await facturarFE(ordenId.value, {
      pago_id: pagoId.value, revisado: true, aceptadas: aceptadas.value,
    })
    toast.success(data.message)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo facturar')
  } finally {
    enviando.value = false
    revisado.value = false
    await cargar()
  }
}

async function preguntarAlegra() {
  if (!factura.value?.id) return
  enviando.value = true
  try {
    const { data } = await actualizarFE(factura.value.id)
    toast.success(data.message)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'Alegra no respondió')
  } finally {
    enviando.value = false
    await cargar()
  }
}

const confirmoPagos = ref(false)
async function reintentarPagos() {
  if (!factura.value?.id) return
  enviando.value = true
  try {
    const { data } = await registrarPagosFE(factura.value.id, confirmoPagos.value)
    toast.success(data.message)
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudieron registrar')
  } finally {
    enviando.value = false
    confirmoPagos.value = false
    await cargar()
  }
}

async function guardarExterna() {
  if (!externa.value.numero.trim()) return
  enviando.value = true
  try {
    const { data } = await externaFE(ordenId.value, { pago_id: pagoId.value, numero: externa.value.numero, nota: externa.value.nota })
    toast.success(data.message)
    externa.value.abierta = false
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo anotar')
  } finally {
    enviando.value = false
  }
}

async function resolverDiferencia() {
  if (!notaDif.value.trim()) return
  try {
    await diferenciaResueltaFE(factura.value.id, notaDif.value)
    toast.success('Diferencia resuelta')
    notaDif.value = ''
    await cargar()
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar')
  }
}

function alternar(codigo) {
  aceptadas.value = aceptadas.value.includes(codigo)
    ? aceptadas.value.filter(c => c !== codigo)
    : [...aceptadas.value, codigo]
}

function fechaHora(v) {
  if (!v) return ''
  return new Date(v).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZone: 'America/Bogota' })
}

function verFotos(lista, i = 0, nombre = '') {
  const fotos = lista.filter(u => !esUrlDePdf(u))
  abrirFoto(fotos, { indice: Math.max(0, fotos.indexOf(lista[i])), nombre })
}

onMounted(cargar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4">
    <div class="flex items-center gap-2">
      <button @click="router.push({ name: 'facturacion-electronica' })" class="p-2 -ml-2 rounded-lg text-gray-500 hover:bg-gray-100">
        <ArrowLeftIcon class="w-5 h-5" />
      </button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        Facturar {{ armado?.referencia ?? '' }}<span v-if="pagoId" class="text-gray-400 font-normal"> · un pago</span>
      </h2>
      <router-link v-if="orden" :to="{ name: 'orden-detalle', params: { id: orden.id } }" class="text-xs font-semibold text-blue-600">
        Ver orden completa
      </router-link>
    </div>

    <AppSpinner v-if="cargando && !datos" />
    <div v-else-if="error" class="bg-white border border-red-200 rounded-xl p-3 text-sm text-red-700">{{ error }}</div>

    <template v-else-if="armado">
      <!-- Ya tiene factura -->
      <div v-if="factura" class="bg-white rounded-xl shadow-sm p-4 space-y-2">
        <div class="flex items-center gap-2 flex-wrap">
          <span :class="['px-2 py-0.5 rounded-full text-xs font-semibold', ESTADOS_FE[factura.estado]?.clase]">
            {{ ESTADOS_FE[factura.estado]?.label ?? factura.estado }}
          </span>
          <span v-if="factura.numero" class="text-sm font-bold text-gray-800">{{ factura.numero }}</span>
          <span class="text-xs text-gray-400">{{ factura.usuario }} · {{ fechaHora(factura.emitida_at || factura.updated_at) }}</span>
        </div>
        <p v-if="factura.cufe" class="text-xs text-gray-500 break-all">CUFE: {{ factura.cufe }}</p>
        <p v-if="factura.error" class="text-sm text-red-600">{{ factura.error }}</p>
        <p v-if="factura.nota" class="text-xs text-gray-500">{{ factura.nota }}</p>
        <div class="flex flex-wrap gap-2">
          <a v-if="factura.pdf_url" :href="factura.pdf_url" target="_blank" data-sin-visor
            class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold">Ver PDF</a>
          <button v-if="factura.alegra_id || factura.estado === 'incierta'" @click="preguntarAlegra" :disabled="enviando"
            class="px-3 py-1.5 bg-gray-100 text-gray-700 rounded-lg text-xs font-semibold disabled:opacity-50">
            Revisar en Alegra
          </button>
        </div>
        <!-- Pagos que no quedaron en Alegra -->
        <div v-if="factura.pagos_sin_registrar?.length" class="border border-amber-200 rounded-lg p-3 space-y-2">
          <p class="text-sm text-amber-800">
            {{ factura.pagos_sin_registrar.length }} pago(s) no quedaron registrados en Alegra. La factura sí salió.
          </p>
          <p v-for="p in factura.pagos_sin_registrar" :key="p.pago_id" class="text-xs text-gray-600">
            Pago #{{ p.pago_id }} · {{ pesos(p.monto) }} · {{ p.estado === 'incierto' ? 'sin confirmar' : 'no entró' }}<span v-if="p.error">: {{ p.error }}</span>
          </p>
          <label v-if="factura.pagos_sin_registrar.some(p => p.estado === 'incierto')" class="flex items-start gap-2 text-xs text-gray-700">
            <input v-model="confirmoPagos" type="checkbox" class="mt-0.5" />
            <span>Revisé en Alegra y esos pagos sin confirmar NO están (si están, no lo marques: quedarían dos veces).</span>
          </label>
          <button @click="reintentarPagos" :disabled="enviando" class="px-3 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-semibold disabled:opacity-50">
            Registrar pagos en Alegra
          </button>
        </div>

        <!-- Cambió la plata después de facturar -->
        <div v-if="factura.diferencia !== null && factura.diferencia !== undefined" class="border border-amber-200 rounded-lg p-3 space-y-2">
          <p v-if="factura.orden_cancelada" class="text-sm text-amber-800">
            La orden se <b>canceló</b> después de facturar: la factura sigue valiendo ante la DIAN.
            Anúlala en Alegra con una nota crédito y anótala aquí.
          </p>
          <p v-else class="text-sm text-amber-800">
            La orden cambió <b>{{ pesos(factura.diferencia) }}</b> después de facturar
            ({{ pesos(factura.valor_sistema) }} → {{ pesos(factura.valor_sistema + factura.diferencia) }}).
            La factura no cambia sola: si bajó, nota crédito; si subió, factura por la diferencia. Hazla en Alegra y anótala aquí.
          </p>
          <div class="flex gap-2">
            <input v-model="notaDif" placeholder="Ej.: nota crédito NC-3" class="flex-1 border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
            <button @click="resolverDiferencia" :disabled="!notaDif.trim()" class="px-3 bg-blue-600 text-white rounded-lg text-xs font-semibold disabled:opacity-50">Resuelta</button>
          </div>
        </div>
        <p v-if="factura.diferencia_nota" class="text-xs text-gray-500">Diferencia anterior resuelta: {{ factura.diferencia_nota }}</p>
      </div>

      <!-- Alertas -->
      <div v-if="!hecha && bloqueos.length" class="bg-white border border-red-200 rounded-xl p-3 space-y-1.5">
        <p class="text-sm font-semibold text-red-700 flex items-center gap-1.5"><NoSymbolIcon class="w-4 h-4" /> No se puede facturar todavía</p>
        <p v-for="a in bloqueos" :key="a.codigo + a.mensaje" class="text-sm text-red-700">• {{ a.mensaje }}</p>
      </div>
      <div v-if="!hecha && advertencias.length" class="bg-white border border-amber-200 rounded-xl p-3 space-y-2">
        <p class="text-sm font-semibold text-amber-800 flex items-center gap-1.5"><ExclamationTriangleIcon class="w-4 h-4" /> Revisa esto antes de facturar</p>
        <label v-for="a in advertencias" :key="a.codigo" class="flex items-start gap-2 text-sm text-gray-700">
          <input type="checkbox" class="mt-1" :checked="aceptadas.includes(a.codigo)" @change="alternar(a.codigo)" />
          <span>{{ a.mensaje }}</span>
        </label>
      </div>
      <div v-if="!hecha && informativas.length" class="bg-white rounded-xl shadow-sm p-3 space-y-1">
        <p v-for="a in informativas" :key="a.codigo" class="text-xs text-gray-500 flex gap-1.5">
          <InformationCircleIcon class="w-4 h-4 shrink-0" /> {{ a.mensaje }}
        </p>
      </div>

      <!-- Cliente -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <div class="flex items-center justify-between">
          <h3 class="text-sm font-bold text-gray-800">Cliente (como va a la DIAN)</h3>
          <button v-if="!hecha && !armado.cliente.consumidor_final" @click="editandoCliente = !editandoCliente" class="text-xs font-semibold text-blue-600">
            {{ editandoCliente ? 'Cancelar' : 'Corregir' }}
          </button>
        </div>
        <div v-if="!editandoCliente" class="text-sm text-gray-700 space-y-0.5">
          <p v-if="armado.cliente.consumidor_final" class="font-semibold">Consumidor final</p>
          <template v-else>
            <p class="font-semibold">{{ armado.cliente.nombre }}</p>
            <p class="text-xs text-gray-500">
              {{ armado.cliente.tipo_documento }} {{ armado.cliente.documento || '—' }}<span v-if="armado.cliente.dv">-{{ armado.cliente.dv }}</span>
              · {{ armado.cliente.tipo_persona === 'juridica' ? 'Empresa' : 'Persona natural' }}
              · {{ armado.cliente.regimen === 'responsable' ? 'Responsable de IVA' : 'No responsable de IVA' }}
            </p>
            <p v-if="armado.cliente.tipo_persona !== 'juridica'" class="text-xs text-gray-500">
              Nombres: {{ armado.cliente.nombres }} · Apellidos: {{ armado.cliente.apellidos }}
            </p>
            <p class="text-xs text-gray-500">{{ armado.cliente.direccion || 'Sin dirección' }} · {{ armado.cliente.ciudad || 'sin ciudad' }}, {{ armado.cliente.departamento || 'sin departamento' }}</p>
            <p class="text-xs text-gray-500">{{ armado.cliente.email || 'Sin correo' }} · {{ armado.cliente.telefono || 'sin teléfono' }}</p>
          </template>
        </div>
        <div v-else class="grid grid-cols-2 gap-2 text-sm">
          <label class="col-span-2 text-xs text-gray-500">Nombre o razón social
            <input v-model="cliente.nombre" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          </label>
          <label class="text-xs text-gray-500">Tipo de documento
            <select v-model="cliente.tipo_documento" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white">
              <option v-for="[v, l] in TIPOS_DOC" :key="v" :value="v">{{ l }}</option>
            </select>
          </label>
          <label class="text-xs text-gray-500">Número
            <input v-model="cliente.cedula" inputmode="numeric" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          </label>
          <label v-if="cliente.tipo_documento === 'NIT'" class="text-xs text-gray-500">Dígito de verificación
            <input v-model="cliente.dv" maxlength="1" placeholder="Se calcula solo" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          </label>
          <label class="text-xs text-gray-500">Tipo de persona
            <select v-model="cliente.tipo_persona" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white">
              <option value="natural">Persona natural</option>
              <option value="juridica">Empresa (jurídica)</option>
            </select>
          </label>
          <label class="text-xs text-gray-500">Régimen de IVA
            <select v-model="cliente.regimen" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white">
              <option value="no_responsable">No responsable</option>
              <option value="responsable">Responsable</option>
            </select>
          </label>
          <!-- De la lista oficial: Alegra rechaza un municipio escrito distinto ("Armenia Q."). -->
          <div class="col-span-2">
            <DireccionColombia
              v-model:departamento="cliente.departamento"
              v-model:ciudad="cliente.ciudad"
              v-model:direccion="cliente.direccion"
              titulo="Dirección del cliente (la de la factura)"
              requerida
            />
          </div>
          <label class="text-xs text-gray-500">Correo
            <input v-model="cliente.email" type="email" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          </label>
          <label class="text-xs text-gray-500">Teléfono
            <input v-model="cliente.telefono" inputmode="tel" class="mt-0.5 w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          </label>
          <button @click="guardarCliente" :disabled="guardandoCliente"
            class="col-span-2 py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold disabled:opacity-50">
            {{ guardandoCliente ? 'Guardando…' : 'Guardar datos del cliente' }}
          </button>
        </div>
      </div>

      <!-- Productos -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-2">
        <h3 class="text-sm font-bold text-gray-800">Lo que dice la factura</h3>
        <div v-for="(l, i) in armado.lineas" :key="i" class="border-b border-gray-100 last:border-0 pb-2">
          <div class="flex justify-between gap-2 text-sm">
            <span class="text-gray-800">{{ l.cantidad }} × {{ l.nombre }}
              <span v-if="l.es_servicio" class="ml-1 px-1.5 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-600">servicio</span>
            </span>
            <span class="font-semibold text-gray-800 shrink-0">{{ pesos(l.total) }}</span>
          </div>
          <p class="text-xs text-gray-500">
            Vendido a {{ pesos(l.precio_con_iva) }} c/u<span v-if="Math.abs(l.neto_con_iva - l.precio_con_iva * l.cantidad) >= 1">, con descuento {{ pesos(l.neto_con_iva) }}</span>
            · sin IVA {{ pesos(l.base_unitaria) }} c/u · IVA {{ l.iva_pct }} % {{ pesos(l.iva_total) }}
          </p>
        </div>
        <div class="text-sm space-y-0.5 pt-1">
          <div class="flex justify-between text-gray-600"><span>Subtotal sin IVA</span><span>{{ pesos(armado.totales.base) }}</span></div>
          <div class="flex justify-between text-gray-600"><span>IVA</span><span>{{ pesos(armado.totales.iva) }}</span></div>
          <div class="flex justify-between font-bold text-gray-800"><span>Total factura</span><span>{{ pesos(armado.totales.total) }}</span></div>
          <div class="flex justify-between text-xs text-gray-500"><span>Valor en el sistema</span><span>{{ pesos(armado.totales.valor_sistema) }}</span></div>
          <div class="flex justify-between text-xs text-gray-500">
            <span>Forma de pago</span>
            <span>{{ armado.forma_pago === 'CASH' ? 'Contado' : `Crédito (debe ${pesos(armado.totales.saldo)})` }}</span>
          </div>
        </div>
      </div>

      <!-- Pagos con sus comprobantes -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <h3 class="text-sm font-bold text-gray-800">Pagos</h3>
        <p v-if="!armado.pagos.length" class="text-sm text-gray-400">Sin pagos.</p>
        <div v-for="p in armado.pagos" :key="p.id" class="space-y-1.5">
          <div class="flex justify-between text-sm">
            <span class="text-gray-700">{{ METODOS[p.metodo] ?? p.metodo }} · {{ TIPOS_PAGO[p.tipo] ?? p.tipo }} · {{ p.fecha }}</span>
            <span class="font-semibold text-gray-800">{{ pesos(p.monto) }}</span>
          </div>
          <div class="flex flex-wrap gap-1.5">
            <span v-if="p.referencia" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">Ref. {{ p.referencia }}</span>
            <span v-if="p.va_a_alegra" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600">Cuenta Alegra {{ p.cuenta || '—' }}</span>
            <span v-if="p.facturado_a_mano" class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">Marcado facturado a mano</span>
          </div>
          <div v-if="p.comprobantes.length" class="flex flex-wrap gap-2">
            <template v-for="(u, i) in p.comprobantes" :key="u">
              <TarjetaPdf v-if="esUrlDePdf(u)" :href="u" nombre="Comprobante" compacta />
              <button v-else @click="verFotos(p.comprobantes, i, 'Comprobante')" class="w-16 h-16 rounded-lg overflow-hidden bg-gray-100">
                <img :src="cloudinaryOpt(u, 160)" class="w-full h-full object-cover" alt="Comprobante" />
              </button>
            </template>
          </div>
          <p v-else class="text-xs text-gray-400">Sin comprobante.</p>
        </div>
      </div>

      <!-- Pruebas de la orden -->
      <div v-if="orden" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <h3 class="text-sm font-bold text-gray-800">La orden</h3>
        <p class="text-xs text-gray-500">
          {{ orden.tienda }} · {{ orden.vendedor }} · creada {{ fechaHora(orden.creada) }} · {{ orden.estado }}<span v-if="orden.canal"> · {{ orden.canal }}</span>
        </p>
        <p v-if="orden.notas" class="text-xs text-gray-600 whitespace-pre-line">{{ orden.notas }}</p>

        <div v-if="orden.fotos_factura.length || orden.anexo_foto_url">
          <p class="text-xs font-semibold text-gray-600 mb-1">Comprobantes y anexo</p>
          <div class="flex flex-wrap gap-2">
            <template v-for="(u, i) in [...orden.fotos_factura, ...(orden.anexo_foto_url ? [orden.anexo_foto_url] : [])]" :key="u">
              <TarjetaPdf v-if="esUrlDePdf(u)" :href="u" compacta />
              <button v-else @click="verFotos([...orden.fotos_factura, ...(orden.anexo_foto_url ? [orden.anexo_foto_url] : [])], i, 'Orden ' + orden.referencia)"
                class="w-16 h-16 rounded-lg overflow-hidden bg-gray-100">
                <img :src="cloudinaryOpt(u, 160)" class="w-full h-full object-cover" alt="Soporte" />
              </button>
            </template>
          </div>
        </div>

        <div v-for="e in orden.entregas" :key="e.id">
          <p class="text-xs font-semibold text-gray-600">Entrega {{ e.entregado_at ? fechaHora(e.entregado_at) : '(sin fecha)' }}</p>
          <div class="flex flex-wrap gap-2 mt-1">
            <button v-for="(u, i) in [...e.fotos_producto, ...e.fotos_pago]" :key="u"
              @click="verFotos([...e.fotos_producto, ...e.fotos_pago], i, 'Entrega')" class="w-16 h-16 rounded-lg overflow-hidden bg-gray-100">
              <img :src="cloudinaryOpt(u, 160)" class="w-full h-full object-cover" alt="Entrega" />
            </button>
            <span v-if="!e.fotos_producto.length && !e.fotos_pago.length" class="text-xs text-gray-400">Sin fotos.</span>
          </div>
        </div>

        <div v-if="orden.cambios_de_plata.length">
          <p class="text-xs font-semibold text-gray-600 mb-1">Cambios de plata</p>
          <p v-for="(c, i) in orden.cambios_de_plata" :key="i" class="text-xs text-gray-500">
            {{ fechaHora(c.fecha) }} · {{ c.quien }} · {{ c.que }}: {{ c.antes ?? '—' }} → {{ c.despues ?? '—' }}
          </p>
        </div>
      </div>

      <!-- Facturar -->
      <div v-if="!hecha" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <label class="flex items-start gap-2 text-sm text-gray-700">
          <input v-model="revisado" type="checkbox" class="mt-1" :disabled="!armado.se_puede" />
          <span>Revisé el cliente, los productos, los valores y los comprobantes: está todo bien para facturar.</span>
        </label>
        <button @click="facturar" :disabled="!puedeFacturar"
          class="w-full py-3 bg-blue-600 text-white rounded-lg text-sm font-semibold disabled:opacity-40 flex items-center justify-center gap-2">
          <CheckCircleIcon class="w-5 h-5" />
          {{ enviando ? 'Enviando a Alegra…' : textoBoton }}
        </button>
        <p v-if="armado.modo !== 'emitir'" class="text-xs text-amber-700">
          Modo prueba: queda como borrador en Alegra y no se manda a la DIAN. Un supervisor lo cambia en Ajustes.
        </p>
        <p v-else class="text-xs text-gray-500">
          Una factura emitida no se puede borrar: solo se corrige con nota crédito.
        </p>

        <button v-if="!externa.abierta" @click="externa.abierta = true" class="text-xs font-semibold text-gray-500 underline">
          Ya la facturé por fuera
        </button>
        <div v-else class="space-y-2 border-t border-gray-100 pt-3">
          <p class="text-xs text-gray-500">Si ya la hiciste a mano en Alegra, anota el número para que salga de la lista.</p>
          <input v-model="externa.numero" placeholder="Número de factura, ej. FE-1203" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          <input v-model="externa.nota" placeholder="Nota (opcional)" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-sm" />
          <div class="flex gap-2">
            <button @click="externa.abierta = false" class="flex-1 py-2 bg-gray-100 text-gray-700 rounded-lg text-xs font-semibold">Cancelar</button>
            <button @click="guardarExterna" :disabled="!externa.numero.trim() || enviando" class="flex-1 py-2 bg-blue-600 text-white rounded-lg text-xs font-semibold disabled:opacity-50">Anotar</button>
          </div>
        </div>
      </div>

      <div v-if="datos.otras?.length" class="bg-white rounded-xl shadow-sm p-4 space-y-1">
        <h3 class="text-sm font-bold text-gray-800">Otras facturas de esta orden</h3>
        <p v-for="o in datos.otras" :key="o.id" class="text-xs text-gray-600">
          {{ o.numero || 'Sin número' }} · {{ ESTADOS_FE[o.estado]?.label }} · {{ pesos(o.total) }}
        </p>
      </div>
    </template>
  </div>
</template>
