<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { getDepartureAlerts, type DepartureAlert } from '@/services/departure-alerts'

const props = defineProps<{ hotelId?: number }>()
const auth = useAuthStore()
const effectiveHotelId = computed<number | null>(() => {
  const candidate = props.hotelId ?? auth.currentHotelId
  return Number.isSafeInteger(candidate) && Number(candidate) > 0 ? Number(candidate) : null
})

const isOpen = ref(false)
const loading = ref(false)
const loadedOnce = ref(false)
const errorMessage = ref('')
const alerts = ref<DepartureAlert[]>([])
const lastUpdated = ref('')
let refreshTimer: ReturnType<typeof setInterval> | undefined
let requestSequence = 0

const storageKeyFor = (hotelId: number | null) => hotelId ? `hotel:${hotelId}:departure-alerts:read:v1` : null
const loadReadAlertKeys = (hotelId: number | null): string[] => {
  const key = storageKeyFor(hotelId)
  if (!key) return []
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return []
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string') : []
  } catch { return [] }
}
const readAlertKeys = ref<string[]>(loadReadAlertKeys(effectiveHotelId.value))
const alertIdentity = (alert: DepartureAlert) => `${alert.stay_id}:${alert.type}:${alert.expected_check_out_at}`
const saveReadAlertKeys = () => {
  const key = storageKeyFor(effectiveHotelId.value)
  if (!key) return
  try { localStorage.setItem(key, JSON.stringify(readAlertKeys.value)) }
  catch (error) { console.warn('No se pudo guardar el estado de lectura de las alertas:', error) }
}
const markCurrentAlertsAsRead = () => {
  const currentKeys = alerts.value.map(alertIdentity)
  readAlertKeys.value = [...new Set([...readAlertKeys.value, ...currentKeys])]
  saveReadAlertKeys()
}
const overdueCount = computed(() => alerts.value.filter(alert => alert.type === 'overdue').length)
const upcomingCount = computed(() => alerts.value.filter(alert => alert.type === 'upcoming').length)
const unreadCount = computed(() => {
  const read = new Set(readAlertKeys.value)
  return alerts.value.filter(alert => !read.has(alertIdentity(alert))).length
})
const timeFormatter = new Intl.DateTimeFormat('es-MX', { hour: 'numeric', minute: '2-digit' })
const dateTimeFormatter = new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' })
const currencyFormatter = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })
const formatCheckoutTime = (value: string) => {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? 'Hora no disponible' : timeFormatter.format(date)
}
const formatCurrency = (value: number) => currencyFormatter.format(value)
const relativeLabel = (alert: DepartureAlert) => {
  if (alert.type === 'overdue') {
    if (alert.minutes_overdue < 1) return 'Salida vencida'
    if (alert.minutes_overdue < 60) return `Vencida hace ${alert.minutes_overdue} min`
    const hours = Math.floor(alert.minutes_overdue / 60)
    const minutes = alert.minutes_overdue % 60
    return minutes ? `Vencida hace ${hours} h ${minutes} min` : `Vencida hace ${hours} h`
  }
  if (alert.minutes_until_checkout < 1) return 'La salida vence ahora'
  if (alert.minutes_until_checkout === 1) return 'Falta 1 minuto'
  return `Faltan ${alert.minutes_until_checkout} min`
}

