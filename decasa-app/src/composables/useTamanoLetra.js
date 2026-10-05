import { ref, computed } from 'vue'

/**
 * El tamaño de la letra del anexo de garantías y del resumen del pedido,
 * para quien casi no ve. Uno solo para toda la página: si se agranda arriba,
 * crece todo junto. Se recuerda en el teléfono (si el navegador no deja
 * guardarlo, igual funciona mientras esté abierta).
 */
const ESCALAS = [1, 1.25, 1.5, 1.8]
const CLAVE = 'anexo-tamano-letra'

const nivel = ref((() => {
  try {
    const n = Number(localStorage.getItem(CLAVE))
    return Number.isInteger(n) && n >= 0 && n < ESCALAS.length ? n : 0
  } catch { return 0 }
})())

export function useTamanoLetra() {
  const escala = computed(() => ESCALAS[nivel.value])
  const esMinima = computed(() => nivel.value === 0)
  const esMaxima = computed(() => nivel.value === ESCALAS.length - 1)

  function cambiar(paso) {
    nivel.value = Math.min(ESCALAS.length - 1, Math.max(0, nivel.value + paso))
    try { localStorage.setItem(CLAVE, String(nivel.value)) } catch { /* sin guardar */ }
  }

  return { escala, esMinima, esMaxima, cambiar }
}
