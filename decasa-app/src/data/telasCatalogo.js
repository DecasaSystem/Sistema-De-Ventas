import { reactive, computed } from 'vue'
import api from '@/api'

const _static = {
  'Visual': {
    'Bistro': ['Marfil', 'Crema', 'Beige', 'Moca', 'Olivia', 'Petroleo', 'Plata', 'Grafito'],
    'Kanvas': ['Crudo', 'Marfil', 'Sand', 'Beige', 'Capuchino', 'Gris'],
    'Alpes': ['Crudo', 'Beige', 'Vainilla', 'Taupe', 'Camel', 'Rosa', 'Azul', 'Hielo', 'Plata', 'Gris', 'Grafito'],
    'Natura Terra': ['Blanco', 'Marfil', 'Beige', 'Vainilla', 'Taupe', 'Plata'],
    'Natura Aqua': ['Blanco', 'Marfil', 'Beige', 'Vainilla', 'Taupe', 'Plata'],
    'Natura Héro': ['Blanco', 'Marfil', 'Beige', 'Vainilla', 'Taupe'],
    'Viena': ['Marfil', 'Beige', 'Arena', 'Camel', 'Taupe', 'Coral', 'Esmeralda', 'Petroleo', 'Plata', 'Gris'],
    'Biscaya': ['Crudo', 'Marfil', 'Beige', 'Arena', 'Capuchino', 'Taupe', 'Azul', 'Plata', 'Gris'],
    'Hammer': ['Marfil', 'Crudo', 'Taupe', 'Chocolate', 'Mauve', 'Esmeralda', 'Turquesa', 'Navy', 'Gris claro', 'Gris oscuro'],
    'Nevada': ['Beige', 'Taupe', 'Tabaco', 'Marrón', 'Mostaza', 'Rojo', 'Turquesa', 'Petroleo', 'Navy', 'Plata', 'Gris', 'Grafito'],
    'Katori': ['Marfil', 'Beige', 'Taupe', 'Rosa', 'Menta', 'Zafiro', 'Plata', 'Gris', 'Negro'],
    'Verona': ['Habano', 'Taupe', 'Palo rosa', 'Rojo', 'Navy', 'Gris claro', 'Gris oscuro', 'Negro'],
    'Rubí': ['Marfil', 'Beige', 'Dorado', 'Rojo', 'Mauve', 'Magenta', 'Verde', 'Navy', 'Plata', 'Gris', 'Negro'],
    'Capri': ['Crudo', 'Beige', 'Chocolate', 'Rojo', 'Fucsia', 'Mauve', 'Esmeralda', 'Navy', 'Plata', 'Gris', 'Negro', 'Mostaza', 'Turquesa', 'Índigo'],
    'Baréin': ['Beige', 'Arena', 'Camel', 'Taupe', 'Gris', 'Plata', 'Humo'],
    'Cybel': ['Marfil', 'Beige', 'Camel', 'Rosa', 'Azul marino', 'Gris', 'Plata', 'Negro'],
    'Savanna': ['Marfil', 'Beige', 'Taupe', 'Mostaza', 'Cocoa', 'Rosa viejo', 'Menta', 'Jade', 'Índigo', 'Plata', 'Gris', 'Humo'],
    'Vienna': ['Marfil', 'Beige', 'Arena', 'Camel', 'Taupe', 'Coral', 'Esmeralda', 'Petroleo', 'Plata', 'Gris'],
  },
  'Arthometextil': {
    'Bershka': ['Ivory', 'Gold', 'Sand', 'Taupe', 'Arcilla', 'Rojo', 'Blue', 'Gris hielo', 'Gris', 'Plomo'],
    'Falcón': ['Sand', 'Oatmeal', 'Negro', 'Gris', 'Gris hielo', 'Grafito', 'Palo de rosa', 'Rojo', 'Mostaza', 'Marrón', 'Aqua', 'Berenjena', 'Azul'],
    'Capri Max': ['Sand', 'Jade', 'Rojo', 'Navy', 'Palo de rosa', 'Gris', 'Grafito', 'Gris hielo', 'Plata', 'Green', 'Tabaco', 'Ivory'],
    'Brum': ['Ivory', 'Sand', 'Taupe', 'Mostaza', 'Celeste', 'Gris', 'Plomo', 'Negro'],
    'Jett': ['Marfil', 'Beige', 'Arena', 'Taupe', 'Petroleo', 'Palo rosa', 'Navy', 'Yellow', 'Gris', 'Grafito', 'Negro'],
    'Selina': ['Mocca', 'Ivory', 'Crudo', 'Gris plata', 'Gris'],
    'Nano': ['Plata', 'Ivory', 'Sand', 'Taupe', 'Palo de rosa', 'Navy'],
    'Jespet': ['Gris hielo', 'Gris', 'Grafito', 'Navy', 'Terracota', 'Beige', 'Sand'],
    'Connor': ['Nieve', 'Sand', 'Taupe', 'Gris light', 'Gris'],
    'Odin': ['Blanco', 'Sand', 'Beige', 'Peach', 'Verde pino', 'Nuvo', 'Navy', 'Gris hielo', 'Gris', 'Grafito'],
    'Memphis': ['Marfil', 'Beige', 'Negro', 'Gris', 'Grafito', 'Palo de rosa', 'Rojo', 'Rojo vivo', 'Naranja', 'Naranja fuerte', 'Mostaza', 'Mocca', 'Chocolate', 'Marrón', 'Verde', 'Verde pino', 'Fucsia', 'Turquesa', 'Petroleo'],
    'Lincoln': ['Ivory', 'Mocca', 'Mostaza', 'Petroleo', 'Gris hielo', 'Taupe', 'Gris', 'Grafito', 'Negro', 'Azul', 'Terra'],
    'Granito': ['Plata', 'Gris', 'Grafito', 'Verde pino', 'Esmeralda', 'Navy', 'Ivory', 'Sand', 'Taupe', 'Chocolate', 'Rojo'],
    'Alice': ['Off white', 'Sand', 'Caramelo', 'Taupe', 'Chocolate', 'Palo de rosa', 'Gris', 'Grafito', 'Azul', 'Mostaza'],
    'Cherry': ['Gris', 'Gris light', 'Gris hielo', 'Navy', 'Azul intenso', 'Palo de rosa', 'Rosy', 'Beige', 'Marfil', 'Blanco'],
    'Perseo': ['Ivory', 'Sand', 'Beige', 'Peach', 'Terra', 'Verde pino', 'Navy', 'Gris hielo', 'Grafito', 'Plomo'],
    'Verdi': ['Gris hielo', 'Gris', 'Mocca', 'Oatmeal', 'Beige', 'Ivory', 'Verde hoja', 'Nuvo', 'Azul', 'Grafito', 'Negro'],
    'Zaid': ['Blanco', 'Marfil', 'Sand', 'Taupe', 'Palo de rosa', 'Rosy', 'Terra', 'Verde pino', 'Verde menta', 'Azul Turquí', 'Azul', 'Gris', 'Grafito'],
    'Dylan': ['Blanco', 'Marfil', 'Sand', 'Gris', 'Plomo'],
    'Nihlo': ['Marfil', 'Sand', 'Taupe', 'Mostaza', 'Rojo', 'Guayaba', 'Nuvo', 'Gris', 'Grafito', 'Negro'],
    'Gabriela': ['Beige', 'Arena', 'Taupe', 'Mostaza', 'Azul', 'Celeste', 'Palo de rosa', 'Hielo', 'Gris', 'Grafito'],
    'Kaira': ['Marfil', 'Sand', 'Taupe', 'Guayaba', 'Mostaza', 'Nuvo', 'Navy', 'Grafito', 'Gris'],
  },
  'Texti Muebles': {
    'Venecia': ['Marfil', 'Beige', 'Plata', 'Gris', 'Camel', 'Taupe', 'Acero'],
    'Polar': ['Marfil', 'Crudo', 'Plata', 'Gris'],
  },
  'Bellatela': {
    'Natura Terra': ['Blanco', 'Marfil', 'Vainilla', 'Taupe', 'Plata'],
    'Natura Aqua': ['Blanco', 'Marfil', 'Beige', 'Vainilla', 'Plata'],
  },
}

