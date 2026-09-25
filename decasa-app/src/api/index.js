import axios from 'axios'
import { iniciarPeticion, terminarPeticion } from '@/composables/useCargaGlobal'

const api = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json' },
})

// Las peticiones que corren solas (sondeos, refrescos por WebSocket) se marcan
// con `silencioso: true` para que no enciendan la barra: el usuario no pidió
// nada y verla parpadear cada 12 segundos desconcierta.
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  // Un archivo va como archivo. Con el 'application/json' de arriba, axios
  // convierte el FormData en JSON y la foto se pierde por el camino: el
  // servidor contesta 422 "falta la foto". Pasaba al subir páginas de un
  // catálogo y la foto de una pieza que vuelve al taller, que no ponían el
  // tipo a mano como las demás. Se pone el mismo que ellas ya usan y que
  // funciona: con él, axios deja que el navegador agregue el separador.
  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    config.headers['Content-Type'] = 'multipart/form-data'
  }
  if (!config.silencioso) {
    config.contadaEnBarra = true
    iniciarPeticion()
  }
  return config
}, (err) => {
  // Si el interceptor falla antes de salir, hay que soltar el contador o la
  // barra se queda encendida para siempre.
  if (err.config?.contadaEnBarra) terminarPeticion()
  return Promise.reject(err)
})

api.interceptors.response.use(
  (res) => {
    if (res.config?.contadaEnBarra) terminarPeticion()
    return res
  },
  (err) => {
    if (err.config?.contadaEnBarra) terminarPeticion()
    if (err.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('usuario')
      localStorage.removeItem('perfiles')
      localStorage.removeItem('perfilActivo')
      // 'perfilAlt' se mantiene para restaurar el doble perfil en el próximo login
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export default api
