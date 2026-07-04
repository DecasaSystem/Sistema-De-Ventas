<script setup>
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useAppearanceStore } from '@/stores/appearance'
import api from '@/api'
import FirmaCanvas from '@/components/FirmaCanvas.vue'
import { CheckCircleIcon, EyeIcon, EyeSlashIcon } from '@heroicons/vue/24/solid'
import { Cog6ToothIcon, SunIcon, MoonIcon, UserCircleIcon, ArrowsRightLeftIcon, TrashIcon, PlusCircleIcon } from '@heroicons/vue/24/outline'

const auth       = useAuthStore()
const appearance = useAppearanceStore()
const ocultarFirma = computed(() => ['conductor', 'despachador'].includes(auth.usuario?.rol))

const fontOptions = [
  { value: 'sm',   label: 'Pequeña', sizeClass: 'text-sm' },
  { value: 'base', label: 'Normal',  sizeClass: 'text-xl' },
  { value: 'lg',   label: 'Grande',  sizeClass: 'text-3xl' },
]

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

// ── Perfil alternativo ────────────────────────────────────────────────────────
const mostrarFormAlt  = ref(false)
const altEmail        = ref('')
const altPassword     = ref('')
const altGuardando    = ref(false)
const altErr          = ref('')
const altMostrarPass  = ref(false)

function abrirFormAlt() {
  altEmail.value    = ''
  altPassword.value = ''
  altErr.value      = ''
  mostrarFormAlt.value = true
}

function cancelarAlt() {
  mostrarFormAlt.value = false
  altErr.value = ''
}

async function activarPerfilAlternativo() {
  altErr.value = ''
  if (!altEmail.value || !altPassword.value) {
    altErr.value = 'Ingresa el correo y la contraseña del segundo perfil.'
    return
  }
  altGuardando.value = true
  try {
    await auth.loginPerfilAlternativo(altEmail.value, altPassword.value)
    mostrarFormAlt.value = false
  } catch (e) {
    altErr.value = e.message?.includes('mismo usuario')
      ? 'Ese usuario ya es el perfil activo.'
      : (e.response?.data?.message ?? 'Correo o contraseña incorrectos.')
  } finally {
    altGuardando.value = false
  }
}

