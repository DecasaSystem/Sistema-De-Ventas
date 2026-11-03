<script setup>
/**
 * Gestión → Ajustes: interruptores que cambian cómo trabaja el sistema.
 *
 * El primero lo pidió el dueño (2026-10-16): poder dejar de calificar a los
 * trabajadores del taller cada vez que una pieza pasa de un paso a otro. El
 * servidor lo respeta aunque una pantalla vieja mande las estrellas.
 */
import { ref, onMounted } from 'vue'
import { useToast } from '@/composables/useToast'
import { getAjustesSistema, guardarAjustesSistema } from '@/api/ajustes'
import AppSpinner from '@/components/common/AppSpinner.vue'
import { WrenchScrewdriverIcon, DocumentCheckIcon, BellAlertIcon } from '@heroicons/vue/24/outline'

const toast = useToast()

// La declaración de diseños especiales (dueño, 2026-10-21): en lo que se
// fabrica fuera del catálogo, seguro; en lo personalizado, "que sea activable".
const declaracion = [
  {
    clave: 'declaracion_disenos_especiales',
    titulo: 'Pedirla en los diseños especiales',
    ayuda: 'Lo que no está en el catálogo y se fabrica desde cero. El vendedor llena la ficha del mueble (madera, tela, relleno, pintura, medidas y tipo) y sube los bocetos; el cliente la aprueba producto por producto y la firma con el anexo.',
  },
  {
    clave: 'declaracion_personalizados',
    titulo: 'Pedirla también en los personalizados',
    ayuda: 'Un producto del catálogo al que se le cambian detalles (otra medida, otro acabado). Igual que arriba: ficha, bocetos y la firma del cliente.',
  },
]

const ajustes   = ref(null)
const cargando  = ref(true)
const guardando = ref('')

async function cargar() {
  cargando.value = true
  try {
    const { data } = await getAjustesSistema()
    ajustes.value = data
  } catch {
    toast.error('No se pudieron cargar los ajustes.')
  } finally {
    cargando.value = false
  }
}

async function cambiar(clave) {
  if (guardando.value) return
  const nuevo = !ajustes.value[clave]
  guardando.value = clave
  try {
    const { data } = await guardarAjustesSistema({ [clave]: nuevo })
    ajustes.value = data
    toast.success(nuevo ? 'Encendido.' : 'Apagado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardando.value = ''
  }
}

// Con cuántos días de anticipación avisar que una pieza del taller se vence.
// Antes eran 3, 2 y 1 fijos y la campana se llenaba (dueño, 2026-11-03); de
// fábrica, solo el día antes.
const diasPosibles = [1, 2, 3]

async function cambiarDiaAviso(dia) {
  if (guardando.value) return
  const actuales = ajustes.value.avisar_entrega_dias_antes ?? []
  const nuevos = actuales.includes(dia) ? actuales.filter(d => d !== dia) : [...actuales, dia].sort()
  guardando.value = 'avisar_entrega_dias_antes'
  try {
    const { data } = await guardarAjustesSistema({ avisar_entrega_dias_antes: nuevos })
    ajustes.value = data
    toast.success('Guardado.')
  } catch (e) {
    toast.error(e.response?.data?.message ?? 'No se pudo guardar.')
  } finally {
    guardando.value = ''
  }
}

onMounted(cargar)
</script>

<template>
  <div class="space-y-3">
    <div v-if="cargando" class="flex justify-center py-10"><AppSpinner /></div>

    <template v-else-if="ajustes">
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <h3 class="text-sm font-bold text-gray-800 flex items-center gap-2">
          <WrenchScrewdriverIcon class="w-4 h-4 text-blue-600" />
          Producción
        </h3>

        <label class="flex items-start gap-2.5 cursor-pointer select-none">
          <button
            type="button" role="switch"
            :aria-checked="ajustes.calificar_trabajadores_taller"
            :disabled="guardando === 'calificar_trabajadores_taller'"
            @click="cambiar('calificar_trabajadores_taller')"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0 mt-0.5 disabled:opacity-50',
              ajustes.calificar_trabajadores_taller ? 'bg-blue-600' : 'bg-gray-300']"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
              ajustes.calificar_trabajadores_taller ? 'translate-x-5' : 'translate-x-0.5']" />
          </button>
          <span class="text-sm text-gray-700">
            Calificar a los trabajadores al terminar un paso
            <span class="block text-xs text-gray-400 mt-0.5">
              Encendido: al pasar una pieza al siguiente paso se pide cuánto se demoró cada
              trabajador, sus estrellas y una opinión. Apagado: solo se dice quién lo hizo.
            </span>
            <span class="block text-xs text-gray-400 mt-1">
              No cambia ningún pago: la nómina y las bonificaciones no usan las estrellas.
              Las calificaciones ya guardadas se conservan.
            </span>
          </span>
        </label>
      </div>

      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <div>
          <h3 class="text-sm font-bold text-gray-800 flex items-center gap-2">
            <BellAlertIcon class="w-4 h-4 text-blue-600" />
            Aviso de entrega próxima
          </h3>
          <p class="text-xs text-gray-400 mt-0.5">
            Cuántos días antes de la fecha prometida se avisa que una pieza del taller se vence.
            Le llega a los supervisores y al vendedor de la orden. El aviso "Entrega hoy" sale siempre.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <button
            v-for="dia in diasPosibles" :key="dia"
            type="button"
            :aria-pressed="(ajustes.avisar_entrega_dias_antes ?? []).includes(dia)"
            :disabled="guardando === 'avisar_entrega_dias_antes'"
            @click="cambiarDiaAviso(dia)"
            :class="['px-3 py-1.5 rounded-full text-xs font-semibold transition-colors disabled:opacity-50',
              (ajustes.avisar_entrega_dias_antes ?? []).includes(dia)
                ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-600']"
          >
            {{ dia === 1 ? '1 día antes' : `${dia} días antes` }}
          </button>
        </div>
        <p v-if="!(ajustes.avisar_entrega_dias_antes ?? []).length" class="text-xs text-amber-600">
          Sin días escogidos no se avisa con anticipación, solo el mismo día.
        </p>
        <p class="text-xs text-gray-400">
          Para que a una persona no le lleguen, se apaga "Próximos a entregar" en Trabajadores → su ficha → Notificaciones.
        </p>
      </div>

      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <div>
          <h3 class="text-sm font-bold text-gray-800 flex items-center gap-2">
            <DocumentCheckIcon class="w-4 h-4 text-blue-600" />
            Declaración de diseños especiales
          </h3>
          <p class="text-xs text-gray-400 mt-0.5">
            Se pide donde se pide el anexo de garantías. En la tienda también vale la foto del papel firmado.
            El texto se cambia en Documentos.
          </p>
        </div>

        <label v-for="d in declaracion" :key="d.clave" class="flex items-start gap-2.5 cursor-pointer select-none">
          <button
            type="button" role="switch"
            :aria-checked="ajustes[d.clave]"
            :disabled="guardando === d.clave"
            @click="cambiar(d.clave)"
            :class="['w-10 h-5 rounded-full transition-colors relative flex-shrink-0 mt-0.5 disabled:opacity-50',
              ajustes[d.clave] ? 'bg-blue-600' : 'bg-gray-300']"
          >
            <div :class="['absolute top-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform',
              ajustes[d.clave] ? 'translate-x-5' : 'translate-x-0.5']" />
          </button>
          <span class="text-sm text-gray-700">
            {{ d.titulo }}
            <span class="block text-xs text-gray-400 mt-0.5">{{ d.ayuda }}</span>
          </span>
        </label>
      </div>
    </template>
  </div>
</template>
