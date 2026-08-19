<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { createUsuario } from '@/api/usuarios'
import { getTiendas } from '@/api/ordenes'
import { getPerfilesProduccion } from '@/api/perfilesProduccion'

const router = useRouter()

const tiendas  = ref([])
const perfiles = ref([])
const submitting = ref(false)
const error = ref('')

const form = ref({
  nombre: '',
  email: '',
  password: '',
  password_confirmation: '',
  rol: 'vendedor',
  facturacion: false,
  es_tapicero: false,
  independiente: false,
  notif_asignar_fecha: true,
  notif_stock: false,
  acceso_redes: false,
  acceso_comisiones: false,
  recarga_telas: false,
  // El rol por defecto de este formulario es 'vendedor', y un vendedor
  // siempre ha tenido Surtir, Proveedores y Reserva por su rol — se arrancan
  // encendidos para que crear uno nuevo no lo deje sin algo que antes traía
  // de fábrica. Costos/Despacho/Métricas/Producción sí arrancan apagados
  // aunque el rol sea supervisor: dejaron de ser automáticos, así que quien
  // crea la cuenta los prende a propósito si corresponde.
  acceso_surtir: true,
  acceso_proveedores: true,
  acceso_reserva: true,
  acceso_costos: false,
  acceso_despacho: false,
  acceso_produccion: false,
  ve_todas_ordenes: false,
  perfil_produccion_id: '',
  tienda_default_id: '',
})

const errores = ref({})

const mostrarPass    = ref(false)
const mostrarConfirm = ref(false)

const rolesSinTienda = ['conductor', 'ebanista', 'despachador', 'costurero']
// Un independiente no pertenece a ninguna tienda: vende por su cuenta y saca
// producto de las que haya, así que tampoco elige una.
const puedeSerIndependiente = computed(() => form.value.rol === 'vendedor')
const esIndependiente = computed(() => puedeSerIndependiente.value && form.value.independiente)
// El selector se muestra para cualquiera que no tenga la tienda oculta por
// completo (ebanista, despachador...) ni sea independiente.
const mostrarTienda = computed(() =>
  !rolesSinTienda.includes(form.value.rol) && !esIndependiente.value
)
// Pero solo es obligatorio elegir una si NO es supervisor: varios son jefes
// que no pertenecen a ninguna tienda en particular.
const requiereTienda = computed(() => mostrarTienda.value && form.value.rol !== 'supervisor')

function errMsg(e) {
  if (!e) return ''
  return Array.isArray(e) ? e[0] : e
}

onMounted(async () => {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
  try {
    const { data } = await getPerfilesProduccion()
    perfiles.value = data
  } catch {}
})

function validar() {
  errores.value = {}
  if (!form.value.nombre.trim()) errores.value.nombre = 'El nombre es obligatorio'
  if (!form.value.email.trim()) errores.value.email = 'El email es obligatorio'
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) errores.value.email = 'Email inválido'
  if (!form.value.password) errores.value.password = 'La contraseña es obligatoria'
  else if (form.value.password.length < 8) errores.value.password = 'Mínimo 8 caracteres'
  if (form.value.password !== form.value.password_confirmation) errores.value.password_confirmation = 'Las contraseñas no coinciden'
  if (requiereTienda.value && !form.value.tienda_default_id) errores.value.tienda_default_id = 'Selecciona una tienda'
  return Object.keys(errores.value).length === 0
}

