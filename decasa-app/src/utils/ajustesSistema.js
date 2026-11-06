/**
 * Los interruptores de Gestión → Ajustes, leídos una vez por sesión de la app
 * para las pantallas que solo los consultan (Nueva orden, Detalle de la orden).
 * El servidor los vuelve a revisar siempre: esto solo decide qué se muestra.
 */
import { ref } from 'vue'
import { getAjustesSistema } from '@/api/ajustes'

// Lo de fábrica mientras se cargan (igual que AjustesSistema::DEFINICION).
const ajustes = ref({ tela_por_definir: true })
let cargados = null

export function useAjustesSistema() {
  if (!cargados) {
    cargados = getAjustesSistema({ silencioso: true })
      .then(({ data }) => { ajustes.value = { ...ajustes.value, ...data } })
      .catch(() => { cargados = null })
  }
  return ajustes
}
