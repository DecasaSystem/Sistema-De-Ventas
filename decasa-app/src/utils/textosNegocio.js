/**
 * Los textos de Gestión → Documentos que la app usa para hablarle al cliente:
 * los mensajes de WhatsApp, el nombre de la empresa, la declaración del acta
 * de satisfacción y los días que vale una cotización.
 *
 * Se piden una vez por sesión (GET /textos-del-negocio) y se guardan aquí. Si
 * la petición falla, se usan los de fábrica de abajo para que el vendedor
 * pueda mandar el mensaje igual. Los de fábrica son copia de
 * EsquemasPlantillas (decasa-api): si cambian allá, cambiarlos aquí.
 */
import { reactive } from 'vue'
import api from '@/api'

export const FABRICA = {
  empresa: {
    nombre: 'Decasa', nombre_completo: 'Decasa Muebles y Decoración',
    nit: '', direccion: '', telefono: '', correo: '', sitio_web: '',
  },
  mensajes: {
    firma_pedido: 'Hola {cliente}, soy {asesor} de {empresa}. Aquí puedes revisar tu pedido completo y firmarlo junto con el documento de garantías: {enlace}',
    firma_orden: 'Hola {cliente}, soy {asesor} de {empresa}. Aquí puedes revisar tu orden {referencia} completa y firmarla junto con el documento de garantías: {enlace}',
    resumen_saludo: 'Hola {cliente} 👋\n\nAquí tienes el resumen de tu pedido en *{empresa}* (Orden {referencia}):',
    resumen_despedida: 'Adjunto encontrarás la cotización en PDF con todos los detalles.\n¡Gracias por tu compra! 🛋️',
    saludo_redes: 'Hola {cliente}, soy {asesor} de {empresa_completo} 🛋️ Me da mucho gusto atenderte.',
  },
  acta: {
    titulo: 'ACTA DE SATISFACCIÓN',
    declaracion: 'Declaro que recibí los productos relacionados en esta acta, correspondientes a la orden {referencia}, en la fecha y dirección indicadas.',
    texto_parcial: 'Esta es una entrega parcial: lo que aparece como pendiente se entregará aparte.',
    texto_conforme: 'Manifiesto que llegaron en buen estado y a satisfacción.',
    texto_novedad: 'Dejo constancia de la siguiente novedad al momento de recibir.',
    firma_recibe: 'Recibe conforme',
  },
  cotizacion: { dias_vigencia: 15 },
}

const estado = reactive({
  ...JSON.parse(JSON.stringify(FABRICA)),
  cargado: false,
})
let pendiente = null

/** Pide los textos (una sola vez) y devuelve el objeto reactivo. */
export function cargarTextosNegocio({ otraVez = false } = {}) {
  if (otraVez) pendiente = null
  pendiente ??= api.get('/textos-del-negocio', { silencioso: true })
    .then(({ data }) => {
      for (const k of ['empresa', 'mensajes', 'acta', 'cotizacion']) {
        if (data?.[k]) estado[k] = { ...FABRICA[k], ...data[k] }
      }
      estado.cargado = true
    })
    .catch(() => { pendiente = null })   // se reintenta la próxima vez
  return pendiente.then(() => estado)
}

/** Los textos (reactivos). Empieza con los de fábrica y se llena al cargar. */
export function useTextosNegocio() {
  cargarTextosNegocio()
  return estado
}

/**
 * Pone los datos donde el texto dice {cliente}, {asesor}... Un dato vacío se
 * lleva el espacio de antes: "Hola {cliente}, soy" sin nombre queda
 * "Hola, soy". Lo que no viene en `datos` se deja como está. (El servidor hace
 * lo mismo en PlantillasDocumento::conDatos.)
 */
export function rellenar(texto, datos = {}) {
  let t = String(texto ?? '')
  for (const [k, v] of Object.entries(datos)) {
    const valor = v == null ? '' : String(v)
    t = valor === ''
      ? t.replace(new RegExp(` ?\\{${k}\\}`, 'g'), '')
      : t.split(`{${k}}`).join(valor)
  }
  return t
}

/** Los datos de la empresa que van en todos los mensajes. */
export function datosEmpresa(t = estado) {
  return { empresa: t.empresa.nombre, empresa_completo: t.empresa.nombre_completo }
}