async function submit() {
  error.value = ''
  if (!validar()) return

  submitting.value = true
  try {
    await createUsuario({
      nombre: form.value.nombre.trim(),
      email: form.value.email.trim(),
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
      rol: form.value.rol,
      facturacion: form.value.facturacion,
      es_tapicero: form.value.es_tapicero,
      independiente: esIndependiente.value,
      notif_asignar_fecha: form.value.notif_asignar_fecha,
      notif_stock: form.value.rol === 'supervisor' ? form.value.notif_stock : false,
      acceso_redes: form.value.acceso_redes,
      acceso_comisiones: form.value.rol === 'supervisor' ? form.value.acceso_comisiones : false,
      recarga_telas: form.value.recarga_telas,
      acceso_surtir: form.value.acceso_surtir,
      acceso_costos: form.value.acceso_costos,
      acceso_proveedores: form.value.acceso_proveedores,
      acceso_despacho: form.value.rol === 'supervisor' ? form.value.acceso_despacho : false,
      acceso_produccion: form.value.rol === 'supervisor' ? form.value.acceso_produccion : false,
      acceso_reserva: form.value.acceso_reserva,
      ve_todas_ordenes: form.value.rol === 'vendedor' ? form.value.ve_todas_ordenes : false,
      perfil_produccion_id: form.value.perfil_produccion_id || null,
      tienda_default_id: mostrarTienda.value ? (form.value.tienda_default_id || null) : null,
    })
    router.push({ name: 'usuarios' })
  } catch (e) {
    const data = e.response?.data
    if (data?.errors) {
      errores.value = data.errors
    } else {
      error.value = data?.message ?? 'Error al crear el usuario'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="p-4 max-w-lg mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">Crear usuario</h2>
    </div>

    <!-- Formulario -->
    <form @submit.prevent="submit" class="space-y-4">
      <!-- Nombre -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre completo *</label>
        <input
          v-model="form.nombre"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="Nombre del usuario"
          :class="{ 'border-red-400': errores.nombre }"
        />
        <p v-if="errores.nombre" class="text-xs text-red-600 mt-1">{{ errMsg(errores.nombre) }}</p>
      </div>

      <!-- Email -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Email *</label>
        <input
          v-model="form.email"
          type="email"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="correo@decasa.com"
          :class="{ 'border-red-400': errores.email }"
        />
        <p v-if="errores.email" class="text-xs text-red-600 mt-1">{{ errMsg(errores.email) }}</p>
      </div>

      <!-- Contraseña -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña *</label>
          <div class="relative">
            <input
              v-model="form.password"
              :type="mostrarPass ? 'text' : 'password'"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Mín. 8 caracteres"
              :class="{ 'border-red-400': errores.password }"
            />
            <button
              type="button"
              @click="mostrarPass = !mostrarPass"
              class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600"
              tabindex="-1"
            >
              <svg v-if="mostrarPass" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </button>
          </div>
          <p v-if="errores.password" class="text-xs text-red-600 mt-1">{{ errMsg(errores.password) }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar *</label>
          <div class="relative">
            <input
              v-model="form.password_confirmation"
              :type="mostrarConfirm ? 'text' : 'password'"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Repetir contraseña"
              :class="{ 'border-red-400': errores.password_confirmation }"
            />
            <button
              type="button"
              @click="mostrarConfirm = !mostrarConfirm"
              class="absolute inset-y-0 right-0 flex items-center px-3 text-gray-400 hover:text-gray-600"
              tabindex="-1"
            >
              <svg v-if="mostrarConfirm" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
            </button>
          </div>
          <p v-if="errores.password_confirmation" class="text-xs text-red-600 mt-1">{{ errMsg(errores.password_confirmation) }}</p>
        </div>
      </div>

      <!-- Rol -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Rol *</label>
        <select
          v-model="form.rol"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="vendedor">Vendedor</option>
          <option value="supervisor">Supervisor</option>
          <option value="conductor">Conductor</option>
          <option value="ebanista">Ebanista</option>
          <option value="despachador">Despachador</option>
          <option value="costurero">Costurero</option>
        </select>
      </div>

      <!-- Descripción del rol de producción -->
      <div v-if="['ebanista', 'despachador', 'costurero'].includes(form.rol)" class="bg-amber-50 rounded-lg px-3 py-2 text-xs text-amber-700">
        <span v-if="form.rol === 'ebanista'">
          El ebanista puede ver y completar los pasos de <strong>ebanistería</strong>, <strong>laca</strong> y <strong>pintura</strong> en las órdenes personalizadas.
        </span>
        <span v-else-if="form.rol === 'costurero'">
          El costurero puede <strong>descontar metros de tela</strong> del inventario cuando los use en producción.
        </span>
        <span v-else>
          El despachador recibe las órdenes cuando terminan todos los pasos de producción, las envía a entrega, y también puede completar pasos de <strong>pintura</strong>.
        </span>
      </div>

      <!-- Vendedor independiente -->
      <div v-if="puedeSerIndependiente" class="flex items-start gap-3 py-2 px-3 bg-amber-50 border border-amber-200 rounded-xl">
        <input
          id="independiente"
          type="checkbox"
          v-model="form.independiente"
          class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
        />
        <div>
          <label for="independiente" class="text-sm font-medium text-gray-800 cursor-pointer">Vendedor independiente</label>
          <p class="text-xs text-gray-600 mt-0.5">
            Trabaja por su cuenta: <strong>no pertenece a ninguna tienda</strong>. Saca producto de las que
            haya, pero su plata no entra a la caja de ninguna — lleva su propia caja y aparece con su nombre
            en las estadísticas. Sus ventas sí suman al total de la empresa.
          </p>
        </div>
      </div>

      <!-- Facturación (solo vendedores) -->
      <div v-if="form.rol === 'vendedor'" class="flex items-start gap-3 py-2">
        <input
          id="facturacion"
          type="checkbox"
          v-model="form.facturacion"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="facturacion" class="text-sm font-medium text-gray-700 cursor-pointer">Facturación</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá ver órdenes entregadas de toda la tienda para facturación externa.</p>
        </div>
      </div>

      <!-- Opciones solo para supervisores -->
      <template v-if="form.rol === 'supervisor'">
        <div class="flex items-start gap-3 py-2">
          <input
            id="es_tapicero"
            type="checkbox"
            v-model="form.es_tapicero"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="es_tapicero" class="text-sm font-medium text-gray-700 cursor-pointer">Encargado de tapicería</label>
            <p class="text-xs text-gray-500 mt-0.5">Podrá completar los pasos de <strong>tapizado</strong>, <strong>laca</strong>, <strong>esqueletería</strong>, <strong>costura</strong> y <strong>pintura</strong> en producción personalizada.</p>
          </div>
        </div>
        <div class="flex items-start gap-3 py-2">
          <input
            id="notif_asignar_fecha"
            type="checkbox"
            v-model="form.notif_asignar_fecha"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="notif_asignar_fecha" class="text-sm font-medium text-gray-700 cursor-pointer">Recibe notificaciones de asignación de fecha</label>
            <p class="text-xs text-gray-500 mt-0.5">Recibirá una alerta cada vez que se cree una orden nueva para asignarle la fecha de entrega.</p>
          </div>
        </div>
        <div class="flex items-start gap-3 py-2">
          <input
            id="notif_stock"
            type="checkbox"
            v-model="form.notif_stock"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="notif_stock" class="text-sm font-medium text-gray-700 cursor-pointer">Recibe avisos de producto agotado</label>
            <p class="text-xs text-gray-500 mt-0.5">Para quien surte: le llega cuando se vende la última unidad de un producto en una tienda, para saber qué mandar.</p>
          </div>
        </div>
      </template>

      <!-- Acceso redes (vendedor y supervisor) -->
      <div v-if="['vendedor', 'supervisor'].includes(form.rol)" class="flex items-start gap-3 py-2">
        <input
          id="acceso_redes"
          type="checkbox"
          v-model="form.acceso_redes"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="acceso_redes" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de redes</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá acceder al módulo de redes sociales y seguimiento digital. Incluye Métricas: no es un permiso aparte.</p>
        </div>
      </div>

      <!-- Acceso comisiones (solo supervisor) -->
      <div v-if="form.rol === 'supervisor'" class="flex items-start gap-3 py-2">
        <input
          id="acceso_comisiones"
          type="checkbox"
          v-model="form.acceso_comisiones"
          class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500"
        />
        <div>
          <label for="acceso_comisiones" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de comisiones</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá ver, gestionar y marcar como pagadas las comisiones de los vendedores.</p>
        </div>
      </div>

      <!-- Recarga telas (vendedor y supervisor) -->
      <div v-if="['vendedor', 'supervisor'].includes(form.rol)" class="flex items-start gap-3 py-2">
        <input
          id="recarga_telas"
          type="checkbox"
          v-model="form.recarga_telas"
          class="mt-0.5 rounded border-gray-300 text-pink-600 focus:ring-pink-500"
        />
        <div>
          <label for="recarga_telas" class="text-sm font-medium text-gray-700 cursor-pointer">Puede recargar telas</label>
          <p class="text-xs text-gray-500 mt-0.5">Tendrá acceso al módulo de telas para agregar metros cuando llegue nueva mercancía.</p>
        </div>
      </div>

      <!-- Acceso surtir (cualquier rol: es lo que se pidió, poder asignarlo) -->
      <div class="flex items-start gap-3 py-2">
        <input
          id="acceso_surtir"
          type="checkbox"
          v-model="form.acceso_surtir"
          class="mt-0.5 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
        />
        <div>
          <label for="acceso_surtir" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de Surtir</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá enviar surtidos desde fábrica y hacer traslados entre tiendas.</p>
        </div>
      </div>

      <!-- Costos (vendedor y supervisor; el ebanista ya lo trae automático) -->
      <div v-if="['vendedor', 'supervisor'].includes(form.rol)" class="flex items-start gap-3 py-2">
        <input
          id="acceso_costos"
          type="checkbox"
          v-model="form.acceso_costos"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="acceso_costos" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de Costos</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá ver fichas técnicas y configuración de costos de producción.</p>
        </div>
      </div>

      <!-- Proveedores (activable solo para vendedor: el supervisor ya lo trae de por sí) -->
      <div v-if="form.rol === 'vendedor'" class="flex items-start gap-3 py-2">
        <input
          id="acceso_proveedores"
          type="checkbox"
          v-model="form.acceso_proveedores"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="acceso_proveedores" class="text-sm font-medium text-gray-700 cursor-pointer">Puede crear y editar proveedores</label>
          <p class="text-xs text-gray-500 mt-0.5">Ver la lista de proveedores ya está disponible para todos; esto habilita agregar o modificar.</p>
        </div>
      </div>

      <!-- Reserva / Fábrica (vendedor y supervisor) -->
      <div v-if="['vendedor', 'supervisor'].includes(form.rol)" class="flex items-start gap-3 py-2">
        <input
          id="acceso_reserva"
          type="checkbox"
          v-model="form.acceso_reserva"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="acceso_reserva" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Reserva / Fábrica</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá consultar y mover el inventario que está en fábrica/reserva.</p>
        </div>
      </div>

      <!-- Ver todas las órdenes (solo vendedor: el supervisor ya ve todo) -->
      <div v-if="form.rol === 'vendedor'" class="flex items-start gap-3 py-2">
        <input
          id="ve_todas_ordenes"
          type="checkbox"
          v-model="form.ve_todas_ordenes"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="ve_todas_ordenes" class="text-sm font-medium text-gray-700 cursor-pointer">Puede ver todas las órdenes</label>
          <p class="text-xs text-gray-500 mt-0.5">Sin esto solo ve las suyas. No hace falta volverlo supervisor para darle esta visibilidad.</p>
        </div>
      </div>

      <!-- Perfil de producción (cualquier rol: quién puede trabajar qué paso del taller) -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Perfil de producción</label>
        <select
          v-model="form.perfil_produccion_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">Sin perfil</option>
          <option v-for="p in perfiles" :key="p.id" :value="p.id">{{ p.nombre }}</option>
        </select>
        <p class="text-xs text-gray-500 mt-0.5">Define qué pasos de producción puede ver y completar en "Mis pasos", sin importar su rol.</p>
      </div>

      <!-- Opciones activables solo para supervisor: ya no vienen automáticas -->
      <template v-if="form.rol === 'supervisor'">
        <div class="flex items-start gap-3 py-2">
          <input
            id="acceso_despacho"
            type="checkbox"
            v-model="form.acceso_despacho"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="acceso_despacho" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de Despacho</label>
            <p class="text-xs text-gray-500 mt-0.5">Podrá asignar conductores, armar rutas y ver el historial de entregas.</p>
          </div>
        </div>
        <div class="flex items-start gap-3 py-2">
          <input
            id="acceso_produccion"
            type="checkbox"
            v-model="form.acceso_produccion"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="acceso_produccion" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Producción</label>
            <p class="text-xs text-gray-500 mt-0.5">Podrá ver y gestionar el tablero completo de producción del taller.</p>
          </div>
        </div>
      </template>

      <!-- Tienda -->
      <div v-if="mostrarTienda">
        <label class="block text-sm font-medium text-gray-700 mb-1">Tienda predeterminada{{ requiereTienda ? ' *' : '' }}</label>
        <select
          v-model="form.tienda_default_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          :class="{ 'border-red-400': errores.tienda_default_id }"
        >
          <option value="">{{ form.rol === 'supervisor' ? 'Sin tienda (jefe)' : 'Seleccionar tienda...' }}</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
        <p v-if="errores.tienda_default_id" class="text-xs text-red-600 mt-1">{{ errMsg(errores.tienda_default_id) }}</p>
        <p v-else-if="form.rol === 'supervisor'" class="text-xs text-gray-500 mt-1">
          Déjalo en "Sin tienda" si es un jefe que no pertenece a ninguna en particular — no le tocará el reparto de comisiones de ninguna tienda.
        </p>
      </div>
      <p v-else-if="esIndependiente" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
        No se elige tienda: al ser independiente no pertenece a ninguna.
      </p>

      <!-- Error general -->
      <p v-if="error" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ error }}</p>

      <!-- Submit -->
      <button
        type="submit"
        :disabled="submitting"
        class="w-full bg-blue-600 text-white rounded-lg py-3 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50 transition-colors"
      >
        {{ submitting ? 'Creando...' : 'Crear usuario' }}
      </button>
    </form>
  </div>
</template>
