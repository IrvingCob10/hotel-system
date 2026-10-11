<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  createHotelRoom,
  getHotelRooms,
  getHotelRoomTypes,
  type ManagedRoom,
  type ManagedRoomStatus,
  type RoomTypeOption,
} from '@/services/rooms'

const router = useRouter()

// El contexto de hotel se obtiene del usuario autenticado.
const auth = useAuthStore()

function getRequiredHotelId(): number {
  const id = auth.currentHotelId
  if (id === null || !Number.isSafeInteger(id) || id <= 0) {
    throw new Error('Tu usuario no tiene un hotel activo válido. Inicia sesión nuevamente.')
  }
  return id
}

const rooms = ref<ManagedRoom[]>([])
const roomTypes = ref<RoomTypeOption[]>([])
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const formError = ref('')
const successMessage = ref('')
const isFormOpen = ref(false)
const searchText = ref('')
const statusFilter = ref('all')

const form = reactive({
  number: '',
  room_type_id: '',
  floor: '',
  notes: '',
})

const statusLabel = (status: ManagedRoomStatus) => {
  const labels: Record<string, string> = {
    available: 'Disponible',
    occupied: 'Ocupada',
    cleaning: 'En limpieza',
    maintenance: 'Mantenimiento',
    out_of_service: 'Fuera de servicio',
  }
  return labels[status] ?? status
}

const statusClass = (status: ManagedRoomStatus) => {
  const classes: Record<string, string> = {
    available: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    occupied: 'bg-red-50 text-red-700 ring-red-200',
    cleaning: 'bg-blue-50 text-blue-700 ring-blue-200',
    maintenance: 'bg-amber-50 text-amber-700 ring-amber-200',
    out_of_service: 'bg-gray-100 text-gray-700 ring-gray-200',
  }
  return classes[status] ?? 'bg-gray-100 text-gray-700 ring-gray-200'
}

const totalRooms = computed(() => rooms.value.length)
const availableCount = computed(() => rooms.value.filter(room => room.status === 'available').length)
const occupiedCount = computed(() => rooms.value.filter(room => room.status === 'occupied').length)
const attentionCount = computed(() =>
  rooms.value.filter(room => ['cleaning', 'maintenance', 'out_of_service'].includes(room.status)).length,
)

const filteredRooms = computed(() => {
  const query = searchText.value.trim().toLocaleLowerCase('es-MX')

  return rooms.value.filter(room => {
    const matchesStatus = statusFilter.value === 'all' || room.status === statusFilter.value
    const matchesQuery = !query
      || room.number.toLocaleLowerCase('es-MX').includes(query)
      || (room.roomType?.name ?? room.room_type?.name ?? '').toLocaleLowerCase('es-MX').includes(query)
      || String(room.floor ?? '').includes(query)

    return matchesStatus && matchesQuery
  })
})

const getApiError = (error: unknown): { message: string; field?: string } => {
  const response = (error as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[]>
      }
    }
  })?.response

  const errors = response?.data?.errors
  const fieldOrder = [
    'number',
    'room_type_id',
    'floor',
    'notes',
  ]

  for (const field of fieldOrder) {
    const message = errors?.[field]?.[0]
    if (message) return { message, field }
  }

  return {
    message: response?.data?.message
      ?? 'No fue posible completar la operación. Revisa la conexión con el servidor.',
  }
}

const loadData = async () => {
  loading.value = true
  loadError.value = ''

  const hotelId = auth.currentHotelId
  if (hotelId === null || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    rooms.value = []
    roomTypes.value = []
    loadError.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente.'
    loading.value = false
    return
  }

  try {
    const [loadedRooms, loadedTypes] = await Promise.all([
      getHotelRooms(hotelId),
      getHotelRoomTypes(hotelId),
    ])

    rooms.value = loadedRooms
    roomTypes.value = loadedTypes

    const firstLoadedType = loadedTypes[0]
    if (!form.room_type_id && firstLoadedType) {
      form.room_type_id = String(firstLoadedType.id)
    }
  } catch (error) {
    console.error('Error al cargar el módulo de habitaciones:', error)
    const result = getApiError(error)
    loadError.value = result.message
  } finally {
    loading.value = false
  }
}

