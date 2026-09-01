import { nextTick } from 'vue'

/**
 * Recargar sin que la pantalla se vaya al principio.
 *
 * El caso de siempre: uno baja media lista, guarda algo, y la vista se recarga
 * y lo devuelve arriba. Pasa por dos motivos, y los dos hay que evitarlos:
 *
 *  1. Mientras carga se oculta la lista para mostrar un spinner. La página se
 *     queda sin altura, el navegador no tiene a dónde bajar y pone el scroll
 *     en cero. Por eso una recarga en segundo plano NO debe vaciar la lista
 *     —eso se resuelve en cada vista, no aquí—.
 *  2. Aunque no se vacíe, al repintar se pierde la posición. De eso se encarga
 *     esta función: apunta dónde iba y vuelve ahí cuando el contenido ya está.
 *
 * Se espera dos cuadros porque un solo nextTick devuelve el control cuando
 * Vue ya escribió el DOM pero el navegador todavía no calculó la altura, y
 * entonces el scroll se recorta a la de antes.
 */
export async function sinPerderElSitio(recargar) {
  const y = window.scrollY

  await recargar()

  if (! y) return   // Ya estaba arriba: no hay nada que devolver.

  await nextTick()
  requestAnimationFrame(() => {
    window.scrollTo({ top: y, behavior: 'instant' })
    // Segunda pasada: si algo terminó de pintarse (una imagen, una tarjeta que
    // creció) la primera se habría quedado corta.
    requestAnimationFrame(() => window.scrollTo({ top: y, behavior: 'instant' }))
  })
}

/**
 * Cuántos ítems hay que volver a pedir para dejar la lista como estaba.
 *
 * Las listas cargan de 20 en 20 al bajar. Si después de eso se recarga
 * pidiendo solo la primera página, lo que el usuario ya había bajado
 * desaparece y el sitio donde iba deja de existir.
 */
export function tamanoParaRecargar(paginaActual, porPagina = 20, tope = 200) {
  return Math.min(Math.max(1, paginaActual) * porPagina, tope)
}
