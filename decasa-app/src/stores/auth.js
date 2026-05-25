import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/api'
import { login as apiLogin, logout as apiLogout } from '@/api/auth'

export const useAuthStore = defineStore('auth', () => {
  const token   = ref(localStorage.getItem('token') ?? null)
  const usuario = ref(JSON.parse(localStorage.getItem('usuario') ?? 'null'))

  const isAuthenticated  = computed(() => !!token.value)
  const isSupervisor     = computed(() => usuario.value?.rol === 'supervisor')
  const isEbanista       = computed(() => usuario.value?.rol === 'ebanista')
  const isTapicero       = computed(() => usuario.value?.rol === 'supervisor' && !!usuario.value?.es_tapicero)
  const isDespachador    = computed(() => usuario.value?.rol === 'despachador')
  const tieneAccesoPasos = computed(() => isEbanista.value || isTapicero.value)
  const isFacturador     = computed(() => usuario.value?.rol === 'vendedor' && !!usuario.value?.facturacion)

  async function login(email, password) {
    const { data } = await apiLogin(email, password)
    token.value = data.token
    usuario.value = {
      id:                data.id,
      nombre:            data.nombre,
      rol:               data.rol,
      es_tapicero:       data.es_tapicero ?? false,
      facturacion:       data.facturacion ?? false,
      tienda_default_id: data.tienda_default_id,
      firma_url:         data.firma_url ?? null,
    }
    localStorage.setItem('token',   data.token)
    localStorage.setItem('usuario', JSON.stringify(usuario.value))
  }

  // Refresca los datos del usuario desde el servidor (incluye firma_url e id)
  async function fetchMe() {
    if (!token.value) return
    try {
      const { data } = await api.get('/auth/me')
      usuario.value = {
        id:                data.id,
        nombre:            data.nombre,
        email:             data.email,
        rol:               data.rol,
        es_tapicero:       data.es_tapicero ?? false,
        facturacion:       data.facturacion ?? false,
        tienda_default_id: data.tienda_default_id,
        firma_url:         data.firma_url ?? null,
      }
      localStorage.setItem('usuario', JSON.stringify(usuario.value))
    } catch {}
  }

  function setFirma(url) {
    if (usuario.value) {
      usuario.value = { ...usuario.value, firma_url: url }
      localStorage.setItem('usuario', JSON.stringify(usuario.value))
    }
  }

  async function logout() {
    try { await apiLogout() } catch {}
    clearSession()
  }

  function clearSession() {
    token.value   = null
    usuario.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('usuario')
  }

  return { token, usuario, isAuthenticated, isSupervisor, isEbanista, isTapicero, isDespachador, tieneAccesoPasos, isFacturador, login, fetchMe, setFirma, logout, clearSession }
})
