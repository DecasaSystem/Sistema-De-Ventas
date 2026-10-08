// Lo que comparten la lista y la ficha de Clientes → Redes.

// Estados del seguimiento (ClienteRed::ESTADOS en el backend).
export const ESTADOS_RED = [
  { value: 'nuevo',      label: 'Nuevo',      plural: 'Nuevos',      chip: 'bg-blue-100 text-blue-700' },
  { value: 'contactado', label: 'Contactado', plural: 'Contactados', chip: 'bg-amber-100 text-amber-700' },
  { value: 'compro',     label: 'Compró',     plural: 'Compraron',   chip: 'bg-green-100 text-green-700' },
  { value: 'perdido',    label: 'Perdido',    plural: 'Perdidos',    chip: 'bg-gray-100 text-gray-500' },
]

export const TIPOS_AVISO = {
  asesor: 'Pidió asesor',
  pedido: 'Pedido',
  cita: 'Cita',
  personalizacion: 'A la medida',
  otro: 'Mensaje',
}

// Mismos colores que la bandeja de Redes.
export function canalBadge(canal) {
  return canal === 'instagram'
    ? { label: 'IG', class: 'bg-purple-100 text-purple-700' }
    : { label: 'WA', class: 'bg-green-100 text-green-700' }
}

export function haceCuanto(fecha) {
  if (!fecha) return ''
  const min = Math.round((Date.now() - new Date(fecha).getTime()) / 60000)
  if (min < 1) return 'ahora'
  if (min < 60) return `hace ${min} min`
  const h = Math.round(min / 60)
  if (h < 24) return `hace ${h} h`
  const d = Math.round(h / 24)
  if (d < 30) return `hace ${d} día${d === 1 ? '' : 's'}`
  return new Date(fecha).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', timeZone: 'America/Bogota' })
}

// Una línea de qué busca: lo que vio, o el aviso sin el título ni la línea de contacto.
export function interesCorto(f) {
  if (f.productos_interes?.length) return `Vio: ${f.productos_interes.slice(0, 3).join(', ')}`
  const lineas = String(f.ultimo_interes ?? '')
    .split('\n')
    .map(l => l.trim())
    .filter(l => l && !l.startsWith('📇') && !l.startsWith('Contacto:') && !/^(Solicitud de|Nuevo pedido|Nueva cita|Notificación)/i.test(l))
  return lineas.join(' · ').slice(0, 160)
}

// Para el botón de WhatsApp: solo dígitos, con el 57 delante.
export function numeroWhatsApp(telefono) {
  const d = String(telefono ?? '').replace(/\D/g, '')
  if (!d) return null
  return d.length === 10 ? `57${d}` : d
}
