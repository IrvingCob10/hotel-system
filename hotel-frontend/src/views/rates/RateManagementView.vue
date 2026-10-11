<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  createHotelRate,
  getHotelRates,
  updateHotelRate,
  type HotelRoomRate,
  type UpdateRoomRatePayload,
} from '@/services/room-rates'

// Catálogo global limitado al hotel activo del usuario autenticado.
const auth = useAuthStore()

const rates = ref<HotelRoomRate[]>([])
const loading = ref(true)
const saving = ref(false)
const busyRateId = ref<number | null>(null)
const pageError = ref('')
const pageSuccess = ref('')
const formError = ref('')
const isModalOpen = ref(false)
const editingRateId = ref<number | null>(null)
const searchText = ref('')
const statusFilter = ref('all')

const form = reactive({
  name: '',
  duration: '24',
  unit: 'horas',
  price: '',
  active: true,
})

const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' })
const formatCurrency = (value: number | string | null | undefined) => money.format(Number(value ?? 0))
const activeCount = computed(() => rates.value.filter(rate => rate.active).length)
const inactiveCount = computed(() => rates.value.filter(rate => !rate.active).length)

const filteredRates = computed(() => {
  const query = searchText.value.trim().toLocaleLowerCase('es-MX')
  return rates.value.filter(rate => {
    const matchesStatus = statusFilter.value === 'all'
      || (statusFilter.value === 'active' ? rate.active : !rate.active)
    const searchable = `${rate.name} ${rate.duration} ${rate.unit}`.toLocaleLowerCase('es-MX')
    return matchesStatus && (!query || searchable.includes(query))
  })
})

const getErrorMessage = (error: unknown) => {
  const response = (error as {
    response?: { data?: { message?: string; errors?: Record<string, string[]> } }
  })?.response
  const errors = response?.data?.errors
  return errors?.name?.[0]
    ?? errors?.duration?.[0]
    ?? errors?.unit?.[0]
    ?? errors?.price?.[0]
    ?? response?.data?.message
    ?? 'No fue posible completar la operación. Verifica la conexión con el servidor.'
}

