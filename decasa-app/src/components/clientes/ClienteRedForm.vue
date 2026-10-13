<script setup>
// Registrar a mano a un interesado en Clientes → Redes (dueño, 2026-10-13): una
// llamada, alguien que vino a la tienda, un referido, o uno de WhatsApp o Instagram
// que no pasó por Elena. Si es de WhatsApp, cuando esa persona escriba Elena sigue
// actualizando esta misma ficha. Si el celular ya está, se abre la ficha que existe.
import { ref, computed, onMounted } from 'vue'
import api from '@/api'
import { createClienteRed } from '@/api/clientes'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import InputPesos from '@/components/common/InputPesos.vue'
import { CANALES_RED } from './clientesRedes'

const emit = defineEmits(['cerrar', 'creado', 'abrir'])
const auth = useAuthStore()
const toast = useToast()

const form = ref({
  nombre: '', canal: 'llamada', telefono: '', usuario_red: '', ciudad: '',
  interes: '', espacio: '', presupuesto: '', notas: '', tienda_id: '',
})
const tiendas = ref([])
const guardando = ref(false)
const error = ref('')
const existenteId = ref(null)

const pideCelular = computed(() => form.value.canal === 'whatsapp')
const puedeGuardar = computed(() =>
  form.value.nombre.trim() && (!pideCelular.value || form.value.telefono.trim()) && !guardando.value)

onMounted(async () => {
  if (!auth.isSupervisor) return
  try { tiendas.value = (await api.get('/tiendas')).data ?? [] } catch { /* se registra sin tienda */ }
})

async function guardar() {
  if (!puedeGuardar.value) return
  guardando.value = true
  error.value = ''
  existenteId.value = null
  try {
    const payload = Object.fromEntries(
      Object.entries(form.value).map(([k, v]) => [k, typeof v === 'string' ? v.trim() || null : v])
    )
    if (!payload.presupuesto) payload.presupuesto = null
    if (!auth.isSupervisor) delete payload.tienda_id
    const { data } = await createClienteRed(payload)
    toast.success('Cliente registrado')
    emit('creado', data)
  } catch (e) {
    error.value = e.response?.data?.message || 'No se pudo guardar'
    existenteId.value = e.response?.data?.existente_id ?? null
  } finally {
    guardando.value = false
  }
}

const campo = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
</script>

<template>
  <Transition name="fade" appear>
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="emit('cerrar')">
      <div class="absolute inset-0 bg-black/40" @click="emit('cerrar')" />
      <form
        @submit.prevent="guardar"
        class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3 max-h-[90vh] overflow-y-auto"
      >
        <div class="flex items-start gap-2">
          <div class="flex-1">
            <h3 class="text-lg font-bold text-gray-800">Agregar cliente</h3>
            <p class="text-xs text-gray-500">Alguien que llamó, vino a la tienda o te escribió y quieres tener a la mano.</p>
          </div>
          <button type="button" @click="emit('cerrar')" class="text-gray-400 text-2xl leading-none" aria-label="Cerrar">&times;</button>
        </div>

        <!-- De dónde llegó -->
        <div>
          <p class="text-xs font-medium text-gray-500 mb-1.5">¿Cómo llegó?</p>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="c in CANALES_RED"
              :key="c.value"
              type="button"
              @click="form.canal = c.value"
              :aria-pressed="form.canal === c.value"
              :class="['px-3 py-1.5 rounded-full text-xs font-semibold border transition-colors',
                form.canal === c.value ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-600 border-gray-200']"
            >{{ c.label }}</button>
          </div>
        </div>

        <div>
          <label for="cr-nombre" class="block text-xs font-medium text-gray-500 mb-1">Nombre <span class="text-red-500">*</span></label>
          <input id="cr-nombre" v-model="form.nombre" maxlength="120" autocomplete="off" :class="campo" placeholder="Nombre de la persona" />
        </div>
        <div>
          <label for="cr-tel" class="block text-xs font-medium text-gray-500 mb-1">
            Celular <span v-if="pideCelular" class="text-red-500">*</span>
            <span v-else class="text-gray-400 font-normal">(si lo dejó)</span>
          </label>
          <input id="cr-tel" v-model="form.telefono" type="tel" inputmode="tel" maxlength="30" :class="campo" placeholder="300 123 4567" />
          <p v-if="pideCelular" class="text-[11px] text-gray-400 mt-1">Si después escribe por WhatsApp, Elena sigue llenando esta misma ficha.</p>
        </div>
        <div v-if="form.canal === 'instagram'">
          <label for="cr-ig" class="block text-xs font-medium text-gray-500 mb-1">Usuario de Instagram</label>
          <input id="cr-ig" v-model="form.usuario_red" maxlength="80" autocomplete="off" :class="campo" placeholder="@usuario" />
        </div>

        <div>
          <label for="cr-interes" class="block text-xs font-medium text-gray-500 mb-1">Lo que busca</label>
          <textarea id="cr-interes" v-model="form.interes" rows="2" maxlength="500" :class="[campo, 'resize-none']"
            placeholder="Ej.: cama queen en madera clara para la habitación principal" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label for="cr-pres" class="block text-xs font-medium text-gray-500 mb-1">Presupuesto</label>
            <InputPesos id="cr-pres" v-model="form.presupuesto" permite-vacio :class="campo" placeholder="$" />
          </div>
          <div>
            <label for="cr-ciudad" class="block text-xs font-medium text-gray-500 mb-1">Ciudad</label>
            <input id="cr-ciudad" v-model="form.ciudad" maxlength="80" :class="campo" placeholder="Armenia" />
          </div>
        </div>
        <div>
          <label for="cr-espacio" class="block text-xs font-medium text-gray-500 mb-1">Para qué espacio</label>
          <input id="cr-espacio" v-model="form.espacio" maxlength="120" :class="campo" placeholder="Sala, habitación, comedor…" />
        </div>

        <div v-if="auth.isSupervisor">
          <label for="cr-tienda" class="block text-xs font-medium text-gray-500 mb-1">Tienda <span class="text-gray-400 font-normal">(quién lo atiende)</span></label>
          <select id="cr-tienda" v-model="form.tienda_id" :class="campo">
            <option value="">Sin tienda (lo ven todas)</option>
            <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
          </select>
        </div>

        <div>
          <label for="cr-notas" class="block text-xs font-medium text-gray-500 mb-1">Notas del equipo</label>
          <textarea id="cr-notas" v-model="form.notas" rows="2" maxlength="2000" :class="[campo, 'resize-none']"
            placeholder="Qué se habló, cuándo volver a llamar…" />
        </div>

        <div v-if="error" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2 flex items-center gap-2">
          <span class="flex-1">{{ error }}</span>
          <button v-if="existenteId" type="button" @click="emit('abrir', existenteId)" class="font-semibold text-blue-600 shrink-0">Ver ficha</button>
        </div>

        <div class="flex gap-3 pt-1">
          <button type="button" @click="emit('cerrar')" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
          <button type="submit" :disabled="!puedeGuardar"
            class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold disabled:opacity-50">
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </form>
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
