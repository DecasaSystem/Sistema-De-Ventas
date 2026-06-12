<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/api'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import { CheckCircleIcon, EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/solid'

const auth    = useAuthStore()
const ocultarFirma = computed(() => ['conductor', 'despachador'].includes(auth.usuario?.rol))

// ── Firma ────────────────────────────────────────────────────────────────────
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
    const fd = new FormData()
    fd.append('foto', firmaBlob.value, 'firma.png')
    fd.append('folder', 'firmas')
    const { data: uploadData } = await api.post('/upload/foto', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    await api.patch('/auth/mi-firma', { firma_url: uploadData.url })
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

// ── Seguridad (email / contraseña) ───────────────────────────────────────────
const segForm = ref({ password_actual: '', email: '', password_nuevo: '', password_nuevo_confirmation: '' })
const segGuardando  = ref(false)
const segOk         = ref(false)
const segErr        = ref('')
const segFieldErrs  = ref({})
const mostrarActual = ref(false)
const mostrarNuevo  = ref(false)

function resetSeg() {
  segForm.value = { password_actual: '', email: '', password_nuevo: '', password_nuevo_confirmation: '' }
  segErr.value  = ''
  segFieldErrs.value = {}
  segOk.value   = false
  mostrarActual.value = false
  mostrarNuevo.value  = false
}

async function guardarCuenta() {
  segErr.value = ''
  segFieldErrs.value = {}
  segOk.value  = false

  if (!segForm.value.email && !segForm.value.password_nuevo) {
    segErr.value = 'Escribe un nuevo email, una nueva contraseña, o ambos.'
    return
  }
  if (segForm.value.password_nuevo && segForm.value.password_nuevo !== segForm.value.password_nuevo_confirmation) {
    segFieldErrs.value.password_nuevo_confirmation = 'Las contraseñas no coinciden.'
    return
  }

  segGuardando.value = true
  try {
    const payload = { password_actual: segForm.value.password_actual }
    if (segForm.value.email)         payload.email = segForm.value.email
    if (segForm.value.password_nuevo) {
      payload.password_nuevo = segForm.value.password_nuevo
      payload.password_nuevo_confirmation = segForm.value.password_nuevo_confirmation
    }

    const { data } = await api.patch('/auth/mi-cuenta', payload)
    if (data.email) auth.setEmail(data.email)
    segOk.value = true
    resetSeg()
    segOk.value = true
  } catch (e) {
    const errs = e.response?.data?.errors ?? {}
    segFieldErrs.value = errs
    segErr.value = e.response?.data?.message ?? 'Error al guardar los cambios.'
  } finally {
    segGuardando.value = false
  }
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
        <span class="text-gray-500">Correo</span>
        <span class="font-medium text-gray-800">{{ auth.usuario?.email ?? '—' }}</span>
      </div>
      <div class="flex justify-between text-sm">
        <span class="text-gray-500">Rol</span>
        <span class="capitalize font-medium text-gray-800">{{ auth.usuario?.rol }}</span>
      </div>
    </div>

    <!-- Seguridad -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-4">
      <p class="text-xs font-semibold text-gray-400 uppercase">Seguridad</p>

      <!-- Éxito -->
      <div v-if="segOk" class="flex items-center gap-2 text-green-600 text-sm bg-green-50 rounded-lg px-3 py-2">
        <CheckCircleIcon class="w-4 h-4 flex-shrink-0" />
        Cambios guardados correctamente.
      </div>

      <!-- Contraseña actual -->
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-gray-600">Contraseña actual <span class="text-red-500">*</span></label>
        <div class="relative">
          <input
            v-model="segForm.password_actual"
            :type="mostrarActual ? 'text' : 'password'"
            placeholder="Tu contraseña actual"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            :class="{ 'border-red-400': segFieldErrs.password_actual }"
          />
          <button type="button" @click="mostrarActual = !mostrarActual" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
            <EyeSlashIcon v-if="mostrarActual" class="w-4 h-4" />
            <EyeIcon v-else class="w-4 h-4" />
          </button>
        </div>
        <p v-if="segFieldErrs.password_actual" class="text-xs text-red-500">{{ segFieldErrs.password_actual[0] }}</p>
      </div>

      <p class="text-xs text-gray-400 -mt-2">Rellena uno o los dos campos a continuación:</p>

      <!-- Nuevo email -->
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-gray-600">Nuevo correo electrónico</label>
        <input
          v-model="segForm.email"
          type="email"
          placeholder="nuevo@correo.com"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          :class="{ 'border-red-400': segFieldErrs.email }"
        />
        <p v-if="segFieldErrs.email" class="text-xs text-red-500">{{ segFieldErrs.email[0] }}</p>
      </div>

      <!-- Nueva contraseña -->
      <div class="space-y-1">
        <label class="block text-xs font-semibold text-gray-600">Nueva contraseña</label>
        <div class="relative">
          <input
            v-model="segForm.password_nuevo"
            :type="mostrarNuevo ? 'text' : 'password'"
            placeholder="Mínimo 8 caracteres"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            :class="{ 'border-red-400': segFieldErrs.password_nuevo }"
          />
          <button type="button" @click="mostrarNuevo = !mostrarNuevo" class="absolute right-3 top-2.5 text-gray-400 hover:text-gray-600">
            <EyeSlashIcon v-if="mostrarNuevo" class="w-4 h-4" />
            <EyeIcon v-else class="w-4 h-4" />
          </button>
        </div>
        <p v-if="segFieldErrs.password_nuevo" class="text-xs text-red-500">{{ segFieldErrs.password_nuevo[0] }}</p>
      </div>

      <!-- Confirmar nueva contraseña -->
      <div v-if="segForm.password_nuevo" class="space-y-1">
        <label class="block text-xs font-semibold text-gray-600">Confirmar nueva contraseña</label>
        <input
          v-model="segForm.password_nuevo_confirmation"
          :type="mostrarNuevo ? 'text' : 'password'"
          placeholder="Repite la nueva contraseña"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          :class="{ 'border-red-400': segFieldErrs.password_nuevo_confirmation }"
        />
        <p v-if="segFieldErrs.password_nuevo_confirmation" class="text-xs text-red-500">{{ segFieldErrs.password_nuevo_confirmation[0] }}</p>
      </div>

      <!-- Error general -->
      <p v-if="segErr" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ segErr }}</p>

      <button
        @click="guardarCuenta"
        :disabled="segGuardando || !segForm.password_actual"
        class="w-full py-2.5 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
      >
        {{ segGuardando ? 'Guardando...' : 'Guardar cambios' }}
      </button>
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
