import api from './index'

export const login = (email, password) =>
  api.post('/auth/login', { email, password })

// El ID token que devuelve el botón de Google. El servidor lo verifica contra
// Google y responde la sesión, igual que el login normal.
export const loginGoogle = (credential) =>
  api.post('/auth/google', { credential })

export const logout = () =>
  api.post('/auth/logout')

export const me = () =>
  api.get('/auth/me')
