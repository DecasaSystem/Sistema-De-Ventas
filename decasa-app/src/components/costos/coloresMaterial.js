/**
 * Colores para marcar materiales (usuario, 2026-11-10: "un color que represente cosas, por
 * ejemplo falta actualizar el precio"). Los colores son fijos (los mismos de
 * App\Support\ColoresMaterial); lo que significa cada uno se escribe en Costos → Materiales
 * y se trae una vez para todo el módulo.
 */
import { ref } from 'vue'
import api from '@/api'

export const COLORES = [
  { id: 'rojo',   nombre: 'Rojo',    punto: 'bg-red-500' },
  { id: 'ambar',  nombre: 'Ámbar',   punto: 'bg-amber-400' },
  { id: 'verde',  nombre: 'Verde',   punto: 'bg-green-500' },
  { id: 'azul',   nombre: 'Azul',    punto: 'bg-blue-500' },
  { id: 'morado', nombre: 'Morado',  punto: 'bg-purple-500' },
  { id: 'gris',   nombre: 'Gris',    punto: 'bg-gray-400' },
]

const leyenda  = ref({})
let cargada    = null

/** Trae qué significa cada color (una vez; con `forzar` vuelve a pedirlo). */
export function cargarLeyenda(forzar = false) {
  if (!cargada || forzar) {
    cargada = api.get('/materiales/colores')
      .then(({ data }) => { leyenda.value = data.colores ?? {} })
      .catch(() => { cargada = null })
  }
  return cargada
}

export async function guardarLeyenda(colores) {
  const { data } = await api.put('/materiales/colores', { colores })
  leyenda.value = data.colores ?? {}
  return leyenda.value
}

export function useColoresMaterial() {
  const color = id => COLORES.find(c => c.id === id) ?? null
  // "Falta actualizar el precio", o el nombre del color si no le han puesto significado
  const significado = id => (leyenda.value[id] || '').trim() || color(id)?.nombre || ''
  return { COLORES, leyenda, color, significado, cargarLeyenda, guardarLeyenda }
}
