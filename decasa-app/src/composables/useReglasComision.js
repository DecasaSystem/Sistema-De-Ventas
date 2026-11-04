/**
 * Las reglas de comisión que rigen hoy (o en un mes), para los textos de
 * pantallas que no son Comisiones: Reportes, Estadísticas, Nueva orden…
 *
 * Esas pantallas decían "50 % pagado", "dividir por 1,19" o "5 % aparte"
 * escrito a mano; con una regla cambiada en Comisiones → Reglas habrían dicho
 * otra cosa que la que se paga (2026-11-04). Arranca con las de siempre y se
 * cambia sola cuando llegan las del servidor (se piden una vez por mes).
 *
 *   const r = useReglasComision()
 *   … `sin ${partePagada(r.meta_req_pagado_pct)}` …
 */
import { ref } from 'vue'
import { cargarReglasVigentes, reglasPara, DEFECTO } from '@/utils/reglasComision'

export function useReglasComision(mes = null) {
  const r = ref({ ...DEFECTO })
  cargarReglasVigentes(mes).then(v => { r.value = reglasPara(v) })
  return r
}
