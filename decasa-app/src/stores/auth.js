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

  // Guarda el perfil alternativo junto al ID del principal al que pertenece.
  // El perfil principal siempre está en índice 0; el alternativo en índice 1.
  function _persistirAlt() {
    const principal = _perfiles.value[0]
    const alt       = _perfiles.value[1]
    if (alt?.token && alt?.usuario && principal?.usuario?.id) {
      localStorage.setItem(KEY_PERFIL_ALT, JSON.stringify({
        mainUserId: principal.usuario.id,   // ← quién activó este perfil alternativo
        token:      alt.token,
        usuario:    alt.usuario,
      }))
    }
  }

  // Restaura el perfil alternativo SOLO si quien inicia sesión es el mismo
  // usuario principal que lo configuró originalmente.
  function _recuperarAlt(mainUserId) {
    try {
      const saved = JSON.parse(localStorage.getItem(KEY_PERFIL_ALT) ?? 'null')
      if (
        saved?.mainUserId === mainUserId &&
        saved?.token &&
        saved?.usuario?.id &&
        saved.usuario.id !== mainUserId
      ) {
        return { token: saved.token, usuario: saved.usuario }
      }
    } catch {}
    return null
  }

  /**
   * Arma el usuario de la sesion.
   *
   * Se conserva lo que venga del backend y solo se le fijan valores por
   * defecto a lo que la app da por hecho. Antes se listaban los campos uno a
   * uno y lo que no estuviera en la lista se perdia en silencio: asi se cayo
   * 'independiente', y con el todo lo que depende de serlo.
   */
  function _buildUsuario(data) {
    return {
      ...data,
      id:                data.id,
      nombre:            data.nombre,
      email:             data.email ?? null,
      rol:               data.rol,
      facturacion:       data.facturacion       ?? false,
      acceso_redes:      data.acceso_redes      ?? false,
      acceso_comisiones: data.acceso_comisiones ?? false,
      recarga_telas:     data.recarga_telas     ?? false,
      acceso_telas:      data.acceso_telas      ?? false,
      acceso_surtir:     data.acceso_surtir     ?? false,
      acceso_costos:      data.acceso_costos      ?? false,
      acceso_proveedores: data.acceso_proveedores ?? false,
      acceso_despacho:    data.acceso_despacho    ?? false,
      acceso_produccion:  data.acceso_produccion  ?? false,
      acceso_reserva:     data.acceso_reserva     ?? false,
      acceso_nomina:      data.acceso_nomina      ?? false,
      acceso_compras:     data.acceso_compras     ?? false,
      acceso_encargos:    data.acceso_encargos    ?? false,
      revisa_encargos:    data.revisa_encargos    ?? false,
      lleva_encargos:     data.lleva_encargos     ?? false,
      ve_todas_ordenes:   data.ve_todas_ordenes   ?? false,
      tiene_pasos_produccion: data.tiene_pasos_produccion ?? false,
      tienda_default_id: data.tienda_default_id ?? null,
      perfil_alterno:    data.perfil_alterno    ?? null,
      firma_url:         data.firma_url         ?? null,
      independiente:     data.independiente     ?? false,
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

  // Llevar pasos del taller ya no depende del rol. Antes había que ponerle a
  // alguien el rol "Ebanista" —o la bandera de tapicero— solo para que le
  // llegaran sus pasos, aunque de verdad fuera un vendedor independiente o
  // una supervisora. Ahora el backend responde si tiene pasos asignados y
  // punto: quién es la persona y qué pasos lleva son dos cosas distintas.
  const tieneAccesoPasos   = computed(() => !!usuario.value?.tiene_pasos_produccion)

  const isFacturador       = computed(() => usuario.value?.rol === 'vendedor' && !!usuario.value?.facturacion)
  // Vende por su cuenta: no pertenece a ninguna tienda y lleva caja propia.
  const isIndependiente    = computed(() => !!usuario.value?.independiente)
  const llevaCajaPropia    = computed(() => isIndependiente.value)
  const tieneAccesoRedes      = computed(() => !!usuario.value?.acceso_redes)
  const tieneAccesoComisiones = computed(() => !!usuario.value?.acceso_comisiones)
  // Telas: dos permisos, ninguno atado a un oficio. Antes descontar dependía
  // de llamarse "Costurero", lo que obligaba a otra empresa a usar ese nombre.
  const puedeRecargarTelas    = computed(() => isSupervisor.value || !!usuario.value?.recarga_telas)
  const puedeUsarTelas        = computed(() => isSupervisor.value || !!usuario.value?.acceso_telas)
  // Ya no es del rol vendedor: es una bandera asignable, como redes o
  // comisiones. Los vendedores existentes la traen encendida desde la
  // migración que la creó, así que nadie perdió acceso el día del cambio.
  // Tampoco hay atajo por ser supervisor: ese respaldo también se dio en la
  // migración, pero de ahí en adelante es un permiso real por trabajador.
  const puedeSurtir           = computed(() => !!usuario.value?.acceso_surtir)
  // Costos: es una bandera por trabajador, para nadie automático.
  const puedeCostos           = computed(() => !!usuario.value?.acceso_costos)
  // Proveedores: ver la lista sigue abierto a todos; esto es solo para
  // crear/editar. Predeterminado para supervisor, activable para el resto.
  const puedeProveedores      = computed(() => isSupervisor.value || !!usuario.value?.acceso_proveedores)
  const puedeDespacho         = computed(() => !!usuario.value?.acceso_despacho)
  // Ver el taller y mandar en el taller son dos permisos. El backend ya manda
  // `ve_produccion` resuelto —cubre tener el permiso o llevar algún paso—, así
  // que la pantalla no tiene que volver a deducirlo.
  const puedeProduccion       = computed(() =>
    !!usuario.value?.ve_produccion || !!usuario.value?.acceso_produccion)
  const gestionaProduccion    = computed(() => !!usuario.value?.gestiona_produccion)
  const puedeReserva          = computed(() => !!usuario.value?.acceso_reserva)
  const puedeNomina           = computed(() => !!usuario.value?.acceso_nomina)
  // Sin excepción para supervisor a propósito: es una bandera activable
  // persona por persona para cualquier rol, no atada a ser supervisor.
  const puedeCompras          = computed(() => !!usuario.value?.acceso_compras)
  // Encargos, tres cosas distintas: mirar quién tiene qué (acceso_encargos),
  // hacer los checks y descontar (revisa_encargos, y a esos les llega el
  // aviso del día), y responder por lo propio (lleva_encargos), que entra
  // igual pero solo a ver su ficha.
  const puedeEncargos         = computed(() => !!usuario.value?.acceso_encargos)
  const revisaEncargos        = computed(() => !!usuario.value?.revisa_encargos)
  const llevaEncargos         = computed(() => !!usuario.value?.lleva_encargos)
  const veTodasOrdenes        = computed(() => !!usuario.value?.ve_todas_ordenes)
  // La cara opuesta, que es como se pregunta en las pantallas: un vendedor ve
  // lo suyo salvo que se le haya activado ver todas.
  const soloVeSusOrdenes      = computed(() =>
    usuario.value?.rol === 'vendedor' && !usuario.value?.ve_todas_ordenes)

  // Dual-profile getters
  const tienePerfilAlternativo = computed(() => _perfiles.value.length > 1)
  /**
   * Con quien alterna segun la CUENTA, aunque en este aparato no este activo.
   * La sesion del otro perfil no se puede sincronizar —es una contrasena
   * ajena—, pero saber quien es sirve para no tener que acordarse.
   */
  const perfilAlternoRecordado = computed(() => usuario.value?.perfil_alterno ?? null)
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
    // Y se anota en la CUENTA, no solo en este aparato: asi al entrar desde el
    // celular ya sabe con quien alterna y solo pide la contrasena.
    api.patch('/auth/mi-perfil-alterno', { usuario_id: u.id }).catch(() => {})
    return u
  }

  function cambiarPerfil() {
    if (!tienePerfilAlternativo.value) return
    const nuevoIdx = _perfilActivo.value === 0 ? 1 : 0
    _activarPerfil(nuevoIdx)
    // Recargar la página para que todas las vistas re-fetchen datos con el
    // nuevo perfil. Sin esto, refs inicializados en onMounted (tiendaId, etc.)
    // y datos cargados al montar quedan con el contexto del perfil anterior.
    window.location.reload()
  }

  function eliminarPerfilAlternativo() {
    api.patch('/auth/mi-perfil-alterno', { usuario_id: null }).catch(() => {})
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
    isAuthenticated, isSupervisor,
    isIndependiente, llevaCajaPropia,
    tieneAccesoPasos,
    isFacturador, tieneAccesoRedes, tieneAccesoComisiones, puedeRecargarTelas, puedeUsarTelas, puedeSurtir,
    puedeCostos, puedeProveedores, puedeDespacho, puedeProduccion, gestionaProduccion, puedeReserva, puedeNomina, puedeCompras,
    puedeEncargos, revisaEncargos, llevaEncargos, veTodasOrdenes, soloVeSusOrdenes,
    tienePerfilAlternativo, perfilAlternativo, perfilActivoIdx, perfilAlternoRecordado,
    login, fetchMe, setFirma, setEmail, logout, clearSession,
    loginPerfilAlternativo, cambiarPerfil, eliminarPerfilAlternativo,
  }
})