const resetForm = () => {
  form.number = ''
  const firstRoomType = roomTypes.value[0]
  form.room_type_id = firstRoomType ? String(firstRoomType.id) : ''
  form.floor = ''
  form.notes = ''
  formError.value = ''
}

const openCreateForm = () => {
  resetForm()
  successMessage.value = ''
  isFormOpen.value = true
}

const closeCreateForm = () => {
  if (saving.value) return
  isFormOpen.value = false
  formError.value = ''
}

const submitCreateRoom = async () => {
  formError.value = ''
  successMessage.value = ''

  const roomNumberInput = String(form.number ?? '').trim()
  const roomTypeId = Number(form.room_type_id)
  const floorInput = String(form.floor ?? '').trim()

  if (!roomNumberInput) {
    formError.value = 'Ingresa el número o identificador de la habitación.'
    return
  }
  if (!Number.isInteger(roomTypeId) || !roomTypes.value.some(type => type.id === roomTypeId)) {
    formError.value = 'Selecciona un tipo de habitación válido.'
    return
  }
  if (floorInput !== '' && (!Number.isInteger(Number(floorInput)) || Number(floorInput) < 0)) {
    formError.value = 'El piso debe ser un número entero igual o mayor que cero, o quedar vacío.'
    return
  }
  const normalizedNumber = String(form.number ?? '').trim()
  if (rooms.value.some(room => room.number.toLocaleLowerCase('es-MX') === normalizedNumber.toLocaleLowerCase('es-MX'))) {
    formError.value = `La habitación ${normalizedNumber} ya aparece en el listado.`
    return
  }

  saving.value = true

  try {
    const result = await createHotelRoom(getRequiredHotelId(), {
      room_type_id: Number(form.room_type_id),
      number: normalizedNumber,
      floor: String(form.floor ?? '').trim() === '' ? null : Number(form.floor),
      notes: form.notes.trim() || null,
    })

    rooms.value = [...rooms.value, result.room].sort((a, b) =>
      a.number.localeCompare(b.number, 'es-MX', { numeric: true }),
    )
    successMessage.value = `Habitación ${result.room.number} creada correctamente. Las tarifas se administran desde el catálogo global.`
    isFormOpen.value = false
    resetForm()
  } catch (error) {
    console.error('Error al crear la habitación:', error)
    formError.value = getApiError(error).message
  } finally {
    saving.value = false
  }
}

const openRoom = (room: ManagedRoom) => {
  void router.push({ name: 'room-detail', params: { id: room.number } })
}

watch(() => auth.currentHotelId, () => { void loadData() }, { immediate: true })
</script>

