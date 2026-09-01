<script setup>
/**
 * Quién hace un proceso del taller, y —si las restauraciones se llevan
 * aparte— en cuál de las dos líneas.
 *
 * Vive en su propio componente porque el modal de procesos lo pintaba dos
 * veces, una para el proceso que se edita y otra para el que se está creando,
 * y con el reparto por línea eso pasaba a ser cien líneas repetidas.
 *
 * Dos papeles distintos en la misma lista: los que entran al programa son los
 * ENCARGADOS —ven el paso en "Mis pasos" y lo confirman—, y los de fábrica no
 * lo ven pero salen de primeros al anotar quién hizo el trabajo. Por eso van
 * separados, y el equipo de fábrica plegado: es largo, y no es lo que uno
 * viene a hacer aquí.
 */
import { computed, ref } from 'vue'

const props = defineProps({
  /** Ids marcados. */
  ids:          { type: Array,  default: () => [] },
  /** Mapa id → 'ambas' | 'normal' | 'restauracion'. */
  lineas:       { type: Object, default: () => ({}) },
  trabajadores: { type: Array,  default: () => [] },
  /** ¿El taller lleva las restauraciones aparte? */
  separa:       { type: Boolean, default: false },
})
const emit = defineEmits(['update:ids', 'update:lineas'])

const LINEAS = [
  { valor: 'ambas',        label: 'Las dos' },
  { valor: 'normal',       label: 'Nuevos' },
  { valor: 'restauracion', label: 'Restauraciones' },
]

const encargadosPosibles = computed(() => props.trabajadores.filter(w => !w.no_usa_programa))
const fabricaPosible     = computed(() => props.trabajadores.filter(w => w.no_usa_programa))

const verFabrica = ref(false)
const fabricaMarcada = computed(() => fabricaPosible.value.filter(w => props.ids.includes(w.id)).length)

function alternar(id) {
  const ids = props.ids.includes(id)
    ? props.ids.filter(x => x !== id)
    : [...props.ids, id]
  emit('update:ids', ids)
}

function lineaDe(id) {
  return props.lineas[id] ?? 'ambas'
}

function ponerLinea(id, linea) {
  emit('update:lineas', { ...props.lineas, [id]: linea })
}

/** Los marcados, en el orden en que salen arriba, para repartirlos. */
const marcados = computed(() =>
  props.trabajadores.filter(w => props.ids.includes(w.id))
)

/**
 * De qué líneas quedaría el proceso sin nadie que pueda confirmar el paso.
 *
 * Es la misma cuenta que hace el servidor. Se repite aquí para avisar mientras
 * se marca, en vez de dejar que el guardado falle al final.
 */
const sinCubrir = computed(() => {
  const conAcceso = marcados.value.filter(w => !w.no_usa_programa)
  if (!props.separa) return conAcceso.length ? [] : ['ambas']

  return ['normal', 'restauracion'].filter(linea =>
    !conAcceso.some(w => [linea, 'ambas'].includes(lineaDe(w.id)))
  )
})

const nadieMarcado = computed(() => props.ids.length === 0)

function textoLinea(linea) {
  return linea === 'restauracion' ? 'las restauraciones' : 'los muebles nuevos'
}
</script>

<template>
  <div>
    <label class="block text-[11px] text-gray-500 mb-1">Encargados — ven el paso y lo confirman</label>
    <div class="flex flex-wrap gap-1.5">
      <button
        v-for="w in encargadosPosibles" :key="w.id" type="button" @click="alternar(w.id)"
        :class="['px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors',
          ids.includes(w.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-500 hover:border-emerald-300']"
      >{{ w.nombre }}</button>
    </div>

    <button type="button" @click="verFabrica = !verFabrica"
      class="mt-2 text-[11px] text-gray-500 hover:text-gray-700">
      Equipo de fábrica — quiénes lo hacen
      <span v-if="fabricaMarcada" class="text-emerald-600 font-semibold">({{ fabricaMarcada }})</span>
      <span class="ml-1 opacity-60">{{ verFabrica ? '▲' : '▼' }}</span>
    </button>
    <div v-if="verFabrica" class="flex flex-wrap gap-1.5 mt-1.5">
      <button
        v-for="w in fabricaPosible" :key="w.id" type="button" @click="alternar(w.id)"
        :class="['px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors',
          ids.includes(w.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-gray-200 bg-white text-gray-500 hover:border-emerald-300']"
      >{{ w.nombre }}</button>
    </div>
    <p v-if="verFabrica" class="text-[11px] text-gray-400 mt-1">
      No entran al programa: no ven el paso, pero salen de primeros al anotar
      quién hizo el trabajo.
    </p>

    <!-- Reparto por línea: solo cuando el taller las lleva aparte -->
    <div v-if="separa && marcados.length" class="mt-2.5 rounded-lg bg-gray-50 border border-gray-200 p-2.5 space-y-1.5">
      <p class="text-[11px] font-semibold text-gray-600">¿De qué se encarga cada uno?</p>
      <div v-for="w in marcados" :key="w.id" class="flex items-center gap-2">
        <span class="text-xs text-gray-700 truncate flex-1 min-w-0">
          {{ w.nombre }}
          <span v-if="w.no_usa_programa" class="text-[10px] text-gray-400">(fábrica)</span>
        </span>
        <div class="flex rounded-lg border border-gray-200 overflow-hidden flex-shrink-0">
          <button
            v-for="l in LINEAS" :key="l.valor" type="button" @click="ponerLinea(w.id, l.valor)"
            :class="['px-2 py-1 text-[11px] font-medium transition-colors',
              lineaDe(w.id) === l.valor ? 'bg-blue-600 text-white' : 'bg-white text-gray-500 hover:bg-gray-100']"
          >{{ l.label }}</button>
        </div>
      </div>
      <p class="text-[11px] text-gray-400">
        "Las dos" es lo de siempre: ve los pasos de todo. Reparte solo donde de
        verdad hay dos encargados distintos.
      </p>
    </div>

    <p class="text-[11px] text-gray-400 mt-1">
      Los que entran al programa VEN el paso en "Mis pasos" y lo confirman.
      Los de fábrica no lo ven, pero salen de primeros al anotar quién hizo
      el trabajo, en vez de tener que buscarlos entre todo el taller.
    </p>

    <p v-if="nadieMarcado" class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5 mt-1.5">
      Nadie puede hacer este proceso: sus pasos quedarían en curso pero invisibles para todos.
    </p>
    <p v-else-if="sinCubrir.length && !separa" class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5 mt-1.5">
      Solo marcaste gente de fábrica. Ellos no entran al programa, así que nadie
      vería este paso para confirmarlo y las piezas se quedarían paradas.
    </p>
    <p v-else-if="sinCubrir.length" class="text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-1.5 mt-1.5">
      Falta un encargado con acceso al programa para
      {{ sinCubrir.map(textoLinea).join(' y ') }}. Esos pasos quedarían en curso
      y sin que nadie los vea.
    </p>
  </div>
</template>
