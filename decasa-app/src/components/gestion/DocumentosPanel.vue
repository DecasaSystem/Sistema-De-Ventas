<script setup>
/**
 * Gestión → Documentos: el texto de los documentos que el negocio entrega o
 * hace firmar, editable sin programador.
 *
 *  - Anexo de garantías: lo que el cliente lee y firma en Nueva orden (en la
 *    tienda o con el enlace a distancia).
 *  - Orden de entrega: la hoja que se lleva el conductor.
 *  - Acta de satisfacción, cotización, datos de la empresa, plazos de
 *    garantía y mensajes de WhatsApp: listas de campos que describe el
 *    servidor (EsquemasPlantillas) y se editan con EditorCampos.
 *
 * Guardar crea una versión nueva y la deja vigente; las anteriores quedan en
 * el historial. Un anexo ya firmado conserva el texto que firmó el cliente, y
 * un enlace mandado y sin firmar se firma con el texto con que se mandó.
 */
import { ref, computed, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { getPlantillas, getPlantilla, guardarPlantilla } from '@/api/plantillas'
import EditorAnexoGarantia from '@/components/gestion/EditorAnexoGarantia.vue'
import EditorOrdenEntrega from '@/components/gestion/EditorOrdenEntrega.vue'
import EditorCampos from '@/components/gestion/EditorCampos.vue'
import AnexoFirma from '@/components/anexo/AnexoFirma.vue'
import { cargarTextosNegocio } from '@/utils/textosNegocio'
import {
  DocumentTextIcon, ShieldCheckIcon, TruckIcon, XMarkIcon, PencilSquareIcon, EyeIcon, ClockIcon,
  ClipboardDocumentCheckIcon, CalculatorIcon, BuildingOffice2Icon, CalendarDaysIcon, ChatBubbleLeftRightIcon,
} from '@heroicons/vue/24/outline'

const toast = useToast()

const lista    = ref([])
const cargando = ref(true)

const ICONOS = {
  anexo_garantia: ShieldCheckIcon, orden_entrega: TruckIcon, acta_entrega: ClipboardDocumentCheckIcon,
  cotizacion: CalculatorIcon, empresa: BuildingOffice2Icon, plazos_garantia: CalendarDaysIcon,
  mensajes_whatsapp: ChatBubbleLeftRightIcon,
}

// Los papeles que se entregan o se firman, y lo demás del negocio.
const GRUPOS = [
  { clave: 'documentos', titulo: 'Documentos' },
  { clave: 'negocio',    titulo: 'Empresa, garantías y mensajes' },
]
const porGrupo = computed(() => GRUPOS
  .map(g => ({ ...g, items: lista.value.filter(d => (d.grupo ?? 'documentos') === g.clave) }))
  .filter(g => g.items.length))

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getPlantillas()
    lista.value = data
  } catch {
    toast.error('No se pudieron cargar los documentos.')
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

function fecha(f) {
  if (!f) return ''
  return new Date(f).toLocaleString('es-CO', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

// ── Editor ──────────────────────────────────────────────────────────────────
const doc        = ref(null)     // { clave, nombre, descripcion, vigente, historial, datos }
const abriendo   = ref(null)
const vista      = ref('editar') // editar | historial
const borrador   = ref(null)
const original   = ref('')
const nota       = ref('')
const guardando  = ref(false)
const editorKey  = ref(0)
const previa     = ref(null)     // contenido del anexo para ver como el cliente

const hayCambios = computed(() => !!borrador.value && JSON.stringify(borrador.value) !== original.value)

async function abrir(d) {
  abriendo.value = d.clave
  try {
    const { data } = await getPlantilla(d.clave)
    ponerDoc(data)
    vista.value = 'editar'
  } catch {
    toast.error('No se pudo abrir el documento.')
  } finally {
    abriendo.value = null
  }
}

function ponerDoc(data) {
  doc.value      = data
  borrador.value = JSON.parse(JSON.stringify(data.vigente.contenido))
  original.value = JSON.stringify(data.vigente.contenido)
  nota.value     = ''
  editorKey.value++
}

function cerrar() {
  if (hayCambios.value && !confirm('Tienes cambios sin guardar. ¿Descartarlos?')) return
  doc.value = null
  borrador.value = null
}

async function guardar() {
  if (!doc.value || guardando.value) return
  guardando.value = true
  try {
    const { data } = await guardarPlantilla(doc.value.clave, borrador.value, nota.value.trim())
    ponerDoc(data)
    data.cambio ? toast.success(data.message) : toast.info(data.message)
    // Los mensajes y el nombre de la empresa que usa la app, ya con el cambio.
    if (data.cambio) cargarTextosNegocio({ otraVez: true })
    await cargar()
  } catch (e) {
    const errores = e.response?.data?.errors
    const primero = errores ? Object.values(errores)[0]?.[0] : null
    toast.error(primero ?? e.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardando.value = false
  }
}

/** Lleva el texto de una versión vieja al editor; queda vigente al guardar. */
function usarVersion(v) {
  if (hayCambios.value && !confirm('Tienes cambios sin guardar. ¿Reemplazarlos por esta versión?')) return
  borrador.value = JSON.parse(JSON.stringify(v.contenido))
  nota.value = v.fabrica ? 'Volver al texto de fábrica' : `Volver a la versión ${v.version}`
  editorKey.value++
  vista.value = 'editar'
  toast.success('Cargada en el editor. Revisa y guarda para que quede vigente.')
}

function verComoCliente(contenido) {
  previa.value = contenido
}

const esAnexo = computed(() => doc.value?.clave === 'anexo_garantia')
</script>

<template>
  <div class="space-y-3">
    <p class="text-xs text-gray-500">
      El texto de los documentos que se entregan o se firman. Cada vez que guardas
      queda una versión nueva y las anteriores quedan en el historial.
    </p>

    <AppSpinner v-if="cargando" />

    <section v-else v-for="g in porGrupo" :key="g.clave" class="space-y-2">
      <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wide pt-1">{{ g.titulo }}</h4>
      <button
        v-for="d in g.items" :key="d.clave" type="button" @click="abrir(d)"
        class="w-full text-left bg-white rounded-xl shadow-sm border border-gray-100 p-4 flex items-start gap-3 hover:bg-gray-50 transition-colors"
      >
        <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
          <component :is="ICONOS[d.clave] ?? DocumentTextIcon" class="w-5 h-5 text-blue-600" />
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-gray-800">{{ d.nombre }}</p>
          <p class="text-xs text-gray-500 mt-0.5">{{ d.descripcion }}</p>
          <div class="flex flex-wrap items-center gap-1.5 mt-2">
            <span v-if="d.fabrica" class="text-[10px] font-semibold text-gray-600 bg-gray-100 rounded-full px-2 py-0.5">Texto de fábrica</span>
            <span v-else class="text-[10px] font-semibold text-blue-700 bg-blue-50 rounded-full px-2 py-0.5">Versión {{ d.version }}</span>
            <span v-if="!d.fabrica" class="text-[11px] text-gray-400">{{ d.autor ?? 'Alguien' }} · {{ fecha(d.creado_at) }}</span>
          </div>
        </div>
        <span v-if="abriendo === d.clave" class="w-4 h-4 mt-1 border-2 border-blue-500 border-t-transparent rounded-full animate-spin shrink-0" />
        <PencilSquareIcon v-else class="w-4 h-4 text-gray-300 shrink-0 mt-1" />
      </button>
    </section>

    <!-- Editor: hoja a pantalla completa en el teléfono -->
    <Teleport to="body">
      <Transition name="fade">
        <div v-if="doc" class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center" @click.self="cerrar">
          <div class="absolute inset-0 bg-black/40" @click="cerrar" />
          <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg h-[94vh] sm:h-[90vh] flex flex-col shadow-2xl">
            <!-- Encabezado -->
            <div class="px-4 pt-4 pb-3 border-b border-gray-100 space-y-3">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-base font-bold text-gray-800">{{ doc.nombre }}</p>
                  <p class="text-[11px] text-gray-500">
                    Vigente: {{ doc.vigente.fabrica ? 'texto de fábrica' : `versión ${doc.vigente.version}` }}
                  </p>
                </div>
                <button @click="cerrar" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0" aria-label="Cerrar">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>
              <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
                <button
                  v-for="t in [{ v: 'editar', l: 'Editar' }, { v: 'historial', l: `Historial (${doc.historial.length})` }]" :key="t.v"
                  @click="vista = t.v"
                  :class="['flex-1 text-xs font-semibold rounded-lg py-2 transition-colors', vista === t.v ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
                >{{ t.l }}</button>
              </div>
            </div>

            <!-- Cuerpo -->
            <div class="flex-1 overflow-y-auto px-4 py-4">
              <template v-if="vista === 'editar'">
                <div v-if="esAnexo" class="mb-4 text-[11px] text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 space-y-1">
                  <p class="font-semibold text-amber-600">Es un texto legal: revísalo con quien corresponda antes de guardar.</p>
                  <p>Lo que ya firmaron los clientes no cambia: cada anexo guarda el texto que se firmó. Los enlaces ya enviados y sin firmar siguen con el texto con que se enviaron.</p>
                  <p>Ojo: los meses con que el sistema calcula si una garantía está vigente no se leen de este texto, sino de "Plazos de garantía". Si cambias un plazo aquí, cámbialo también allá.</p>
                </div>
                <!-- El aviso de cada plantilla de campos (lo manda el servidor) -->
                <p v-else-if="doc.aviso" class="mb-4 text-[11px] text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">{{ doc.aviso }}</p>

                <EditorAnexoGarantia v-if="esAnexo" :key="editorKey" v-model="borrador" />
                <EditorOrdenEntrega v-else-if="doc.clave === 'orden_entrega'" :key="editorKey" v-model="borrador" :datos="doc.datos" />
                <EditorCampos v-else :key="editorKey" v-model="borrador" :campos="doc.campos ?? []" :datos="doc.datos ?? []" />
              </template>

              <div v-else class="space-y-2">
                <div
                  v-for="(v, i) in doc.historial" :key="v.version"
                  class="bg-white rounded-xl border border-gray-200 p-3 space-y-1.5"
                >
                  <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-sm font-semibold text-gray-800">{{ v.fabrica ? 'Texto de fábrica' : `Versión ${v.version}` }}</span>
                    <span v-if="i === 0" class="text-[10px] font-semibold text-green-700 bg-green-50 rounded-full px-2 py-0.5">Vigente</span>
                    <span v-if="esAnexo && v.firmados" class="text-[10px] font-semibold text-gray-600 bg-gray-100 rounded-full px-2 py-0.5">
                      {{ v.firmados }} firmado{{ v.firmados === 1 ? '' : 's' }}
                    </span>
                  </div>
                  <p v-if="!v.fabrica" class="text-[11px] text-gray-500 flex items-center gap-1">
                    <ClockIcon class="w-3.5 h-3.5" /> {{ v.autor ?? 'Alguien' }} · {{ fecha(v.creado_at) }}
                  </p>
                  <p v-if="v.nota" class="text-xs text-gray-600">{{ v.nota }}</p>
                  <div class="flex gap-2 pt-1">
                    <button
                      v-if="esAnexo" type="button" @click="verComoCliente(v.contenido)"
                      class="flex-1 text-xs font-semibold border border-gray-300 rounded-lg py-2 text-gray-700 hover:bg-gray-50 flex items-center justify-center gap-1"
                    ><EyeIcon class="w-4 h-4" /> Ver</button>
                    <button
                      v-if="i !== 0" type="button" @click="usarVersion(v)"
                      class="flex-1 text-xs font-semibold border border-gray-300 rounded-lg py-2 text-blue-600 hover:bg-gray-50"
                    >Usar este texto</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Guardar -->
            <div v-if="vista === 'editar'" class="border-t border-gray-100 px-4 py-3 space-y-2 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
              <input
                v-model="nota" maxlength="255" placeholder="¿Por qué se cambia? (opcional, queda en el historial)"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <div class="flex gap-2">
                <button
                  v-if="esAnexo" type="button" @click="verComoCliente(borrador)"
                  class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-xs font-semibold hover:bg-gray-200 flex items-center justify-center gap-1"
                ><EyeIcon class="w-4 h-4" /> Ver como el cliente</button>
                <button
                  type="button" @click="guardar" :disabled="guardando || !hayCambios"
                  class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-xs font-semibold hover:bg-blue-700 disabled:opacity-50"
                >{{ guardando ? 'Guardando…' : hayCambios ? 'Guardar versión nueva' : 'Sin cambios' }}</button>
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- El anexo tal como lo lee el cliente (no se firma desde aquí) -->
      <Transition name="fade">
        <div v-if="previa" class="fixed inset-0 z-[70] flex items-end sm:items-center justify-center">
          <div class="absolute inset-0 bg-black/50" @click="previa = null" />
          <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-lg h-[94vh] sm:h-[90vh] flex flex-col shadow-2xl">
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-gray-100">
              <div class="min-w-0">
                <p class="text-sm font-bold text-gray-800">Así lo ve el cliente</p>
                <p class="text-[11px] text-gray-500">Vista previa: desde aquí no se firma nada.</p>
              </div>
              <button @click="previa = null" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 shrink-0" aria-label="Cerrar">
                <XMarkIcon class="w-5 h-5" />
              </button>
            </div>
            <div class="flex-1 overflow-y-auto px-4 pb-6">
              <p class="text-base font-bold text-gray-800 py-3">{{ previa.titulo }}</p>
              <AnexoFirma :contenido="previa" texto-boton="Vista previa: no se firma" :enviando="true" />
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from,
.fade-leave-to { opacity: 0; }
</style>
