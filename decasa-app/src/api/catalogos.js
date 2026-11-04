import api from '@/api'
import { comprimirImagen } from '@/utils/comprimirImagen'

// ── Catálogos visuales — administración (solo supervisor) ─────────────────────
export const listarCatalogos   = ()            => api.get('/catalogos-visuales')
export const verCatalogo        = (id)          => api.get(`/catalogos-visuales/${id}`)
export const crearCatalogo      = (payload)     => api.post('/catalogos-visuales', payload)
export const actualizarCatalogo = (id, payload) => api.patch(`/catalogos-visuales/${id}`, payload)
export const eliminarCatalogo   = (id)          => api.delete(`/catalogos-visuales/${id}`)

export const agregarPaginas   = (id, imagenes) => api.post(`/catalogos-visuales/${id}/paginas`, { imagenes })
export const reordenarPaginas = (id, orden)    => api.patch(`/catalogos-visuales/${id}/paginas/orden`, { orden })
// cambios: { nombre } y/o { nota }; solo se toca lo que se manda.
export const actualizarPagina = (id, pid, cambios) => api.patch(`/catalogos-visuales/${id}/paginas/${pid}`, cambios)
export const eliminarPagina   = (id, pid)      => api.delete(`/catalogos-visuales/${id}/paginas/${pid}`)

// Sube un archivo de imagen a Cloudinary y devuelve su URL segura.
//
// Las páginas de un catálogo suelen venir de diseño, pesadas, y el servidor
// no recibe más de 10 MB. Es lo que ve el cliente: se sube el original tal cual
// siempre que quepa. Antes toda hoja de más de 1 MB se pasaba a JPEG al 88 % y
// a 2.400 px, y Cloudinary la volvía a comprimir al mostrarla: dos pérdidas, y
// la letra pequeña y los bordes quedaban sucios. Solo si pesa más de lo que
// recibe el servidor se comprime, alta, y sin agrandarla al leerla (eso la
// suavizaba en las hojas verticales).
export async function subirImagenCatalogo(file) {
  const liviana = await comprimirImagen(file, {
    maxDim: 3200, quality: 0.92, skipBytes: 9_500_000, reducirAlLeer: false,
  })
  const fd = new FormData()
  fd.append('foto', liviana, liviana === file ? file.name : 'pagina.jpg')
  fd.append('folder', 'catalogos')
  const { data } = await api.post('/upload/foto', fd)
  return data.url
}

// ── Público (sin sesión) ─────────────────────────────────────────────────────
export const portadaCatalogos = ()     => api.get('/c')
export const catalogoPublico   = (slug) => api.get(`/c/${encodeURIComponent(slug)}`)
