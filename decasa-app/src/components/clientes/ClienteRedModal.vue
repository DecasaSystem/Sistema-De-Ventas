<script setup>
// Ficha de un cliente de redes: cómo contactarlo, lo que le contó a Elena, el estado del
// seguimiento, notas del equipo y su historial de tarjetas en Redes. Desde aquí se pasa a
// la lista de clientes (o se enlaza con el que ya existe con ese celular).
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { PhoneIcon, PencilSquareIcon } from '@heroicons/vue/24/outline'
import { getClienteRed, updateClienteRed, convertirClienteRed } from '@/api/clientes'
import { useToast } from '@/composables/useToast'
import { pesos } from '@/utils/pesos'
import { ESTADOS_RED, TIPOS_AVISO, canalBadge, haceCuanto, numeroWhatsApp } from './clientesRedes'

const props = defineProps({ id: { type: Number, required: true } })
const emit = defineEmits(['cerrar', 'actualizado'])

const router = useRouter()
const toast = useToast()

const ficha = ref(null)
const cargando = ref(true)
const guardando = ref(false)
const convirtiendo = ref(false)
const notas = ref('')
const editando = ref(false)
const edicion = ref({ nombre: '', telefono: '', ciudad: '' })

const whatsapp = computed(() => numeroWhatsApp(ficha.value?.telefono))
const notasCambiaron = computed(() => (notas.value ?? '') !== (ficha.value?.notas ?? ''))
const loQueConto = computed(() => {
  const f = ficha.value
  if (!f) return []
  return [
    f.presupuesto ? ['Presupuesto', pesos(f.presupuesto)] : null,
    f.espacio ? ['Para', f.espacio] : null,
    f.ciudad ? ['Ciudad', f.ciudad] : null,
    f.forma_pago ? ['Pago', f.forma_pago] : null,
  ].filter(Boolean)
})

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getClienteRed(props.id)
    ficha.value = data
    notas.value = data.notas ?? ''
  } catch {
    toast.error('No se pudo abrir la ficha')
    emit('cerrar')
  } finally {
    cargando.value = false
  }
}

async function guardar(cambios, mensaje) {
  guardando.value = true
  try {
    const { data } = await updateClienteRed(props.id, cambios)
    ficha.value = { ...ficha.value, ...data }
    notas.value = data.notas ?? ''
    emit('actualizado', data)
    if (mensaje) toast.success(mensaje)
    return true
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
    return false
  } finally {
    guardando.value = false
  }
}

function cambiarEstado(estado) {
  if (estado === ficha.value.estado || guardando.value) return
  guardar({ estado }, `Marcado como ${ESTADOS_RED.find(e => e.value === estado)?.label.toLowerCase()}`)
}

function abrirEdicion() {
  edicion.value = { nombre: ficha.value.nombre ?? '', telefono: ficha.value.telefono ?? '', ciudad: ficha.value.ciudad ?? '' }
  editando.value = true
}

async function guardarEdicion() {
  if (await guardar({ ...edicion.value }, 'Datos actualizados')) editando.value = false
}

async function convertir() {
  convirtiendo.value = true
  try {
    const { data } = await convertirClienteRed(props.id)
    ficha.value = { ...ficha.value, cliente_id: data.cliente_id }
    emit('actualizado', { ...ficha.value, cliente: { id: data.cliente_id } })
    toast.success(data.creado ? 'Quedó en la lista de clientes' : 'Enlazado con el cliente que ya existía')
    router.push({ name: 'cliente-detalle', params: { id: data.cliente_id } })
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo pasar a clientes')
  } finally {
    convirtiendo.value = false
  }
}

