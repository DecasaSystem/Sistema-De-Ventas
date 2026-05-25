import api from '@/api'

export const tomarFacturacion = (pagoId) =>
  api.post(`/pagos/${pagoId}/tomar-facturacion`)

export const marcarFacturada = (pagoId) =>
  api.post(`/pagos/${pagoId}/marcar-facturada`)
