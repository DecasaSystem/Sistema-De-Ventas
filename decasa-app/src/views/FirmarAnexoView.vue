<script setup>
/**
 * La página que abre el cliente con el enlace que le llega por WhatsApp o
 * correo: ve el resumen de su pedido, lee el anexo de garantías, responde
 * el check list y firma con el dedo. Sin sesión: la llave es el token.
 *
 * Al firmar, al vendedor le aparece al instante en la orden que está
 * armando (AnexoFirmado) y le llega una notificación.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import AnexoFirma from '@/components/anexo/AnexoFirma.vue'
import { getAnexoPublico, firmarAnexo, pedirCambioAnexo, pdfPublicoAnexo } from '@/api/anexos'
import { pdfAImagen, sePuedeCompartir, compartirImagen, descargarImagen } from '@/utils/pdfAImagen'
import { CheckCircleIcon } from '@heroicons/vue/24/solid'
import { DocumentArrowDownIcon, PhotoIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'
import { useTamanoLetra } from '@/composables/useTamanoLetra'
import { useVisorFoto } from '@/composables/useVisorFoto'

// El mismo tamaño que el anexo de abajo: se agranda todo junto.
const { escala, esMinima, esMaxima, cambiar: cambiarLetra } = useTamanoLetra()

const route = useRoute()
const token = route.params.token

const cargando = ref(true)
const error    = ref('')
const datos    = ref(null)
const enviando = ref(false)
const errorFirma = ref('')
const listo    = ref(false)

const resumen = computed(() => datos.value?.resumen ?? null)
const { abrirFoto } = useVisorFoto()

// Sus datos tal como están en el sistema: si algo está mal, se lo dice al asesor.
const datosCliente = computed(() => {
  const c = datos.value?.cliente ?? {}
  return [
    { l: 'Nombre',    v: c.nombre },
    { l: 'Cédula',    v: c.cedula },
    { l: 'Teléfono',  v: c.telefono },
    { l: 'Correo',    v: c.email },
    { l: 'Dirección', v: c.direccion },
  ].filter(d => d.v)
})
const direccion = computed(() => {
  const e = resumen.value?.envio
  return e ? [e.direccion, e.ciudad, e.departamento].filter(Boolean).join(', ') : ''
})
// Los resúmenes viejos traen un solo número de descuentos; los nuevos separan
// el del efectivo, que tiene su condición.
const descuentoComercial = computed(() =>
  Math.max(0, (Number(resumen.value?.descuentos) || 0) - (Number(resumen.value?.descuento_efectivo) || 0)))

function pesos(v) {
  return '$' + new Intl.NumberFormat('es-CO').format(Math.round(Number(v) || 0))
}
function fecha(f) {
  if (!f) return ''
  const [y, m, d] = String(f).slice(0, 10).split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' })
}

onMounted(async () => {
  try {
    const { data } = await getAnexoPublico(token)
    datos.value = data
    if (data.estado === 'firmado') listo.value = true
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo abrir el enlace. Revisa tu conexión e inténtalo de nuevo.'
  } finally {
    cargando.value = false
  }
})

// ── Pedirle un cambio al asesor (en vez de firmar) ───────────────────────────
// El cliente no edita nada: le escribe al asesor, que corrige y le manda el
// enlace nuevo. Queda escrito para los dos.
const cambioAbierto = ref(false)
const cambioTexto   = ref('')
const enviandoCambio = ref(false)
const cambioEnviado = ref(false)
function abrirCambio(nombreProducto) {
  cambioTexto.value = nombreProducto && !cambioTexto.value ? `${nombreProducto}: ` : cambioTexto.value
  cambioAbierto.value = true
}
async function enviarCambio() {
  if (!cambioTexto.value.trim() || enviandoCambio.value) return
  enviandoCambio.value = true
  try {
    await pedirCambioAnexo(token, cambioTexto.value.trim())
    datos.value = { ...datos.value, cambio_pedido: cambioTexto.value.trim(), cambio_pedido_at: new Date().toISOString() }
    cambioEnviado.value = true
    cambioAbierto.value = false
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    errorFirma.value = e.response?.data?.message ?? 'No se pudo enviar. Revisa tu conexión.'
    cambioAbierto.value = false
  } finally {
    enviandoCambio.value = false
  }
}

// ── Lo firmado, para guardarlo (PDF o imagen) ────────────────────────────────
const documentos = computed(() => [
  { doc: 'anexo', nombre: 'Anexo de garantías', archivo: 'anexo-garantias' },
  ...(datos.value?.declaracion ? [{ doc: 'declaracion', nombre: 'Declaración de diseños especiales', archivo: 'declaracion-disenos-especiales' }] : []),
])
const bajando = ref('')
// La imagen lista para compartir: el menú de compartir solo abre desde un toque.
const imagenLista = ref(null)   // { blob, nombre }
async function bajarDocumento(d, formato) {
  if (bajando.value) return
  bajando.value = `${d.doc}-${formato}`
  try {
    const { data } = await pdfPublicoAnexo(token, d.doc)
    const pdf = new Blob([data], { type: 'application/pdf' })
    if (formato === 'pdf') {
      descargarImagen(pdf, `${d.archivo}.pdf`)
    } else {
      const img = await pdfAImagen(pdf)
      const nombre = `${d.archivo}.jpg`
      if (sePuedeCompartir(img, nombre)) imagenLista.value = { blob: img, nombre }
      else descargarImagen(img, nombre)
    }
  } catch {
    errorFirma.value = 'No se pudo descargar. Inténtalo otra vez en un momento.'
  } finally {
    bajando.value = ''
  }
}
async function compartirLista() {
  const i = imagenLista.value
  if (!i) return
  try { await compartirImagen(i.blob, i.nombre) } catch { descargarImagen(i.blob, i.nombre) }
  imagenLista.value = null
}

async function firmar(payload) {
  enviando.value = true
  errorFirma.value = ''
  try {
    await firmarAnexo(token, {
      secciones: payload.secciones,
      checklist: payload.checklist,
      declaracion_aprobados: payload.declaracion_aprobados,
      nombre:    payload.nombre,
      documento: payload.documento,
      firma:     payload.firma,
    })
    listo.value = true
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (e) {
    errorFirma.value = e.response?.data?.message ?? 'No se pudo guardar. Revisa tu conexión y vuelve a darle.'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div class="min-h-screen bg-gray-50">
    <header class="bg-gray-900 text-white px-4 py-4">
      <div class="max-w-lg mx-auto flex items-center gap-3">
        <div class="flex-1 min-w-0">
          <p class="text-lg font-bold">Decasa Muebles &amp; Decoración</p>
          <p class="text-xs text-gray-300">Revisa tu pedido y fírmalo junto con el documento de garantías</p>
        </div>
        <!-- Para quien no ve bien: agranda el pedido y el documento. -->
        <div class="flex items-center gap-1 flex-shrink-0" role="group" aria-label="Tamaño de la letra">
          <button
            type="button" @click="cambiarLetra(-1)" :disabled="esMinima"
            class="w-10 h-9 rounded-lg bg-white/10 border border-white/20 font-bold disabled:opacity-30"
            aria-label="Letra más pequeña"
          ><span style="font-size: 13px;">A−</span></button>
          <button
            type="button" @click="cambiarLetra(1)" :disabled="esMaxima"
            class="w-10 h-9 rounded-lg bg-white/10 border border-white/20 font-bold disabled:opacity-30"
            aria-label="Letra más grande"
          ><span style="font-size: 18px;">A+</span></button>
        </div>
      </div>
    </header>

    <main class="max-w-lg mx-auto px-4 py-5 space-y-4" :style="{ fontSize: `${14 * escala}px` }">
      <div v-if="cargando" class="text-center text-[1em] text-gray-500 py-16">Abriendo…</div>

      <div v-else-if="error" class="bg-white border border-gray-200 rounded-xl p-6 text-center space-y-2">
        <p class="text-[1.15em] font-semibold text-gray-800">No se puede abrir</p>
        <p class="text-[1em] text-gray-600">{{ error }}</p>
      </div>

      <template v-else-if="listo">
        <div class="bg-white border border-green-200 rounded-xl p-6 text-center space-y-3">
          <CheckCircleIcon class="w-14 h-14 text-green-600 mx-auto" />
          <p class="text-[1.3em] font-bold text-gray-800">¡Listo, quedó firmado!</p>
          <p class="text-[1em] text-gray-600">
            {{ datos?.vendedor ? `${datos.vendedor} ya` : 'Tu asesor ya' }} recibió tu firma. Guarda una copia si quieres; ya puedes cerrar esta página.
          </p>
        </div>

        <!-- Lo que firmó, para guardarlo: en PDF o como imagen -->
        <section class="bg-white border border-gray-200 rounded-xl overflow-hidden">
          <p class="px-4 py-3 border-b border-gray-100 text-[1.05em] font-bold text-gray-800">Tus documentos firmados</p>
          <div class="divide-y divide-gray-100">
            <div v-for="d in documentos" :key="d.doc" class="px-4 py-3 space-y-2">
              <p class="text-[1em] font-medium text-gray-800">{{ d.nombre }}</p>
              <div class="grid grid-cols-2 gap-2">
                <button
                  type="button" @click="bajarDocumento(d, 'pdf')" :disabled="!!bajando"
                  class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white py-2.5 text-[0.95em] font-semibold text-gray-700 disabled:opacity-50"
                >
                  <DocumentArrowDownIcon class="w-4 h-4" />
                  {{ bajando === `${d.doc}-pdf` ? 'Bajando…' : 'PDF' }}
                </button>
                <button
                  type="button" @click="bajarDocumento(d, 'imagen')" :disabled="!!bajando"
                  class="flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white py-2.5 text-[0.95em] font-semibold text-gray-700 disabled:opacity-50"
                >
                  <PhotoIcon class="w-4 h-4" />
                  {{ bajando === `${d.doc}-imagen` ? 'Preparando…' : 'Imagen' }}
                </button>
              </div>
            </div>
          </div>
          <div v-if="imagenLista" class="px-4 pb-4">
            <button
              type="button" @click="compartirLista"
              class="w-full rounded-lg bg-green-600 py-2.5 text-[1em] font-semibold text-white"
            >Guardar o compartir la imagen</button>
          </div>
        </section>
        <p v-if="errorFirma" class="text-red-600 text-center text-[0.9em]">{{ errorFirma }}</p>
      </template>

      <template v-else-if="datos">
        <p class="text-[1em] text-gray-700" :style="{ fontSize: `${14 * escala}px` }">
          Hola{{ datos.cliente?.nombre ? `, ${datos.cliente.nombre}` : '' }}.
          {{ resumen ? 'Revisa que tus datos y tu pedido estén bien, lee' : 'Lee' }} el documento de garantías y firma al final.
          <template v-if="resumen"> Con esa firma confirmas tu pedido.</template>
        </p>

        <template v-if="resumen">
          <!-- Sus datos: que estén bien antes de firmar -->
          <section class="bg-white border border-gray-200 rounded-xl overflow-hidden" :style="{ fontSize: `${14 * escala}px` }">
            <p class="px-4 py-3 border-b border-gray-100 text-[1.05em] font-bold text-gray-800">Tus datos</p>
            <dl class="px-4 py-3 space-y-1.5 text-[1em]">
              <div v-for="d in datosCliente" :key="d.l" class="flex justify-between gap-3">
                <dt class="text-gray-500">{{ d.l }}</dt>
                <dd class="text-gray-800 text-right break-words min-w-0">{{ d.v }}</dd>
              </div>
            </dl>
          </section>

          <!-- El pedido completo -->
          <section class="bg-white border border-gray-200 rounded-xl overflow-hidden" :style="{ fontSize: `${14 * escala}px` }">
            <div class="px-4 py-3 border-b border-gray-100">
              <p class="text-[1.05em] font-bold text-gray-800">
                Tu pedido<span v-if="resumen.referencia" class="font-normal text-gray-500"> · {{ resumen.referencia }}</span>
              </p>
              <p v-if="resumen.tienda || datos.vendedor" class="text-[0.85em] text-gray-500">
                {{ [resumen.tienda, datos.vendedor ? `Asesor: ${datos.vendedor}` : null].filter(Boolean).join(' · ') }}
              </p>
            </div>
            <ul class="divide-y divide-gray-100">
              <li v-for="(it, i) in resumen.items" :key="i" class="px-4 py-3 space-y-2">
                <div class="flex items-start gap-3">
                  <button v-if="it.foto" type="button" @click="abrirFoto(it.foto, { nombre: it.nombre })" class="flex-shrink-0">
                    <img :src="it.foto" alt="" class="w-16 h-16 rounded-lg object-cover border border-gray-100" />
                  </button>
                  <div class="flex-1 min-w-0">
                    <p class="text-[1em] font-medium text-gray-800">{{ it.nombre }}</p>
                    <!-- Qué es: se fabrica, es una restauración, un mueble único... -->
                    <p v-if="it.tipo" class="text-[0.8em] font-semibold text-blue-700">{{ it.tipo }}</p>
                    <p v-if="it.detalle" class="text-[0.85em] text-gray-500">{{ it.detalle }}</p>
                    <p v-if="it.juego" class="text-[0.85em] text-gray-500">{{ it.juego }}</p>
                    <p class="text-[0.85em] text-gray-500">
                      {{ it.cantidad }} ×
                      <span v-if="it.precio_lista" class="line-through mr-1">{{ pesos(it.precio_lista) }}</span>
                      {{ it.precio ? pesos(it.precio) : (it.obsequio ? '$0' : 'precio por confirmar') }}
                    </p>
                    <p v-if="it.precio_lista" class="text-[0.85em] text-green-700">
                      Descuento en este producto: <span class="whitespace-nowrap">−{{ pesos((it.precio_lista - it.precio) * it.cantidad) }}</span>
                    </p>
                    <p v-if="it.fecha_entrega && it.fecha_entrega !== resumen.fecha_entrega" class="text-[0.85em] text-gray-500">
                      Entrega de este producto: {{ fecha(it.fecha_entrega) }}
                    </p>
                    <p v-if="it.se_lo_lleva" class="text-[0.85em] font-medium text-gray-700">Te lo llevas hoy</p>
                    <p v-if="it.entregado" class="text-[0.85em] font-medium text-gray-700">{{ it.entregado }}</p>
                  </div>
                  <p class="text-[1em] font-semibold text-gray-800 whitespace-nowrap">{{ it.precio ? pesos(it.precio * it.cantidad) : (it.obsequio ? '$0' : '—') }}</p>
                </div>
                <!-- Cómo se va a hacer lo personalizado -->
                <dl v-if="it.specs?.length" class="rounded-lg bg-gray-50 px-3 py-2 space-y-0.5 text-[0.85em]">
                  <div v-for="(sp, j) in it.specs" :key="j">
                    <dt class="inline text-gray-500">{{ sp.label }}: </dt>
                    <dd class="inline text-gray-700 whitespace-pre-wrap">{{ sp.value }}</dd>
                  </div>
                </dl>
                <div v-if="it.bocetos?.length" class="flex flex-wrap gap-2">
                  <button v-for="b in it.bocetos" :key="b" type="button" @click="abrirFoto(it.bocetos, { indice: it.bocetos.indexOf(b), nombre: 'Boceto' })">
                    <img :src="b" alt="Boceto" class="w-16 h-16 rounded-lg object-cover border border-gray-200" />
                  </button>
                </div>
              </li>
            </ul>
          </section>

          <!-- La plata -->
          <section class="bg-white border border-gray-200 rounded-xl overflow-hidden" :style="{ fontSize: `${14 * escala}px` }">
            <p class="px-4 py-3 border-b border-gray-100 text-[1.05em] font-bold text-gray-800">Valor</p>
            <div class="px-4 py-3 space-y-1 text-[1em]">
              <div v-if="resumen.subtotal" class="flex justify-between text-gray-600">
                <span>Subtotal</span><span>{{ pesos(resumen.subtotal) }}</span>
              </div>
              <div v-if="descuentoComercial > 0" class="flex justify-between text-gray-600">
                <span>Descuento</span><span class="whitespace-nowrap">−{{ pesos(descuentoComercial) }}</span>
              </div>
              <div v-if="resumen.descuento_efectivo > 0" class="flex justify-between gap-3 text-gray-600">
                <span>Descuento por efectivo o transferencia</span><span class="whitespace-nowrap">−{{ pesos(resumen.descuento_efectivo) }}</span>
              </div>
              <div class="flex justify-between font-bold text-gray-900 text-[1.15em] pt-1">
                <span>Total</span><span>{{ pesos(resumen.total) }}</span>
              </div>
              <div v-if="resumen.pagado > 0" class="flex justify-between text-gray-600">
                <span>Ya pagaste</span><span>{{ pesos(resumen.pagado) }}</span>
              </div>
              <div v-else-if="resumen.anticipo > 0" class="flex justify-between text-gray-600">
                <span>Anticipo</span><span>{{ pesos(resumen.anticipo) }}</span>
              </div>
              <div v-if="resumen.saldo !== undefined && resumen.saldo !== null" class="flex justify-between font-semibold text-gray-800">
                <span>Saldo pendiente</span><span>{{ pesos(Math.max(0, resumen.saldo)) }}</span>
              </div>
            </div>
            <p v-if="resumen.descuento_efectivo > 0" class="px-4 pb-3 text-[0.8em] text-gray-500">
              El descuento por efectivo o transferencia se pierde si alguna parte se paga con tarjeta o Addi.
            </p>
            <p v-if="resumen.precio_pendiente" class="px-4 pb-3 text-[0.8em] text-amber-700">
              El total todavía no incluye los productos con precio por confirmar: tu asesor te lo confirma antes de fabricarlos.
            </p>
          </section>

          <!-- La entrega -->
          <section v-if="resumen.fecha_entrega || direccion" class="bg-white border border-gray-200 rounded-xl overflow-hidden" :style="{ fontSize: `${14 * escala}px` }">
            <p class="px-4 py-3 border-b border-gray-100 text-[1.05em] font-bold text-gray-800">Entrega</p>
            <dl class="px-4 py-3 space-y-1.5 text-[1em]">
              <div v-if="resumen.fecha_entrega" class="flex justify-between gap-3">
                <dt class="text-gray-500">Fecha acordada</dt><dd class="text-gray-800 text-right">{{ fecha(resumen.fecha_entrega) }}</dd>
              </div>
              <div v-if="direccion" class="flex justify-between gap-3">
                <dt class="text-gray-500">Dirección</dt><dd class="text-gray-800 text-right break-words min-w-0">{{ direccion }}</dd>
              </div>
            </dl>
          </section>

          <p class="text-[0.85em] text-gray-600 bg-white border border-gray-200 rounded-xl px-4 py-3" :style="{ fontSize: `${14 * escala}px` }">
            Si algo no está bien —tus datos, un producto, el valor o la entrega—, no firmes y escríbele a tu asesor.
          </p>
        </template>

        <!-- Ya le pidió un cambio al asesor: que lo vea y sepa qué sigue. -->
        <div v-if="datos.cambio_pedido" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 space-y-1" :style="{ fontSize: `${14 * escala}px` }">
          <p class="text-[1em] font-semibold text-amber-900">{{ cambioEnviado ? 'Listo, se lo enviamos a tu asesor' : 'Le pediste un cambio a tu asesor' }}</p>
          <p class="text-[0.9em] text-amber-900 whitespace-pre-wrap">"{{ datos.cambio_pedido }}"</p>
          <p class="text-[0.85em] text-amber-800">
            Cuando lo corrija te manda un enlace nuevo para firmar. Si de todas formas está bien así, puedes firmar aquí.
          </p>
        </div>

        <AnexoFirma
          :contenido="datos.contenido"
          :declaracion="datos.declaracion"
          :puede-pedir-cambio="true"
          :nombre="datos.cliente?.nombre ?? ''"
          :documento="datos.cliente?.cedula ?? ''"
          :enviando="enviando"
          :texto-boton="resumen ? 'Confirmo mi pedido y firmo' : 'Acepto y firmo'"
          @firmar="firmar"
          @pedir-cambio="abrirCambio"
        />
        <button
          type="button" @click="abrirCambio('')"
          class="w-full flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 bg-white py-3 text-[1em] font-semibold text-gray-700"
          :style="{ fontSize: `${14 * escala}px` }"
        >
          <PencilSquareIcon class="w-5 h-5" /> Algo no está bien: escribirle a mi asesor
        </button>
        <p v-if="errorFirma" class="text-red-600 text-center" :style="{ fontSize: `${14 * escala}px` }">{{ errorFirma }}</p>
      </template>
    </main>

    <!-- Pedirle un cambio al asesor -->
    <div v-if="cambioAbierto" class="fixed inset-0 z-50 bg-black/40 flex items-end sm:items-center justify-center" @click.self="cambioAbierto = false">
      <div class="w-full max-w-lg bg-white rounded-t-2xl sm:rounded-2xl p-4 space-y-3" :style="{ fontSize: `${14 * escala}px` }">
        <p class="text-[1.1em] font-bold text-gray-800">¿Qué quieres cambiar?</p>
        <p class="text-[0.9em] text-gray-600">
          Escríbelo con tus palabras (una medida, la tela, el color, el boceto…). Le llega a
          {{ datos?.vendedor ?? 'tu asesor' }} y te manda el pedido corregido para firmar.
        </p>
        <textarea
          v-model="cambioTexto" rows="4" maxlength="1500"
          class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 text-[1em] resize-y" placeholder="Ej: la biblioteca la necesito de 230 cm de ancho, no de 240."
        />
        <div class="grid grid-cols-2 gap-2">
          <button type="button" @click="cambioAbierto = false" class="rounded-lg border border-gray-300 py-2.5 text-[1em] font-semibold text-gray-600">Cancelar</button>
          <button
            type="button" @click="enviarCambio" :disabled="!cambioTexto.trim() || enviandoCambio"
            class="rounded-lg bg-blue-600 py-2.5 text-[1em] font-semibold text-white disabled:opacity-40"
          >{{ enviandoCambio ? 'Enviando…' : 'Enviar a mi asesor' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