function fecha(f) {
  return new Date(f).toLocaleString('es-CO', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', timeZone: 'America/Bogota' })
}

onMounted(cargar)
</script>

<template>
  <Transition name="fade" appear>
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="absolute inset-0 bg-black/40" @click="emit('cerrar')" />
      <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4 max-h-[90vh] overflow-y-auto">
        <AppSpinner v-if="cargando" />

        <template v-else-if="ficha">
          <!-- Encabezado -->
          <div class="flex items-start gap-2">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5">
                <h3 class="text-lg font-bold text-gray-800 truncate">{{ ficha.nombre || 'Sin nombre' }}</h3>
                <span :class="['px-1.5 py-0.5 rounded-full text-[10px] font-semibold', canalBadge(ficha.canal).class]">{{ canalBadge(ficha.canal).label }}</span>
              </div>
              <p class="text-xs text-gray-500">
                Primer contacto {{ haceCuanto(ficha.primer_contacto_at) }} · {{ ficha.total_conversaciones }} {{ ficha.total_conversaciones === 1 ? 'aviso' : 'avisos' }}
              </p>
            </div>
            <button @click="abrirEdicion" class="p-1.5 text-gray-400 hover:text-gray-600" aria-label="Editar datos de contacto">
              <PencilSquareIcon class="w-5 h-5" />
            </button>
            <button @click="emit('cerrar')" class="text-gray-400 text-2xl leading-none" aria-label="Cerrar">&times;</button>
          </div>

          <!-- Editar contacto -->
          <div v-if="editando" class="bg-gray-50 rounded-xl p-3 space-y-2">
            <input v-model="edicion.nombre" placeholder="Nombre" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <input v-model="edicion.telefono" type="tel" inputmode="tel" placeholder="Celular" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <input v-model="edicion.ciudad" placeholder="Ciudad" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <div class="flex gap-2">
              <button @click="editando = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2 text-xs font-semibold">Cancelar</button>
              <button @click="guardarEdicion" :disabled="guardando" class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-xs font-semibold disabled:opacity-50">Guardar</button>
            </div>
          </div>

          <!-- Contactar -->
          <div class="space-y-2">
            <p class="flex items-center gap-1.5 text-sm text-gray-700">
              <PhoneIcon class="w-4 h-4 text-gray-400" />
              <span v-if="ficha.telefono" class="font-medium">{{ ficha.telefono }}</span>
              <span v-else class="italic text-gray-400">{{ ficha.no_quiso_dar_datos ? 'No quiso dejar celular' : 'Sin celular' }}</span>
              <span v-if="ficha.usuario_red" class="text-purple-500">· {{ ficha.usuario_red }}</span>
            </p>
            <div class="flex gap-2">
              <a v-if="ficha.telefono" :href="`tel:${ficha.telefono}`"
                class="flex-1 text-center bg-blue-600 text-white rounded-lg py-2 text-xs font-semibold">Llamar</a>
              <a v-if="whatsapp" :href="`https://wa.me/${whatsapp}`" target="_blank" rel="noopener"
                class="flex-1 text-center border border-green-200 text-green-700 rounded-lg py-2 text-xs font-semibold">WhatsApp</a>
              <a v-if="ficha.canal === 'instagram' && ficha.contacto_url" :href="ficha.contacto_url" target="_blank" rel="noopener"
                class="flex-1 text-center border border-purple-200 text-purple-700 rounded-lg py-2 text-xs font-semibold">Instagram</a>
            </div>
          </div>

          <!-- Estado -->
          <div>
            <p class="text-xs font-medium text-gray-500 mb-1.5">Seguimiento</p>
            <div class="flex gap-1.5 flex-wrap">
              <button
                v-for="e in ESTADOS_RED"
                :key="e.value"
                @click="cambiarEstado(e.value)"
                :disabled="guardando"
                :aria-pressed="ficha.estado === e.value"
                :class="['px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
                  ficha.estado === e.value ? `${e.chip} border-transparent` : 'bg-white text-gray-500 border-gray-200']"
              >{{ e.label }}</button>
            </div>
          </div>

          <!-- Lo que contó -->
          <div v-if="loQueConto.length || ficha.preferencias?.length || ficha.productos_interes?.length" class="space-y-2">
            <p class="text-xs font-medium text-gray-500">Lo que le contó a Elena</p>
            <dl v-if="loQueConto.length" class="grid grid-cols-2 gap-x-3 gap-y-1.5">
              <template v-for="[k, v] in loQueConto" :key="k">
                <div>
                  <dt class="text-[11px] text-gray-400">{{ k }}</dt>
                  <dd class="text-sm text-gray-800">{{ v }}</dd>
                </div>
              </template>
            </dl>
            <div v-if="ficha.preferencias?.length" class="flex flex-wrap gap-1">
              <span v-for="p in ficha.preferencias" :key="p" class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">{{ p }}</span>
            </div>
            <div v-if="ficha.productos_interes?.length">
              <p class="text-[11px] text-gray-400 mb-1">Productos que vio</p>
              <div class="flex flex-wrap gap-1">
                <span v-for="p in ficha.productos_interes" :key="p" class="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">{{ p }}</span>
              </div>
            </div>
          </div>

          <!-- Notas -->
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1" for="notas-cliente-red">Notas del equipo</label>
            <textarea
              id="notas-cliente-red"
              v-model="notas"
              rows="3"
              placeholder="Qué se habló, cuándo volver a llamar…"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
            />
            <button
              v-if="notasCambiaron"
              @click="guardar({ notas }, 'Notas guardadas')"
              :disabled="guardando"
              class="mt-1 bg-blue-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-50"
            >Guardar notas</button>
          </div>

          <!-- Historial en Redes -->
          <div v-if="ficha.conversaciones?.length">
            <p class="text-xs font-medium text-gray-500 mb-1.5">Avisos en Redes</p>
            <ul class="space-y-2">
              <li v-for="c in ficha.conversaciones" :key="c.id" class="rounded-xl border border-gray-100 p-3">
                <div class="flex items-center gap-1.5 text-[11px] text-gray-400">
                  <span class="font-semibold text-gray-600">{{ TIPOS_AVISO[c.tipo] ?? c.tipo }}</span>
                  <span>· {{ fecha(c.created_at) }}</span>
                  <span v-if="c.tomada_por?.nombre">· {{ c.tomada_por.nombre }}</span>
                </div>
                <p class="text-xs text-gray-600 whitespace-pre-line line-clamp-4 mt-1">{{ c.resumen }}</p>
              </li>
            </ul>
          </div>

          <!-- Pasar a clientes -->
          <div class="pt-1">
            <button
              v-if="ficha.cliente_id"
              @click="router.push({ name: 'cliente-detalle', params: { id: ficha.cliente_id } })"
              class="w-full border border-blue-200 text-blue-600 rounded-lg py-2.5 text-sm font-semibold"
            >Ver ficha de cliente</button>
            <button
              v-else
              @click="convertir"
              :disabled="convirtiendo || !ficha.nombre"
              class="w-full bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold disabled:opacity-50"
            >{{ convirtiendo ? 'Pasando…' : 'Pasar a clientes' }}</button>
            <p v-if="!ficha.cliente_id && !ficha.nombre" class="text-[11px] text-gray-400 mt-1 text-center">Ponle nombre (lápiz arriba) para pasarlo a clientes.</p>
          </div>
        </template>
      </div>
    </div>
  </Transition>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