const loadData = async () => {
  loading.value = true
  pageError.value = ''
  const hotelId = auth.currentHotelId
  if (hotelId === null || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    rates.value = []
    pageError.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente.'
    loading.value = false
    return
  }
  try {
    rates.value = (await getHotelRates(hotelId))
      .filter(rate => !rate.name.startsWith('MANUAL#') && !rate.name.startsWith('LEGACY#'))
      .sort((a, b) => Number(b.active) - Number(a.active) || a.duration - b.duration || a.name.localeCompare(b.name, 'es-MX'))
  } catch (error) {
    console.error('Error al cargar el catálogo global de tarifas:', error)
    pageError.value = getErrorMessage(error)
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.name = ''
  form.duration = '24'
  form.unit = 'horas'
  form.price = ''
  form.active = true
  formError.value = ''
}

const openCreateModal = () => {
  editingRateId.value = null
  resetForm()
  pageSuccess.value = ''
  isModalOpen.value = true
}

const openEditModal = (rate: HotelRoomRate) => {
  editingRateId.value = rate.id
  form.name = rate.name
  form.duration = String(rate.duration)
  form.unit = rate.unit
  form.price = String(rate.price)
  form.active = rate.active
  formError.value = ''
  pageSuccess.value = ''
  isModalOpen.value = true
}

const closeModal = () => {
  if (saving.value) return
  isModalOpen.value = false
  formError.value = ''
}

const formIsValid = computed(() => {
  const duration = Number(form.duration)
  const price = Number(form.price)
  return form.name.trim().length > 0
    && form.name.trim().length <= 100
    && Number.isInteger(duration)
    && duration >= 1
    && duration <= 10000
    && ['minutos', 'horas', 'días', 'noches', 'semanas'].includes(form.unit)
    && Number.isFinite(price)
    && price > 0
    && price <= 99999999.99
})

const submitForm = async () => {
  formError.value = ''
  pageError.value = ''
  pageSuccess.value = ''
  if (!formIsValid.value) {
    formError.value = 'Completa el concepto, la duración, la unidad y un precio válido.'
    return
  }

  const hotelId = auth.currentHotelId
  if (hotelId === null || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    formError.value = 'Tu usuario no tiene un hotel activo asignado. Inicia sesión nuevamente.'
    return
  }

  saving.value = true
  try {
    const payload = {
      name: form.name.trim(),
      duration: Number(form.duration),
      unit: form.unit,
      price: Math.round(Number(form.price) * 100) / 100,
    }

    if (editingRateId.value !== null) {
      const updatePayload: UpdateRoomRatePayload = { ...payload, active: form.active }
      await updateHotelRate(hotelId, editingRateId.value, updatePayload)
      pageSuccess.value = 'Tarifa actualizada. El cambio solo aplica a nuevos check-ins; las estancias existentes conservan el precio contratado.'
    } else {
      await createHotelRate(hotelId, { ...payload, active: form.active })
      pageSuccess.value = 'Tarifa creada para todas las habitaciones de este hotel.'
    }

    isModalOpen.value = false
    await loadData()
  } catch (error) {
    console.error('Error al guardar tarifa global:', error)
    formError.value = getErrorMessage(error)
  } finally {
    saving.value = false
  }
}

const toggleRate = async (rate: HotelRoomRate) => {
  const hotelId = auth.currentHotelId
  if (hotelId === null || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    pageError.value = 'Tu usuario no tiene un hotel activo asignado. Inicia sesión nuevamente.'
    return
  }

  busyRateId.value = rate.id
  pageError.value = ''
  pageSuccess.value = ''
  try {
    const updated = await updateHotelRate(hotelId, rate.id, {
      name: rate.name,
      duration: Number(rate.duration),
      unit: rate.unit,
      price: Math.round(Number(rate.price) * 100) / 100,
      active: !rate.active,
    })
    rates.value = rates.value.map(item => item.id === updated.id ? { ...item, ...updated } : item)
    pageSuccess.value = updated.active
      ? `La tarifa “${updated.name}” quedó activa para cualquier habitación del hotel.`
      : `La tarifa “${updated.name}” quedó inactiva para nuevos check-ins.`
  } catch (error) {
    console.error('Error al cambiar estado de tarifa:', error)
    pageError.value = getErrorMessage(error)
  } finally {
    busyRateId.value = null
  }
}

watch(() => auth.currentHotelId, () => { void loadData() }, { immediate: true })
</script>

<template>
  <div class="space-y-5 text-sm text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administración</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Tarifas</h1>
        <p class="mt-1 text-sm text-slate-500">Configura el catálogo general del hotel. Todas las tarifas activas estarán disponibles al hacer check-in en cualquier habitación.</p>
      </div>
      <div class="flex gap-2">
        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60" :disabled="loading" @click="loadData">Actualizar</button>
        <button type="button" class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white shadow-sm hover:bg-blue-700" @click="openCreateModal">+ Nueva tarifa</button>
      </div>
    </header>

    <div v-if="pageSuccess" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ pageSuccess }}</div>
    <div v-if="pageError" role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ pageError }}</div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
      <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Tarifas del catálogo</p>
        <p class="mt-2 text-2xl font-bold text-slate-900">{{ rates.length }}</p>
      </section>
      <section class="rounded-xl border border-emerald-100 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Tarifas activas</p>
        <p class="mt-2 text-2xl font-bold text-emerald-600">{{ activeCount }}</p>
      </section>
      <section class="rounded-xl border border-amber-100 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Tarifas inactivas</p>
        <p class="mt-2 text-2xl font-bold text-amber-600">{{ inactiveCount }}</p>
      </section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-lg font-bold text-slate-900">Catálogo general de tarifas</h2>
          <p class="mt-1 text-sm text-slate-500">Cada tarifa pertenece al hotel, no a una habitación. Desactiva una tarifa para ocultarla de nuevos check-ins.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
          <input v-model="searchText" type="search" placeholder="Buscar tarifa" aria-label="Buscar tarifa" class="min-w-56 rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          <select v-model="statusFilter" aria-label="Filtrar por estado" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
            <option value="all">Todos los estados</option>
            <option value="active">Solo activas</option>
            <option value="inactive">Solo inactivas</option>
          </select>
        </div>
      </div>

      <div v-if="loading" class="px-5 py-12 text-center text-slate-500">Cargando catálogo de tarifas…</div>
      <div v-else-if="filteredRates.length === 0" class="px-5 py-12 text-center">
        <h3 class="font-semibold text-slate-900">{{ rates.length ? 'No hay coincidencias' : 'Aún no hay tarifas globales' }}</h3>
        <p class="mt-1 text-slate-500">{{ rates.length ? 'Cambia los filtros para ver otras tarifas.' : 'Crea una tarifa para que esté disponible al hacer check-in en cualquier habitación del hotel.' }}</p>
        <button v-if="rates.length === 0" type="button" class="mt-4 rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white hover:bg-blue-700" @click="openCreateModal">+ Nueva tarifa</button>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Tarifa</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Duración</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Precio</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</th>
              <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="rate in filteredRates" :key="rate.id" class="hover:bg-slate-50">
              <td class="px-5 py-4"><p class="font-semibold text-slate-900">{{ rate.name }}</p><p class="mt-0.5 text-xs text-slate-500">Disponible para todo el hotel</p></td>
              <td class="whitespace-nowrap px-4 py-4 text-slate-600">{{ rate.duration }} {{ rate.unit }}</td>
              <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-900">{{ formatCurrency(rate.price) }}</td>
              <td class="whitespace-nowrap px-4 py-4"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="rate.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ rate.active ? 'Activa' : 'Inactiva' }}</span></td>
              <td class="whitespace-nowrap px-5 py-4 text-right"><div class="inline-flex gap-2"><button type="button" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50" :disabled="busyRateId === rate.id" @click="openEditModal(rate)">Editar</button><button type="button" class="rounded-lg border px-3 py-2 text-xs font-semibold disabled:opacity-50" :class="rate.active ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'" :disabled="busyRateId === rate.id" @click="toggleRate(rate)">{{ busyRateId === rate.id ? 'Guardando…' : rate.active ? 'Desactivar' : 'Activar' }}</button></div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <footer class="border-t border-slate-100 bg-slate-50 px-5 py-3 text-xs text-slate-500">Mostrando {{ filteredRates.length }} de {{ rates.length }} tarifas del catálogo global.</footer>
    </section>

    <Teleport to="body">
      <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-labelledby="rate-modal-title" @click.self="closeModal">
        <section class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
          <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4"><div><h2 id="rate-modal-title" class="text-lg font-bold text-slate-900">{{ editingRateId === null ? 'Nueva tarifa global' : 'Editar tarifa global' }}</h2><p class="mt-1 text-sm text-slate-500">La tarifa se ofrecerá en todas las habitaciones de este hotel. Los cambios no afectan estancias ya iniciadas.</p></div><button type="button" class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100" :disabled="saving" aria-label="Cerrar" @click="closeModal">✕</button></header>
          <form class="space-y-4 overflow-y-auto px-5 py-4" @submit.prevent="submitForm">
            <div v-if="formError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700">{{ formError }}</div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div><label for="rate-name" class="mb-1 block text-xs font-semibold text-slate-700">Nombre o concepto *</label><input id="rate-name" v-model.trim="form.name" required maxlength="100" placeholder="Ej. 24 horas" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="saving" /></div>
              <div><label for="rate-price" class="mb-1 block text-xs font-semibold text-slate-700">Precio (MXN) *</label><input id="rate-price" v-model="form.price" type="number" min="0.01" max="99999999.99" step="0.01" required placeholder="900.00" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="saving" /></div>
              <div><label for="rate-duration" class="mb-1 block text-xs font-semibold text-slate-700">Duración *</label><input id="rate-duration" v-model="form.duration" type="number" min="1" max="10000" step="1" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="saving" /></div>
              <div><label for="rate-unit" class="mb-1 block text-xs font-semibold text-slate-700">Unidad *</label><select id="rate-unit" v-model="form.unit" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="saving"><option value="minutos">Minutos</option><option value="horas">Horas</option><option value="días">Días</option><option value="noches">Noches</option><option value="semanas">Semanas</option></select></div>
            </div>
            <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-3 text-sm text-slate-700"><input v-model="form.active" type="checkbox" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500" :disabled="saving" /> Tarifa activa para ofrecer en nuevos check-ins</label>
            <footer class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end"><button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-50" :disabled="saving" @click="closeModal">Cancelar</button><button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="saving || !formIsValid">{{ saving ? 'Guardando…' : editingRateId === null ? 'Crear tarifa' : 'Guardar cambios' }}</button></footer>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
