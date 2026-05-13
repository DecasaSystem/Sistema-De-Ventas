<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useDespachoStore } from '@/stores/despacho'
import { useDespachoSocket } from '@/composables/useDespachoSocket'
import { misEntregas } from '@/api/despacho'
import EntregaDetalleModal from '@/components/despacho/EntregaDetalleModal.vue'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const despacho = useDespachoStore()
const socket   = useDespachoSocket()

const entregas = ref([])
const cargando = ref(true)
const itemActivo = ref(null)
const error = ref('')

onMounted(async () => {
  await cargar()
  socket.conectar()
})

onBeforeUnmount(() => {
  socket.desconectar()
})

async function cargar() {
  cargando.value = true
  error.value = ''
  try {
    const { data } = await misEntregas()
    entregas.value = data
  } catch (e) {
    error.value = e.response?.data?.message || 'Error al cargar las entregas'
  } finally {
    cargando.value = false
  }
}

function abrirDetalle(item) {
  itemActivo.value = item
}

function cerrarDetalle() {
  itemActivo.value = null
}

async function trasEntregar() {
  await cargar()
}
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-bold text-gray-900">Mis Entregas</h1>
      <span v-if="entregas.length > 0" class="text-sm text-gray-500">
        {{ entregas.length }} pendiente(s)
      </span>
    </div>

    <div v-if="cargando" class="text-center py-8 text-sm text-gray-400">Cargando entregas...</div>

    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">{{ error }}</div>

    <template v-else-if="entregas.length === 0">
      <EmptyState message="No tienes entregas asignadas" />
    </template>

    <template v-else>
      <div class="space-y-3">
        <div
          v-for="item in entregas"
          :key="item.id"
          @click="abrirDetalle(item)"
          class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 active:scale-[0.98] transition-transform cursor-pointer"
        >
          <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-bold">
              {{ item.posicion }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-center justify-between">
                <p class="font-semibold text-gray-900 truncate">{{ item.orden?.cliente?.nombre }}</p>
                <BadgeEstado :estado="item.estado" />
              </div>
              <p class="text-xs text-gray-500 mt-0.5">{{ item.orden?.cliente?.telefono }}</p>
              <p class="text-xs text-gray-500 truncate">{{ item.orden?.cliente?.direccion }}</p>
              <div class="flex items-center gap-3 mt-2 text-sm">
                <span class="text-gray-600">
                  <MoneyDisplay :amount="item.orden?.valor_total" />
                </span>
                <span v-if="item.orden?.saldo_pendiente > 0" class="text-orange-600 text-xs">
                  Saldo: <MoneyDisplay :amount="item.orden?.saldo_pendiente" />
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Modal de detalle de entrega -->
    <EntregaDetalleModal
      v-if="itemActivo"
      :despacho-item-id="itemActivo.id"
      @cerrar="cerrarDetalle"
      @entregado="trasEntregar"
    />
  </div>
</template>
