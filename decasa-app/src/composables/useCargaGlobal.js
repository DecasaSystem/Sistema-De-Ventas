import { ref, computed } from 'vue'

/**
 * Cuenta las peticiones al backend en vuelo para poder mostrar la "S" en el
 * centro de la pantalla.
 *
 * Existe porque muchos botones lanzan una petición y no avisan nada: el usuario
 * toca "asignar ruta", no pasa nada visible durante dos segundos y vuelve a
 * tocar. La S global da esa señal sin tener que acordarse de poner un estado de
 * carga en cada botón nuevo.
 *
 * Nunca debe verse al tiempo que la S propia de una pantalla, ni justo después
 * de que esa termine: eso se leía como "cargó dos veces".
 */

const enVuelo = ref(0)
const visible = ref(false)
let temporizador = null

// Nada se muestra durante los primeros 300 ms. La mayoría de las peticiones
// responden antes, y una S que parpadea en cada clic estorba más de lo que
// informa.
const RETRASO_MS = 300

// Después de que una pantalla termina su propia carga suelen quedar peticiones
// de relleno (una por tarjeta, por ejemplo). Se les da este margen para que
// terminen calladas; solo si de verdad se demoran aparece la S.
const MARGEN_TRAS_LOCAL_MS = 900

function programarAparicion(retraso) {
  if (temporizador !== null) return
  temporizador = setTimeout(() => {
    temporizador = null
    if (enVuelo.value > 0) visible.value = true
  }, retraso)
}

export function iniciarPeticion() {
  enVuelo.value++
  if (!visible.value) programarAparicion(RETRASO_MS)
}

export function terminarPeticion() {
  // Nunca por debajo de cero: si alguna petición se cancela dos veces, un
  // contador negativo dejaría la S encendida para siempre.
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
// cada pantalla pone en su propio panel).
const localesActivos = ref(0)

export function registrarCargaLocal() {
  localesActivos.value++
}

export function quitarCargaLocal() {
  localesActivos.value = Math.max(0, localesActivos.value - 1)
  if (localesActivos.value > 0) return

  // La pantalla acaba de pintar su contenido. Si `visible` ya venía en true,
  // la S global saltaría en este mismo instante y se vería como una segunda
  // carga encima de lo que el usuario ya está leyendo. Se apaga y se vuelve a
  // esperar desde cero.
  visible.value = false
  if (temporizador !== null) {
    clearTimeout(temporizador)
    temporizador = null
  }
  if (enVuelo.value > 0) programarAparicion(MARGEN_TRAS_LOCAL_MS)
}

export function useCargaGlobal() {
  const hayCargaLocal = computed(() => localesActivos.value > 0)
  return {
    cargando: computed(() => visible.value && !hayCargaLocal.value),
    hayCargaLocal,
    peticionesEnVuelo: computed(() => enVuelo.value),
  }
}
