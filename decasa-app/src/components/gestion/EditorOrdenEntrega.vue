<script setup>
/**
 * La redacción de la orden de entrega (la hoja que se lleva el conductor),
 * para editarla desde Gestión → Documentos.
 *
 * Solo se edita lo que es redacción: título, rótulo del saldo, condiciones,
 * rótulos de la firma y pie. Los datos del cliente, los productos, el total,
 * los abonos y lo que queda pendiente salen siempre de la orden. Abajo va un
 * dibujo de la hoja con datos de ejemplo para ver dónde cae cada texto.
 */
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
  datos:      { type: Array, default: () => ['referencia', 'cliente', 'tienda', 'asesor'] },
})
const emit = defineEmits(['update:modelValue'])

const f = ref({
  titulo:             props.modelValue.titulo ?? '',
  etiqueta_saldo:     props.modelValue.etiqueta_saldo ?? '',
  condiciones:        (props.modelValue.condiciones ?? []).join('\n'),
  firma_recibe:       props.modelValue.firma_recibe ?? '',
  firma_recibe_ayuda: props.modelValue.firma_recibe_ayuda ?? '',
  firma_entrega:      props.modelValue.firma_entrega ?? '',
  pie:                props.modelValue.pie ?? '',
  renglones_blanco:   props.modelValue.renglones_blanco ?? 8,
})

const condiciones = computed(() => f.value.condiciones.split('\n').map(p => p.trim()).filter(Boolean))

watch(f, () => {
  emit('update:modelValue', {
    titulo:             f.value.titulo.trim(),
    etiqueta_saldo:     f.value.etiqueta_saldo.trim(),
    condiciones:        condiciones.value,
    firma_recibe:       f.value.firma_recibe.trim(),
    firma_recibe_ayuda: f.value.firma_recibe_ayuda.trim(),
    firma_entrega:      f.value.firma_entrega.trim(),
    pie:                f.value.pie.trim(),
    renglones_blanco:   Math.max(0, Math.min(15, parseInt(f.value.renglones_blanco, 10) || 0)),
  })
}, { deep: true })

// Lo que se ve en el dibujo en lugar de {referencia}, {cliente}...
const EJEMPLO = { referencia: 'A-1520', cliente: 'Ana Ruiz', tienda: 'Decasa Norte', asesor: 'Laura Gómez' }
const conEjemplo = (t) => t.replace(/\{(referencia|cliente|tienda|asesor)\}/g, (_, k) => EJEMPLO[k])

const areaCondiciones = ref(null)
/** Pone {dato} donde está el cursor en las condiciones. */
function insertarDato(d) {
  const el = areaCondiciones.value
  const txt = `{${d}}`
  if (!el) { f.value.condiciones += txt; return }
  const ini = el.selectionStart ?? f.value.condiciones.length
  const fin = el.selectionEnd ?? ini
  f.value.condiciones = f.value.condiciones.slice(0, ini) + txt + f.value.condiciones.slice(fin)
  requestAnimationFrame(() => { el.focus(); el.setSelectionRange(ini + txt.length, ini + txt.length) })
}

const renglonesEjemplo = computed(() => Math.max(0, (parseInt(f.value.renglones_blanco, 10) || 0) - 2))
const claseInput = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500'
</script>

