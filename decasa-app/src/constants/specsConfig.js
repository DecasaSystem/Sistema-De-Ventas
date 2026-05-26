// Normaliza categoría (texto libre o clave BD) a una clave de template
export function resolverCategoria(cat) {
  if (!cat) return null
  const c = cat.toLowerCase().trim()
  if (c.includes('modular'))                                                    return 'sofas_modulares'
  if (c.includes('sofa cama') || c.includes('sofá cama') || c === 'sofa_camas') return 'sofa_camas'
  if (c.includes('sofa') || c.includes('sofá') || c === 'sofas')               return 'sofas'
  if (c.includes('colchon') || c.includes('colchón') || c === 'colchones')     return 'colchones'
  if (c.includes('cama'))                                                       return 'camas'
  if (c.includes('comedor') && !c.includes('silla'))                           return 'comedores'
  if (c.includes('silla') && (c.includes('comedor') || c === 'sillas_comedor')) return 'sillas_comedor'
  if (c.includes('silla') && (c.includes('barra')   || c === 'sillas_barra'))  return 'sillas_barra'
  if (c.includes('silla'))                                                      return 'sillas_aux'
  if (c.includes('escritorio'))                                                 return 'escritorios'
  if (c === 'mesas_noche' || c.includes('noche'))                              return 'mesas_noche'
  if (c === 'mesas_tv'    || c.includes(' tv'))                                return 'mesas_tv'
  if (c.includes('mesa') || c.includes('auxiliar'))                            return 'mesas'
  if (c.includes('cajon') || c.includes('cajón') || c.includes('zapatero'))   return 'cajoneros'
  return null
}

/**
 * Devuelve los campos a mostrar según si es producto del catálogo o nuevo.
 *  - isCatalogo = true  → solo campos que NO sean solo_nuevo (lo que cambia)
 *  - isCatalogo = false → todos los campos (producto completamente nuevo)
 */
export function camposParaModo(template, isCatalogo) {
  if (!template) return []
  return isCatalogo
    ? template.campos.filter(c => !c.solo_nuevo)
    : template.campos
}

// Construye descripción textual de specs para el cotizador IA
export function specsToDescripcion(specs, template) {
  if (!template || !specs || !Object.keys(specs).length) return ''
  return template.campos
    .filter(c => specs[c.key] !== null && specs[c.key] !== undefined && specs[c.key] !== '')
    .map(c => `${c.label}: ${specs[c.key]}${c.unit ? ' ' + c.unit : ''}`)
    .join('. ')
}

// Extrae dimensiones numéricas de un objeto de specs
export function extraerDimensiones(specs) {
  return {
    largo_cm:    parseFloat(specs?.largo_cm || specs?.largo_total_cm) || null,
    ancho_cm:    parseFloat(specs?.ancho_cm)    || null,
    alto_cm:     parseFloat(specs?.alto_cm)     || null,
    num_puestos: parseInt(specs?.num_puestos)   || null,
  }
}

// ─── Templates por categoría ──────────────────────────────────────────────────
// solo_nuevo: true → solo se muestra cuando el producto no tiene catálogo (campo estructural)
// sin flag       → se muestra siempre (es lo que el cliente quiere cambiar)

const SEL = (options) => ({ type: 'select', options })
const NUM = (placeholder, unit = 'cm') => ({ type: 'number', placeholder, unit })
const TXT = (placeholder) => ({ type: 'text', placeholder })
const N   = true   // alias: solo_nuevo

