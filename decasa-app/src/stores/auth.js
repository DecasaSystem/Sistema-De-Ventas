import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/api'
import { login as apiLogin, loginGoogle as apiLoginGoogle, logout as apiLogout } from '@/api/auth'

// 'perfilesAlt' guarda las sesiones de los otros perfiles para que no se
// pierdan cuando la sesion se cae sola (un 401, un token vencido). Al cerrar
// sesion a proposito se borra: dejar la sesion de otra cuenta guardada en un
// aparato del que alguien acaba de salir es dejarle la puerta abierta al
// siguiente. (Antes era 'perfilAlt', con una sola sesion; se migra al leer.)
const KEY_PERFIL_ALT = 'perfilesAlt'
const KEY_PERFIL_ALT_VIEJA = 'perfilAlt'

// Cuantas personas pueden turnarse una misma sesion, contando la principal.
// El mismo tope que el backend (Usuario::MAX_PERFILES).
export const MAX_PERFILES = 4

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

  // Guarda los perfiles alternativos junto al ID del principal al que
  // pertenecen. El perfil principal siempre está en índice 0; los
  // alternativos, en el orden en que se agregaron, del 1 en adelante.
  function _persistirAlt() {
    const principal = _perfiles.value[0]
    const alts      = _perfiles.value.slice(1).filter(p => p?.token && p?.usuario?.id)
    if (alts.length && principal?.usuario?.id) {
      localStorage.setItem(KEY_PERFIL_ALT, JSON.stringify({
        mainUserId: principal.usuario.id,   // ← quién activó estos perfiles
        perfiles:   alts.map(p => ({ token: p.token, usuario: p.usuario })),
      }))
    }
  }

  // Restaura los perfiles alternativos SOLO si quien inicia sesión es el
  // mismo usuario principal que los configuró originalmente.
  function _recuperarAlt(mainUserId) {
    try {
      let saved = JSON.parse(localStorage.getItem(KEY_PERFIL_ALT) ?? 'null')
      // Lo guardado por la version de un solo perfil alternativo.
      if (!saved) {
        const viejo = JSON.parse(localStorage.getItem(KEY_PERFIL_ALT_VIEJA) ?? 'null')
        if (viejo?.token && viejo?.usuario) saved = { mainUserId: viejo.mainUserId, perfiles: [viejo] }
        localStorage.removeItem(KEY_PERFIL_ALT_VIEJA)
      }
      if (saved?.mainUserId !== mainUserId) return []
      const vistos = new Set([mainUserId])
      return (saved.perfiles ?? [])
        .filter(p => p?.token && p?.usuario?.id && !vistos.has(p.usuario.id) && vistos.add(p.usuario.id))
        .slice(0, MAX_PERFILES - 1)
        .map(p => ({ token: p.token, usuario: p.usuario }))
    } catch {}
    return []
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
      acceso_entregas:    data.acceso_entregas    ?? false,
      acceso_produccion:  data.acceso_produccion  ?? false,
      acceso_reserva:     data.acceso_reserva     ?? false,
      acceso_nomina:      data.acceso_nomina      ?? false,
      acceso_compras:     data.acceso_compras     ?? false,
      acceso_encargos:    data.acceso_encargos    ?? false,
      revisa_encargos:    data.revisa_encargos    ?? false,
      lleva_encargos:     data.lleva_encargos     ?? false,
      ve_todas_ordenes:   data.ve_todas_ordenes   ?? false,
      puede_fv2_sin_iva:  data.puede_fv2_sin_iva  ?? false,
      tiene_pasos_produccion: data.tiene_pasos_produccion ?? false,
      tienda_default_id: data.tienda_default_id ?? null,
      // Con quiénes alterna según la cuenta (hasta tres). El backend viejo
      // mandaba uno solo en `perfil_alterno`; se acepta por si queda cacheado.
      perfiles_alternos: data.perfiles_alternos ?? (data.perfil_alterno ? [data.perfil_alterno] : []),
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
  // La misma regla que el backend (Usuario::soloVeSusOrdenes): solo ve lo
  // suyo y lo que le compartieron, no la operación entera.
  const esVendedorLimitado = computed(() =>
    usuario.value?.rol === 'vendedor' && ! usuario.value?.ve_todas_ordenes
  )
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
  // Entrega directa: entregar su propia orden sin conductor. Medida temporal
  // mientras los conductores no usan el programa. Por trabajador; los
  // supervisores la traen encendida desde la migración.
  const puedeEntregar         = computed(() => !!usuario.value?.acceso_entregas)
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

  // ── Multiperfil (hasta MAX_PERFILES personas turnándose la sesión) ──────
  const tienePerfilAlternativo = computed(() => _perfiles.value.length > 1)
  const puedeAgregarPerfil     = computed(() => _perfiles.value.length < MAX_PERFILES)
  /** Todos los perfiles de este aparato, en orden: el principal de primero. */
  const perfiles = computed(() => _perfiles.value.map((p, idx) => ({
    idx, usuario: p.usuario, activo: idx === _perfilActivo.value, principal: idx === 0,
  })))
  /**
   * Con quienes alterna segun la CUENTA, aunque en este aparato no esten
   * activos. La sesion del otro perfil no se puede sincronizar —es una
   * contrasena ajena—, pero saber quien es sirve para no tener que acordarse.
   * Se anotan en la cuenta PRINCIPAL (la que entró primero): es la que
   * arma el grupo.
   */
  const perfilesRecordados = computed(() => _perfiles.value[0]?.usuario?.perfiles_alternos ?? [])
  /** Los recordados que en este aparato todavía no tienen sesión. */
  const perfilesPorActivar = computed(() => {
    const aqui = new Set(_perfiles.value.map(p => p?.usuario?.id))
    return perfilesRecordados.value.filter(r => r?.id && !aqui.has(r.id))
  })
  /** El siguiente al que se pasa con el chip de arriba (en rueda). */
  const perfilAlternativo = computed(() => {
    if (_perfiles.value.length < 2) return null
    return _perfiles.value[(_perfilActivo.value + 1) % _perfiles.value.length]?.usuario ?? null
  })
  const perfilActivoIdx = computed(() => _perfilActivo.value)

  // ── Acciones de sesión ────────────────────────────────────────────────────
  async function login(email, password) {
    _abrirSesion(await apiLogin(email, password))
  }

  /** Entrar con Google. Del lado de acá la sesión que llega es la misma. */
  async function loginConGoogle(credential) {
    _abrirSesion(await apiLoginGoogle(credential))
  }

  function _abrirSesion({ data }) {
    const u = _buildUsuario(data)

    // Restaurar los perfiles alternativos si sobrevivieron al logout/401
    _perfiles.value     = [{ token: data.token, usuario: u }, ..._recuperarAlt(data.id)]
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

  // La barra de abajo que eligió: nombres de ruta en orden, o null.
  function setNavFavoritos(lista) {
    if (!usuario.value) return
    usuario.value = { ...usuario.value, nav_favoritos: lista }
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

  /**
   * Cerrar sesion a proposito: no queda nada del aparato.
   *
   * Antes tambien aca se conservaba el token del perfil alternativo, asi que
   * despues de "cerrar sesion" seguia guardada la sesion de la OTRA cuenta —en
   * un computador compartido eso es dejarle la puerta abierta al siguiente—.
   * Ese respaldo tiene sentido cuando la sesion se cae sola (un 401, el token
   * vencido), no cuando alguien decide salir.
   */
  async function logout() {
    try { await apiLogout() } catch {}

    // Se cierran tambien las sesiones de los otros perfiles en el servidor:
    // si no, esos tokens siguen siendo validos aunque se borren del aparato.
    await Promise.all(
      _perfiles.value
        .filter((p, i) => i !== _perfilActivo.value && p?.token)
        .map(p => _revocarToken(p.token))
    )

    clearSession({ conservarAlterno: false })
    localStorage.removeItem(KEY_PERFIL_ALT)
  }

  /** Cierra en el servidor la sesion de OTRO perfil (con su propio token). */
  async function _revocarToken(tokenAjeno) {
    try {
      await fetch('/api/auth/logout', {
        method: 'POST',
        headers: { Authorization: `Bearer ${tokenAjeno}` },
      })
    } catch {}
  }

  function clearSession({ conservarAlterno = true } = {}) {
    // Al caerse la sesion sola se conserva, para no perder el doble perfil por
    // un token vencido. Al salir a proposito, no.
    if (conservarAlterno) _persistirAlt()

    _perfiles.value     = []
    _perfilActivo.value = 0
    token.value         = null
    usuario.value       = null
    localStorage.removeItem('token')
    localStorage.removeItem('usuario')
    localStorage.removeItem('perfiles')
    localStorage.removeItem('perfilActivo')
    // KEY_PERFIL_ALT lo decide quien llama: se conserva si la sesion se
    // cayo sola, y se borra si se cerro a proposito (ver logout).
  }

  // ── Acciones de multiperfil ───────────────────────────────────────────────

  /**
   * Deja anotado en la CUENTA PRINCIPAL con quiénes alterna, para que al
   * entrar desde otro aparato ya sepa quiénes son y solo pida contraseñas.
   *
   * Va con el token del principal a propósito, no con el de quien esté
   * activo: si quien agrega el tercer perfil está parado en el segundo, la
   * lista igual es del grupo que armó el primero.
   */
  async function _guardarRecordados({ quitar = null } = {}) {
    const principal = _perfiles.value[0]
    if (!principal?.token) return

    // La lista de la cuenta es la unión: los que están en este aparato y los
    // que ya recordaba de otros. Si aquí se quita a alguien, se quita de la
    // cuenta; pero quien solo está activo en el celular no se borra por
    // tocar la lista desde el PC. Los del aparato van primero, y si se pasa
    // del tope se caen los recordados más viejos.
    const enAparato  = _perfiles.value.slice(1)
      .map(p => ({ id: p.usuario.id, nombre: p.usuario.nombre, email: p.usuario.email ?? null }))
    const idsAqui    = new Set(enAparato.map(p => p.id))
    const recordados = (principal.usuario?.perfiles_alternos ?? []).filter(r => r?.id && !idsAqui.has(r.id))
    const lista = [...enAparato, ...recordados]
      .filter(r => r.id !== quitar)
      .slice(0, MAX_PERFILES - 1)

    try {
      await fetch('/api/auth/mis-perfiles-alternos', {
        method: 'PATCH',
        headers: { Authorization: `Bearer ${principal.token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ usuario_ids: lista.map(r => r.id) }),
      })
    } catch {}
    // Lo que la cuenta recuerda, actualizado aquí sin esperar otro /auth/me.
    principal.usuario = { ...principal.usuario, perfiles_alternos: lista }
    if (_perfilActivo.value === 0) usuario.value = principal.usuario
    _syncStorage()
  }

  async function loginPerfilAlternativo(email, password) {
    if (!puedeAgregarPerfil.value) {
      throw new Error(`Máximo ${MAX_PERFILES} perfiles en un mismo equipo.`)
    }
    const { data } = await apiLogin(email, password)
    if (_perfiles.value.some(p => p?.usuario?.id === data.id)) {
      throw new Error('Este usuario ya está entre los perfiles.')
    }
    const u = _buildUsuario(data)
    _perfiles.value = [..._perfiles.value, { token: data.token, usuario: u }]
    _syncStorage()
    // Guardar también en clave persistente
    _persistirAlt()
    _guardarRecordados()
    return u
  }

  /** Pasa a otro perfil: al índice dado, o al siguiente en la rueda. */
  function cambiarPerfil(idx = null) {
    if (!tienePerfilAlternativo.value) return
    const nuevoIdx = idx === null ? (_perfilActivo.value + 1) % _perfiles.value.length : idx
    if (nuevoIdx === _perfilActivo.value || !_perfiles.value[nuevoIdx]) return
    _activarPerfil(nuevoIdx)
    // Recargar la página para que todas las vistas re-fetchen datos con el
    // nuevo perfil. Sin esto, refs inicializados en onMounted (tiendaId, etc.)
    // y datos cargados al montar quedan con el contexto del perfil anterior.
    window.location.reload()
  }

  /**
   * Quita un perfil alternativo (nunca el principal, que es la sesión).
   * Se cierra su sesión en el servidor: el token no debe seguir vivo en un
   * aparato del que ya se lo sacó.
   */
  function eliminarPerfilAlternativo(idx) {
    if (!idx || !_perfiles.value[idx]) return
    const quitado = _perfiles.value[idx]
    const estabaActivo = _perfilActivo.value === idx
    _perfiles.value = _perfiles.value.filter((_, i) => i !== idx)
    if (estabaActivo) {
      _activarPerfil(0)
    } else if (_perfilActivo.value > idx) {
      // El activo corrió un puesto hacia arriba.
      _activarPerfil(_perfilActivo.value - 1)
    }
    _syncStorage()
    if (_perfiles.value.length > 1) _persistirAlt()
    else localStorage.removeItem(KEY_PERFIL_ALT)
    if (quitado?.token) _revocarToken(quitado.token)
    _guardarRecordados({ quitar: quitado?.usuario?.id ?? null })
    // Si el que se quitó era el que estaba en pantalla, todo lo cargado es
    // suyo: se recarga igual que al cambiar.
    if (estabaActivo) window.location.reload()
  }

  return {
    token, usuario,
    isAuthenticated, isSupervisor,
    isIndependiente, llevaCajaPropia,
    tieneAccesoPasos,
    isFacturador, esVendedorLimitado, tieneAccesoRedes, tieneAccesoComisiones, puedeRecargarTelas, puedeUsarTelas, puedeSurtir,
    puedeCostos, puedeProveedores, puedeDespacho, puedeEntregar, puedeProduccion, gestionaProduccion, puedeReserva, puedeNomina, puedeCompras,
    puedeEncargos, revisaEncargos, llevaEncargos, veTodasOrdenes, soloVeSusOrdenes,
    tienePerfilAlternativo, puedeAgregarPerfil, perfiles, perfilesRecordados, perfilesPorActivar, perfilAlternativo, perfilActivoIdx,
    login, loginConGoogle, fetchMe, setFirma, setNavFavoritos, setEmail, logout, clearSession,
    loginPerfilAlternativo, cambiarPerfil, eliminarPerfilAlternativo,
  }
})
