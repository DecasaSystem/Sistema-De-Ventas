import { ref } from 'vue'
import { defineStore } from 'pinia'

export const useAppearanceStore = defineStore('appearance', () => {
  const darkMode = ref(localStorage.getItem('app-dark') === '1')
  const fontSize = ref(localStorage.getItem('app-font') ?? 'base') // 'sm' | 'base' | 'lg'

  function apply() {
    const html = document.documentElement
    darkMode.value ? html.classList.add('dark') : html.classList.remove('dark')
    html.classList.remove('font-sm', 'font-base', 'font-lg')
    html.classList.add('font-' + fontSize.value)
  }

  function toggleDark() {
    darkMode.value = !darkMode.value
    localStorage.setItem('app-dark', darkMode.value ? '1' : '0')
    apply()
  }

  function setFontSize(size) {
    fontSize.value = size
    localStorage.setItem('app-font', size)
    apply()
  }

  // Aplica preferencias guardadas al iniciar
  apply()

  return { darkMode, fontSize, toggleDark, setFontSize }
})
