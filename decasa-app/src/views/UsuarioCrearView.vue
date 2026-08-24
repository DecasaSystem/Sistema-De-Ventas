<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { createUsuario } from '@/api/usuarios'
import { getTiendas } from '@/api/ordenes'
import { getRoles } from '@/api/roles'

const router = useRouter()

const tiendas  = ref([])
const roles    = ref([])
const submitting = ref(false)
const error = ref('')

const form = ref({
  nombre: '',
  // Trabajador de fábrica: no entra al programa, así que no lleva correo,
  // contraseña, tienda ni permisos. Solo sus datos y su oficio, para que
  // aparezca en Nómina.
  no_usa_programa: false,
  cedula: '',
  apto_comisiones: false,
  apto_produccion: false,
  email: '',
  password: '',
  password_confirmation: '',
  rol_id: '',
  facturacion: false,
  independiente: false,
  notif_asignar_fecha: true,
  notif_stock: false,
  acceso_redes: false,
  acceso_comisiones: false,
  recarga_telas: false,
  acceso_telas: false,
  // El rol por defecto de este formulario es 'vendedor', y un vendedor
  // siempre ha tenido Surtir, Proveedores y Reserva por su rol — se arrancan
  // encendidos para que crear uno nuevo no lo deje sin algo que antes traía
  // de fábrica. Costos/Despacho/Métricas/Producción sí arrancan apagados
  // aunque el rol sea supervisor: dejaron de ser automáticos, así que quien
  // crea la cuenta los prende a propósito si corresponde.
  acceso_surtir: true,
  acceso_proveedores: true,
  acceso_reserva: true,
  // Compras nació abierta a cualquiera con sesión, sin importar el rol: se
  // arranca encendida por la misma razón que Surtir, para no dejar a nadie
  // nuevo sin algo que el módulo ya daba por hecho.
  acceso_compras: true,
  acceso_costos: false,
  acceso_despacho: false,
  acceso_produccion: false,
  gestiona_produccion: false,
  acceso_nomina: false,
  ve_todas_ordenes: false,
  tienda_default_id: '',
})

const errores = ref({})

const mostrarPass    = ref(false)
const mostrarConfirm = ref(false)

// Arquetipo del rol elegido: el comportamiento de fondo (vendedor, supervisor,
// conductor, taller, despachador) que determina qué campos aplican — el rol
// en sí puede ser cualquier nombre que la empresa haya inventado.
const rolSeleccionado = computed(() => roles.value.find(r => r.id === form.value.rol_id))
const arquetipo = computed(() => rolSeleccionado.value?.arquetipo ?? '')
const claveSeleccionada = computed(() => rolSeleccionado.value?.clave ?? '')

const arquetiposSinTienda = ['conductor', 'despachador', 'taller']
// Un independiente no pertenece a ninguna tienda: vende por su cuenta y saca
// producto de las que haya, así que tampoco elige una.
const puedeSerIndependiente = computed(() => arquetipo.value === 'vendedor')
const esIndependiente = computed(() => puedeSerIndependiente.value && form.value.independiente)
// El selector se muestra para cualquiera que no tenga la tienda oculta por
// completo (conductor, taller, despachador...) ni sea independiente.
const mostrarTienda = computed(() =>
  !arquetiposSinTienda.includes(arquetipo.value) && !esIndependiente.value
)
// Pero solo es obligatorio elegir una si el arquetipo es vendedor: los demás
// (incluido supervisor, donde varios son jefes sin tienda) no la necesitan.
const requiereTienda = computed(() => mostrarTienda.value && arquetipo.value === 'vendedor')

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
    const { data } = await getRoles()
    roles.value = data
    // Vendedor sigue siendo el rol por defecto de este formulario.
    form.value.rol_id = data.find(r => r.clave === 'vendedor')?.id ?? data[0]?.id ?? ''
  } catch {}
})

function validar() {
  errores.value = {}
  if (!form.value.nombre.trim()) errores.value.nombre = 'El nombre es obligatorio'

  // Al de fábrica no se le piden credenciales ni tienda: no entra al programa.
  if (!form.value.no_usa_programa) {
    if (!form.value.email.trim()) errores.value.email = 'El email es obligatorio'
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email)) errores.value.email = 'Email inválido'
    if (!form.value.password) errores.value.password = 'La contraseña es obligatoria'
    else if (form.value.password.length < 8) errores.value.password = 'Mínimo 8 caracteres'
    if (form.value.password !== form.value.password_confirmation) errores.value.password_confirmation = 'Las contraseñas no coinciden'
    if (requiereTienda.value && !form.value.tienda_default_id) errores.value.tienda_default_id = 'Selecciona una tienda'
  }
  return Object.keys(errores.value).length === 0
}

