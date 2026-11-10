import api from '@/api'

// Facturación electrónica con Alegra (docs/plan-facturacion-alegra.md).
const base = '/facturacion-electronica'

export const estadoFE      = () => api.get(`${base}/estado`)
export const pendientesFE  = (params = {}) => api.get(`${base}/pendientes`, { params })
export const facturasFE    = (params = {}) => api.get(`${base}/facturas`, { params })
export const detalleFE     = (ordenId, pagoId = null) =>
  api.get(`${base}/ordenes/${ordenId}`, { params: pagoId ? { pago_id: pagoId } : {} })
export const guardarClienteFE = (clienteId, datos) => api.put(`${base}/clientes/${clienteId}`, datos)
export const facturarFE    = (ordenId, datos) => api.post(`${base}/ordenes/${ordenId}/facturar`, datos)
export const externaFE     = (ordenId, datos) => api.post(`${base}/ordenes/${ordenId}/externa`, datos)
export const actualizarFE  = (facturaId) => api.post(`${base}/facturas/${facturaId}/actualizar`)
export const diferenciaResueltaFE = (facturaId, nota) =>
  api.post(`${base}/facturas/${facturaId}/diferencia-resuelta`, { nota })

export const reglasFE         = () => api.get(`${base}/reglas`)
export const guardarReglasFE  = (reglas) => api.put(`${base}/reglas`, { reglas })
export const catalogosAlegra  = () => api.get(`${base}/alegra/catalogos`)
export const itemsAlegra      = (buscar) => api.get(`${base}/alegra/items`, { params: { buscar } })
export const contactosAlegra  = (buscar) => api.get(`${base}/alegra/contactos`, { params: { buscar } })

/** Cómo se dice cada estado de una factura, y su color de chip. */
export const ESTADOS_FE = {
  enviando:        { label: 'Enviando…',          clase: 'bg-blue-100 text-blue-700' },
  emitida:         { label: 'Emitida',            clase: 'bg-green-100 text-green-700' },
  pendiente_dian:  { label: 'DIAN validando',     clase: 'bg-amber-100 text-amber-700' },
  borrador_alegra: { label: 'Borrador en Alegra', clase: 'bg-gray-100 text-gray-700' },
  rechazada:       { label: 'Rechazada',          clase: 'bg-red-100 text-red-700' },
  error:           { label: 'No salió',           clase: 'bg-red-100 text-red-700' },
  incierta:        { label: 'Sin confirmar',      clase: 'bg-amber-100 text-amber-700' },
  externa:         { label: 'Facturada por fuera', clase: 'bg-gray-100 text-gray-700' },
}
