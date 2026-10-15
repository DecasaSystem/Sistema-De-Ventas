import api from './index'

// Textos de documentos editables desde Gestión → Documentos (anexo de
// garantías, orden de entrega). Ver PlantillaDocumentoController.
export const getPlantillas     = ()                       => api.get('/plantillas-documento')
export const getPlantilla      = (clave)                  => api.get(`/plantillas-documento/${clave}`)
// Guardar crea una versión nueva; si no cambió nada, no.
export const guardarPlantilla  = (clave, contenido, nota) => api.put(`/plantillas-documento/${clave}`, { contenido, nota: nota || null })
