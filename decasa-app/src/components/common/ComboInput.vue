<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
  modelValue:  { type: String,  default: '' },
  options:     { type: Array,   default: () => [] },
  placeholder: { type: String,  default: '' },
  disabled:    { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const inputRef = ref(null)
const abierto  = ref(false)
const local    = ref(props.modelValue ?? '')
const pos      = ref({ top: '0px', left: '0px', width: '0px' })

const filtradas = computed(() => {
  const term = local.value.trim().toLowerCase()
  if (!term) return props.options.slice(0, 30)
  return props.options.filter(o => o.toLowerCase().includes(term)).slice(0, 30)
})

function actualizarPos() {
  if (!inputRef.value) return
  const r = inputRef.value.getBoundingClientRect()
  pos.value = {
    top:   `${r.bottom + window.scrollY + 2}px`,
    left:  `${r.left + window.scrollX}px`,
    width: `${r.width}px`,
  }
}

function onInput(e) {
  local.value = e.target.value
  emit('update:modelValue', e.target.value)
  abierto.value = true
}

function seleccionar(opt) {
  local.value = opt
  emit('update:modelValue', opt)
  abierto.value = false
}

function onFocus() {
  local.value = props.modelValue ?? ''
  actualizarPos()
  abierto.value = true
}

function onBlur() {
  setTimeout(() => { abierto.value = false }, 150)
}

watch(() => props.modelValue, v => {
  if (!abierto.value) local.value = v ?? ''
})

// Recalcula posición si la página hace scroll mientras el dropdown está abierto
function onScroll() { if (abierto.value) actualizarPos() }
window.addEventListener('scroll', onScroll, { passive: true, capture: true })
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll, { capture: true }))
</script>

<template>
  <div class="relative">
    <input
      ref="inputRef"
      :value="local"
      @input="onInput"
      @focus="onFocus"
      @blur="onBlur"
      :placeholder="placeholder"
      :disabled="disabled"
      autocomplete="off"
      class="w-full rounded border border-gray-300 px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:text-gray-400"
    />

    <Teleport to="body">
      <div
        v-if="abierto && filtradas.length > 0"
        :style="{ position: 'absolute', top: pos.top, left: pos.left, width: pos.width, zIndex: 9999 }"
        class="bg-white rounded-lg shadow-xl border border-gray-200 max-h-52 overflow-y-auto"
      >
        <button
          v-for="opt in filtradas"
          :key="opt"
          type="button"
          @mousedown.prevent="seleccionar(opt)"
          class="w-full text-left px-3 py-1.5 text-xs hover:bg-blue-50 transition-colors"
        >
          {{ opt }}
        </button>
      </div>
    </Teleport>
  </div>
</template>
