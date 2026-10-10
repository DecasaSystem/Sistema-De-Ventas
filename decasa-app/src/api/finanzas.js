import api from '@/api'
import { comprimirImagen } from '@/utils/comprimirImagen'

// Finanzas: lo que entra y lo que sale de la empresa. Solo supervisores con
// acceso_finanzas (el backend lo valida).

export const getResumen          = (mes) => api.get('/finanzas/resumen', { params: { mes } })
export const getEstadoResultados = (desde, hasta) => api.get('/finanzas/estado-resultados', { params: { desde, hasta } })
export const getFlujoCaja        = (desde, hasta) => api.get('/finanzas/flujo-caja', { params: { desde, hasta } })
export const getCalendario       = (dias = 45) => api.get('/finanzas/calendario', { params: { dias } })
export const getProyeccion       = (meses = 3) => api.get('/finanzas/proyeccion', { params: { meses } })
export const getPorTienda        = (mes) => api.get('/finanzas/por-tienda', { params: { mes } })
export const getAjustes          = () => api.get('/finanzas/ajustes')
export const guardarAjustes      = (payload) => api.put('/finanzas/ajustes', payload)
// El Excel se baja como archivo, no como JSON.
export const exportarResultados  = (desde, hasta) => api.get('/finanzas/exportar', { params: { desde, hasta }, responseType: 'blob' })

export const getCategorias       = (incluirInactivas = false) =>
  api.get('/finanzas/categorias', { params: incluirInactivas ? { incluir_inactivas: 1 } : {} })
export const crearCategoria      = (payload) => api.post('/finanzas/categorias', payload)
export const editarCategoria     = (id, payload) => api.patch(`/finanzas/categorias/${id}`, payload)
export const getPresupuesto      = (mes) => api.get('/finanzas/presupuesto', { params: { mes } })
export const guardarPresupuesto  = (payload) => api.put('/finanzas/presupuesto', payload)

export const getRecurrentes      = (incluirInactivos = false) =>
  api.get('/finanzas/recurrentes', { params: incluirInactivos ? { incluir_inactivos: 1 } : {} })
export const crearRecurrente     = (payload) => api.post('/finanzas/recurrentes', payload)
export const editarRecurrente    = (id, payload) => api.patch(`/finanzas/recurrentes/${id}`, payload)

export const getGastos           = (params) => api.get('/finanzas/gastos', { params })
export const getGastosPendientes = (dias = 45) => api.get('/finanzas/gastos/pendientes', { params: { dias } })
export const crearGasto          = (payload) => api.post('/finanzas/gastos', payload)
export const omitirPeriodo       = (payload) => api.post('/finanzas/gastos/omitir', payload)
export const editarGasto         = (id, payload) => api.patch(`/finanzas/gastos/${id}`, payload)
export const anularGasto         = (id, motivo) => api.post(`/finanzas/gastos/${id}/anular`, { motivo })

/** Sube la foto de un recibo a Cloudinary (carpeta `gastos`) y devuelve la URL. */
export async function subirRecibo(file) {
  const liviana = await comprimirImagen(file)
  const fd = new FormData()
  fd.append('foto', liviana, liviana === file ? file.name : 'recibo.jpg')
  fd.append('folder', 'gastos')
  const { data } = await api.post('/upload/foto', fd)
  return data.url
}

// Rentabilidad por canal (ventas contra la publicidad de cada canal).
export const getPorCanal         = (mes) => api.get('/finanzas/por-canal', { params: { mes } })

// Cierre de mes: congela un mes ya revisado.
export const getCierres          = () => api.get('/finanzas/cierres')
export const cerrarMes           = (mes) => api.post('/finanzas/cierres', { mes })
export const reabrirMes          = (mes, motivo) => api.post(`/finanzas/cierres/${mes}/reabrir`, { motivo })

// Facturas de proveedores a crédito.
export const getCuentasPorPagar  = (estado = 'pendiente') => api.get('/finanzas/cuentas-por-pagar', { params: { estado } })
export const crearCuentaPorPagar = (payload) => api.post('/finanzas/cuentas-por-pagar', payload)
export const pagarCuentaPorPagar = (id, payload) => api.post(`/finanzas/cuentas-por-pagar/${id}/pagar`, payload)
export const anularCuentaPorPagar = (id, motivo) => api.post(`/finanzas/cuentas-por-pagar/${id}/anular`, { motivo })
