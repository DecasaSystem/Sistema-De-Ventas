import api from './index'

export const getClientes = (params = {}) => api.get('/clientes', { params })
export const getCliente = (id) => api.get(`/clientes/${id}`)
export const getClienteOrdenes = (id, params = {}) => api.get(`/clientes/${id}/ordenes`, { params })
export const createCliente = (data) => api.post('/clientes', data)
export const updateCliente = (id, data) => api.put(`/clientes/${id}`, data)
export const eliminarCliente = (id) => api.delete(`/clientes/${id}`)

export async function exportarClientes({ tipo = '', search = '' } = {}) {
  const params = new URLSearchParams()
  if (tipo)   params.set('tipo', tipo)
  if (search) params.set('search', search)
  const res = await api.get(`/clientes/exportar?${params}`, { responseType: 'blob' })
  const url = window.URL.createObjectURL(new Blob([res.data]))
  const a   = document.createElement('a')
  const label = tipo === 'oficial' ? 'oficiales' : tipo === 'interesado' ? 'interesados' : 'todos'
  a.href     = url
  a.download = `clientes_${label}_${new Date().toISOString().slice(0, 10)}.xlsx`
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}

export const CATEGORIAS_DISPONIBLES = [
  'Sofá',
  'Sofá modular',
  'Silla comedor',
  'Silla auxiliar',
  'Comedor',
  'Sofá cama',
  'Silla de barra',
  'Escritorio',
  'Mesas auxiliares',
  'Camas o colchones',
]
