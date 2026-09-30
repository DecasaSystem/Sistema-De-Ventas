<script setup>
/**
 * "Revisa la orden antes de crearla": todo lo que se va a guardar, de un
 * vistazo, para que el vendedor lo compare con lo que habló con el cliente.
 *
 * Una vez creada, cambiar el dinero ya pide aprobación de un supervisor (y a
 * los 5 días no se cambia nada), así que este es el momento de ver que el
 * precio, el descuento, el anticipo y si es FV2 están bien.
 */
import { ArrowLeftIcon, CheckIcon, UserIcon, BuildingStorefrontIcon, TagIcon, TruckIcon, CalendarIcon, ChatBubbleLeftIcon, CameraIcon, PencilIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  show:          { type: Boolean, default: false },
  resumen:       { type: Object, required: true },
  textoConfirmar: { type: String, default: 'Confirmar y crear' },
})
const emit = defineEmits(['volver', 'confirmar'])

const pesos = (n) => '$' + Math.round(Number(n) || 0).toLocaleString('es-CO')
const fecha = (iso) => iso
  ? new Date(`${String(iso).slice(0, 10)}T12:00:00`).toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' })
  : null
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="show" class="fixed inset-0 z-[80] flex items-end sm:items-center justify-center" @click.self="emit('volver')">
        <div class="absolute inset-0 bg-black/50" @click="emit('volver')" />

        <div class="relative w-full sm:max-w-lg max-h-[94dvh] bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col">
          <!-- Encabezado -->
          <div class="px-5 pt-5 pb-4 border-b border-gray-100 flex-shrink-0">
            <h3 class="text-lg font-bold text-gray-900">Revisa la orden antes de crearla</h3>
            <p class="text-xs text-gray-500 mt-0.5">
              Después, los cambios de dinero los aprueba un supervisor. Si algo no cuadra, vuelve y corrígelo.
            </p>
          </div>

          <div class="overflow-y-auto px-5 py-4 space-y-4 flex-1 min-h-0">
            <!-- FV2: lo primero, porque cambia la numeración y la comisión -->
            <div v-if="resumen.fv2" class="rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3">
              <p class="text-sm font-bold text-amber-900">Orden con descuento especial (FV2)</p>
              <p v-if="resumen.fv2.motivo" class="text-xs text-amber-800 mt-0.5">Motivo: {{ resumen.fv2.motivo }}</p>
              <p v-if="resumen.fv2.sinIva" class="text-xs text-amber-800 mt-0.5">Sin descontar IVA.</p>
            </div>

            <!-- Cliente y dónde -->
            <section class="grid grid-cols-1 sm:grid-cols-2 gap-2">
              <div class="flex items-start gap-2.5 rounded-xl bg-gray-50 px-3 py-2.5">
                <UserIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <div class="min-w-0">
                  <p class="text-[11px] text-gray-500">Cliente</p>
                  <p class="text-sm font-semibold text-gray-900 truncate">{{ resumen.cliente.nombre }}</p>
                  <p class="text-xs text-gray-500">
                    {{ [resumen.cliente.telefono, resumen.cliente.cedula ? `CC ${resumen.cliente.cedula}` : null].filter(Boolean).join(' · ') }}
                  </p>
                </div>
              </div>
              <div class="flex items-start gap-2.5 rounded-xl bg-gray-50 px-3 py-2.5">
                <BuildingStorefrontIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <div class="min-w-0">
                  <p class="text-[11px] text-gray-500">Tienda · canal</p>
                  <p class="text-sm font-semibold text-gray-900">{{ resumen.tienda }}</p>
                  <p class="text-xs text-gray-500">{{ resumen.canal }}</p>
                </div>
              </div>
            </section>
            <p v-if="resumen.compartida" class="text-xs text-indigo-800 bg-indigo-50 rounded-lg px-3 py-2">
              Venta compartida con <span class="font-semibold">{{ resumen.compartida }}</span>.
            </p>
            <p v-if="resumen.abonadaA" class="text-xs text-indigo-800 bg-indigo-50 rounded-lg px-3 py-2">
              La mitad de la venta se le abona a <span class="font-semibold">{{ resumen.abonadaA }}</span>.
            </p>

            <!-- Productos -->
            <section>
              <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                Productos ({{ resumen.productos.length }})
              </p>
              <ul class="rounded-xl border border-gray-200 divide-y divide-gray-100">
                <li v-for="(p, i) in resumen.productos" :key="i" class="px-3 py-2.5">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <p class="text-sm font-semibold text-gray-900">{{ p.cantidad }} × {{ p.nombre }}</p>
                      <p v-if="p.variante" class="text-xs text-gray-500">{{ p.variante }}</p>
                      <p v-if="p.detalle" class="text-xs text-gray-500">{{ p.detalle }}</p>
                    </div>
                    <div class="text-right flex-shrink-0">
                      <p v-if="p.subtotal === null" class="text-sm font-semibold text-violet-700">Por consultar</p>
                      <template v-else>
                        <p class="text-sm font-bold text-gray-900">{{ pesos(p.subtotal) }}</p>
                        <p class="text-[11px] text-gray-500">
                          <span v-if="p.rebaja > 0" class="line-through mr-1">{{ pesos(p.precio) }}</span>{{ pesos(p.final) }} c/u
                        </p>
                      </template>
                    </div>
                  </div>
                  <div v-if="p.etiquetas.length" class="flex flex-wrap gap-1 mt-1.5">
                    <span v-for="e in p.etiquetas" :key="e"
                      class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-gray-700 px-2 py-0.5 text-[11px] font-medium">
                      <TagIcon class="w-3 h-3" />{{ e }}
                    </span>
                  </div>
                </li>
              </ul>
            </section>

            <!-- Plata -->
            <section class="rounded-xl bg-gray-50 px-4 py-3 space-y-1.5 text-sm">
              <div class="flex justify-between"><span class="text-gray-600">Subtotal</span><span class="font-medium">{{ pesos(resumen.subtotal) }}</span></div>
              <div v-if="resumen.descuento > 0" class="flex justify-between text-green-700">
                <span>Descuento</span><span class="font-medium">− {{ pesos(resumen.descuento) }}</span>
              </div>
              <div v-if="resumen.descuentoCondicionado > 0" class="flex justify-between text-green-700">
                <span>Descuento por pago en efectivo o transferencia</span><span class="font-medium">− {{ pesos(resumen.descuentoCondicionado) }}</span>
              </div>
              <div class="flex justify-between text-base font-bold border-t border-gray-200 pt-2">
                <span>Total</span><span class="text-blue-700">{{ pesos(resumen.total) }}</span>
              </div>
              <p v-if="resumen.hayCotizar" class="text-xs text-violet-700">
                Hay productos con precio por consultar<span v-if="resumen.consultaA"> (se le pregunta a {{ resumen.consultaA }})</span>: el total cambia cuando respondan.
              </p>

              <div class="border-t border-gray-200 pt-2 space-y-1">
                <template v-if="resumen.abonos.length">
                  <div v-for="(a, i) in resumen.abonos" :key="i" class="flex justify-between">
                    <span class="text-gray-600">Anticipo · {{ a.metodo }}</span><span class="font-medium">{{ pesos(a.monto) }}</span>
                  </div>
                </template>
                <div v-else class="flex justify-between"><span class="text-gray-600">Anticipo</span><span class="font-medium text-gray-500">Sin anticipo</span></div>
                <div class="flex justify-between font-semibold">
                  <span>Queda debiendo</span><span :class="resumen.saldo > 0 ? 'text-red-600' : 'text-green-700'">{{ pesos(resumen.saldo) }}</span>
                </div>
              </div>
            </section>

            <!-- Entrega y lo demás -->
            <section class="space-y-2 text-sm">
              <div class="flex items-start gap-2.5">
                <CalendarIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <p><span class="text-gray-500">Entrega prometida:</span>
                  <span :class="resumen.fechaEntrega ? 'font-medium text-gray-900' : 'text-amber-700'"> {{ fecha(resumen.fechaEntrega) ?? 'sin fecha' }}</span></p>
              </div>
              <div v-if="resumen.seLlevaTodo || resumen.seLlevaAlgo" class="flex items-start gap-2.5">
                <TruckIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <p class="font-medium text-gray-900">{{ resumen.seLlevaTodo ? 'Se lo lleva todo hoy (venta directa, entregada).' : 'Se lleva hoy lo marcado.' }}</p>
              </div>
              <div v-if="resumen.envio" class="flex items-start gap-2.5">
                <TruckIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <p><span class="text-gray-500">Enviar a:</span> <span class="text-gray-900">{{ resumen.envio }}</span></p>
              </div>
              <div v-if="resumen.notas" class="flex items-start gap-2.5">
                <ChatBubbleLeftIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <p class="whitespace-pre-line"><span class="text-gray-500">Notas:</span> <span class="text-gray-900">{{ resumen.notas }}</span></p>
              </div>
              <div class="flex items-start gap-2.5">
                <CameraIcon class="w-4 h-4 text-gray-400 mt-0.5 flex-shrink-0" />
                <p class="text-gray-700">
                  {{ resumen.fotosFactura }} foto{{ resumen.fotosFactura === 1 ? '' : 's' }} del comprobante
                  <span class="text-gray-400">·</span>
                  <PencilIcon class="w-3.5 h-3.5 inline -mt-0.5 text-gray-400" />
                  {{ resumen.firma ? 'firmada por el cliente' : 'sin firma todavía' }}
                </p>
              </div>
            </section>
          </div>

          <!-- Botones -->
          <div class="px-5 pt-3 pb-5 border-t border-gray-100 flex gap-3 flex-shrink-0">
            <button @click="emit('volver')"
              class="flex-1 flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 bg-white text-gray-700 py-3 text-sm font-semibold hover:bg-gray-50">
              <ArrowLeftIcon class="w-4 h-4" /> Volver a revisar
            </button>
            <button @click="emit('confirmar')"
              class="flex-[1.4] flex items-center justify-center gap-1.5 rounded-xl bg-blue-600 text-white py-3 text-sm font-bold hover:bg-blue-700">
              <CheckIcon class="w-4 h-4" /> {{ textoConfirmar }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
