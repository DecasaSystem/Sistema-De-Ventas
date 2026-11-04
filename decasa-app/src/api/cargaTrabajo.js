import api from './index'

// Cuánto trabajo hay y cuánto viene: órdenes por entregar, piezas del taller
// y la cola de cada paso. Solo lee.
export const getCargaTrabajo = (params = {}, opciones = {}) =>
  api.get('/carga-trabajo', { params, ...opciones })
