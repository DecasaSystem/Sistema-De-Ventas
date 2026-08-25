<script setup>
/**
 * Entregarle cosas a un trabajador.
 *
 * Es una lista, no un artículo: a alguien que llega se le entrega el portátil,
 * la pantalla, el teclado y el mouse el mismo día. De a uno serían cuatro
 * formularios iguales, y a la tercera el mouse se queda sin anotar.
 *
 * Vive en un componente y no en cada pantalla porque se abre desde dos sitios
 * —la lista general y la ficha de una persona— y la única diferencia es si hay
 * que preguntar a quién.
 */
import { ref, computed, nextTick } from 'vue'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { entregar } from '@/api/encargos'
import { BriefcaseIcon, XMarkIcon, PlusIcon, TrashIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  // Cuando se abre desde la ficha de alguien ya se sabe a quién: no se pregunta.
  trabajadorId: { type: [Number, String], default: null },
  nombreTrabajador: { type: String, default: '' },
  // Para elegir persona cuando se abre desde la lista general.
  asignables: { type: Array, default: () => [] },
})
const emit = defineEmits(['cerrar', 'guardado'])

const toast = useToast()

function hoyISO() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function lineaVacia() {
  return { nombre: '', cantidad: 1, serial: '', valor_unitario: 0, notas: '', detalle: false }
}

const usuarioId = ref(props.trabajadorId ?? '')
const fecha     = ref(hoyISO())
const items     = ref([lineaVacia()])
const guardando = ref(false)
const nombresRef = ref([])

async function agregar() {
  items.value.push(lineaVacia())
  // El foco baja solo a la línea nueva: si no, hay que buscarla y tocarla,
  // que es justo la fricción que este formulario venía a quitar.
  await nextTick()
  nombresRef.value[items.value.length - 1]?.focus()
}

function quitar(i) {
  items.value.splice(i, 1)
  if (!items.value.length) items.value.push(lineaVacia())
}

// Las líneas en blanco no estorban: se agregó una de más y se deja ahí.
const conNombre = computed(() => items.value.filter(i => i.nombre.trim()))
const totalPiezas = computed(() => conNombre.value.reduce((s, i) => s + (Number(i.cantidad) || 0), 0))
const totalValor  = computed(() =>
  conNombre.value.reduce((s, i) => s + (Number(i.valor_unitario) || 0) * (Number(i.cantidad) || 0), 0)
)

function formatoPesos(n) {
  return '$' + Math.round(n ?? 0).toLocaleString('es-CO')
}

