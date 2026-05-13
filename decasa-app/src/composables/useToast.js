import { ref } from 'vue'

const _items = ref([])
let _seq = 0

function _add(msg, type, duration) {
  const id = ++_seq
  _items.value.push({ id, msg, type })
  setTimeout(() => _dismiss(id), duration)
}

function _dismiss(id) {
  const i = _items.value.findIndex(t => t.id === id)
  if (i !== -1) _items.value.splice(i, 1)
}

export function useToast() {
  return {
    items: _items,
    success: (msg, ms = 3000) => _add(msg, 'success', ms),
    error:   (msg, ms = 5000) => _add(msg, 'error',   ms),
    info:    (msg, ms = 3000) => _add(msg, 'info',    ms),
    dismiss: _dismiss,
  }
}
