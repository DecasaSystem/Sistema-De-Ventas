<script setup>
/**
 * Un módulo que la empresa creó a partir de otro.
 *
 * La URL trae la clave (`/m/telas-espumas`); aquí se busca de qué plantilla
 * nació y se monta la pantalla de esa plantilla con la clave, para que ella
 * pida sus propios datos y se llame como la empresa la llamó. Agregar una
 * plantilla nueva es agregar una línea a `PANTALLAS`.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useModulosStore } from '@/stores/modulos'
import { ENTRA_A_PLANTILLA } from '@/router'
import TelasView from '@/views/TelasView.vue'
import { Squares2X2Icon } from '@heroicons/vue/24/outline'

const props = defineProps({
  clave: { type: String, required: true },
})

const router  = useRouter()
const auth    = useAuthStore()
const modulos = useModulosStore()

const PANTALLAS = {
  telas: TelasView,
}

const buscando = ref(!modulos.cargado)

const modulo    = computed(() => modulos.porClave[props.clave] ?? null)
const plantilla = computed(() => modulo.value?.plantilla ?? null)
const pantalla  = computed(() => (plantilla.value && PANTALLAS[plantilla.value]) || null)

// Si se abrió por enlace directo, la lista de módulos puede no haber llegado
// todavía: se pide y recién ahí se decide si existe y si se puede entrar.
onMounted(async () => {
  if (!modulos.cargado) await modulos.cargar()
  buscando.value = false
})

watch([plantilla, buscando], ([p, b]) => {
  if (b || !p) return
  const entra = ENTRA_A_PLANTILLA[p]
  if (entra && !entra(auth)) router.replace({ name: 'dashboard' })
}, { immediate: true })
</script>

<template>
  <div v-if="buscando" class="flex justify-center py-12">
    <AppSpinner />
  </div>

  <!-- La clave como key: pasar de Espumas a Hilos tiene que montar la pantalla
       de cero, no reciclar la lista de la otra. -->
  <component v-else-if="pantalla" :is="pantalla" :key="clave" :clave="clave" />

  <div v-else class="p-4 max-w-md mx-auto text-center py-16 space-y-3">
    <Squares2X2Icon class="w-12 h-12 text-gray-300 mx-auto" />
    <p class="text-sm font-semibold text-gray-700">Este módulo ya no existe</p>
    <p class="text-xs text-gray-400">Puede que lo hayan borrado desde Gestión.</p>
    <button
      @click="router.replace({ name: 'dashboard' })"
      class="mt-2 px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700"
    >
      Ir al inicio
    </button>
  </div>
</template>