export const SPECS_TEMPLATES = {
  sofas: {
    titulo: 'Sofá',
    campos: [
      { key: 'num_puestos', label: 'Puestos',          ...SEL(['2', '3', '4', 'L / esquinero']), solo_nuevo: N },
      { key: 'tela',        label: 'Material / tela',  ...TXT('ej: cuero negro, microfibra beige'), useVariantes: true },
      { key: 'tipo_brazos', label: 'Brazos',           ...SEL(['Con brazos', 'Sin brazos']),       solo_nuevo: N },
      { key: 'tipo_patas',  label: 'Patas',            ...SEL(['Madera', 'Metálicas', 'Sin patas']), solo_nuevo: N },
      { key: 'largo_cm',    label: 'Largo',            ...NUM('200'),                              solo_nuevo: N },
      { key: 'ancho_cm',    label: 'Ancho (prof.)',    ...NUM('90'),                               solo_nuevo: N },
      { key: 'alto_cm',     label: 'Alto',             ...NUM('85'),                               solo_nuevo: N },
    ],
  },

  sofas_modulares: {
    titulo: 'Sofá modular',
    campos: [
      { key: 'num_puestos',    label: 'Módulos / puestos', type: 'number', placeholder: '4',          solo_nuevo: N },
      { key: 'tela',           label: 'Material / tela',   ...TXT('ej: tela chenille, cuero'), useVariantes: true },
      { key: 'tipo_patas',     label: 'Patas',             ...SEL(['Madera', 'Metálicas', 'Sin patas']), solo_nuevo: N },
      { key: 'largo_total_cm', label: 'Largo total',       ...NUM('300'),                             solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho (prof.)',     ...NUM('90'),                              solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',              ...NUM('85'),                              solo_nuevo: N },
    ],
  },

  sofa_camas: {
    titulo: 'Sofá cama',
    campos: [
      { key: 'num_puestos', label: 'Puestos',         ...SEL(['2', '3']),    solo_nuevo: N },
      { key: 'tela',        label: 'Material / tela', ...TXT('ej: tela, cuero'), useVariantes: true },
      { key: 'largo_cm',    label: 'Largo',           ...NUM('190'),         solo_nuevo: N },
      { key: 'ancho_cm',    label: 'Ancho',           ...NUM('90'),          solo_nuevo: N },
      { key: 'alto_cm',     label: 'Alto',            ...NUM('85'),          solo_nuevo: N },
    ],
  },

  camas: {
    titulo: 'Cama / base',
    campos: [
      { key: 'tamano',           label: 'Tamaño',          ...SEL(['Sencilla (100 cm)', 'Doble (120 cm)', 'Semi-doble (140 cm)', 'Queen (160 cm)', 'King (200 cm)']), solo_nuevo: N },
      { key: 'tipo_cabecero',    label: 'Tipo de cabecero', ...SEL(['Tapizado', 'Madera', 'Metálico', 'Sin cabecero']) },
      { key: 'tela_cabecero',    label: 'Tela / material cabecero', ...TXT('ej: paño gris, madera roble'), useVariantes: true },
      { key: 'alto_cabecero_cm', label: 'Alto cabecero',   ...NUM('120'),    solo_nuevo: N },
    ],
  },

  colchones: {
    titulo: 'Colchón',
    campos: [
      { key: 'tamano',     label: 'Tamaño',  ...SEL(['Sencillo (100 cm)', 'Doble (120 cm)', 'Semi-doble (140 cm)', 'Queen (160 cm)', 'King (200 cm)']), solo_nuevo: N },
      { key: 'tipo',       label: 'Tipo',    ...SEL(['Espuma', 'Resortes', 'Látex', 'Viscoelástica']) },
      { key: 'espesor_cm', label: 'Espesor', ...NUM('25'),                   solo_nuevo: N },
    ],
  },

  comedores: {
    titulo: 'Comedor (solo mesa)',
    campos: [
      { key: 'num_puestos',    label: 'Puestos',       ...SEL(['4', '6', '8', '10', '12']),                    solo_nuevo: N },
      { key: 'material',       label: 'Material mesa', ...SEL(['Madera sólida', 'Melanina', 'Vidrio', 'Mármol']), solo_nuevo: N },
      { key: 'forma',          label: 'Forma',         ...SEL(['Rectangular', 'Redonda', 'Cuadrada']),          solo_nuevo: N },
      { key: 'largo_cm',       label: 'Largo',         ...NUM('180'),                                          solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho',         ...NUM('90'),                                           solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',          ...NUM('75'),                                           solo_nuevo: N },
    ],
  },

  sillas_comedor: {
    titulo: 'Silla comedor',
    campos: [
      { key: 'tela',                label: 'Tapizado / tela',  ...TXT('ej: cuero negro, tela chenille'), useVariantes: true },
      { key: 'material_estructura', label: 'Estructura',       ...SEL(['Madera', 'Metálica', 'Plástico']),  solo_nuevo: N },
      { key: 'con_brazos',          label: 'Con brazos',       ...SEL(['No', 'Sí']),                        solo_nuevo: N },
      { key: 'alto_asiento_cm',     label: 'Alto asiento',     ...NUM('45'),                                solo_nuevo: N },
    ],
  },

  sillas_aux: {
    titulo: 'Silla auxiliar / sala',
    campos: [
      { key: 'tela',       label: 'Tapizado / tela', ...TXT('ej: tela jaspe, cuero café'), useVariantes: true },
      { key: 'tipo_patas', label: 'Patas',           ...SEL(['Madera', 'Metálicas']),      solo_nuevo: N },
      { key: 'con_brazos', label: 'Con brazos',      ...SEL(['No', 'Sí']),                 solo_nuevo: N },
      { key: 'ancho_cm',   label: 'Ancho',           ...NUM('70'),                         solo_nuevo: N },
      { key: 'alto_cm',    label: 'Alto total',      ...NUM('90'),                         solo_nuevo: N },
    ],
  },

  sillas_barra: {
    titulo: 'Silla de barra',
    campos: [
      { key: 'tela',           label: 'Tapizado / tela', ...TXT('ej: cuero blanco, tela gris'), useVariantes: true },
      { key: 'altura',         label: 'Altura',          ...SEL(['Alta – bar (~75 cm)', 'Media – counter (~65 cm)']), solo_nuevo: N },
      { key: 'con_respaldo',   label: 'Con respaldo',    ...SEL(['Sí', 'No']),                                        solo_nuevo: N },
      { key: 'con_reposapiés', label: 'Con reposapiés',  ...SEL(['Sí', 'No']),                                        solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto total',      ...NUM('110'),                                               solo_nuevo: N },
    ],
  },

  escritorios: {
    titulo: 'Escritorio',
    campos: [
      { key: 'tipo',           label: 'Tipo',           ...SEL(['Recto', 'En L', 'Esquinero']),           solo_nuevo: N },
      { key: 'material',       label: 'Material',       ...SEL(['Melanina', 'Madera sólida', 'Vidrio']) },
      { key: 'color_material', label: 'Color / acabado', ...TXT('ej: roble, blanco, negro') },
      { key: 'num_cajones',    label: 'Cajones',        type: 'number', placeholder: '3',                 solo_nuevo: N },
      { key: 'largo_cm',       label: 'Largo',          ...NUM('140'),                                    solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho',          ...NUM('60'),                                     solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',           ...NUM('75'),                                     solo_nuevo: N },
    ],
  },

  mesas: {
    titulo: 'Mesa',
    campos: [
      { key: 'tipo',           label: 'Tipo',            ...SEL(['Centro', 'Auxiliar', 'Consola', 'TV', 'Noche']),       solo_nuevo: N },
      { key: 'forma',          label: 'Forma',           ...SEL(['Redonda', 'Rectangular', 'Cuadrada', 'Irregular']),    solo_nuevo: N },
      { key: 'material',       label: 'Material',        ...SEL(['Madera sólida', 'Melanina', 'Vidrio', 'Mármol', 'Metal']) },
      { key: 'color_material', label: 'Color / acabado', ...TXT('ej: nogal, blanco mate') },
      { key: 'largo_cm',       label: 'Largo / diámetro', ...NUM('100'),                                                 solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho',           ...NUM('60'),                                                   solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',            ...NUM('45'),                                                   solo_nuevo: N },
    ],
  },

  mesas_noche: {
    titulo: 'Mesa de noche',
    campos: [
      { key: 'material',       label: 'Material',        ...SEL(['Madera sólida', 'Melanina', 'Metal']) },
      { key: 'color_material', label: 'Color / acabado', ...TXT('ej: roble, blanco') },
      { key: 'num_cajones',    label: 'Cajones',         type: 'number', placeholder: '2',  solo_nuevo: N },
      { key: 'largo_cm',       label: 'Largo',           ...NUM('50'),                       solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho',           ...NUM('40'),                       solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',            ...NUM('55'),                       solo_nuevo: N },
    ],
  },

  mesas_tv: {
    titulo: 'Mesa para TV',
    campos: [
      { key: 'material',       label: 'Material',        ...SEL(['Madera sólida', 'Melanina', 'Vidrio']) },
      { key: 'color_material', label: 'Color / acabado', ...TXT('ej: roble, negro') },
      { key: 'num_cajones',    label: 'Cajones',         type: 'number', placeholder: '2',  solo_nuevo: N },
      { key: 'largo_cm',       label: 'Largo',           ...NUM('150'),                      solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho',           ...NUM('40'),                       solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',            ...NUM('50'),                       solo_nuevo: N },
    ],
  },

  cajoneros: {
    titulo: 'Cajonero / zapatero',
    campos: [
      { key: 'material',       label: 'Material',        ...SEL(['Madera sólida', 'Melanina']) },
      { key: 'color_material', label: 'Color / acabado', ...TXT('ej: cedro natural, blanco') },
      { key: 'num_cajones',    label: 'Cajones',         type: 'number', placeholder: '5',  solo_nuevo: N },
      { key: 'largo_cm',       label: 'Largo',           ...NUM('90'),                       solo_nuevo: N },
      { key: 'ancho_cm',       label: 'Ancho',           ...NUM('45'),                       solo_nuevo: N },
      { key: 'alto_cm',        label: 'Alto',            ...NUM('120'),                      solo_nuevo: N },
    ],
  },

  // Fallback para muebles híbridos o categorías no reconocidas
  generico: {
    titulo: 'Mueble personalizado',
    campos: [
      { key: 'material',       label: 'Material principal',  ...TXT('ej: madera sólida, melanina, tapizado') },
      { key: 'color_material', label: 'Color / acabado',     ...TXT('ej: roble natural, blanco, negro mate') },
      { key: 'tela',           label: 'Tapizado / tela',     ...TXT('ej: cuero café, tela gris'), useVariantes: true },
      { key: 'num_cajones',    label: 'Cajones',             type: 'number', placeholder: '0' },
      { key: 'largo_cm',       label: 'Largo',               ...NUM('120') },
      { key: 'ancho_cm',       label: 'Ancho',               ...NUM('60') },
      { key: 'alto_cm',        label: 'Alto',                ...NUM('80') },
    ],
  },
}
