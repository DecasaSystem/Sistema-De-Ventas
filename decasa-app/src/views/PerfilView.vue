<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import { CheckCircleIcon } from '@heroicons/vue/24/solid'

const auth    = useAuthStore()
const ocultarFirma = computed(() => ['conductor', 'despachador'].includes(auth.usuario?.rol))

const firmaBlob    = ref(null)
const cambiandoFirma = ref(!auth.usuario?.firma_url)
const guardando    = ref(false)
const guardado     = ref(false)
const errFirma     = ref('')

async function guardarFirma() {
  if (!firmaBlob.value) return
  guardando.value = true
  errFirma.value  = ''
  guardado.value  = false
  try {
    // 1. Subir imagen a Cloudinary
    const fd = new FormData()
    fd.append('foto', firmaBlob.value, 'firma.png')
    fd.append('folder', 'firmas')
    const { data: uploadData } = await api.post('/upload/foto', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })

    // 2. Guardar URL en el perfil del usuario
    await api.patch('/auth/mi-firma', { firma_url: uploadData.url })

    // 3. Actualizar el store local
    auth.setFirma(uploadData.url)
    cambiandoFirma.value = false
    firmaBlob.value = null
    guardado.value  = true
  } catch (e) {
    errFirma.value = e.response?.data?.message ?? 'Error al guardar la firma'
  } finally {
    guardando.value = false
  }
}

function iniciarCambio() {
  cambiandoFirma.value = true
  firmaBlob.value = null
  guardado.value  = false
}

function cancelarCambio() {
  cambiandoFirma.value = false
  firmaBlob.value = null
}
</script>

<template>
  <div class="p-4 max-w-lg mx-auto space-y-4 pb-10">

    <h2 class="text-lg font-bold text-gray-800">Mi Perfil</h2>

    <!-- Datos del usuario -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-2">
      <p class="text-xs font-semibold text-gray-400 uppercase mb-3">Información de cuenta</p>
      <div class="flex justify-between text-sm">
        <span class="text-gray-500">Nombre</span>
        <span class="font-medium text-gray-800">{{ auth.usuario?.nombre }}</span>
      </div>
      <div class="flex justify-between text-sm">
        <span class="text-gray-500">Rol</span>
        <span class="capitalize font-medium text-gray-800">{{ auth.usuario?.rol }}</span>
      </div>
    </div>

    <!-- Firma guardada — solo vendedor y supervisor -->
    <div v-if="!ocultarFirma" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div class="flex items-start justify-between">
        <div>
          <p class="text-sm font-semibold text-gray-700">Mi firma</p>
          <p class="text-xs text-gray-400 mt-0.5">Se usa automáticamente al crear órdenes</p>
        </div>
        <button
          v-if="auth.usuario?.firma_url && !cambiandoFirma"
          @click="iniciarCambio"
          class="text-xs text-blue-600 font-medium hover:underline flex-shrink-0"
        >
          Cambiar
        </button>
      </div>

      <!-- Firma actual guardada -->
      <div v-if="auth.usuario?.firma_url && !cambiandoFirma">
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 inline-block">
          <img
            :src="auth.usuario.firma_url"
            alt="Mi firma"
            class="h-20 max-w-xs object-contain"
          />
        </div>
        <div v-if="guardado" class="flex items-center gap-1.5 text-green-600 text-sm mt-2">
          <CheckCircleIcon class="w-4 h-4" />
          Firma guardada correctamente
        </div>
      </div>

      <!-- Estado sin firma -->
      <div v-else-if="!cambiandoFirma" class="text-center py-6 text-gray-400 text-sm bg-gray-50 rounded-lg border border-dashed border-gray-200">
        Sin firma guardada
      </div>

      <!-- Editor de firma -->
      <div v-if="cambiandoFirma" class="space-y-3">
        <FirmaCanvas v-model="firmaBlob" />

        <p v-if="errFirma" class="text-sm text-red-600">{{ errFirma }}</p>

        <div class="flex gap-2">
          <button
            v-if="auth.usuario?.firma_url"
            type="button"
            @click="cancelarCambio"
            class="flex-1 py-2 rounded-lg border border-gray-200 text-sm text-gray-600 hover:bg-gray-50"
          >
            Cancelar
          </button>
          <button
            type="button"
            @click="guardarFirma"
            :disabled="!firmaBlob || guardando"
            class="flex-1 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
          >
            {{ guardando ? 'Guardando...' : 'Guardar firma' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>
