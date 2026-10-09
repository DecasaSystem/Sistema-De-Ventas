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
import { getAnexoPublico, firmarAnexo } from '@/api/anexos'
import { CheckCircleIcon } from '@heroicons/vue/24/solid'
import { useTamanoLetra } from '@/composables/useTamanoLetra'

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
const imagenGrande = ref(null)

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

async function firmar(payload) {
  enviando.value = true
  errorFirma.value = ''
  try {
    await firmarAnexo(token, {
      secciones: payload.secciones,
      checklist: payload.checklist,
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

      <div v-else-if="listo" class="bg-white border border-green-200 rounded-xl p-6 text-center space-y-3">
        <CheckCircleIcon class="w-14 h-14 text-green-600 mx-auto" />
        <p class="text-[1.3em] font-bold text-gray-800">¡Listo, quedó firmado!</p>
        <p class="text-[1em] text-gray-600">
          {{ datos?.vendedor ? `${datos.vendedor} ya` : 'Tu asesor ya' }} recibió tu firma. Ya puedes cerrar esta página.
        </p>
      </div>

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
                  <button v-if="it.foto" type="button" @click="imagenGrande = it.foto" class="flex-shrink-0">
                    <img :src="it.foto" alt="" class="w-16 h-16 rounded-lg object-cover border border-gray-100" />
                  </button>
                  <div class="flex-1 min-w-0">
                    <p class="text-[1em] font-medium text-gray-800">{{ it.nombre }}</p>
                    <p v-if="it.detalle" class="text-[0.85em] text-gray-500">{{ it.detalle }}</p>
                    <p class="text-[0.85em] text-gray-500">{{ it.cantidad }} × {{ it.precio ? pesos(it.precio) : 'precio por confirmar' }}</p>
                  </div>
                  <p class="text-[1em] font-semibold text-gray-800 whitespace-nowrap">{{ it.precio ? pesos(it.precio * it.cantidad) : '—' }}</p>
                </div>
                <!-- Cómo se va a hacer lo personalizado -->
                <dl v-if="it.specs?.length" class="rounded-lg bg-gray-50 px-3 py-2 space-y-0.5 text-[0.85em]">
                  <div v-for="(sp, j) in it.specs" :key="j">
                    <dt class="inline text-gray-500">{{ sp.label }}: </dt>
                    <dd class="inline text-gray-700 whitespace-pre-wrap">{{ sp.value }}</dd>
                  </div>
                </dl>
                <div v-if="it.bocetos?.length" class="flex flex-wrap gap-2">
                  <button v-for="b in it.bocetos" :key="b" type="button" @click="imagenGrande = b">
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

        <!-- Una imagen en grande (foto del producto o boceto) -->
        <div v-if="imagenGrande" class="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-4" @click="imagenGrande = null">
          <img :src="imagenGrande" alt="" class="max-w-full max-h-full rounded-lg" />
        </div>

        <AnexoFirma
          :contenido="datos.contenido"
          :nombre="datos.cliente?.nombre ?? ''"
          :documento="datos.cliente?.cedula ?? ''"
          :enviando="enviando"
          :texto-boton="resumen ? 'Confirmo mi pedido y firmo' : 'Acepto y firmo'"
          @firmar="firmar"
        />
        <p v-if="errorFirma" class="text-red-600 text-center" :style="{ fontSize: `${14 * escala}px` }">{{ errorFirma }}</p>
      </template>
    </main>
  </div>
</template>