// Reactive catalog — starts with static data, DB additions merge in after auth
export const TELAS_CATALOGO = reactive({ ..._static })

function _mergeDB(data) {
  for (const { marca, tipos } of data) {
    if (!TELAS_CATALOGO[marca]) TELAS_CATALOGO[marca] = {}
    for (const { tipo, colores } of tipos) {
      if (!TELAS_CATALOGO[marca][tipo]) {
        TELAS_CATALOGO[marca][tipo] = colores.map(c => c.color)
      } else {
        const existing = new Set(TELAS_CATALOGO[marca][tipo])
        for (const { color } of colores) {
          if (!existing.has(color)) {
            TELAS_CATALOGO[marca][tipo].push(color)
            existing.add(color)
          }
        }
      }
    }
  }
}

/**
 * Las telas de la base se piden la PRIMERA vez que una pantalla las usa, no al
 * abrir la app. Antes llegaban en cada apertura (unos 38 KB) a todo el mundo,
 * aunque solo las usan órdenes, inventario, surtir, reserva, producción y
 * telas. Los tres accesos de abajo las piden solos, así que una pantalla nueva
 * que los use no tiene que acordarse de nada; la que lea TELAS_CATALOGO
 * directo llama a `asegurarCatalogoDB()` al montarse.
 */
let _cargado  = false
let _cargando = null

export function asegurarCatalogoDB() {
  if (_cargado) return Promise.resolve()
  if (!_cargando) {
    _cargando = api.get('/catalogo-telas', { silencioso: true })
      .then(({ data }) => { _mergeDB(data); _cargado = true })
      .catch(() => {})
      // Si falló, se puede volver a intentar, pero no en cada repintada.
      .finally(() => { setTimeout(() => { _cargando = null }, _cargado ? 0 : 30_000) })
  }
  return _cargando
}

// Vuelve a pedirlas aunque ya estén: después de agregar una tela nueva.
export async function cargarCatalogoDB(apiCliente = api) {
  try {
    const { data } = await apiCliente.get('/catalogo-telas')
    _mergeDB(data)
    _cargado = true
  } catch {}
}

// computed ref — auto-unwrapped in templates (script setup)
export const marcasOrdenadas = computed(() => {
  asegurarCatalogoDB()
  return Object.keys(TELAS_CATALOGO).sort()
})

export function tiposTelaDeM(marca) {
  asegurarCatalogoDB()
  return Object.keys(TELAS_CATALOGO[marca] ?? {}).sort()
}

export function coloresDeTela(marca, tela) {
  asegurarCatalogoDB()
  return TELAS_CATALOGO[marca]?.[tela] ?? []
}
