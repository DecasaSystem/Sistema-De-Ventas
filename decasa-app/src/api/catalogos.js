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
export const actualizarPagina = (id, pid, nota) => api.patch(`/catalogos-visuales/${id}/paginas/${pid}`, { nota })
export const eliminarPagina   = (id, pid)      => api.delete(`/catalogos-visuales/${id}/paginas/${pid}`)

// Sube un archivo de imagen a Cloudinary y devuelve su URL segura.
//
// Las páginas de un catálogo suelen venir de diseño, pesadas, y el servidor
// no recibe más de 10 MB. Se comprimen antes, con más resolución y calidad
// que una foto de factura: es lo que ve el cliente, y tiene que verse nítido
// al hacer zoom en el celular.
export async function subirImagenCatalogo(file) {
  const liviana = await comprimirImagen(file, { maxDim: 2400, quality: 0.88 })
  const fd = new FormData()
  fd.append('foto', liviana, liviana === file ? file.name : 'pagina.jpg')
  fd.append('folder', 'catalogos')
  const { data } = await api.post('/upload/foto', fd)
  return data.url
}

// ── Público (sin sesión) ─────────────────────────────────────────────────────
export const portadaCatalogos = ()     => api.get('/c')
export const catalogoPublico   = (slug) => api.get(`/c/${encodeURIComponent(slug)}`)
