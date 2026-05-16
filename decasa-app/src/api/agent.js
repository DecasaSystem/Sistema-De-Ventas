import api from './index.js'

export const chatWithAgent = (messages) => api.post('/agent/chat', { messages })