async function submit() {
  error.value = ''
  if (!validar()) return

  submitting.value = true
  try {
    await createUsuario({
      nombre: form.value.nombre.trim(),
      cedula: form.value.cedula.trim() || null,
      no_usa_programa: form.value.no_usa_programa,
      // Quien no usa el programa va sin credenciales y no puede comisionar.
      email: form.value.no_usa_programa ? null : form.value.email.trim(),
      password: form.value.no_usa_programa ? null : form.value.password,
      password_confirmation: form.value.no_usa_programa ? null : form.value.password_confirmation,
      apto_comisiones: !form.value.no_usa_programa && form.value.apto_comisiones,
      apto_produccion: form.value.no_usa_programa || form.value.apto_produccion,
      rol_id: form.value.rol_id,
      facturacion: form.value.facturacion,
      independiente: esIndependiente.value,
      notif_asignar_fecha: form.value.notif_asignar_fecha,
      notif_stock: arquetipo.value === 'supervisor' ? form.value.notif_stock : false,
      acceso_redes: form.value.acceso_redes,
      acceso_comisiones: arquetipo.value === 'supervisor' ? form.value.acceso_comisiones : false,
      recarga_telas: form.value.recarga_telas,
      acceso_telas: form.value.acceso_telas,
      acceso_surtir: form.value.acceso_surtir,
      acceso_costos: form.value.acceso_costos,
      acceso_proveedores: form.value.acceso_proveedores,
      acceso_despacho: arquetipo.value === 'supervisor' ? form.value.acceso_despacho : false,
      acceso_produccion: form.value.acceso_produccion,
      gestiona_produccion: form.value.acceso_produccion && form.value.gestiona_produccion,
      acceso_nomina: arquetipo.value === 'supervisor' ? form.value.acceso_nomina : false,
      acceso_compras: form.value.acceso_compras,
      acceso_reserva: form.value.acceso_reserva,
      ve_todas_ordenes: arquetipo.value === 'vendedor' ? form.value.ve_todas_ordenes : false,
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
      <!-- No usa el programa: define casi todo lo que sigue -->
      <div class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-3">
        <input
          id="no_usa_programa"
          type="checkbox"
          v-model="form.no_usa_programa"
          class="mt-0.5 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
        />
        <div>
          <label for="no_usa_programa" class="text-sm font-medium text-gray-700 cursor-pointer">Este trabajador no usa el programa</label>
          <p class="text-xs text-gray-500 mt-0.5">
            Para la gente de fábrica (lijador, laquero, tapicero…). No lleva correo, contraseña, tienda ni
            permisos: solo sus datos y su oficio, y aparece solo en Nómina para pagarle.
          </p>
        </div>
      </div>

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

      <!-- Cédula -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Cédula</label>
        <input
          v-model="form.cedula"
          inputmode="numeric"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          placeholder="Opcional"
        />
      </div>

      <!-- Email -->
      <div v-show="!form.no_usa_programa">
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
      <div v-show="!form.no_usa_programa" class="grid grid-cols-2 gap-3">
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
          v-model="form.rol_id"
          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
        </select>
      </div>

      <!-- Apto para comisiones. Quien no usa el programa no hace ventas. -->
      <div v-if="!form.no_usa_programa" class="flex items-start gap-3 py-2">
        <input
          id="apto_comisiones"
          type="checkbox"
          v-model="form.apto_comisiones"
          class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500"
        />
        <div>
          <label for="apto_comisiones" class="text-sm font-medium text-gray-700 cursor-pointer">Apto para comisiones</label>
          <p class="text-xs text-gray-500 mt-0.5">Marca que esta persona hace ventas y por lo tanto le corresponde comisión.</p>
        </div>
      </div>


      <!-- Apto para producción. La fábrica lo trae puesto: es el taller. -->
      <div class="flex items-start gap-3 py-2">
        <input
          id="apto_produccion"
          type="checkbox"
          :checked="form.no_usa_programa || form.apto_produccion"
          :disabled="form.no_usa_programa"
          @change="form.apto_produccion = $event.target.checked"
          class="mt-0.5 rounded border-gray-300 text-orange-600 focus:ring-orange-500 disabled:opacity-60"
        />
        <div>
          <label for="apto_produccion" class="text-sm font-medium text-gray-700 cursor-pointer">Apto para producción</label>
          <p class="text-xs text-gray-500 mt-0.5">
            <template v-if="form.no_usa_programa">
              Quien no usa el programa es del taller, así que va marcado siempre.
              Aparecerá al anotar quién hizo un paso.
            </template>
            <template v-else>
              Sale en las listas de producción: para ponerlo de encargado de un proceso
              y para anotarlo como que hizo un paso. Sin esto no aparece, y así esas
              listas no se llenan de gente que nunca pisa el taller.
            </template>
          </p>
        </div>
      </div>

      <!-- Todo lo que sigue es del programa: no aplica a la gente de fábrica -->
      <template v-if="!form.no_usa_programa">

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
      <div v-if="arquetipo === 'vendedor'" class="flex items-start gap-3 py-2">
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
      <template v-if="arquetipo === 'supervisor'">
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
      <div v-if="['vendedor', 'supervisor'].includes(arquetipo)" class="flex items-start gap-3 py-2">
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
      <div v-if="arquetipo === 'supervisor'" class="flex items-start gap-3 py-2">
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

      <!-- Telas: dos permisos sueltos, sin atarlos a ningún oficio -->
      <div class="flex items-start gap-3 py-2">
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

      <div class="flex items-start gap-3 py-2">
        <input
          id="acceso_telas"
          type="checkbox"
          v-model="form.acceso_telas"
          class="mt-0.5 rounded border-gray-300 text-pink-600 focus:ring-pink-500"
        />
        <div>
          <label for="acceso_telas" class="text-sm font-medium text-gray-700 cursor-pointer">Puede descontar telas</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá descontar del inventario los metros que use en producción. Antes esto dependía de tener el rol "Costurero"; ahora es un permiso, para que cada empresa llame a su gente como quiera.</p>
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
      <div v-if="['vendedor', 'supervisor'].includes(arquetipo)" class="flex items-start gap-3 py-2">
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
      <div v-if="arquetipo === 'vendedor'" class="flex items-start gap-3 py-2">
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

      <!-- Compras (cualquier rol: igual que Surtir, es lo que se pidió) -->
      <div class="flex items-start gap-3 py-2">
        <input
          id="acceso_compras"
          type="checkbox"
          v-model="form.acceso_compras"
          class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
        />
        <div>
          <label for="acceso_compras" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a módulo de Compras</label>
          <p class="text-xs text-gray-500 mt-0.5">Podrá pedir lo que haga falta comprar y marcar cuando ya lo compró.</p>
        </div>
      </div>

      <!-- Reserva / Fábrica (vendedor y supervisor) -->
      <div v-if="['vendedor', 'supervisor'].includes(arquetipo)" class="flex items-start gap-3 py-2">
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
      <div v-if="arquetipo === 'vendedor'" class="flex items-start gap-3 py-2">
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


      <!-- Los pasos del taller no se dan aquí: se asignan por proceso -->
      <div class="bg-blue-50 border border-blue-200 rounded-xl px-3 py-2.5">
        <p class="text-xs text-blue-800 leading-snug">
          <span class="font-semibold">¿Va a llevar pasos del taller?</span>
          Eso ya no depende del rol. Se asigna en <strong>Producción → Procesos</strong>,
          eligiendo quién hace cada paso. Cualquiera puede estar a cargo de uno.
        </p>
      </div>

      <!-- Opciones activables solo para supervisor: ya no vienen automáticas -->
      <template v-if="arquetipo === 'supervisor'">
        <div class="flex items-start gap-3 py-2">
          <input
            id="acceso_despacho"
            type="checkbox"
            v-model="form.acceso_despacho"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="acceso_despacho" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Despacho (logística de entregas)</label>
            <p class="text-xs text-gray-500 mt-0.5">
              Asignar conductores, armar rutas y ver el historial de entregas a domicilio.
              <strong>No es el paso de producción</strong> (cuando un producto termina y pasa a listo para entrega) —
              eso ya lo tiene automático quien sea despachador o encargado de tapicería, sin necesitar esto.
            </p>
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
            <label for="acceso_produccion" class="text-sm font-medium text-gray-700 cursor-pointer">Ver Producción</label>
            <p class="text-xs text-gray-500 mt-0.5">Podrá abrir el tablero del taller y mirar en qué va cada pieza. Solo mirar.</p>
          </div>
        </div>
        <div v-if="form.acceso_produccion" class="flex items-start gap-3 py-2 pl-6">
          <input
            id="gestiona_produccion"
            type="checkbox"
            v-model="form.gestiona_produccion"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="gestiona_produccion" class="text-sm font-medium text-gray-700 cursor-pointer">…y gestionarla</label>
            <p class="text-xs text-gray-500 mt-0.5">Además podrá arrancar procesos, armar el flujo de pasos y cambiarle el estado a una pieza. Sin esto, el tablero es de solo lectura.</p>
          </div>
        </div>
        <div class="flex items-start gap-3 py-2">
          <input
            id="acceso_nomina"
            type="checkbox"
            v-model="form.acceso_nomina"
            class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
          />
          <div>
            <label for="acceso_nomina" class="text-sm font-medium text-gray-700 cursor-pointer">Acceso a Nómina</label>
            <p class="text-xs text-gray-500 mt-0.5">Podrá gestionar el pago quincenal de los trabajadores del taller.</p>
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
          <option value="">{{ arquetipo === 'supervisor' ? 'Sin tienda (jefe)' : 'Seleccionar tienda...' }}</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
        <p v-if="errores.tienda_default_id" class="text-xs text-red-600 mt-1">{{ errMsg(errores.tienda_default_id) }}</p>
        <p v-else-if="arquetipo === 'supervisor'" class="text-xs text-gray-500 mt-1">
          Déjalo en "Sin tienda" si es un jefe que no pertenece a ninguna en particular — no le tocará el reparto de comisiones de ninguna tienda.
        </p>
      </div>
      <p v-else-if="esIndependiente" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
        No se elige tienda: al ser independiente no pertenece a ninguna.
      </p>

      </template>

      <p v-if="form.no_usa_programa" class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
        Al no usar el programa no lleva tienda ni permisos. Su sueldo y cada cuánto se le paga
        se le asignan desde <span class="font-semibold">Nómina</span>, donde va a aparecer solo.
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