async function refreshAlerts(quiet = false): Promise<void> {
  const hotelId = effectiveHotelId.value
  const sequence = ++requestSequence
  if (!hotelId) {
    alerts.value = []
    errorMessage.value = ''
    loadedOnce.value = false
    loading.value = false
    lastUpdated.value = ''
    return
  }
  loading.value = true
  if (!quiet) errorMessage.value = ''
  try {
    const result = await getDepartureAlerts(hotelId)
    // Ignorar respuestas atrasadas de otro hotel después de cambiar de sesión.
    if (sequence !== requestSequence || hotelId !== effectiveHotelId.value) return
    alerts.value = result.alerts
    const currentKeys = result.alerts.map(alertIdentity)
    const currentKeySet = new Set(currentKeys)
    const retainedReadKeys = readAlertKeys.value.filter(key => currentKeySet.has(key))
    readAlertKeys.value = isOpen.value ? [...new Set([...retainedReadKeys, ...currentKeys])] : retainedReadKeys
    saveReadAlertKeys()
    lastUpdated.value = new Intl.DateTimeFormat('es-MX', { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(new Date())
    errorMessage.value = ''
    loadedOnce.value = true
  } catch (error) {
    if (sequence !== requestSequence) return
    console.error('No se pudieron consultar las alertas de salida:', error)
    if (!loadedOnce.value || !quiet) errorMessage.value = 'No fue posible consultar las alertas. Comprueba tu sesión y la conexión con el servidor.'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}
async function togglePanel() {
  isOpen.value = !isOpen.value
  if (isOpen.value) { await refreshAlerts(); markCurrentAlertsAsRead() }
}
function closePanel() { isOpen.value = false }

watch(effectiveHotelId, (hotelId) => {
  // Aislar estado y conteos de lectura entre hotel A y hotel B.
  requestSequence++
  alerts.value = []
  errorMessage.value = ''
  loadedOnce.value = false
  lastUpdated.value = ''
  readAlertKeys.value = loadReadAlertKeys(hotelId)
  void refreshAlerts()
}, { immediate: true })

onMounted(() => { refreshTimer = setInterval(() => void refreshAlerts(true), 60_000) })
onBeforeUnmount(() => { requestSequence++; if (refreshTimer) clearInterval(refreshTimer) })
</script>

<template>
  <div v-if="effectiveHotelId !== null" class="relative">
    <button type="button" class="relative flex h-10 w-10 items-center justify-center rounded-full text-xl text-gray-600 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500" :aria-label="unreadCount > 0 ? `Alertas de salida, ${unreadCount} sin revisar` : 'Alertas de salida'" :aria-expanded="isOpen" @click="togglePanel">
      <span aria-hidden="true">🔔</span>
      <span v-if="unreadCount > 0" class="absolute -right-0.5 -top-0.5 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white ring-2 ring-white">{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
    </button>
    <div v-if="isOpen" class="absolute right-0 top-12 z-50 w-[min(92vw,390px)] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl" role="dialog" aria-label="Alertas de salida">
      <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-4 py-3"><div><h2 class="text-sm font-bold text-gray-900">Alertas de salida</h2><p class="mt-0.5 text-xs text-gray-500">Próximos 60 minutos y salidas vencidas</p><p v-if="unreadCount > 0" class="mt-1 text-[11px] font-semibold text-red-600">{{ unreadCount }} nueva(s) sin revisar</p></div><button type="button" class="rounded-md px-2 py-1 text-xs font-semibold text-blue-700 hover:bg-blue-50 disabled:opacity-50" :disabled="loading" @click="refreshAlerts()">{{ loading ? 'Actualizando…' : 'Actualizar' }}</button></div>
      <div class="grid grid-cols-2 gap-2 border-b border-gray-100 bg-gray-50 px-4 py-2.5"><div><p class="text-[11px] text-gray-500">Próximas</p><p class="text-sm font-bold text-amber-700">{{ upcomingCount }}</p></div><div><p class="text-[11px] text-gray-500">Vencidas</p><p class="text-sm font-bold text-red-700">{{ overdueCount }}</p></div></div>
      <div v-if="errorMessage" class="m-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">{{ errorMessage }}</div>
      <div v-else-if="!loadedOnce && loading" class="px-5 py-8 text-center text-sm text-gray-500">Consultando estancias activas…</div>
      <div v-else-if="!alerts.length && loadedOnce" class="px-5 py-8 text-center"><p class="text-2xl" aria-hidden="true">✅</p><p class="mt-2 text-sm font-semibold text-gray-800">Sin alertas de salida</p><p class="mt-1 text-xs text-gray-500">No hay salidas previstas en los próximos 60 minutos ni estancias vencidas.</p></div>
      <div v-else-if="alerts.length" class="max-h-[60vh] divide-y divide-gray-100 overflow-y-auto"><article v-for="alert in alerts" :key="alert.stay_id" class="px-4 py-3"><div class="flex items-start gap-3"><span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm" :class="alert.type === 'overdue' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'" aria-hidden="true">{{ alert.type === 'overdue' ? '!' : '◷' }}</span><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><p class="text-sm font-bold text-gray-900">Habitación {{ alert.room_number }}</p><span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="alert.type === 'overdue' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-800'">{{ alert.type === 'overdue' ? 'Vencida' : 'Próxima' }}</span></div><p class="mt-1 truncate text-xs font-medium text-gray-800">{{ alert.guest_name }}</p><p class="mt-0.5 text-xs text-gray-500">Salida prevista: {{ formatCheckoutTime(alert.expected_check_out_at) }} · {{ relativeLabel(alert) }}</p><p v-if="alert.balance > 0" class="mt-1 text-xs font-semibold text-red-700">Saldo pendiente: {{ formatCurrency(alert.balance) }}</p><p v-else class="mt-1 text-xs font-medium text-emerald-700">Cuenta liquidada</p><RouterLink :to="{ name: 'room-detail', params: { id: alert.room_number } }" class="mt-2 inline-flex text-xs font-semibold text-blue-700 hover:text-blue-900 hover:underline" @click="closePanel">Abrir habitación →</RouterLink></div></div></article></div>
      <div class="border-t border-gray-100 px-4 py-2"><p class="text-[10px] text-gray-400">Actualización automática cada minuto<span v-if="lastUpdated"> · Última consulta {{ lastUpdated }}</span></p></div>
    </div>
  </div>
</template>
