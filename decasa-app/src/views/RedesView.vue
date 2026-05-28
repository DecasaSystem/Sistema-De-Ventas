<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import api from '@/api'
import {
  ChatBubbleLeftRightIcon,
  PhoneIcon,
  CheckCircleIcon,
  ClockIcon,
  UserIcon,
  ArrowTopRightOnSquareIcon,
  ShoppingBagIcon,
  CalendarDaysIcon,
  WrenchScrewdriverIcon,
  ExclamationCircleIcon,
  MapPinIcon,
} from '@heroicons/vue/24/outline'

const auth  = useAuthStore()
const toast = useToast()
const items = ref([])
const tab   = ref('pendiente') // pendiente | tomada | terminada
const cargando = ref(false)
const error    = ref('')

const filtrados = computed(() => {
  if (tab.value === 'mias') {
    return items.value.filter(c => c.estado === 'tomada' && c.tomada_por === auth.usuario?.id)
  }
  return items.value.filter(c => c.estado === tab.value)
})

const badges = computed(() => ({
  pendiente: items.value.filter(c => c.estado === 'pendiente').length,
  tomada:    items.value.filter(c => c.estado === 'tomada').length,
  terminada: items.value.filter(c => c.estado === 'terminada').length,
}))

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await api.get('/redes/conversaciones')
    items.value = data
  } catch (e) {
    error.value = 'Error cargando conversaciones'
  } finally {
    cargando.value = false
  }
}

async function tomar(id) {
  try {
    const { data } = await api.post(`/redes/conversaciones/${id}/tomar`)
    actualizarItem(data)
  } catch (e) {
    alert(e.response?.data?.error || 'No se pudo tomar la conversación')
  }
}

async function terminar(id) {
  if (!confirm('¿Marcar esta conversación como terminada?')) return
  try {
    const { data } = await api.post(`/redes/conversaciones/${id}/terminar`)
    actualizarItem(data)
  } catch (e) {
    alert(e.response?.data?.error || 'Error al terminar')
  }
}

function actualizarItem(conv) {
  const idx = items.value.findIndex(c => c.id === conv.id)
  if (idx !== -1) items.value[idx] = conv
  else items.value.unshift(conv)
}

function tipoLabel(tipo) {
  return { pedido: 'Pedido', cita: 'Cita', asesor: 'Asesor', personalizacion: 'Personalización', otro: 'Otro' }[tipo] ?? tipo
}

function tipoIcon(tipo) {
  return { pedido: ShoppingBagIcon, cita: CalendarDaysIcon, asesor: ExclamationCircleIcon, personalizacion: WrenchScrewdriverIcon }[tipo] ?? ChatBubbleLeftRightIcon
}

function tipoBadgeColor(tipo) {
  return { pedido: 'bg-green-100 text-green-700', cita: 'bg-blue-100 text-blue-700', asesor: 'bg-red-100 text-red-700', personalizacion: 'bg-purple-100 text-purple-700', otro: 'bg-gray-100 text-gray-600' }[tipo] ?? 'bg-gray-100 text-gray-600'
}

function contactoUrl(conv) {
  if (conv.fuente === 'instagram') {
    return conv.contacto_url || 'https://www.instagram.com/direct/inbox/'
  }
  // WhatsApp — construir URL con mensaje pre-cargado
  const phone = (conv.telefono || '').replace(/\D/g, '')
  if (!phone) return conv.whatsapp_url || '#'

  const asesor = auth.usuario?.nombre || 'tu asesor'
  const saludo = conv.nombre_cliente ? `Hola ${conv.nombre_cliente}, ` : 'Hola, '
  let texto = `${saludo}soy ${asesor} tu asesor de DeCasa y me encantaría ayudarte 😊`
  if (conv.tipo === 'pedido' && conv.resumen) texto += `\n\n${conv.resumen}`
  return `https://wa.me/${phone}?text=${encodeURIComponent(texto)}`
}

function fuenteBadge(fuente) {
  return fuente === 'instagram'
    ? { label: 'IG', class: 'bg-purple-100 text-purple-700' }
    : { label: 'WA', class: 'bg-green-100 text-green-700' }
}

function contactoLabel(fuente) {
  return fuente === 'instagram' ? 'Abrir IG' : 'Abrir WA'
}

function contactoColor(fuente) {
  return fuente === 'instagram'
    ? 'text-purple-600 hover:text-purple-700'
    : 'text-green-600 hover:text-green-700'
}

async function abrirContacto(conv) {
  const url = contactoUrl(conv)
  if (conv.fuente === 'instagram') {
    const nombre = auth.usuario?.nombre || 'tu asesor'
    const msg    = `Hola mi nombre es ${nombre} y es un gusto ayudarte hoy 😊`
    try {
      await navigator.clipboard.writeText(msg)
      toast.success('Saludo copiado — pégalo al abrir el chat de Instagram')
    } catch {
      toast.info('Abriendo Instagram...')
    }
  }
  window.open(url, '_blank', 'noopener')
}

