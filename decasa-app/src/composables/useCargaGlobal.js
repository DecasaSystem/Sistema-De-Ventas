import { ref, computed } from 'vue'

/**
 * Cuenta las peticiones al backend que están en vuelo para poder mostrar una
 * barra de progreso arriba de la pantalla.
 *
 * Existe porque muchos botones lanzan una petición y no avisan nada: el usuario
 * toca "asignar ruta", no pasa nada visible durante dos segundos y vuelve a
 * tocar. La barra da esa señal de forma global, sin tener que acordarse de
 * poner un estado de carga en cada botón nuevo.
 */

const enVuelo = ref(0)
const visible = ref(false)
let temporizador = null

// Nada se muestra durante los primeros 300 ms. La mayoría de las peticiones
// responden antes, y una barra que parpadea en cada clic estorba más de lo que
// informa.
const RETRASO_MS = 300

export function iniciarPeticion() {
  enVuelo.value++
  if (temporizador === null && !visible.value) {
    temporizador = setTimeout(() => {
      temporizador = null
      if (enVuelo.value > 0) visible.value = true
    }, RETRASO_MS)
  }
}

export function terminarPeticion() {
  // Nunca por debajo de cero: si alguna petición se cancela dos veces, un
  // contador negativo dejaría la barra encendida para siempre.
  enVuelo.value = Math.max(0, enVuelo.value - 1)
  if (enVuelo.value === 0) {
    if (temporizador !== null) {
      clearTimeout(temporizador)
      temporizador = null
    }
    visible.value = false
  }
}

// Cuántas "S" locales hay pintadas ahora mismo (las de AppSpinner y las que
// cada pantalla pone en su propio panel). Si ya hay una, el indicador global
// se calla: la idea es que nunca se vean dos cargas al tiempo.
const localesActivos = ref(0)

export function registrarCargaLocal() {
  localesActivos.value++
}

export function quitarCargaLocal() {
  localesActivos.value = Math.max(0, localesActivos.value - 1)
}

export function useCargaGlobal() {
  const hayCargaLocal = computed(() => localesActivos.value > 0)
  return {
    cargando: computed(() => visible.value && !hayCargaLocal.value),
    hayCargaLocal,
    peticionesEnVuelo: computed(() => enVuelo.value),
  }
}
