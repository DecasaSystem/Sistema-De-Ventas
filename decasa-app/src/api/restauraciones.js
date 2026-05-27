import api from './index'

export const getRestauraciones = (params = {}) => api.get('/restauraciones', { params })
export const createRestauracion = (data) => api.post('/restauraciones', data)
