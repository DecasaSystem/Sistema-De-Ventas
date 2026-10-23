/**
 * Quién es este aparato, para decir "se guardó en el celular" y no confundir
 * lo que se hizo aquí con lo que se hizo en otro.
 *
 * El id vive en localStorage: si el navegador lo borra sale uno nuevo y este
 * aparato pasa por "otro", que es lo seguro (se pregunta en vez de pisar).
 */
const LLAVE = 'decasa-aparato-id'

let id = null

export function idDeAparato() {
  if (id) return id
  try {
    id = localStorage.getItem(LLAVE)
    if (!id) {
      id = (crypto.randomUUID?.() ?? `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`)
      localStorage.setItem(LLAVE, id)
    }
  } catch {
    id ??= `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`
  }
  return id
}

/** "el celular" / "la tablet" / "el computador". */
export function nombreDeAparato() {
  const ua = navigator.userAgent || ''
  if (/iPad|Tablet/i.test(ua) || (/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'la tablet'
  if (/Android|iPhone|iPod|Mobile/i.test(ua)) return 'el celular'
  return 'el computador'
}