<template>
  <div class="space-y-6 text-sm text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Administración</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Habitaciones</h1>
        <p class="mt-1 text-sm text-slate-500">
          Administra las habitaciones físicas de tu hotel. Las tarifas se configuran una sola vez en el catálogo general.
        </p>
      </div>

      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
          :disabled="loading"
          @click="loadData"
        >
          Actualizar
        </button>
        <button
          type="button"
          class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
          :disabled="loading || roomTypes.length === 0"
          @click="openCreateForm"
        >
          + Agregar habitación
        </button>
      </div>
    </header>

    <div v-if="loadError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ loadError }}
    </div>

    <div
      v-if="!loading && !loadError && roomTypes.length === 0"
      class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
    >
      Este hotel todavía no tiene tipos de habitación activos. Primero registra una categoría para poder dar de alta habitaciones.
      <RouterLink :to="{ name: 'room-types-management' }" class="ml-1 inline-flex font-semibold text-amber-950 underline underline-offset-2">Configurar tipos de habitación</RouterLink>
    </div>

    <div v-if="successMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
      {{ successMessage }}
    </div>

    <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
      <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Total de habitaciones</p>
        <p class="mt-2 text-2xl font-bold text-slate-900">{{ totalRooms }}</p>
      </section>
      <section class="rounded-xl border border-emerald-100 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Disponibles</p>
        <p class="mt-2 text-2xl font-bold text-emerald-600">{{ availableCount }}</p>
      </section>
      <section class="rounded-xl border border-red-100 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Ocupadas</p>
        <p class="mt-2 text-2xl font-bold text-red-600">{{ occupiedCount }}</p>
      </section>
      <section class="rounded-xl border border-amber-100 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Requieren atención</p>
        <p class="mt-2 text-2xl font-bold text-amber-600">{{ attentionCount }}</p>
      </section>
    </div>

    <Teleport to="body">
      <div
        v-if="isFormOpen"
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3 backdrop-blur-sm sm:p-5"
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-room-title"
        @click.self="closeCreateForm"
      >
        <section class="relative flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-white/70 bg-white shadow-2xl">
          <header class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4 sm:px-6">
            <div class="flex min-w-0 items-start gap-3">
              <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl text-blue-700">
                <span aria-hidden="true">🏨</span>
              </div>
              <div>
                <h2 id="create-room-title" class="text-lg font-bold tracking-tight text-slate-900">Agregar habitación</h2>
                <p class="mt-1 text-sm text-slate-500">Registra los datos de la habitación. No necesitas definir tarifas en cada habitación.</p>
              </div>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 disabled:opacity-50"
              :disabled="saving"
              aria-label="Cerrar formulario"
              @click="closeCreateForm"
            >
              ✕
            </button>
          </header>

          <form class="min-h-0 space-y-4 overflow-y-auto px-5 py-4 sm:px-6" @submit.prevent="submitCreateRoom">
            <div v-if="formError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700">
              {{ formError }}
            </div>

            <section class="space-y-3">
              <div>
                <h3 class="font-semibold text-slate-900">Datos de la habitación</h3>
                <p class="mt-0.5 text-xs text-slate-500">El número debe ser único dentro de este hotel.</p>
              </div>
              <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                  <label for="room-number" class="mb-1 block text-xs font-semibold text-slate-700">Número o identificador <span class="text-red-500">*</span></label>
                  <input
                    id="room-number"
                    v-model="form.number"
                    type="text"
                    maxlength="50"
                    autocomplete="off"
                    placeholder="Ej. 102"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
                    :disabled="saving"
                  />
                </div>

                <div>
                  <label for="room-type" class="mb-1 block text-xs font-semibold text-slate-700">Tipo de habitación <span class="text-red-500">*</span></label>
                  <select
                    id="room-type"
                    v-model="form.room_type_id"
                    class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
                    :disabled="saving || !roomTypes.length"
                  >
                    <option value="">Selecciona un tipo</option>
                    <option v-for="type in roomTypes" :key="type.id" :value="String(type.id)">
                      {{ type.name }} · {{ type.capacity }} personas
                    </option>
                  </select>
                </div>

                <div>
                  <label for="room-floor" class="mb-1 block text-xs font-semibold text-slate-700">Piso <span class="font-normal text-slate-400">(opcional)</span></label>
                  <input
                    id="room-floor"
                    v-model="form.floor"
                    type="number"
                    min="0"
                    step="1"
                    placeholder="Ej. 1"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
                    :disabled="saving"
                  />
                </div>
              </div>
            </section>
