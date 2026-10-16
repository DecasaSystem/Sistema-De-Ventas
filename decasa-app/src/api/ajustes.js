import api from './index'

// Interruptores de Gestión → Ajustes. Leerlos, cualquiera; cambiarlos, supervisor.
export const getAjustesSistema = (opciones = {}) =>
  api.get('/ajustes-sistema', opciones)

export const guardarAjustesSistema = (data) =>
  api.patch('/ajustes-sistema', data)