<template>
  <div class="space-y-4">
    <p class="text-[11px] text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
      Aquí cambias la redacción. Los datos del cliente, los productos, el total, los
      abonos y el saldo salen siempre de la orden.
    </p>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1">Título de la hoja</label>
      <input v-model="f.titulo" maxlength="60" :class="claseInput" />
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1">Rótulo del saldo a cobrar</label>
      <input v-model="f.etiqueta_saldo" maxlength="80" :class="claseInput" />
      <p class="text-[11px] text-gray-400 mt-1">En una entrega parcial dice siempre que el saldo se cobra en la última entrega.</p>
    </div>

    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1">Condiciones o notas (opcional)</label>
      <textarea
        ref="areaCondiciones" v-model="f.condiciones" rows="5"
        placeholder="Ej.: Revise el producto antes de firmar. Después de firmado no se aceptan reclamos por golpes o rayones."
        :class="claseInput + ' leading-relaxed'"
      />
      <div class="flex flex-wrap items-center gap-1.5 mt-1">
        <span class="text-[11px] text-gray-400">Cada renglón es un párrafo. Insertar dato:</span>
        <button
          v-for="d in datos" :key="d" type="button" @click="insertarDato(d)"
          class="rounded-full border border-gray-300 px-2 py-0.5 text-[11px] font-semibold text-gray-600 hover:bg-gray-100"
        >{{ '{' + d + '}' }}</button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Firma de quien recibe</label>
        <input v-model="f.firma_recibe" maxlength="60" :class="claseInput" />
      </div>
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Firma de quien entrega</label>
        <input v-model="f.firma_entrega" maxlength="60" :class="claseInput" />
      </div>
    </div>
    <div>
      <label class="block text-xs font-semibold text-gray-600 mb-1">Ayuda bajo la firma de quien recibe</label>
      <input v-model="f.firma_recibe_ayuda" maxlength="100" placeholder="Vacío: no se imprime" :class="claseInput" />
    </div>

    <div class="grid grid-cols-[1fr_auto] gap-2 items-end">
      <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">Pie de página</label>
        <input v-model="f.pie" maxlength="80" :class="claseInput" />
      </div>
      <div class="w-24">
        <label class="block text-xs font-semibold text-gray-600 mb-1">Renglones</label>
        <input v-model.number="f.renglones_blanco" type="number" min="0" max="15" inputmode="numeric" :class="claseInput" />
      </div>
    </div>
    <p class="text-[11px] text-gray-400 -mt-2">Renglones: la tabla de productos se completa con renglones en blanco hasta este número, para anotar a mano.</p>

    <!-- Cómo queda la hoja, con datos de ejemplo -->
    <div class="space-y-1.5">
      <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Así se ve la hoja (datos de ejemplo)</p>
      <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 text-[11px] text-gray-700 space-y-3">
        <div class="flex items-start justify-between gap-2">
          <span class="text-xs font-bold text-gray-400">LOGO</span>
          <div class="text-right">
            <p class="text-sm font-bold text-gray-800 tracking-wide break-words">{{ conEjemplo(f.titulo) || '—' }}</p>
            <p class="text-[10px] text-gray-500">Orden {{ EJEMPLO.referencia }}</p>
          </div>
        </div>
        <div class="border border-gray-300 rounded divide-y divide-gray-200">
          <p class="px-2 py-1"><span class="font-bold">NOMBRE:</span> {{ EJEMPLO.cliente }}</p>
          <p class="px-2 py-1"><span class="font-bold">DIRECCIÓN:</span> Cra 14 # 5-20</p>
          <p class="px-2 py-1"><span class="font-bold">ASESOR:</span> {{ EJEMPLO.asesor }}</p>
        </div>
        <div class="border border-gray-300 rounded divide-y divide-gray-200">
          <div class="flex px-2 py-1 font-bold bg-gray-100"><span class="flex-1">PRODUCTO</span><span class="w-10 text-center">CANT.</span><span class="w-20 text-right">VALOR</span></div>
          <div class="flex px-2 py-1"><span class="flex-1">SOFÁ MILÁN</span><span class="w-10 text-center">1</span><span class="w-20 text-right">$ 2.400.000</span></div>
          <div class="flex px-2 py-1"><span class="flex-1">MESA DE CENTRO</span><span class="w-10 text-center">1</span><span class="w-20 text-right">$ 600.000</span></div>
          <div v-for="n in renglonesEjemplo" :key="n" class="h-5" />
          <div class="flex px-2 py-1"><span class="flex-1 text-right font-bold">TOTAL PEDIDO</span><span class="w-20 text-right font-bold">$ 3.000.000</span></div>
          <div class="flex px-2 py-1"><span class="flex-1 text-right font-bold">ABONOS</span><span class="w-20 text-right">$ 1.500.000</span></div>
          <div class="flex gap-2 px-2 py-1 bg-gray-100"><span class="flex-1 text-right font-bold break-words">{{ conEjemplo(f.etiqueta_saldo) || '—' }}</span><span class="w-20 text-right font-bold">$ 1.500.000</span></div>
        </div>
        <div v-if="condiciones.length" class="space-y-1 text-[10px] leading-snug text-gray-600">
          <p v-for="(c, i) in condiciones" :key="i" class="break-words">{{ conEjemplo(c) }}</p>
        </div>
        <div class="grid grid-cols-2 gap-4 pt-4">
          <div class="border-t border-gray-500 pt-1">
            <p class="font-bold text-gray-800 break-words">{{ conEjemplo(f.firma_recibe) || '—' }}</p>
            <p v-if="f.firma_recibe_ayuda.trim()" class="text-[10px] text-gray-500 break-words">{{ conEjemplo(f.firma_recibe_ayuda) }}</p>
          </div>
          <div class="border-t border-gray-500 pt-1">
            <p class="font-bold text-gray-800 break-words">{{ conEjemplo(f.firma_entrega) || '—' }}</p>
            <p class="text-[10px] text-gray-500">Conductor / asesor</p>
          </div>
        </div>
        <p class="text-center text-[10px] text-gray-400">
          {{ f.pie.trim() ? conEjemplo(f.pie) + ' — ' : '' }}{{ EJEMPLO.tienda }} · Generado el 15/10/2026 10:30
        </p>
      </div>
    </div>
  </div>
</template>
