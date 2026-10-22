import api from './index'

// Nueva orden a medio hacer guardada en la cuenta (ver BorradorEnCursoController).
// Todas silenciosas: corren solas mientras el vendedor escribe.
const ruta = (clave) => `/borradores-en-curso/${encodeURIComponent(clave)}`

export const leerBorradorNube   = (clave) => api.get(ruta(clave), { silencioso: true }).then(r => r.data)
export const guardarBorradorNube = (clave, cuerpo) => api.put(ruta(clave), cuerpo, { silencioso: true }).then(r => r.data)
export const borrarBorradorNube = (clave) => api.delete(ruta(clave), { silencioso: true }).then(r => r.data)

/**
 * Lo último antes de que Android cierre la app. axios no sirve aquí: la
 * petición se corta al irse la página. `keepalive` deja que salga igual
 * (el navegador la limita a ~64 KB; el formulario sin fotos pesa mucho menos).
 */
export function guardarBorradorNubeAlSalir(clave, cuerpo) {
  const token = localStorage.getItem('token')
  if (!token) return
  try {
    fetch(`/api${ruta(clave)}`, {
      method: 'PUT',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(cuerpo),
    }).catch(() => {})
  } catch { /* cuerpo muy grande para keepalive: queda el guardado normal */ }
}

/** Sube una foto del borrador a Cloudinary y devuelve su enlace. */
export async function subirFotoBorrador(blob) {
  const tipo = blob.type || 'image/jpeg'
  const ext = tipo.includes('png') ? 'png' : tipo.includes('webp') ? 'webp' : 'jpg'
  const fd = new FormData()
  fd.append('foto', blob, blob.name || `borrador.${ext}`)
  fd.append('folder', 'borradores')
  const { data } = await api.post('/upload/foto', fd, { silencioso: true })
  return data.url
}