<section class="border-t border-slate-100 pt-4">
              <label for="room-notes" class="mb-1 block text-xs font-semibold text-slate-700">Notas internas <span class="font-normal text-slate-400">(opcional)</span></label>
              <textarea
                id="room-notes"
                v-model="form.notes"
                rows="2"
                maxlength="2000"
                placeholder="Puedes dejar este campo vacío. Agrega observaciones solo si hacen falta."
                class="w-full resize-y rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:bg-slate-50"
                :disabled="saving"
              />
            </section>

            <footer class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
              <button
                type="button"
                class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
                :disabled="saving"
                @click="closeCreateForm"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="saving || roomTypes.length === 0"
              >
                <span v-if="saving" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
                {{ saving ? 'Guardando…' : 'Guardar habitación' }}
              </button>
            </footer>
          </form>
        </section>
      </div>
    </Teleport>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <h2 class="text-lg font-bold text-slate-900">Habitaciones registradas</h2>
          <p class="mt-1 text-sm text-slate-500">Selecciona una habitación para abrir su ficha operativa.</p>
        </div>
        <div class="flex flex-col gap-2 sm:flex-row">
          <input
            v-model="searchText"
            type="search"
            placeholder="Buscar número, tipo o piso"
            aria-label="Buscar habitaciones"
            class="min-w-56 rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
          />
          <select
            v-model="statusFilter"
            aria-label="Filtrar por estado"
            class="rounded-lg border border-slate-300 bg-white px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
          >
            <option value="all">Todos los estados</option>
            <option value="available">Disponibles</option>
            <option value="occupied">Ocupadas</option>
            <option value="cleaning">En limpieza</option>
            <option value="maintenance">Mantenimiento</option>
            <option value="out_of_service">Fuera de servicio</option>
          </select>
        </div>
      </div>

      <div v-if="loading" class="px-5 py-12 text-center text-slate-500">
        Cargando habitaciones…
      </div>

      <div v-else-if="filteredRooms.length === 0" class="px-5 py-12 text-center">
        <div class="text-3xl">🏨</div>
        <h3 class="mt-3 font-semibold text-slate-900">
          {{ rooms.length ? 'No hay coincidencias' : 'Aún no hay habitaciones registradas' }}
        </h3>
        <p class="mt-1 text-sm text-slate-500">
          {{ rooms.length ? 'Cambia los filtros para ver otras habitaciones.' : 'Agrega la primera habitación de tu hotel para comenzar.' }}
        </p>
        <button
          v-if="rooms.length === 0 && roomTypes.length > 0"
          type="button"
          class="mt-4 rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white hover:bg-blue-700"
          @click="openCreateForm"
        >
          + Agregar habitación
        </button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100">
          <thead class="bg-slate-50">
            <tr>
              <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Habitación</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Tipo / capacidad</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Ubicación</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Notas internas</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</th>
              <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Acción</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="room in filteredRooms" :key="room.id" class="hover:bg-slate-50">
              <td class="whitespace-nowrap px-5 py-4">
                <p class="font-bold text-slate-900">{{ room.number }}</p>
                <p class="mt-0.5 text-xs text-slate-400">{{ room.active ? 'Activa en el sistema' : 'Desactivada' }}</p>
              </td>
              <td class="px-4 py-4">
                <p class="font-medium text-slate-800">{{ room.roomType?.name ?? 'Sin tipo' }}</p>
                <p class="mt-0.5 text-xs text-slate-500">
                  {{ room.roomType?.capacity ?? '—' }} personas
                </p>
              </td>
              <td class="px-4 py-4 text-slate-600">
                {{ room.floor === null || room.floor === undefined ? 'Piso no definido' : `Piso ${room.floor}` }}
              </td>
              <td class="px-4 py-4 text-slate-600">{{ room.notes || 'Sin notas internas' }}</td>
              <td class="whitespace-nowrap px-4 py-4">
                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset" :class="statusClass(room.status)">
                  {{ statusLabel(room.status) }}
                </span>
              </td>
              <td class="whitespace-nowrap px-5 py-4 text-right">
                <button
                  type="button"
                  class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                  @click="openRoom(room)"
                >
                  Abrir ficha →
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="border-t border-slate-100 bg-slate-50 px-5 py-3 text-xs text-slate-500">
        Mostrando {{ filteredRooms.length }} de {{ rooms.length }} habitaciones.
      </div>
    </section>
  </div>
</template>
