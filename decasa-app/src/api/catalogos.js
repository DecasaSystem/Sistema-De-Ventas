import api from '@/api'

// ── Catálogos visuales — administración (solo supervisor) ─────────────────────
export const listarCatalogos   = ()            => api.get('/catalogos-visuales')
export const verCatalogo        = (id)          => api.get(`/catalogos-visuales/${id}`)
export const crearCatalogo      = (payload)     => api.post('/catalogos-visuales', payload)
export const actualizarCatalogo = (id, payload) => api.patch(`/catalogos-visuales/${id}`, payload)
export const eliminarCatalogo   = (id)          => api.delete(`/catalogos-visuales/${id}`)

export const agregarPaginas   = (id, imagenes) => api.post(`/catalogos-visuales/${id}/paginas`, { imagenes })
export const reordenarPaginas = (id, orden)    => api.patch(`/catalogos-visuales/${id}/paginas/orden`, { orden })
export const actualizarPagina = (id, pid, nota) => api.patch(`/catalogos-visuales/${id}/paginas/${pid}`, { nota })
export const eliminarPagina   = (id, pid)      => api.delete(`/catalogos-visuales/${id}/paginas/${pid}`)

// Sube un archivo de imagen a Cloudinary y devuelve su URL segura.
export async function subirImagenCatalogo(file) {
  const fd = new FormData()
  fd.append('foto', file)
  fd.append('folder', 'catalogos')
  const { data } = await api.post('/upload/foto', fd)
  return data.url
}

// ── Público (sin sesión) ─────────────────────────────────────────────────────
export const portadaCatalogos = ()     => api.get('/c')
export const catalogoPublico   = (slug) => api.get(`/c/${encodeURIComponent(slug)}`)
