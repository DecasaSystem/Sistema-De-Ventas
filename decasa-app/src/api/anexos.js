import api from './index'

// Anexo de garantías firmado en el sistema (ver AnexoGarantiaController).
export const crearAnexo        = (data)      => api.post('/anexos', data)
export const getAnexo          = (id)        => api.get(`/anexos/${id}`, { silencioso: true })
// El enlace que se le mandó a este cliente desde Nueva orden y sigue sin orden
// (esperando firma o recién firmado): para retomarlo si la página se recargó.
export const anexoEnCurso      = (clienteId) => api.get('/anexos/en-curso', { params: { cliente_id: clienteId }, silencioso: true })
// "Cancelar envío": el enlace deja de servir.
export const anularAnexo        = (id)        => api.post(`/anexos/${id}/anular`, {}, { silencioso: true })
export const enviarAnexoEmail  = (id, email) => api.post(`/anexos/${id}/enviar-email`, { email: email || undefined })
export const pdfAnexo          = (id)        => api.get(`/anexos/${id}/pdf`, { responseType: 'blob' })

// Página pública: sin sesión, la llave es el token del enlace.
export const getAnexoPublico   = (token)       => api.get(`/public/anexos/${token}`)
export const firmarAnexo       = (token, data) => api.post(`/public/anexos/${token}/firmar`, data)

// Mandarle a firmar una orden que ya existe y quedó sin firma (orden + anexo,
// una sola firma). `resumen` es lo que ve el cliente.
export const enviarOrdenAFirmar  = (ordenId, resumen) => api.post(`/ordenes/${ordenId}/firma-remota`, { resumen })
export const firmaRemotaDeOrden  = (ordenId)          => api.get(`/ordenes/${ordenId}/firma-remota`, { silencioso: true })

// Declaración de diseños especiales (va con el anexo, misma firma).
export const pdfDeclaracion     = (id)            => api.get(`/anexos/${id}/declaracion/pdf`, { responseType: 'blob' })
// El cliente le pide un cambio al asesor en vez de firmar.
export const pedirCambioAnexo   = (token, mensaje) => api.post(`/public/anexos/${token}/cambio`, { mensaje })
// Lo que el cliente firmó, descargado desde su enlace (doc: 'anexo' | 'declaracion').
export const pdfPublicoAnexo    = (token, doc)    => api.get(`/public/anexos/${token}/pdf/${doc}`, { responseType: 'blob' })
// La ficha del mueble especial de una orden ya creada.
export const guardarFichaEspecial = (itemId, ficha) => api.patch(`/orden-items/${itemId}/ficha-especial`, { ficha })
