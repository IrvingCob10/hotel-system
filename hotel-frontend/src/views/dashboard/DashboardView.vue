<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { getRooms, type Room } from '@/services/rooms'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const rooms = ref<Room[]>([])
const loadingRooms = ref(true)
const roomsError = ref('')

async function loadRooms(hotelId: number | null): Promise<void> {
  // No se usa ningún hotel predeterminado: un contexto ausente no debe
  // consultar accidentalmente los datos del Hotel Demo Centro.
  if (!hotelId || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    rooms.value = []
    roomsError.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente o contacta al administrador.'
    loadingRooms.value = false
    return
  }

  loadingRooms.value = true
  roomsError.value = ''

  try {
    rooms.value = await getRooms(hotelId)
  } catch (error) {
    console.error('Error al cargar habitaciones del hotel activo:', error)
    rooms.value = []
    roomsError.value = 'No se pudieron cargar las habitaciones de este hotel. Verifica la conexión con la API y los permisos de tu cuenta.'
  } finally {
    loadingRooms.value = false
  }
}

// Si cambia el hotel de la sesión, vuelve a cargar solo sus habitaciones.
watch(
  () => auth.currentHotelId,
  (hotelId) => {
    void loadRooms(hotelId)
  },
  { immediate: true },
)

const totalRooms = computed(() => rooms.value.length)

const availableRooms = computed(() =>
  rooms.value.filter(room => room.status === 'available').length
)

const occupiedRooms = computed(() =>
  rooms.value.filter(room => room.status === 'occupied').length
)

const attentionRooms = computed(() =>
  rooms.value.filter(
    room =>
      room.status === 'cleaning' ||
      room.status === 'maintenance' ||
      room.status === 'out_of_service'
  ).length
)

const getStatusLabel = (status: string) => {
  switch (status) {
    case 'available':
      return 'Disponible'
    case 'occupied':
      return 'Ocupada'
    case 'cleaning':
      return 'Limpieza'
    case 'maintenance':
      return 'Mantenimiento'
    case 'out_of_service':
      return 'Fuera de servicio'
    default:
      return status
  }
}

const getStatusClasses = (status: string) => {
  switch (status) {
    case 'available':
      return 'bg-emerald-50 text-emerald-700 border-emerald-200'
    case 'occupied':
      return 'bg-red-50 text-red-700 border-red-200'
    case 'cleaning':
      return 'bg-blue-50 text-blue-700 border-blue-200'
    case 'maintenance':
      return 'bg-orange-50 text-orange-700 border-orange-200'
    case 'out_of_service':
      return 'bg-gray-100 text-gray-700 border-gray-300'
    default:
      return 'bg-gray-50 text-gray-700 border-gray-200'
  }
}

const getRoomCardClasses = (status: string) => {
  switch (status) {
    case 'available':
      return 'border-emerald-200 hover:border-emerald-400 hover:shadow-emerald-100'
    case 'occupied':
      return 'border-red-200 hover:border-red-400 hover:shadow-red-100'
    case 'cleaning':
      return 'border-blue-200 hover:border-blue-400 hover:shadow-blue-100'
    case 'maintenance':
      return 'border-orange-200 hover:border-orange-400 hover:shadow-orange-100'
    case 'out_of_service':
      return 'border-gray-300 hover:border-gray-400 hover:shadow-gray-100'
    default:
      return 'border-gray-200 hover:border-gray-300'
  }
}

// Reservas y actividad aún no están conectadas a endpoints reales.
// Se dejan vacías deliberadamente para no mostrar datos de demostración
// de otro hotel a los nuevos propietarios.
interface UpcomingReservation {
  guest: string
  room: string
  date: string
  nights: number
}

interface RecentActivity {
  title: string
  description: string
  time: string
}

const upcomingReservations: UpcomingReservation[] = []
const recentActivity: RecentActivity[] = []
</script>