async function guardar() {
  if (!usuarioId.value) return toast.error('Elige a quién se le entrega')
  if (!conNombre.value.length) return toast.error('Escribe al menos una cosa')
  if (conNombre.value.some(i => !Number(i.cantidad) || Number(i.cantidad) < 1)) {
    return toast.error('Hay una línea sin cantidad')
  }

  guardando.value = true
  try {
    const { data } = await entregar({
      usuario_id: Number(usuarioId.value),
      fecha_entrega: fecha.value,
      items: conNombre.value.map(i => ({
        nombre: i.nombre.trim(),
        cantidad: Number(i.cantidad),
        serial: i.serial.trim() || null,
        // Sin valor no se puede sugerir cuánto descontar si se pierde, pero se
        // permite: hay cosas que no se le cobran a nadie.
        valor_unitario: Number(i.valor_unitario) || null,
        notas: i.notas.trim() || null,
      })),
    })
    const n = data.entregados.length
    toast.success(n === 1 ? 'Entregado y anotado' : `${n} cosas anotadas`)
    emit('guardado')
  } catch (e) {
    const errores = e.response?.data?.errors
    toast.error(errores ? Object.values(errores).flat()[0] : (e.response?.data?.message || 'No se pudo guardar'))
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] flex flex-col shadow-2xl">

        <!-- Cabecera -->
        <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 shrink-0">
          <div class="flex items-center gap-2.5 min-w-0">
            <div class="w-9 h-9 rounded-xl bg-teal-50 flex items-center justify-center shrink-0">
              <BriefcaseIcon class="w-5 h-5 text-teal-600" />
            </div>
            <p class="font-semibold text-gray-800 truncate">
              {{ nombreTrabajador ? `Entregarle a ${nombreTrabajador}` : 'Entregar' }}
            </p>
          </div>
          <button @click="emit('cerrar')" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors shrink-0">
            <XMarkIcon class="w-5 h-5" />
          </button>
        </div>

        <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">
          <!-- A quién y cuándo. La fecha es una sola: es el mismo acto. -->
          <div v-if="!trabajadorId">
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿A quién? *</label>
            <select v-model="usuarioId" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent">
              <option value="">Elegir trabajador...</option>
              <option v-for="a in asignables" :key="a.id" :value="a.id">{{ a.nombre }} — {{ a.cargo }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1.5">¿Cuándo se le entrega? *</label>
            <input v-model="fecha" type="date" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent" />
          </div>

          <div class="border-t border-gray-100 pt-3">
            <p class="text-xs font-semibold text-gray-500 mb-2">¿Qué se le entrega?</p>

            <div class="space-y-2.5">
              <div v-for="(item, i) in items" :key="i" class="border border-gray-200 rounded-xl p-2.5">
                <!-- Lo imprescindible en una línea: qué y cuántas -->
                <div class="flex items-center gap-2">
                  <input
                    :ref="el => nombresRef[i] = el"
                    v-model="item.nombre"
                    placeholder="Portátil HP"
                    class="flex-1 min-w-0 rounded-lg border border-gray-200 px-2.5 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500"
                    @keydown.enter.prevent="agregar"
                  />
                  <input
                    v-model="item.cantidad" type="number" min="1"
                    class="w-14 shrink-0 rounded-lg border border-gray-200 px-1.5 py-2 text-sm text-center text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500"
                  />
                  <button
                    v-if="items.length > 1"
                    @click="quitar(i)"
                    class="p-1.5 text-gray-300 hover:text-red-600 transition-colors shrink-0"
                    aria-label="Quitar línea"
                  >
                    <TrashIcon class="w-4 h-4" />
                  </button>
                </div>

                <!-- El resto va detrás de un toque: en una entrega de cuatro
                     cosas, pedir serial y valor de todas alarga el formulario
                     hasta donde nadie lo llena. -->
                <button
                  v-if="!item.detalle"
                  @click="item.detalle = true"
                  class="text-[11px] font-medium text-gray-400 hover:text-teal-700 mt-1.5"
                >+ serial, valor o notas</button>

                <div v-else class="mt-2 space-y-2">
                  <div class="grid grid-cols-2 gap-2">
                    <div>
                      <label class="block text-[10px] font-semibold text-gray-500 mb-1">Serial o placa</label>
                      <input v-model="item.serial" placeholder="Opcional" class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500" />
                    </div>
                    <div>
                      <label class="block text-[10px] font-semibold text-gray-500 mb-1">Vale reponer una</label>
                      <InputPesos v-model="item.valor_unitario" class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-teal-500" />
                    </div>
                  </div>
                  <input v-model="item.notas" placeholder="Notas: estado en que se entrega, accesorios..." class="w-full rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-teal-500" />
                </div>
              </div>
            </div>

            <button
              @click="agregar"
              class="w-full mt-2.5 border-2 border-dashed border-gray-200 text-gray-500 text-xs font-semibold rounded-xl py-2.5 hover:border-teal-300 hover:text-teal-700 transition-colors flex items-center justify-center gap-1.5"
            >
              <PlusIcon class="w-4 h-4" /> Agregar otra
            </button>

            <p class="text-[11px] text-gray-400 mt-2">
              El valor es lo que se le sugiere descontar si se pierde. Se puede dejar vacío.
            </p>
          </div>
        </div>

        <!-- Pie: lo que se va a entregar, para no guardar a ciegas -->
        <div class="px-5 pt-3 border-t border-gray-100 shrink-0">
          <p v-if="conNombre.length" class="text-xs text-gray-500">
            {{ conNombre.length }} {{ conNombre.length === 1 ? 'cosa' : 'cosas' }}
            <span class="text-gray-400">· {{ totalPiezas }} {{ totalPiezas === 1 ? 'pieza' : 'piezas' }}</span>
            <span v-if="totalValor" class="text-gray-400"> · {{ formatoPesos(totalValor) }}</span>
          </p>
          <p v-else class="text-xs text-gray-400">Todavía no has escrito nada.</p>
        </div>
        <div class="flex gap-2.5 p-5 pt-3 shrink-0">
          <button @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 transition-colors">Cancelar</button>
          <button
            @click="guardar" :disabled="guardando || !conNombre.length"
            class="flex-1 bg-teal-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-teal-700 transition-colors disabled:opacity-50 flex items-center justify-center gap-1.5"
          >
            <span v-if="guardando" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
            {{ guardando ? 'Guardando...' : 'Entregar' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