function rolLabel(rol) {
  const map = { supervisor: 'Supervisor', vendedor: 'Vendedor', conductor: 'Conductor', ebanista: 'Ebanista', despachador: 'Despachador', costurero: 'Costurero' }
  return map[rol] ?? rol
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

    <!-- Apariencia -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-5">

      <!-- Encabezado sección -->
      <div class="flex items-center gap-2">
        <Cog6ToothIcon class="w-4 h-4 text-gray-400" />
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide">Apariencia</p>
      </div>

      <!-- Modo claro / oscuro -->
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div
            :class="['w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors', appearance.darkMode ? 'bg-indigo-900' : 'bg-amber-100']"
          >
            <MoonIcon v-if="appearance.darkMode" class="w-5 h-5 text-indigo-300" />
            <SunIcon  v-else                      class="w-5 h-5 text-amber-500" />
          </div>
          <div>
            <p class="text-sm font-semibold text-gray-800">{{ appearance.darkMode ? 'Modo oscuro' : 'Modo claro' }}</p>
            <p class="text-xs text-gray-400 mt-0.5">{{ appearance.darkMode ? 'Interfaz oscura activa' : 'Interfaz clara activa' }}</p>
          </div>
        </div>

        <!-- Toggle switch animado -->
        <button
          @click="appearance.toggleDark()"
          :class="['relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500', appearance.darkMode ? 'bg-indigo-600' : 'bg-gray-300']"
          :aria-pressed="appearance.darkMode"
          aria-label="Alternar modo oscuro"
        >
          <span
            :class="['pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-lg transition duration-200 ease-in-out', appearance.darkMode ? 'translate-x-6' : 'translate-x-1']"
          />
        </button>
      </div>

      <hr class="border-gray-100" />

      <!-- Tamaño de fuente -->
      <div class="space-y-3">
        <p class="text-sm font-semibold text-gray-700">Tamaño de letra</p>
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="opt in fontOptions"
            :key="opt.value"
            @click="appearance.setFontSize(opt.value)"
            :class="[
              'flex flex-col items-center gap-1.5 py-4 rounded-xl border-2 transition-all duration-150',
              appearance.fontSize === opt.value
                ? 'border-blue-500 bg-blue-50'
                : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50'
            ]"
          >
            <span
              :class="['font-bold leading-none transition-colors', opt.sizeClass, appearance.fontSize === opt.value ? 'text-blue-600' : 'text-gray-700']"
            >Aa</span>
            <span :class="['text-xs font-medium transition-colors', appearance.fontSize === opt.value ? 'text-blue-600' : 'text-gray-400']">{{ opt.label }}</span>
          </button>
        </div>
        <p class="text-xs text-gray-400 text-center">Los cambios se aplican de inmediato y se guardan automáticamente</p>
      </div>
    </div>

    <!-- ── Perfil alternativo ─────────────────────────────────────────── -->
    <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <p class="text-xs font-semibold text-gray-400 uppercase flex items-center gap-1.5">
        <ArrowsRightLeftIcon class="w-3.5 h-3.5" /> Doble perfil
      </p>
      <p class="text-xs text-gray-500">
        Permite cambiar de usuario sin cerrar sesión — ideal cuando dos personas comparten un mismo equipo.
      </p>

      <!-- Sin perfil alternativo -->
      <template v-if="!auth.tienePerfilAlternativo">
        <button
          v-if="!mostrarFormAlt"
          @click="abrirFormAlt"
          class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl border-2 border-dashed border-blue-300 text-blue-600 text-sm font-semibold hover:bg-blue-50 transition-colors"
        >
          <PlusCircleIcon class="w-5 h-5" /> Activar perfil alternativo
        </button>

        <!-- Formulario para añadir segundo perfil -->
        <div v-else class="space-y-3">
          <p class="text-xs text-gray-600 font-medium">Ingresa las credenciales del segundo usuario:</p>
          <input
            v-model="altEmail"
            type="email"
            placeholder="Correo del segundo usuario"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <div class="relative">
            <input
              v-model="altPassword"
              :type="altMostrarPass ? 'text' : 'password'"
              placeholder="Contraseña"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm pr-10 focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <button type="button" @click="altMostrarPass = !altMostrarPass" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
              <EyeIcon v-if="!altMostrarPass" class="w-4 h-4" />
              <EyeSlashIcon v-else class="w-4 h-4" />
            </button>
          </div>
          <p v-if="altErr" class="text-xs text-red-500">{{ altErr }}</p>
          <div class="flex gap-2">
            <button @click="cancelarAlt" class="flex-1 py-2 rounded-xl border border-gray-300 text-sm text-gray-600 hover:bg-gray-50 transition-colors">
              Cancelar
            </button>
            <button
              @click="activarPerfilAlternativo"
              :disabled="altGuardando"
              class="flex-1 py-2 rounded-xl bg-blue-600 text-white text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
            >
              {{ altGuardando ? 'Verificando...' : 'Activar' }}
            </button>
          </div>
        </div>
      </template>

      <!-- Con perfil alternativo: mostrar ambos -->
      <template v-else>
        <!-- Perfil 0 -->
        <div
          v-for="(idx) in [0, 1]"
          :key="idx"
          :class="[
            'flex items-center gap-3 rounded-xl p-3 border-2 transition-all',
            auth.perfilActivoIdx === idx
              ? 'border-blue-400 bg-blue-50'
              : 'border-gray-200 bg-gray-50 cursor-pointer hover:border-blue-200'
          ]"
          @click="auth.perfilActivoIdx !== idx && auth.cambiarPerfil()"
        >
          <div :class="['w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 text-white text-sm font-bold', idx === 0 ? 'bg-blue-500' : 'bg-purple-500']">
            {{ (idx === 0 ? auth.usuario?.nombre : auth.perfilAlternativo?.nombre)?.charAt(0)?.toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-gray-800 truncate">
              {{ idx === 0 ? auth.usuario?.nombre : auth.perfilAlternativo?.nombre }}
            </p>
            <p class="text-xs text-gray-500">{{ rolLabel(idx === 0 ? auth.usuario?.rol : auth.perfilAlternativo?.rol) }}</p>
          </div>
          <span v-if="auth.perfilActivoIdx === idx" class="text-xs bg-blue-600 text-white px-2 py-0.5 rounded-full font-semibold flex-shrink-0">
            Activo
          </span>
          <span v-else class="text-xs text-blue-600 font-semibold flex-shrink-0">
            Cambiar →
          </span>
        </div>

        <!-- Botón eliminar perfil alternativo -->
        <button
          @click="auth.eliminarPerfilAlternativo()"
          class="w-full flex items-center justify-center gap-1.5 py-2 rounded-xl border border-red-200 text-red-500 text-xs font-medium hover:bg-red-50 transition-colors"
        >
          <TrashIcon class="w-3.5 h-3.5" /> Desactivar perfil alternativo
        </button>
      </template>
    </div>

  </div>
</template>