<template>
  <div class="space-y-6">

    <!-- Encabezado -->
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="text-sm font-medium text-gray-500">
          Panel operativo
        </p>

        <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900">
          Buenos días 👋
        </h1>

        <p class="mt-1 text-sm text-gray-500">
          Aquí tienes un resumen de la operación de tu hotel.
        </p>
      </div>

      <RouterLink
        to="/reception"
        class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-gray-800"
      >
        Ver recepción
      </RouterLink>
    </div>

    <!-- Error -->
    <div
      v-if="roomsError"
      class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
    >
      {{ roomsError }}
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

      <!-- Total -->
      <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-sm font-medium text-gray-500">
              Total de habitaciones
            </p>

            <p class="mt-2 text-3xl font-bold text-gray-900">
              <span v-if="!loadingRooms">
                {{ totalRooms }}
              </span>

              <span v-else class="text-gray-300">
                ...
              </span>
            </p>
          </div>

          <div class="rounded-xl bg-gray-100 p-3">
            <svg
              class="h-6 w-6 text-gray-700"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M3 21h18M5 21V7a2 2 0 012-2h10a2 2 0 012 2v14M8 9h1m-1 4h1m6-4h1m-1 4h1M9 21v-4h6v4"
              />
            </svg>
          </div>
        </div>
      </div>

      <!-- Disponibles -->
      <div class="rounded-2xl border border-emerald-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-sm font-medium text-gray-500">
              Disponibles
            </p>

            <p class="mt-2 text-3xl font-bold text-emerald-600">
              <span v-if="!loadingRooms">
                {{ availableRooms }}
              </span>

              <span v-else class="text-gray-300">
                ...
              </span>
            </p>
          </div>

          <div class="rounded-xl bg-emerald-50 p-3">
            <svg
              class="h-6 w-6 text-emerald-600"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M5 13l4 4L19 7"
              />
            </svg>
          </div>
        </div>
      </div>

      <!-- Ocupadas -->
      <div class="rounded-2xl border border-red-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-sm font-medium text-gray-500">
              Ocupadas
            </p>

            <p class="mt-2 text-3xl font-bold text-red-600">
              <span v-if="!loadingRooms">
                {{ occupiedRooms }}
              </span>

              <span v-else class="text-gray-300">
                ...
              </span>
            </p>
          </div>

          <div class="rounded-xl bg-red-50 p-3">
            <svg
              class="h-6 w-6 text-red-600"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM4 21a8 8 0 0116 0"
              />
            </svg>
          </div>
        </div>
      </div>

      <!-- Atención -->
      <div class="rounded-2xl border border-orange-100 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between">
          <div>
            <p class="text-sm font-medium text-gray-500">
              Requieren atención
            </p>

            <p class="mt-2 text-3xl font-bold text-orange-600">
              <span v-if="!loadingRooms">
                {{ attentionRooms }}
              </span>

              <span v-else class="text-gray-300">
                ...
              </span>
            </p>
          </div>

          <div class="rounded-xl bg-orange-50 p-3">
            <svg
              class="h-6 w-6 text-orange-600"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z"
              />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Mapa de habitaciones -->
    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">

      <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="text-lg font-bold text-gray-900">
            Habitaciones
          </h2>

          <p class="mt-1 text-sm text-gray-500">
            Estado actual de las habitaciones.
          </p>
        </div>

        <div class="flex flex-wrap gap-2">
          <span class="inline-flex items-center gap-1.5 rounded-full border bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            Disponible
          </span>

          <span class="inline-flex items-center gap-1.5 rounded-full border bg-red-50 px-3 py-1 text-xs font-medium text-red-700">
            <span class="h-2 w-2 rounded-full bg-red-500"></span>
            Ocupada
          </span>

          <span class="inline-flex items-center gap-1.5 rounded-full border bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700">
            <span class="h-2 w-2 rounded-full bg-blue-500"></span>
            Limpieza
          </span>
        </div>
      </div>

      <div class="p-5">

        <!-- Cargando -->
        <div
          v-if="loadingRooms"
          class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
        >
          <div
            v-for="i in 5"
            :key="i"
            class="h-36 animate-pulse rounded-2xl border border-gray-200 bg-gray-50"
          ></div>
        </div>

        <!-- Habitaciones -->
        <div
          v-else-if="rooms.length"
          class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
        >
          <RouterLink
            v-for="room in rooms"
            :key="room.id"
            :to="{
              name: 'room-detail',
              params: {
                id: room.number
              }
            }"
            class="group rounded-2xl border bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
            :class="getRoomCardClasses(room.status)"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">
                  Habitación
                </p>

                <p class="mt-1 text-2xl font-bold text-gray-900">
                  {{ room.number }}
                </p>
              </div>

              <span
                class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold"
                :class="getStatusClasses(room.status)"
              >
                {{ getStatusLabel(room.status) }}
              </span>
            </div>

            <div class="mt-5 border-t border-gray-100 pt-4">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-semibold text-gray-800">
                    {{ room.room_type?.name ?? 'Tipo no definido' }}
                  </p>

                  <p class="mt-1 text-xs text-gray-500">
                    Capacidad: {{ room.room_type?.capacity ?? '—' }} personas
                  </p>
                </div>

                <div class="text-gray-300 transition group-hover:text-gray-500">
                  <svg
                    class="h-5 w-5"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="1.8"
                      d="M9 5l7 7-7 7"
                    />
                  </svg>
                </div>
              </div>
            </div>
          </RouterLink>
        </div>

        <!-- Sin habitaciones -->
        <div
          v-else
          class="rounded-2xl border border-dashed border-gray-300 px-6 py-12 text-center"
        >
          <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-gray-100">
            <svg
              class="h-6 w-6 text-gray-500"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.8"
                d="M3 21h18M5 21V7a2 2 0 012-2h10a2 2 0 012 2v14"
              />
            </svg>
          </div>

          <h3 class="mt-4 text-sm font-semibold text-gray-900">
            No hay habitaciones registradas
          </h3>

          <p class="mt-1 text-sm text-gray-500">
            Comienza agregando las habitaciones de este hotel.
          </p>
        </div>
      </div>
    </div>

    <!-- Reservas y actividad -->
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">

      <!-- Próximas reservas -->
      <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4">
          <div class="flex items-center justify-between">
            <div>
              <h2 class="text-lg font-bold text-gray-900">
                Próximas reservas
              </h2>

              <p class="mt-1 text-sm text-gray-500">
                Llegadas programadas.
              </p>
            </div>

            <span class="text-xs font-medium text-gray-400">
              Integración pendiente
            </span>
          </div>
        </div>

        <div v-if="upcomingReservations.length" class="divide-y divide-gray-100">
          <div
            v-for="reservation in upcomingReservations"
            :key="`${reservation.guest}-${reservation.room}`"
            class="flex items-center justify-between gap-4 px-5 py-4"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold text-gray-900">
                {{ reservation.guest }}
              </p>

              <p class="mt-1 text-xs text-gray-500">
                Habitación {{ reservation.room }} · {{ reservation.nights }} noche(s)
              </p>
            </div>

            <div class="shrink-0 text-right">
              <p class="text-sm font-medium text-gray-700">
                {{ reservation.date }}
              </p>
            </div>
          </div>
        </div>

        <div v-else class="px-5 py-8 text-center">
          <p class="text-sm font-medium text-gray-700">
            No hay reservas para mostrar
          </p>
          <p class="mt-1 text-xs text-gray-500">
            El resumen de reservas se mostrará cuando esté conectado al servicio de este hotel.
          </p>
        </div>
      </div>

      <!-- Actividad reciente -->
      <div class="rounded-2xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4">
          <h2 class="text-lg font-bold text-gray-900">
            Actividad reciente
          </h2>

          <p class="mt-1 text-sm text-gray-500">
            Últimos movimientos de operación.
          </p>
        </div>

        <div v-if="recentActivity.length" class="divide-y divide-gray-100">
          <div
            v-for="activity in recentActivity"
            :key="`${activity.title}-${activity.time}`"
            class="flex items-start gap-4 px-5 py-4"
          >
            <div class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-gray-900"></div>

            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold text-gray-900">
                {{ activity.title }}
              </p>

              <p class="mt-1 text-xs text-gray-500">
                {{ activity.description }}
              </p>
            </div>

            <p class="shrink-0 text-xs text-gray-400">
              {{ activity.time }}
            </p>
          </div>
        </div>

        <div v-else class="px-5 py-8 text-center">
          <p class="text-sm font-medium text-gray-700">
            Sin actividad reciente
          </p>
          <p class="mt-1 text-xs text-gray-500">
            Este panel todavía no está conectado al historial operativo de este hotel.
          </p>
        </div>
      </div>
    </div>

  </div>
</template>