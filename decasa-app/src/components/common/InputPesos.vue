<script setup>
/**
 * Campo de dinero que se va puntuando solo mientras se escribe.
 *
 *   se teclea 1 0 0 0 0 0 0   →   1  10  100  1.000  10.000  100.000  1.000.000
 *
 * Hacia fuera entrega un número, así que sustituye a un <input type="number">
 * sin tocar nada más. Y va con teclado numérico en el móvil, que en un
 * type="text" no saldría solo.
 */
import { ref, watch, nextTick } from 'vue'
import { soloDigitos, formatearPesos, posicionTrasDigitos, quitarCentavos } from '@/utils/pesos'

const props = defineProps({
  modelValue: { type: [Number, String], default: null },
  // Algunos formularios distinguen "vacío" de "cero" (un precio sin poner no
  // es un precio de cero). Con esto el vacío se devuelve como '' y no como 0.
  permiteVacio: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const campo = ref(null)
const texto = ref('')

// Cambios que vienen de fuera (cargar la orden, recalcular un descuento).
// Se comparan sólo los dígitos: si son los mismos, no se repinta, porque
// hacerlo movería el cursor mientras el usuario escribe.
watch(() => props.modelValue, (v) => {
  const deFuera = (v === null || v === undefined || v === '') ? '' : formatearPesos(v)
  if (soloDigitos(deFuera) !== soloDigitos(texto.value)) texto.value = deFuera
}, { immediate: true })

function alEscribir(e) {
  const input  = e.target
  // Si entró más de un carácter de golpe es que pegaron algo, y lo pegado
  // puede traer centavos que hay que descartar antes de leer los dígitos.
  const pegado = (input.value.length - texto.value.length) > 1
  const crudo  = pegado ? quitarCentavos(input.value) : input.value
  const cursor = pegado ? crudo.length : (input.selectionStart ?? crudo.length)

  const digitosIzquierda = soloDigitos(crudo.slice(0, cursor)).length

  // Se quitan los ceros de la izquierda: "007" es 7, no 007.
  const digitos    = soloDigitos(crudo).replace(/^0+(?=\d)/, '')
  const formateado = digitos === '' ? '' : formatearPesos(digitos)

  texto.value = formateado
  emit('update:modelValue',
    digitos === '' ? (props.permiteVacio ? '' : 0) : Number(digitos))

  nextTick(() => {
    if (! campo.value) return
    campo.value.value = formateado
    const p = posicionTrasDigitos(formateado, digitosIzquierda)
    campo.value.setSelectionRange(p, p)
  })
}

/**
 * Borrar encima de un punto.
 *
 * El punto no lo escribió nadie, así que borrarlo no debería hacer nada — y
 * eso se siente como que la tecla no responde. Se borra el dígito de antes,
 * que es lo que la persona quería.
 */
function alTeclear(e) {
  if (e.key !== 'Backspace') return
  const input = e.target
  const p = input.selectionStart
  if (p !== input.selectionEnd || p === 0) return
  if (input.value[p - 1] !== '.') return

  e.preventDefault()
  input.value = input.value.slice(0, p - 2) + input.value.slice(p)
  input.setSelectionRange(p - 2, p - 2)
  alEscribir({ target: input })
}
</script>

<template>
  <input
    ref="campo"
    :value="texto"
    type="text"
    inputmode="numeric"
    autocomplete="off"
    :disabled="disabled"
    :placeholder="placeholder"
    @input="alEscribir"
    @keydown="alTeclear"
  />
</template>
