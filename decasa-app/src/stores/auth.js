import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/api'
import { login as apiLogin, logout as apiLogout } from '@/api/auth'

// 'perfilAlt' es la clave que sobrevive logout y 401 para que el perfil
// alternativo no se pierda cuando el usuario cierra sesión y vuelve a entrar.
const KEY_PERFIL_ALT = 'perfilAlt'

export const useAuthStore = defineStore('auth', () => {

  // ── Migración de sesión antigua (single-profile) ─────────────────────────
  function migrarSiNecesario() {
    const oldToken   = localStorage.getItem('token')
    const oldUsuario = localStorage.getItem('usuario')
    if (oldToken && oldUsuario && !localStorage.getItem('perfiles')) {
      localStorage.setItem('perfiles',     JSON.stringify([{ token: oldToken, usuario: JSON.parse(oldUsuario) }]))
      localStorage.setItem('perfilActivo', '0')
    }
  }
  migrarSiNecesario()

  // ── Estado interno ────────────────────────────────────────────────────────
  const _perfiles      = ref(JSON.parse(localStorage.getItem('perfiles')     ?? '[]'))
  const _perfilActivo  = ref(parseInt(localStorage.getItem('perfilActivo')   ?? '0', 10))

  // Refs públicos siempre alineados con el perfil activo
  const token   = ref(_perfiles.value[_perfilActivo.value]?.token   ?? null)
  const usuario = ref(_perfiles.value[_perfilActivo.value]?.usuario ?? null)

  // ── Helpers ───────────────────────────────────────────────────────────────
  function _syncStorage() {
    if (token.value) {
      localStorage.setItem('token',   token.value)
      localStorage.setItem('usuario', JSON.stringify(usuario.value))
    } else {
      localStorage.removeItem('token')
      localStorage.removeItem('usuario')
    }
    localStorage.setItem('perfiles',     JSON.stringify(_perfiles.value))
    localStorage.setItem('perfilActivo', String(_perfilActivo.value))
  }

  // Guarda el perfil alternativo en clave persistente (sobrevive logout/401)
  function _persistirAlt() {
    const altIdx = _perfilActivo.value === 0 ? 1 : 0
    const alt = _perfiles.value[altIdx]
    if (alt?.token && alt?.usuario) {
      localStorage.setItem(KEY_PERFIL_ALT, JSON.stringify(alt))
    }
  }

  // Recupera el perfil alternativo guardado si es un usuario distinto
  function _recuperarAlt(mainUserId) {
    try {
      const saved = JSON.parse(localStorage.getItem(KEY_PERFIL_ALT) ?? 'null')
      if (saved?.token && saved?.usuario?.id && saved.usuario.id !== mainUserId) {
        return saved
      }
    } catch {}
    return null
  }

  function _buildUsuario(data) {
    return {
      id:                data.id,
      nombre:            data.nombre,
      email:             data.email ?? null,
      rol:               data.rol,
      es_tapicero:       data.es_tapicero       ?? false,
      facturacion:       data.facturacion       ?? false,
      acceso_redes:      data.acceso_redes      ?? false,
      recarga_telas:     data.recarga_telas     ?? false,
      tienda_default_id: data.tienda_default_id ?? null,
      firma_url:         data.firma_url         ?? null,
    }
  }

  function _activarPerfil(idx) {
    _perfilActivo.value = idx
    token.value         = _perfiles.value[idx]?.token   ?? null
    usuario.value       = _perfiles.value[idx]?.usuario ?? null
    _syncStorage()
  }

  // ── Getters ───────────────────────────────────────────────────────────────
  const isAuthenticated    = computed(() => !!token.value)
  const isSupervisor       = computed(() => usuario.value?.rol === 'supervisor')
  const isEbanista         = computed(() => usuario.value?.rol === 'ebanista')
  const isTapicero         = computed(() => usuario.value?.rol === 'supervisor' && !!usuario.value?.es_tapicero)
  const isDespachador      = computed(() => usuario.value?.rol === 'despachador')
  const isCosturero        = computed(() => usuario.value?.rol === 'costurero')
  const tieneAccesoPasos   = computed(() => isEbanista.value || isTapicero.value || isDespachador.value)
  const isFacturador       = computed(() => usuario.value?.rol === 'vendedor' && !!usuario.value?.facturacion)
  const tieneAccesoRedes   = computed(() => !!usuario.value?.acceso_redes)
  const puedeRecargarTelas = computed(() => isSupervisor.value || (!!usuario.value?.recarga_telas && ['vendedor', 'supervisor'].includes(usuario.value?.rol)))

  // Dual-profile getters
  const tienePerfilAlternativo = computed(() => _perfiles.value.length > 1)
  const perfilAlternativo      = computed(() => {
    const otroIdx = _perfilActivo.value === 0 ? 1 : 0
    return _perfiles.value[otroIdx]?.usuario ?? null
  })
  const perfilActivoIdx = computed(() => _perfilActivo.value)

  // ── Acciones de sesión ────────────────────────────────────────────────────
  async function login(email, password) {
    const { data } = await apiLogin(email, password)
    const u = _buildUsuario(data)

    // Restaurar perfil alternativo si sobrevivió al logout/401
    const alt = _recuperarAlt(data.id)
    _perfiles.value     = alt ? [{ token: data.token, usuario: u }, alt] : [{ token: data.token, usuario: u }]
    _perfilActivo.value = 0
    token.value         = data.token
    usuario.value       = u
    _syncStorage()
  }

  async function fetchMe() {
    if (!token.value) return
    try {
      const { data } = await api.get('/auth/me')
      const u = _buildUsuario(data)
      usuario.value = u
      if (_perfiles.value[_perfilActivo.value]) {
        _perfiles.value[_perfilActivo.value].usuario = u
      }
      _syncStorage()
    } catch {}
  }

  function setFirma(url) {
    if (!usuario.value) return
    usuario.value = { ...usuario.value, firma_url: url }
    if (_perfiles.value[_perfilActivo.value]) {
      _perfiles.value[_perfilActivo.value].usuario = usuario.value
    }
    _syncStorage()
  }

  function setEmail(email) {
    if (!usuario.value) return
    usuario.value = { ...usuario.value, email }
    if (_perfiles.value[_perfilActivo.value]) {
      _perfiles.value[_perfilActivo.value].usuario = usuario.value
    }
    _syncStorage()
  }

  async function logout() {
    try { await apiLogout() } catch {}
    clearSession()
  }

  function clearSession() {
    // Persistir el perfil alternativo ANTES de limpiar, para que sobreviva
    _persistirAlt()

    _perfiles.value     = []
    _perfilActivo.value = 0
    token.value         = null
    usuario.value       = null
    localStorage.removeItem('token')
    localStorage.removeItem('usuario')
    localStorage.removeItem('perfiles')
    localStorage.removeItem('perfilActivo')
    // KEY_PERFIL_ALT se mantiene intencionalmente
  }

  // ── Acciones de doble perfil ──────────────────────────────────────────────
  async function loginPerfilAlternativo(email, password) {
    const { data } = await apiLogin(email, password)
    if (data.id === usuario.value?.id) {
      throw new Error('Este usuario ya es el perfil activo.')
    }
    const u = _buildUsuario(data)
    const principal = _perfiles.value[0]
    _perfiles.value = [principal, { token: data.token, usuario: u }]
    _syncStorage()
    // Guardar también en clave persistente
    _persistirAlt()
    return u
  }

  function cambiarPerfil() {
    if (!tienePerfilAlternativo.value) return
    const nuevoIdx = _perfilActivo.value === 0 ? 1 : 0
    _activarPerfil(nuevoIdx)
  }

  function eliminarPerfilAlternativo() {
    if (_perfilActivo.value === 1) {
      _activarPerfil(0)
    }
    _perfiles.value = [_perfiles.value[0]]
    _syncStorage()
    // Eliminar también la clave persistente
    localStorage.removeItem(KEY_PERFIL_ALT)
  }

  return {
    token, usuario,
    isAuthenticated, isSupervisor, isEbanista, isTapicero, isDespachador, isCosturero,
    tieneAccesoPasos, isFacturador, tieneAccesoRedes, puedeRecargarTelas,
    tienePerfilAlternativo, perfilAlternativo, perfilActivoIdx,
    login, fetchMe, setFirma, setEmail, logout, clearSession,
    loginPerfilAlternativo, cambiarPerfil, eliminarPerfilAlternativo,
  }
})