function totalCarrito(carrito) {
  if (!carrito?.length) return 0
  return carrito.reduce((s, i) => {
    const p = parseInt(String(i.precio ?? 0).replace(/[^0-9]/g, '')) || 0
    return s + p * (i.cantidad || 1)
  }, 0)
}

function formatPeso(n) {
  return '$' + n.toLocaleString('es-CO')
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const diffMin = Math.floor((Date.now() - d) / 60000)
  if (diffMin < 1)  return 'Ahora'
  if (diffMin < 60) return `Hace ${diffMin} min`
  const h = Math.floor(diffMin / 60)
  if (h < 24) return `Hace ${h} h`
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

// Escuchar actualizaciones en tiempo real
let echoChannel = null
onMounted(() => {
  cargar()
  if (window.Echo) {
    echoChannel = window.Echo.channel('redes')
      .listen('.conversacion.actualizada', (conv) => {
        actualizarItem(conv)
      })
  }
})
onUnmounted(() => {
  if (echoChannel) window.Echo.leaveChannel('redes')
})
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4">
    <h1 class="text-xl font-bold text-gray-800 mb-4 flex items-center gap-2">
      <ChatBubbleLeftRightIcon class="w-6 h-6 text-blue-600" />
      Redes
    </h1>

    <!-- Tabs -->
    <div class="flex rounded-xl bg-gray-100 p-1 mb-4 gap-1">
      <button
        v-for="t in [
          { key: 'pendiente', label: 'Pendientes' },
          { key: 'tomada',    label: 'En curso' },
          { key: 'terminada', label: 'Terminadas' },
        ]"
        :key="t.key"
        @click="tab = t.key"
        :class="[
          'flex-1 py-1.5 rounded-lg text-xs font-semibold transition-colors relative',
          tab === t.key ? 'bg-white shadow text-gray-800' : 'text-gray-500 hover:text-gray-700'
        ]"
      >
        {{ t.label }}
        <span
          v-if="badges[t.key] > 0"
          :class="[
            'absolute -top-1 -right-1 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5',
            t.key === 'pendiente' ? 'bg-red-500' : 'bg-blue-500'
          ]"
        >{{ badges[t.key] > 9 ? '9+' : badges[t.key] }}</span>
      </button>
    </div>

    <!-- Estado -->
    <div v-if="cargando" class="flex justify-center py-12">
      <div class="w-8 h-8 border-2 border-green-500 border-t-transparent rounded-full animate-spin" />
    </div>
    <div v-else-if="error" class="text-center py-10 text-red-500 text-sm">{{ error }}</div>
    <div v-else-if="filtrados.length === 0" class="text-center py-14">
      <ChatBubbleLeftRightIcon class="w-12 h-12 text-gray-300 mx-auto mb-2" />
      <p class="text-gray-400 text-sm">
        {{ tab === 'pendiente' ? 'No hay conversaciones pendientes' : tab === 'tomada' ? 'Ninguna en curso' : 'Sin terminadas' }}
      </p>
    </div>

    <!-- Lista -->
    <div v-else class="space-y-3">
      <div
        v-for="conv in filtrados"
        :key="conv.id"
        class="bg-white rounded-xl shadow-sm border border-gray-100 p-4"
      >
        <!-- Header -->
        <div class="flex items-start justify-between gap-2 mb-2">
          <div class="flex items-center gap-2 min-w-0">
            <component :is="tipoIcon(conv.tipo)" class="w-5 h-5 flex-shrink-0 text-gray-500" />
            <div class="min-w-0">
              <p class="font-semibold text-gray-800 text-sm truncate">
                {{ conv.nombre_cliente || conv.telefono }}
              </p>
              <p v-if="conv.nombre_cliente && conv.fuente !== 'instagram'" class="text-xs text-gray-400 flex items-center gap-1">
                <PhoneIcon class="w-3 h-3" />{{ conv.telefono }}
              </p>
              <p v-else-if="conv.fuente === 'instagram'" class="text-xs text-purple-400">
                @muebles_decasa · Instagram
              </p>
            </div>
          </div>
          <div class="flex items-center gap-1.5 flex-shrink-0">
            <span :class="['text-[11px] font-bold px-1.5 py-0.5 rounded-full', fuenteBadge(conv.fuente).class]">
              {{ fuenteBadge(conv.fuente).label }}
            </span>
            <span :class="['text-[11px] font-semibold px-2 py-0.5 rounded-full', tipoBadgeColor(conv.tipo)]">
              {{ tipoLabel(conv.tipo) }}
            </span>
          </div>
        </div>

        <!-- Resumen -->
        <p class="text-sm text-gray-600 leading-snug mb-3 line-clamp-3">{{ conv.resumen }}</p>

        <!-- Datos de cita -->
        <div v-if="conv.tipo === 'cita' && conv.datos_cita" class="mb-3 bg-blue-50 rounded-lg p-3 text-xs space-y-1">
          <p class="font-semibold text-blue-700 flex items-center gap-1">
            <CalendarDaysIcon class="w-3.5 h-3.5" /> Cita agendada
          </p>
          <p class="text-blue-800">
            <span class="font-medium">Fecha:</span> {{ conv.datos_cita.dia }} a las {{ conv.datos_cita.hora }}
          </p>
          <p class="text-blue-800 flex items-center gap-1">
            <MapPinIcon class="w-3 h-3" />
            {{ conv.datos_cita.sede_nombre || ('Sede ' + conv.datos_cita.ubicacion) }}
          </p>
          <p v-if="conv.datos_cita.motivo" class="text-blue-700 italic">"{{ conv.datos_cita.motivo }}"</p>
          <p v-if="conv.datos_cita.nombre" class="text-blue-800"><span class="font-medium">Cliente:</span> {{ conv.datos_cita.nombre }}</p>
        </div>

        <!-- Carrito -->
        <div v-if="conv.carrito?.length" class="mb-3 bg-green-50 rounded-lg p-3 text-xs">
          <p class="font-semibold text-green-700 flex items-center gap-1 mb-2">
            <ShoppingBagIcon class="w-3.5 h-3.5" /> Carrito ({{ conv.carrito.length }} producto{{ conv.carrito.length > 1 ? 's' : '' }})
          </p>
          <div class="space-y-1">
            <div v-for="(item, i) in conv.carrito" :key="i" class="flex justify-between text-green-800">
              <span>{{ item.producto }} <span v-if="(item.cantidad || 1) > 1" class="text-green-600">×{{ item.cantidad }}</span></span>
              <span class="font-medium">{{ formatPeso(parseInt(String(item.precio ?? 0).replace(/[^0-9]/g, '')) * (item.cantidad || 1)) }}</span>
            </div>
          </div>
          <div class="border-t border-green-200 mt-2 pt-2 flex justify-between font-bold text-green-800">
            <span>Total</span>
            <span>{{ formatPeso(totalCarrito(conv.carrito)) }}</span>
          </div>
        </div>

        <!-- Historial colapsable -->
        <details v-if="conv.historial?.length" class="mb-3">
          <summary class="text-xs text-blue-600 cursor-pointer hover:underline">Ver historial ({{ conv.historial.length }} mensajes)</summary>
          <div class="mt-2 space-y-1 bg-gray-50 rounded-lg p-2 max-h-40 overflow-y-auto">
            <div
              v-for="(m, i) in conv.historial"
              :key="i"
              :class="['text-xs px-2 py-1 rounded', m.role === 'user' ? 'bg-white text-gray-700' : 'bg-blue-50 text-blue-800']"
            >
              <span class="font-semibold">{{ m.role === 'user' ? '👤' : '🤖' }}</span>
              {{ String(m.content).substring(0, 120) }}
            </div>
          </div>
        </details>

        <!-- Footer -->
        <div class="flex items-center justify-between gap-2 flex-wrap">
          <div class="flex items-center gap-2 text-xs text-gray-400">
            <ClockIcon class="w-3.5 h-3.5" />
            {{ formatFecha(conv.created_at) }}
            <span v-if="conv.tomada_por_nombre || conv.tomada_por?.nombre" class="flex items-center gap-1 text-blue-600">
              <UserIcon class="w-3.5 h-3.5" />
              {{ conv.tomada_por?.nombre || conv.tomada_por_nombre }}
            </span>
          </div>

          <div class="flex items-center gap-2">
            <!-- Botón de contacto (WA o IG según fuente) -->
            <button
              v-if="conv.telefono || conv.contacto_url"
              @click="abrirContacto(conv)"
              :class="['flex items-center gap-1 text-xs font-medium', contactoColor(conv.fuente)]"
            >
              <ArrowTopRightOnSquareIcon class="w-3.5 h-3.5" />
              {{ contactoLabel(conv.fuente) }}
            </button>

            <!-- Botón Tomar -->
            <button
              v-if="conv.estado === 'pendiente'"
              @click="tomar(conv.id)"
              class="px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition-colors"
            >
              Tomar
            </button>

            <!-- Botón Terminar — solo quien tomó o supervisor -->
            <button
              v-if="conv.estado === 'tomada' && conv.tomada_por === auth.usuario?.id"
              @click="terminar(conv.id)"
              class="flex items-center gap-1 px-3 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700 transition-colors"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" />
              Terminar
            </button>

            <!-- Estado tomada por otro -->
            <span
              v-else-if="conv.estado === 'tomada' && conv.tomada_por !== auth.usuario?.id"
              class="text-xs text-gray-400 italic"
            >Tomada</span>

            <!-- Terminada -->
            <span
              v-if="conv.estado === 'terminada'"
              class="flex items-center gap-1 text-xs text-green-600 font-medium"
            >
              <CheckCircleIcon class="w-3.5 h-3.5" />
              Terminada
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
