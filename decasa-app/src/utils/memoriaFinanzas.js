// Lo último que se cargó en cada pantalla de Finanzas, para pintarlo al
// instante al volver (cambiar de pestaña o de mes y regresar) mientras llega
// lo nuevo por detrás. Calcular el resumen tarda varios segundos en el
// servidor y antes se veía el spinner cada vez (2026-10-13).
//
// Solo en memoria (se pierde al recargar): son las cifras más sensibles del
// sistema y no deben quedar guardadas en el aparato. La llave lleva el usuario
// para que, si cambian de perfil en el mismo celular, nadie vea lo de otro.
import { useAuthStore } from '@/stores/auth'

const memoria = new Map()
const MAX = 30

function llave(nombre) {
  const auth = useAuthStore()
  return `${auth.usuario?.id ?? 0}|${nombre}`
}

export function recordado(nombre) {
  return memoria.get(llave(nombre)) ?? null
}

export function recordar(nombre, valor) {
  const k = llave(nombre)
  memoria.delete(k)
  memoria.set(k, valor)
  // Se queda con los más recientes.
  while (memoria.size > MAX) memoria.delete(memoria.keys().next().value)
}
