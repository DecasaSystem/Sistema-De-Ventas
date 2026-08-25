import api from './index'

// Supervisor
export const crearSurtido        = (data)    => api.post('/inventario/surtir', data)
export const getSurtidos         = (params)  => api.get('/inventario/surtidos', { params })
export const getSurtido          = (id)      => api.get(`/inventario/surtidos/${id}`)
// La remisión: la hoja que viaja con la mercancía y se firma al descargarla.
// Con `surtidoTiendaId` sale solo la de esa tienda, que es lo que se imprime
// cuando el envío se reparte entre varias.
export const descargarPdfSurtido = (id, surtidoTiendaId = null) =>
  api.get(`/inventario/surtidos/${id}/pdf`, {
    params: surtidoTiendaId ? { tienda: surtidoTiendaId } : {},
    responseType: 'blob',
  })
export const getVendedoresTienda = (tiendaId) => api.get(`/inventario/vendedores-tienda/${tiendaId}`)
export const getRecomendaciones  = (params)  => api.get('/inventario/recomendaciones', { params })

// Vendedor
export const getSurtidosPendientes = ()         => api.get('/inventario/surtidos/pendientes')
export const aceptarSurtido        = (stId, payload = {}) => api.patch(`/inventario/surtido-tiendas/${stId}/aceptar`, payload)
export const rechazarSurtido       = (stId, notas) =>
  api.patch(`/inventario/surtido-tiendas/${stId}/rechazar`, { notas_vendedor: notas })
