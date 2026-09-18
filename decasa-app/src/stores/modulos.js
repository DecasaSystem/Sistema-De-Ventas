import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/api'
import { iconoPorNombre } from '@/constants/iconos'

/**
 * Cómo se llama cada módulo en esta empresa.
 *
 * Las pantallas ya no escriben "Telas" ni eligen el dibujo: piden el nombre y
 * el icono por su clave, y si la empresa no ha cambiado nada reciben lo que
 * traen escrito por defecto. Eso es a propósito: el día que este servicio no
 * responda, la aplicación tiene que seguir mostrando sus botones con nombres
 * razonables en vez de una fila de cuadros vacíos.
 *
 * Se guarda en el aparato para que al abrir no se vea primero el nombre viejo
 * y un parpadeo después: se pinta lo último que se supo y se revalida detrás.
 */
const CLAVE_CACHE = 'modulosPersonalizados'

export const useModulosStore = defineStore('modulos', () => {
  const porClave = ref(leerCache())
  const cargado  = ref(Object.keys(porClave.value).length > 0)

  function leerCache() {
    try {
      return JSON.parse(localStorage.getItem(CLAVE_CACHE) ?? '{}')
    } catch {
      return {}
    }
  }

  async function cargar() {
    try {
      const { data } = await api.get('/modulos')
      const mapa = {}
      for (const m of data) mapa[m.clave] = m
      porClave.value = mapa
      cargado.value  = true
      localStorage.setItem(CLAVE_CACHE, JSON.stringify(mapa))
    } catch {
      // Sin respuesta se queda con lo último que supo, o con los nombres de
      // repuesto que traen las pantallas.
    }
  }

  /** El nombre que le puso la empresa, o el que trae la pantalla escrito. */
  function nombre(clave, porDefecto = '') {
    return porClave.value[clave]?.nombre || porDefecto
  }

  /**
   * El icono que le puso la empresa, o el que trae la pantalla.
   * Se devuelve el componente ya resuelto para que la vista sólo lo pinte.
   */
  function icono(clave, porDefecto = null) {
    return iconoPorNombre(porClave.value[clave]?.icono) ?? porDefecto
  }

  /**
   * ¿Se muestra este módulo? Apagarlo es cosa de la empresa que no lo usa —una
   * tienda de ropa no tiene taller—, y no toca los permisos: quien tenga el
   * suyo sigue pudiendo entrar si llega por su cuenta.
   */
  function visible(clave) {
    return porClave.value[clave]?.visible !== false
  }

  /** Un acceso del menú, ya con el nombre y el icono de esta empresa. */
  function acceso(clave, base) {
    return { ...base, label: nombre(clave, base.label), icon: icono(clave, base.icon) }
  }

  /** De qué módulo nació éste, o nada si es de los de siempre. */
  function plantilla(clave) {
    return porClave.value[clave]?.plantilla ?? null
  }

  /** La config de un módulo copiado (unidad, singular, decimales), ya completa. */
  function config(clave) {
    return porClave.value[clave]?.config ?? {}
  }

  /**
   * Los módulos que la empresa creó a partir de uno (Espumas e Hilos a partir
   * de Telas), encendidos y en su orden. Van a donde va el original, con su
   * mismo permiso: la pantalla que los lista no tiene que saber cuántos hay.
   */
  function instancias(dePlantilla) {
    return Object.values(porClave.value)
      .filter(m => m.plantilla === dePlantilla && m.visible !== false)
      .sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0) || a.nombre.localeCompare(b.nombre))
  }

  /**
   * Deja pasar sólo los accesos que la empresa tiene encendidos. Cada acceso
   * dice de qué módulo es con `modulo`; el que no lo diga pasa siempre.
   */
  function soloVisibles(accesos) {
    return accesos
      .filter(a => !a.modulo || visible(a.modulo))
      .map(a => (a.modulo ? acceso(a.modulo, a) : a))
  }

  function limpiar() {
    porClave.value = {}
    cargado.value  = false
    localStorage.removeItem(CLAVE_CACHE)
  }

  return { porClave, cargado, cargar, nombre, icono, visible, acceso, soloVisibles, plantilla, config, instancias, limpiar }
})
