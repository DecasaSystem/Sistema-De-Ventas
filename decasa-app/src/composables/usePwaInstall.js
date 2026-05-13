import { ref, onMounted, onBeforeUnmount } from 'vue'

export function usePwaInstall() {
  const canInstall = ref(false)
  const dismissed = ref(false)
  let deferredPrompt = null

  function onBeforeInstall(e) {
    e.preventDefault()
    deferredPrompt = e
    canInstall.value = true
  }

  async function instalar() {
    if (!deferredPrompt) return
    deferredPrompt.prompt()
    const result = await deferredPrompt.userChoice
    if (result.outcome === 'accepted') {
      canInstall.value = false
      dismissed.value = true
    }
    deferredPrompt = null
  }

  function descartar() {
    canInstall.value = false
    dismissed.value = true
    deferredPrompt = null
  }

  onMounted(() => window.addEventListener('beforeinstallprompt', onBeforeInstall))
  onBeforeUnmount(() => window.removeEventListener('beforeinstallprompt', onBeforeInstall))

  return { canInstall, dismissed, instalar, descartar }
}
