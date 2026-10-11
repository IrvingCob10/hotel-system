<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { getDailyHistory, type DailyHistoryResponse, type DailyActivity } from '@/services/daily-history'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const todayLocal = () => {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const selectedDate = ref(String(route.query.date ?? todayLocal()))
const roomFilter = ref(String(route.query.room ?? ''))
const searchText = ref('')
const page = ref(1)
const loading = ref(false)
const errorMessage = ref('')
const result = ref<DailyHistoryResponse | null>(null)

const currencyFormatter = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
})
const formatCurrency = (value: number | string | null | undefined) =>
  currencyFormatter.format(Number(value ?? 0))

const formatDateTime = (value: string | null | undefined) => {
  if (!value) return 'No registrado'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Fecha no disponible'
  return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

const formatDateTitle = computed(() => {
  if (!selectedDate.value) return 'Selecciona una fecha'
  const [year, month, day] = selectedDate.value.split('-').map(Number)
  if (!year || !month || !day) return selectedDate.value
  return new Intl.DateTimeFormat('es-MX', { dateStyle: 'full' }).format(new Date(year, month - 1, day, 12))
})

const setDateAndLoad = (date: string) => {
  selectedDate.value = date
  page.value = 1
  void loadHistory()
}

const selectToday = () => setDateAndLoad(todayLocal())

const yesterdayLocal = () => {
  const date = new Date()
  date.setDate(date.getDate() - 1)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const selectYesterday = () => setDateAndLoad(yesterdayLocal())

const activityTypeLabel = (type: DailyActivity['type']) => ({
  check_in: 'Check-in',
  check_out: 'Check-out',
  charge: 'Cargo',
  payment: 'Pago',
}[type])

const activityStyle = (type: DailyActivity['type']) => ({
  check_in: 'bg-emerald-50 text-emerald-700',
  check_out: 'bg-blue-50 text-blue-700',
  charge: 'bg-amber-50 text-amber-700',
  payment: 'bg-violet-50 text-violet-700',
}[type])

const loadHistory = async () => {
  const hotelId = auth.currentHotelId

  // No consultar un hotel por defecto: cada dueño debe consultar exclusivamente
  // el historial del hotel asociado a su sesión.
  if (!hotelId || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    result.value = null
    errorMessage.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente.'
    loading.value = false
    return
  }

  if (!selectedDate.value) {
    errorMessage.value = 'Selecciona una fecha para consultar el historial.'
    result.value = null
    loading.value = false
    return
  }

  loading.value = true
  errorMessage.value = ''
  try {
    result.value = await getDailyHistory({
      hotelId,
      date: selectedDate.value,
      room: roomFilter.value,
      search: searchText.value,
      page: page.value,
      perPage: 20,
    })
  } catch (error) {
    console.error('Error al consultar el historial del hotel activo:', error)
    result.value = null
    const response = (error as { response?: { data?: { message?: string } } })?.response
    errorMessage.value = response?.data?.message
      ?? 'No fue posible consultar el historial. Verifica la conexión con la API y los permisos de tu cuenta.'
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  page.value = 1
  void loadHistory()
}

const goToPage = (nextPage: number) => {
  const lastPage = result.value?.pagination.last_page ?? 1
  if (nextPage < 1 || nextPage > lastPage || loading.value) return
  page.value = nextPage
  void loadHistory()
}

// Carga el historial cuando inicia la vista y vuelve a cargarlo si cambia
// el hotel autenticado. Evita mantener datos del hotel anterior en pantalla.
watch(
  () => auth.currentHotelId,
  () => {
    result.value = null
    page.value = 1
    void loadHistory()
  },
  { immediate: true },
)
</script>

<template>
  <div class="space-y-4 text-sm text-gray-800">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-start gap-3">
        <RouterLink
          :to="{ name: 'dashboard' }"
          class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-lg text-gray-600 hover:bg-gray-50"
          aria-label="Regresar al dashboard"
        >←</RouterLink>
        <div>
          <h1 class="text-xl font-bold tracking-tight text-gray-900">Historial del día</h1>
          <p class="mt-1 text-xs text-gray-500">Movimientos operativos y financieros del hotel · {{ formatDateTitle }}</p>
        </div>
      </div>
      <button
        type="button"
        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-60"
        :disabled="loading"
        @click="loadHistory"
      >{{ loading ? 'Actualizando…' : 'Actualizar' }}</button>
    </header>

    <div class="flex flex-wrap items-center gap-2">
      <span class="mr-1 text-xs text-gray-500">Consulta rápida:</span>
      <button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition" :class="selectedDate === todayLocal() ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'" :disabled="loading" @click="selectToday">Hoy</button>
      <button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition" :class="selectedDate === yesterdayLocal() ? 'border-blue-200 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'" :disabled="loading" @click="selectYesterday">Ayer</button>
    </div>

    <form class="grid grid-cols-1 gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm md:grid-cols-4" @submit.prevent="applyFilters">
      <div>
        <label for="history-date" class="mb-1 block text-[11px] font-medium text-gray-600">Fecha</label>
        <input id="history-date" v-model="selectedDate" :max="todayLocal()" type="date" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" required />
      </div>
      <div>
        <label for="history-room" class="mb-1 block text-[11px] font-medium text-gray-600">Habitación</label>
        <input id="history-room" v-model.trim="roomFilter" type="text" placeholder="Ej. 101" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" />
      </div>
      <div>
        <label for="history-search" class="mb-1 block text-[11px] font-medium text-gray-600">Huésped o teléfono</label>
        <input id="history-search" v-model.trim="searchText" type="text" placeholder="Nombre o teléfono" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" />
      </div>
      <div class="flex items-end">
        <button type="submit" class="w-full rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-60" :disabled="loading">
          {{ loading ? 'Consultando…' : 'Aplicar filtros' }}
        </button>
      </div>
    </form>

    <div v-if="errorMessage" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-700">
      {{ errorMessage }}
      <button type="button" class="ml-2 font-semibold underline" @click="loadHistory">Reintentar</button>
    </div>

    <div v-if="loading && !result" class="rounded-xl border border-gray-200 bg-white p-8 text-center text-xs text-gray-500">Cargando movimientos…</div>

    <template v-if="result">
      <section class="grid grid-cols-2 gap-2 xl:grid-cols-7">
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Check-ins</p>
          <p class="mt-1 text-xl font-bold text-gray-900">{{ result.summary.check_ins }}</p>
        </article>
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Check-outs</p>
          <p class="mt-1 text-xl font-bold text-gray-900">{{ result.summary.check_outs }}</p>
        </article>
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Cargos registrados hoy</p>
          <p class="mt-1 text-xl font-bold text-amber-700">{{ formatCurrency(result.summary.charges_total) }}</p>
        </article>
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Pagos recibidos hoy</p>
          <p class="mt-1 text-xl font-bold text-emerald-700">{{ formatCurrency(result.summary.payments_total) }}</p>
        </article>
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Efectivo recibido (bruto)</p>
          <p class="mt-1 text-xl font-bold text-blue-700">{{ formatCurrency(result.summary.cash_received_total) }}</p>
        </article>
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Cambio entregado</p>
          <p class="mt-1 text-xl font-bold text-orange-700">{{ formatCurrency(result.summary.change_given_total) }}</p>
        </article>
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-3 shadow-sm">
          <p class="text-[11px] text-gray-500">Efectivo neto en caja</p>
          <p class="mt-1 text-xl font-bold text-teal-700">{{ formatCurrency(result.summary.cash_received_total - result.summary.change_given_total) }}</p>
        </article>
      </section>

      <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3">
          <div>
            <h2 class="font-bold text-gray-900">Actividad del hotel</h2>
            <p class="mt-0.5 text-[11px] text-gray-500">Cada tarjeta agrupa los movimientos del día por estancia. Los registros históricos se conservan sin llenar la ficha de habitación.</p>
          </div>
          <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-medium text-gray-600">{{ result.pagination.total }} estancias con actividad</span>
        </div>

        <div v-if="result.data.length" class="divide-y divide-gray-100">
          <article v-for="stay in result.data" :key="stay.id" class="px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-bold text-gray-800">Habitación {{ stay.room_number }}</span>
                  <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="stay.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">{{ stay.status === 'active' ? 'Activa' : stay.status === 'completed' ? 'Cerrada' : stay.status }}</span>
                </div>
                <p class="mt-1.5 text-sm font-semibold text-gray-900">{{ stay.guest_name }}</p>
                <p class="mt-0.5 text-[11px] text-gray-500">Entrada {{ formatDateTime(stay.check_in_at) }} <span class="mx-1">·</span> Salida {{ formatDateTime(stay.actual_check_out_at ?? stay.expected_check_out_at) }}</p>
              </div>
              <div class="grid grid-cols-2 gap-x-5 gap-y-1 text-right sm:grid-cols-3">
                <div>
                  <p class="text-[10px] text-gray-500">Cargos del día</p>
                  <p class="text-xs font-bold text-gray-900">{{ formatCurrency(stay.day_charges_total) }}</p>
                </div>
                <div>
                  <p class="text-[10px] text-gray-500">Pagos del día</p>
                  <p class="text-xs font-bold text-emerald-700">{{ formatCurrency(stay.day_paid_total) }}</p>
                </div>
                <div>
                  <p class="text-[10px] text-gray-500">Saldo de estancia</p>
                  <p class="text-xs font-bold" :class="stay.account.balance > 0 ? 'text-red-600' : 'text-emerald-700'">{{ formatCurrency(stay.account.balance) }}</p>
                </div>
              </div>
            </div>

            <details class="mt-3 rounded-lg border border-gray-100 bg-gray-50">
              <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100">
                <span>Ver movimientos de esta estancia ({{ stay.activities.length }})</span>
                <span class="text-gray-400">＋</span>
              </summary>
              <div class="max-h-64 overflow-y-auto border-t border-gray-200">
                <div v-if="stay.activities.length" class="divide-y divide-gray-200">
                  <div v-for="activity in stay.activities" :key="activity.id" class="flex items-start justify-between gap-3 px-3 py-2.5">
                    <div class="flex min-w-0 items-start gap-2">
                      <span class="mt-0.5 rounded px-1.5 py-0.5 text-[9px] font-semibold" :class="activityStyle(activity.type)">{{ activityTypeLabel(activity.type) }}</span>
                      <div class="min-w-0">
                        <p class="text-xs font-medium text-gray-900">{{ activity.title }}</p>
                        <p v-if="activity.description" class="mt-0.5 break-words text-[10px] text-gray-500">{{ activity.description }}</p>
                        <p class="mt-0.5 text-[10px] text-gray-400">{{ formatDateTime(activity.occurred_at) }}</p>
                      </div>
                    </div>
                    <p v-if="activity.amount !== null" class="shrink-0 text-xs font-semibold" :class="activity.type === 'payment' ? 'text-emerald-700' : 'text-gray-800'">{{ formatCurrency(activity.amount) }}</p>
                  </div>
                </div>
                <p v-else class="px-3 py-4 text-xs text-gray-500">No hay movimientos detallados para esta estancia en la fecha seleccionada.</p>
              </div>
            </details>
          </article>
        </div>
        <div v-else class="px-4 py-12 text-center">
          <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-xl text-gray-500">📭</div>
          <p class="font-semibold text-gray-900">No hay movimientos para esta fecha</p>
          <p class="mt-1 text-xs text-gray-500">La pantalla queda vacía cuando no hay actividad. Puedes consultar ayer o elegir cualquier fecha anterior.</p>
          <button type="button" class="mt-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" :disabled="loading || selectedDate === todayLocal()" @click="selectToday">Volver a hoy</button>
        </div>

        <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 bg-gray-50 px-4 py-3">
          <p class="text-[11px] text-gray-500">Página {{ result.pagination.current_page }} de {{ result.pagination.last_page }} · {{ result.pagination.total }} estancias</p>
          <div class="flex gap-2">
            <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40" :disabled="loading || result.pagination.current_page <= 1" @click="goToPage(result.pagination.current_page - 1)">Anterior</button>
            <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-40" :disabled="loading || result.pagination.current_page >= result.pagination.last_page" @click="goToPage(result.pagination.current_page + 1)">Siguiente</button>
          </div>
        </footer>
      </section>
    </template>
  </div>
</template>
