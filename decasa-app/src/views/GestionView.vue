<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { getTiendasAdmin, crearTienda, actualizarTienda, eliminarTienda } from '@/api/tiendas'
import { getPerfilesProduccion, crearPerfilProduccion, actualizarPerfilProduccion, eliminarPerfilProduccion } from '@/api/perfilesProduccion'
import { getRoles, crearRol, actualizarRol, eliminarRol } from '@/api/roles'
import {
  Cog6ToothIcon,
  BuildingStorefrontIcon,
  PlusIcon,
  PencilSquareIcon,
  TrashIcon,
  XMarkIcon,
  MapPinIcon,
  PhoneIcon,
  WrenchScrewdriverIcon,
  UserGroupIcon,
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast  = useToast()

const tab = ref('roles')

// ── Roles (puestos de trabajo) ────────────────────────────────────────────
const ARQUETIPOS = [
  { valor: 'vendedor',    etiqueta: 'Vende y genera comisión' },
  { valor: 'supervisor',  etiqueta: 'Administra el negocio' },
  { valor: 'conductor',   etiqueta: 'Conduce y entrega' },
  { valor: 'taller',      etiqueta: 'Fabrica y lleva caja propia' },
  { valor: 'despachador', etiqueta: 'Recibe y despacha producción' },
]

const roles         = ref([])
const cargandoRoles = ref(true)
const mostrarFormRol = ref(false)
const editandoRol    = ref(null)
const formRol         = ref({ nombre: '', arquetipo: 'vendedor' })
const guardandoRol    = ref(false)

async function cargarRoles() {
  cargandoRoles.value = true
  try {
    const { data } = await getRoles(true)
    roles.value = data
  } catch {
    toast.error('No se pudo cargar la lista de roles')
  } finally {
    cargandoRoles.value = false
  }
}
onMounted(cargarRoles)

function etiquetaArquetipo(valor) {
  return ARQUETIPOS.find(a => a.valor === valor)?.etiqueta ?? valor
}

function abrirNuevoRol() {
  editandoRol.value = null
  formRol.value = { nombre: '', arquetipo: 'vendedor' }
  mostrarFormRol.value = true
}

function abrirEditarRol(r) {
  editandoRol.value = r.id
  formRol.value = { nombre: r.nombre, arquetipo: r.arquetipo }
  mostrarFormRol.value = true
}

async function guardarRol() {
  if (!formRol.value.nombre.trim()) {
    toast.error('El nombre es obligatorio')
    return
  }
  guardandoRol.value = true
  try {
    if (editandoRol.value) {
      // El arquetipo no se puede cambiar después de creado.
      await actualizarRol(editandoRol.value, { nombre: formRol.value.nombre.trim() })
      toast.success('Rol actualizado')
    } else {
      await crearRol({ nombre: formRol.value.nombre.trim(), arquetipo: formRol.value.arquetipo })
      toast.success('Rol creado')
    }
    mostrarFormRol.value = false
    await cargarRoles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoRol.value = false
  }
}

async function eliminarRolItem(r) {
  if (!confirm(`¿Eliminar el rol "${r.nombre}"? Si algún trabajador lo tiene asignado, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarRol(r.id)
    toast.success(data?.message ?? 'Rol eliminado')
    await cargarRoles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

async function reactivarRol(r) {
  try {
    await actualizarRol(r.id, { activo: true })
    toast.success('Rol reactivado')
    await cargarRoles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo reactivar')
  }
}

const rolesActivos   = computed(() => roles.value.filter(r => r.activo))
const rolesInactivos = computed(() => roles.value.filter(r => !r.activo))

// ── Especialidades de taller (antes "perfiles de producción") ────────────
const perfiles         = ref([])
const cargandoPerfiles = ref(true)
const nuevoPerfil       = ref('')
const creandoPerfil     = ref(false)
const guardandoPerfilId = ref(null)

async function cargarPerfiles() {
  cargandoPerfiles.value = true
  try {
    const { data } = await getPerfilesProduccion(true)
    perfiles.value = data
  } catch {
    toast.error('No se pudo cargar la lista de perfiles de producción')
  } finally {
    cargandoPerfiles.value = false
  }
}

async function crearPerfil() {
  if (!nuevoPerfil.value.trim()) return
  creandoPerfil.value = true
  try {
    await crearPerfilProduccion({ nombre: nuevoPerfil.value.trim() })
    nuevoPerfil.value = ''
    toast.success('Perfil creado')
    await cargarPerfiles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo crear el perfil')
  } finally {
    creandoPerfil.value = false
  }
}

async function guardarPerfil(p) {
  guardandoPerfilId.value = p.id
  try {
    await actualizarPerfilProduccion(p.id, { nombre: p.nombre, activo: p.activo })
    toast.success('Perfil actualizado')
    await cargarPerfiles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardandoPerfilId.value = null
  }
}

async function borrarPerfil(p) {
  if (!confirm(`¿Eliminar el perfil "${p.nombre}"? Si algún trabajador lo tiene asignado, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarPerfilProduccion(p.id)
    toast.success(data?.message ?? 'Perfil eliminado')
    await cargarPerfiles()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

// ── Tiendas ──────────────────────────────────────────────────────────────
const tiendas   = ref([])
const cargando  = ref(true)

const vacio = () => ({ nombre: '', ciudad: '', direccion: '', telefono: '', es_fabrica: false, es_independientes: false })
const mostrarForm = ref(false)
const editando     = ref(null)
const form          = ref(vacio())
const guardando     = ref(false)

async function cargarTiendas() {
  cargando.value = true
  try {
    const { data } = await getTiendasAdmin()
    tiendas.value = data
  } catch {
    toast.error('No se pudo cargar la lista de tiendas')
  } finally {
    cargando.value = false
  }
}
onMounted(cargarTiendas)

function abrirNueva() {
  editando.value = null
  form.value = vacio()
  mostrarForm.value = true
}

function abrirEditar(t) {
  editando.value = t.id
  form.value = {
    nombre: t.nombre, ciudad: t.ciudad ?? '', direccion: t.direccion ?? '', telefono: t.telefono ?? '',
    es_fabrica: !!t.es_fabrica, es_independientes: !!t.es_independientes,
  }
  mostrarForm.value = true
}

function cerrarForm() {
  mostrarForm.value = false
}

async function guardar() {
  if (!form.value.nombre.trim()) {
    toast.error('El nombre es obligatorio')
    return
  }
  guardando.value = true
  try {
    const payload = {
      nombre: form.value.nombre.trim(),
      ciudad: form.value.ciudad.trim() || null,
      direccion: form.value.direccion.trim() || null,
      telefono: form.value.telefono.trim() || null,
      es_fabrica: form.value.es_fabrica,
      es_independientes: form.value.es_independientes,
    }
    if (editando.value) {
      await actualizarTienda(editando.value, payload)
      toast.success('Tienda actualizada')
    } else {
      await crearTienda(payload)
      toast.success('Tienda creada')
    }
    mostrarForm.value = false
    await cargarTiendas()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo guardar')
  } finally {
    guardando.value = false
  }
}

async function eliminar(t) {
  if (!confirm(`¿Eliminar "${t.nombre}"? Si tiene datos asociados, se desactiva en vez de borrarse.`)) return
  try {
    const { data } = await eliminarTienda(t.id)
    toast.success(data?.message ?? 'Tienda eliminada')
    await cargarTiendas()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo eliminar')
  }
}

async function reactivar(t) {
  try {
    await actualizarTienda(t.id, { activa: true })
    toast.success('Tienda reactivada')
    await cargarTiendas()
  } catch (e) {
    toast.error(e.response?.data?.message || 'No se pudo reactivar')
  }
}

const activas   = computed(() => tiendas.value.filter(t => t.activa))
const inactivas = computed(() => tiendas.value.filter(t => !t.activa))

let perfilesCargados = false
watch(tab, (t) => {
  if (t === 'especialidades' && !perfilesCargados) {
    perfilesCargados = true
    cargarPerfiles()
  }
})
</script>

<template>
  <div class="max-w-lg mx-auto px-4 py-4 pb-24">
    <div class="flex items-center gap-3 mb-4">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h1 class="text-lg font-bold text-gray-800 flex items-center gap-2 flex-1">
        <Cog6ToothIcon class="w-5 h-5 text-blue-600" />
        Gestión
      </h1>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-4 bg-gray-100 rounded-xl p-1">
      <button
        @click="tab = 'roles'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'roles' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Roles
      </button>
      <button
        @click="tab = 'tiendas'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'tiendas' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Tiendas
      </button>
      <button
        @click="tab = 'especialidades'"
        :class="['flex-1 text-sm font-semibold rounded-lg py-2 transition-colors', tab === 'especialidades' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500']"
      >
        Especialidades de taller
      </button>
    </div>

    <template v-if="tab === 'roles'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Los puestos de trabajo del negocio. Cada uno determina qué pantalla y qué permisos trae por defecto.</p>
        <button
          @click="abrirNuevoRol"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Nuevo
        </button>
      </div>

      <div v-if="cargandoRoles" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else class="space-y-2.5">
        <div v-for="r in rolesActivos" :key="r.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate">{{ r.nombre }}</p>
              <p class="text-xs text-gray-500 mt-0.5">{{ etiquetaArquetipo(r.arquetipo) }}</p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirEditarRol(r)" class="p-1.5 text-gray-300 hover:text-blue-600 transition-colors" aria-label="Editar">
                <PencilSquareIcon class="w-4 h-4" />
              </button>
              <button @click="eliminarRolItem(r)" class="p-1.5 text-gray-300 hover:text-red-600 transition-colors" aria-label="Eliminar">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <template v-if="rolesInactivos.length">
          <p class="text-xs font-semibold text-gray-400 uppercase pt-2">Desactivados</p>
          <div v-for="r in rolesInactivos" :key="r.id" class="bg-gray-50 rounded-xl p-4 flex items-center justify-between gap-2">
            <p class="text-sm text-gray-500 truncate">{{ r.nombre }}</p>
            <button @click="reactivarRol(r)" class="text-xs font-semibold text-blue-600 hover:text-blue-700 shrink-0">Reactivar</button>
          </div>
        </template>
      </div>

      <!-- Nuevo / editar rol -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarFormRol" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="mostrarFormRol = false">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <UserGroupIcon class="w-5 h-5 text-blue-600" />
                  </div>
                  <p class="font-semibold text-gray-800">{{ editandoRol ? 'Editar rol' : 'Nuevo rol' }}</p>
                </div>
                <button @click="mostrarFormRol = false" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>

              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                  <input v-model="formRol.nombre" placeholder="Bodeguero" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Se comporta como</label>
                  <select
                    v-model="formRol.arquetipo"
                    :disabled="!!editandoRol"
                    class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow disabled:bg-gray-50 disabled:text-gray-400"
                  >
                    <option v-for="a in ARQUETIPOS" :key="a.valor" :value="a.valor">{{ a.etiqueta }}</option>
                  </select>
                  <p class="text-[11px] text-gray-400 mt-1">
                    {{ editandoRol ? 'No se puede cambiar después de creado.' : 'Define el cálculo de comisiones, caja y demás — elige el que más se parezca al puesto real.' }}
                  </p>
                </div>
              </div>

              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="mostrarFormRol = false" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 active:bg-gray-300 transition-colors">Cancelar</button>
                <button
                  @click="guardarRol"
                  :disabled="guardandoRol"
                  class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 active:bg-blue-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardandoRol" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardandoRol ? 'Guardando...' : 'Guardar' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <template v-if="tab === 'tiendas'">
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-gray-400">Crea, edita o desactiva las tiendas del negocio.</p>
        <button
          @click="abrirNueva"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Nueva
        </button>
      </div>

      <div v-if="cargando" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else class="space-y-2.5">
        <div v-for="t in activas" :key="t.id" class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="font-semibold text-sm text-gray-800 truncate flex items-center gap-1.5">
                {{ t.nombre }}
                <span v-if="t.es_fabrica" class="text-[10px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded-full">FÁBRICA</span>
                <span v-if="t.es_independientes" class="text-[10px] font-bold text-purple-700 bg-purple-100 px-1.5 py-0.5 rounded-full">INDEPENDIENTES</span>
              </p>
              <p v-if="t.ciudad" class="text-xs text-gray-500 flex items-center gap-1 mt-0.5">
                <MapPinIcon class="w-3.5 h-3.5 text-gray-300" /> {{ t.ciudad }}
              </p>
              <p v-if="t.telefono" class="text-xs text-gray-500 flex items-center gap-1 mt-0.5">
                <PhoneIcon class="w-3.5 h-3.5 text-gray-300" /> {{ t.telefono }}
              </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <button @click="abrirEditar(t)" class="p-1.5 text-gray-300 hover:text-blue-600 transition-colors" aria-label="Editar">
                <PencilSquareIcon class="w-4 h-4" />
              </button>
              <button @click="eliminar(t)" class="p-1.5 text-gray-300 hover:text-red-600 transition-colors" aria-label="Eliminar">
                <TrashIcon class="w-4 h-4" />
              </button>
            </div>
          </div>
        </div>

        <template v-if="inactivas.length">
          <p class="text-xs font-semibold text-gray-400 uppercase pt-2">Desactivadas</p>
          <div v-for="t in inactivas" :key="t.id" class="bg-gray-50 rounded-xl p-4 flex items-center justify-between gap-2">
            <p class="text-sm text-gray-500 truncate">{{ t.nombre }}</p>
            <button @click="reactivar(t)" class="text-xs font-semibold text-blue-600 hover:text-blue-700 shrink-0">Reactivar</button>
          </div>
        </template>
      </div>

      <!-- Nueva / editar tienda -->
      <Teleport to="body">
        <Transition
          enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
          leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0"
        >
          <div v-if="mostrarForm" class="fixed inset-0 bg-black/50 backdrop-blur-[2px] z-50 flex items-end sm:items-center justify-center" @click.self="cerrarForm">
            <div class="bg-white rounded-t-3xl sm:rounded-2xl w-full sm:max-w-md max-h-[92vh] overflow-y-auto shadow-2xl">
              <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100 sticky top-0 bg-white/95 backdrop-blur-sm rounded-t-3xl sm:rounded-t-2xl">
                <div class="flex items-center gap-2.5">
                  <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                    <BuildingStorefrontIcon class="w-5 h-5 text-blue-600" />
                  </div>
                  <p class="font-semibold text-gray-800">{{ editando ? 'Editar tienda' : 'Nueva tienda' }}</p>
                </div>
                <button @click="cerrarForm" class="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors">
                  <XMarkIcon class="w-5 h-5" />
                </button>
              </div>

              <div class="p-5 space-y-4">
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Nombre *</label>
                  <input v-model="form.nombre" placeholder="Decasa Norte" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Ciudad</label>
                    <input v-model="form.ciudad" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                  <div>
                    <label class="block text-xs font-semibold text-gray-500 mb-1.5">Teléfono</label>
                    <input v-model="form.telefono" inputmode="numeric" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                  </div>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-500 mb-1.5">Dirección</label>
                  <input v-model="form.direccion" placeholder="Opcional" class="w-full rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow" />
                </div>

                <div class="flex items-start gap-3 py-1 px-3 bg-amber-50 border border-amber-200 rounded-xl">
                  <input id="es_fabrica" type="checkbox" v-model="form.es_fabrica" class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500" />
                  <div>
                    <label for="es_fabrica" class="text-sm font-medium text-gray-800 cursor-pointer">Es la fábrica</label>
                    <p class="text-xs text-gray-600 mt-0.5">Es el sitio que usa el módulo de Reserva. Solo puede haber una: al marcar esta, la que era fábrica deja de serlo.</p>
                  </div>
                </div>

                <div class="flex items-start gap-3 py-1 px-3 bg-purple-50 border border-purple-200 rounded-xl">
                  <input id="es_independientes" type="checkbox" v-model="form.es_independientes" class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500" />
                  <div>
                    <label for="es_independientes" class="text-sm font-medium text-gray-800 cursor-pointer">Sede de independientes</label>
                    <p class="text-xs text-gray-600 mt-0.5">Es donde cuelgan las órdenes de los vendedores independientes. También debe haber solo una.</p>
                  </div>
                </div>
              </div>

              <div class="flex gap-2.5 p-5 pt-2">
                <button @click="cerrarForm" class="flex-1 bg-gray-100 text-gray-700 text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-gray-200 active:bg-gray-300 transition-colors">Cancelar</button>
                <button
                  @click="guardar"
                  :disabled="guardando"
                  class="flex-1 bg-blue-600 text-white text-sm font-semibold rounded-xl px-4 py-2.5 hover:bg-blue-700 active:bg-blue-800 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5"
                >
                  <span v-if="guardando" class="w-3.5 h-3.5 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                  {{ guardando ? 'Guardando...' : 'Guardar' }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </Teleport>
    </template>

    <template v-else-if="tab === 'especialidades'">
      <p class="text-xs text-gray-400 mb-3">
        Quién puede trabajar qué paso del taller. Se asignan desde la ficha de cada trabajador.
      </p>

      <div class="flex gap-2 mb-3">
        <input
          v-model="nuevoPerfil"
          @keyup.enter="crearPerfil"
          placeholder="Nombre del perfil nuevo (ej: Lacador)"
          class="flex-1 rounded-xl border border-gray-200 px-3.5 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-shadow"
        />
        <button
          @click="crearPerfil"
          :disabled="creandoPerfil || !nuevoPerfil.trim()"
          class="flex items-center gap-1.5 bg-blue-600 text-white text-xs font-semibold px-3 py-2 rounded-xl hover:bg-blue-700 transition-colors shadow-sm disabled:opacity-50 shrink-0"
        >
          <PlusIcon class="w-4 h-4" /> Crear
        </button>
      </div>

      <div v-if="cargandoPerfiles" class="flex justify-center py-12">
        <div class="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>

      <div v-else class="space-y-2.5">
        <div v-for="p in perfiles" :key="p.id" class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3">
          <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
            <WrenchScrewdriverIcon class="w-5 h-5 text-blue-600" />
          </div>
          <input
            v-model="p.nombre"
            class="flex-1 min-w-0 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
          <label class="flex items-center gap-1.5 text-xs text-gray-600 shrink-0">
            <input type="checkbox" v-model="p.activo" /> Activo
          </label>
          <button
            @click="guardarPerfil(p)"
            :disabled="guardandoPerfilId === p.id"
            class="text-xs font-semibold text-blue-600 hover:text-blue-700 disabled:opacity-50 shrink-0"
          >Guardar</button>
          <button @click="borrarPerfil(p)" class="p-1.5 text-gray-300 hover:text-red-600 transition-colors shrink-0" aria-label="Eliminar">
            <TrashIcon class="w-4 h-4" />
          </button>
        </div>

        <p v-if="!perfiles.length" class="text-center py-8 text-gray-400 text-sm">Todavía no hay perfiles de producción.</p>
      </div>
    </template>
  </div>
</template>
