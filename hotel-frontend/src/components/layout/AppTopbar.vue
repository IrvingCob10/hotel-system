<script setup lang="ts">
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import DepartureAlertsBell from '@/components/notifications/DepartureAlertsBell.vue'

const auth = useAuthStore()
const router = useRouter()

const hotelName = computed(() => auth.currentHotelDisplayName)
const hotelSubtitle = computed(() =>
  auth.currentHotelId === null ? 'Sin establecimiento asignado' : 'Panel operativo',
)

const logout = () => {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <header class="fixed left-64 right-0 top-0 z-30 h-16 border-b border-slate-200 bg-white">
    <div class="flex h-full items-center justify-between px-6">
      <!-- El encabezado refleja el hotel devuelto por el inicio de sesión. -->
      <div class="min-w-0">
        <h2 class="truncate text-lg font-semibold text-slate-800">
          {{ hotelName }}
        </h2>
        <p class="text-xs text-slate-400">
          {{ hotelSubtitle }}
        </p>
      </div>

      <div class="flex items-center gap-5">
        <!-- No consulta alertas de un hotel fijo ni muestra la campana sin contexto. -->
        <DepartureAlertsBell
          v-if="auth.currentHotelId !== null"
          :hotel-id="auth.currentHotelId ?? undefined"
        />

        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 font-semibold text-white">
            {{ auth.user?.name?.charAt(0).toUpperCase() || '?' }}
          </div>

          <div class="hidden sm:block">
            <p class="text-sm font-semibold text-slate-700">
              {{ auth.user?.name || 'Usuario' }}
            </p>
            <button
              type="button"
              class="text-xs text-slate-400 hover:text-red-500"
              @click="logout"
            >
              Cerrar sesión
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>
