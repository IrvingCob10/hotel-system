<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')

const handleLogin = async () => {
  error.value = ''

  try {
    await auth.login(email.value, password.value)

    // Los propietarios con un hotel sin configurar deben completar el perfil.
    if (auth.currentHotelId !== null && auth.needsHotelSetup) {
      await router.push({ name: 'hotel-settings' })
      return
    }

    // Evita que un usuario de hotel sin asignación entre al panel operativo.
    // El usuario SuperAdmin puede utilizar su entorno administrativo aunque
    // todavía no tenga hoteles asociados a su cuenta.
    if (auth.currentHotelId === null && !auth.user?.is_super_admin) {
      auth.logout()
      error.value = 'Tu usuario no tiene un hotel asignado. Contacta al administrador del sistema.'
      return
    }

    await router.push('/dashboard')
  } catch (err: any) {
    error.value = err.response?.data?.message ?? 'No fue posible iniciar sesión. Verifica tus credenciales.'
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center bg-slate-100 px-4">
    <div class="w-full max-w-md">
      <div class="rounded-2xl bg-white p-8 shadow-xl">
        <div class="mb-8 text-center">
          <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-600 text-3xl text-white">
            🏨
          </div>
          <h1 class="text-3xl font-bold text-slate-800">Hotel Management</h1>
          <p class="mt-2 text-slate-500">Acceso al sistema</p>
        </div>

        <div
          v-if="error"
          class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
          role="alert"
        >
          {{ error }}
        </div>

        <form @submit.prevent="handleLogin">
          <div class="mb-5">
            <label for="email" class="mb-2 block text-sm font-medium text-slate-700">Correo electrónico</label>
            <input
              id="email"
              v-model.trim="email"
              type="email"
              autocomplete="email"
              required
              placeholder="correo@hotel.com"
              class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
            >
          </div>

          <div class="mb-6">
            <label for="password" class="mb-2 block text-sm font-medium text-slate-700">Contraseña</label>
            <input
              id="password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
              placeholder="••••••••"
              class="w-full rounded-lg border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
            >
          </div>

          <button
            type="submit"
            :disabled="auth.loading"
            class="w-full rounded-lg bg-blue-600 px-4 py-3 font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ auth.loading ? 'Iniciando sesión...' : 'Iniciar sesión' }}
          </button>
        </form>

        <div class="mt-6 text-center text-sm text-slate-400">Hotel Management</div>
      </div>
    </div>
  </div>
</template>
