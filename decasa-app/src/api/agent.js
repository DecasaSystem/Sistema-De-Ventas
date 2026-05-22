import api from './index.js'

// messages: array de { role, content, image? }
// image es un data URL base64 (solo en mensajes de usuario con foto/boceto)
export const chatWithAgent = (messages) => api.post('/agent/chat', { messages })
