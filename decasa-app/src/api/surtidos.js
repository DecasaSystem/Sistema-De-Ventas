import api from './index'

// Supervisor
export const crearSurtido        = (data)    => api.post('/inventario/surtir', data)
export const getSurtidos         = (params)  => api.get('/inventario/surtidos', { params })
export const getSurtido          = (id)      => api.get(`/inventario/surtidos/${id}`)
export const getVendedoresTienda = (tiendaId) => api.get(`/inventario/vendedores-tienda/${tiendaId}`)
export const getRecomendaciones  = (params)  => api.get('/inventario/recomendaciones', { params })

// Vendedor
export const getSurtidosPendientes = ()         => api.get('/inventario/surtidos/pendientes')
export const aceptarSurtido        = (stId)     => api.patch(`/inventario/surtido-tiendas/${stId}/aceptar`)
export const rechazarSurtido       = (stId, notas) =>
  api.patch(`/inventario/surtido-tiendas/${stId}/rechazar`, { notas_vendedor: notas })
