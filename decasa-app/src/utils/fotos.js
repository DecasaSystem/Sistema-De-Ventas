/**
 * Descargar y compartir fotos del sistema como JPG normal.
 *
 * Por qué existe: las fotos se muestran con `cloudinaryOpt` (f_auto), y Cloudinary
 * le entrega al navegador WebP o AVIF. Al guardarlas desde el celular quedaban en
 * esos formatos "raros" que WhatsApp, la galería o el computador no siempre abren.
 * Aquí se pide a Cloudinary el original en JPG (f_jpg) y, si la foto no es de
 * Cloudinary y no es JPG/PNG, se convierte en el navegador.
 */

// Una transformación de Cloudinary es un tramo tipo "w_800,c_limit" o "f_auto,q_auto".
const TRAMO_TRANSFORMACION = /^[a-z]{1,3}_[^/]+$/

/** Quita las transformaciones de un URL de Cloudinary y deja el original. */
export function urlOriginal(url) {
  if (!url || !url.includes('res.cloudinary.com') || !url.includes('/upload/')) return url
  const [antes, despues] = url.split('/upload/')
  const tramos = despues.split('/')
  while (tramos.length > 1 && TRAMO_TRANSFORMACION.test(tramos[0]) && !/^v\d+$/.test(tramos[0])) tramos.shift()
  return `${antes}/upload/${tramos.join('/')}`
}

/** El original de Cloudinary convertido a JPG de buena calidad. */
function urlJpg(url) {
  const original = urlOriginal(url)
  if (!original?.includes('res.cloudinary.com') || !original.includes('/image/upload/')) return original
  return original.replace('/upload/', '/upload/f_jpg,q_90/')
}

/** ¿El enlace apunta a una foto (y no a un PDF u otro archivo)? */
export function esUrlDeFoto(url) {
  if (!url) return false
  if (url.startsWith('data:image/')) return true
  const limpio = url.split('?')[0].split('#')[0].toLowerCase()
  if (/\.(pdf|xlsx?|docx?|zip|mp4|mov)$/.test(limpio)) return false
  if (limpio.includes('res.cloudinary.com') && limpio.includes('/image/upload/')) return true
  return /\.(jpe?g|png|webp|avif|gif|heic|heif)$/.test(limpio)
}

/** Nombre de archivo limpio: "Cama Bali 1.40" → "cama-bali-1-40.jpg". */
export function nombreDeArchivo(nombre, ext = 'jpg') {
  const base = (nombre || '')
    .toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60)
  if (base) return `${base}.${ext}`
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `decasa-foto-${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}.${ext}`
}

// Las fotos ya bajadas se recuerdan: compartir en el celular tiene que salir
// "en el mismo toque" (Safari cancela navigator.share si se demora la descarga).
const recordadas = new Map()

/** Baja la foto como Blob JPG (o PNG si ya lo era). Se puede llamar antes para tenerla lista. */
export function prepararFoto(url) {
  if (!url) return Promise.reject(new Error('Sin foto'))
  if (!recordadas.has(url)) {
    const promesa = bajarComoJpg(url).catch((e) => { recordadas.delete(url); throw e })
    recordadas.set(url, promesa)
    if (recordadas.size > 30) recordadas.delete(recordadas.keys().next().value)
  }
  return recordadas.get(url)
}

async function bajarComoJpg(url) {
  const resp = await fetch(urlJpg(url), { mode: 'cors' })
  if (!resp.ok) throw new Error(`No se pudo bajar la foto (${resp.status})`)
  const blob = await resp.blob()
  if (blob.type === 'image/jpeg' || blob.type === 'image/png') return blob
  return convertirAJpg(blob)
}

async function convertirAJpg(blob) {
  const img = await new Promise((resolve, reject) => {
    const i = new Image()
    const src = URL.createObjectURL(blob)
    i.onload = () => { URL.revokeObjectURL(src); resolve(i) }
    i.onerror = () => { URL.revokeObjectURL(src); reject(new Error('Formato de foto no soportado')) }
    i.src = src
  })
  const canvas = document.createElement('canvas')
  canvas.width = img.naturalWidth
  canvas.height = img.naturalHeight
  const ctx = canvas.getContext('2d')
  ctx.fillStyle = '#ffffff' // las transparencias quedan blancas, no negras
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.drawImage(img, 0, 0)
  return new Promise((resolve, reject) =>
    canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('No se pudo convertir'))), 'image/jpeg', 0.92))
}

function extensionDe(blob) {
  return blob.type === 'image/png' ? 'png' : 'jpg'
}

/** Guarda la foto en el aparato como archivo JPG con nombre legible. */
export async function descargarFoto(url, nombre) {
  try {
    const blob = await prepararFoto(url)
    const enlace = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = enlace
    a.download = nombreDeArchivo(nombre, extensionDe(blob))
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(enlace), 10_000)
  } catch {
    // Si el navegador no deja bajarla (sin internet, CORS), al menos se abre la original.
    window.open(urlJpg(url), '_blank', 'noopener')
  }
}

/**
 * Comparte la foto (WhatsApp, correo…) con la hoja de compartir del celular.
 * Devuelve 'compartida' | 'cancelada' | 'copiada' (en computador sin compartir: copia el link).
 */
export async function compartirFoto(url, nombre) {
  try {
    const blob = await prepararFoto(url)
    const archivo = new File([blob], nombreDeArchivo(nombre, extensionDe(blob)), { type: blob.type })
    if (navigator.canShare?.({ files: [archivo] })) {
      await navigator.share({ files: [archivo], title: nombre || 'Foto' })
      return 'compartida'
    }
  } catch (e) {
    if (e?.name === 'AbortError') return 'cancelada'
    // Si no se pudo compartir el archivo, se intenta con el link.
  }
  const link = urlJpg(url)
  if (navigator.share && !link.startsWith('data:')) {
    try {
      await navigator.share({ url: link, title: nombre || 'Foto' })
      return 'compartida'
    } catch (e) {
      if (e?.name === 'AbortError') return 'cancelada'
    }
  }
  await navigator.clipboard?.writeText(link)
  return 'copiada'
}
