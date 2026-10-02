/**
 * Que una pantalla no le dispare al servidor decenas de peticiones a la vez.
 *
 * Inventario pide las variantes y las medidas de cada tarjeta apenas se pinta:
 * con una página de 40 productos eran hasta 80 peticiones en el mismo
 * instante. El servidor no da abasto con eso (cada una abre su conexión a la
 * base) y empezaba a responder 500 a un montón de ellas, en tarjetas
 * seguidas. En fila, unas pocas a la vez, terminan todas y casi igual de
 * rápido.
 *
 * @param {number} max  Cuántas pueden ir al mismo tiempo.
 * @returns {(tarea: () => Promise<any>) => Promise<any>}
 */
export function crearCola(max = 4) {
  let activas = 0
  const espera = []

  function siguiente() {
    if (activas >= max || !espera.length) return
    activas++
    const { tarea, resolve, reject } = espera.shift()
    Promise.resolve()
      .then(tarea)
      .then(resolve, reject)
      .finally(() => { activas--; siguiente() })
  }

  return (tarea) => new Promise((resolve, reject) => {
    espera.push({ tarea, resolve, reject })
    siguiente()
  })
}

/**
 * Reintenta una vez si el servidor falló (5xx) o no respondió. Un 4xx no se
 * reintenta: ahí la respuesta es la que es.
 */
export async function conReintento(tarea, esperaMs = 800) {
  try {
    return await tarea()
  } catch (e) {
    const status = e?.response?.status
    if (status && status < 500) throw e
    await new Promise(r => setTimeout(r, esperaMs))
    return tarea()
  }
}
