```vue
<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <aside
    class="fixed left-0 top-0 z-40 flex h-screen w-64 flex-col border-r border-slate-200 bg-white"
  >
    <!-- Logo -->
    <div class="flex h-16 shrink-0 items-center border-b border-slate-200 px-5">
      <div
        class="mr-3 flex h-9 w-9 items-center justify-center rounded-lg bg-blue-600 text-lg text-white"
      >
        🏨
      </div>

      <div>
        <div class="font-bold text-slate-800">
          Hotel Management
        </div>
        <div class="text-xs text-slate-400">
          PMS
        </div>
      </div>
    </div>

    <!-- Navegación -->
    <nav class="flex-1 space-y-6 overflow-y-auto p-4">

      <!-- Inicio -->
      <section>
        <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Inicio
        </p>

        <RouterLink
          to="/dashboard"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
          exact-active-class="bg-blue-50 text-blue-700"
        >
          <span>🏠</span>
          Dashboard
        </RouterLink>
      </section>

      <!-- Operación -->
      <section>
        <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Operación
        </p>

        <button
          type="button"
          disabled
          title="Módulo pendiente de conectar"
          class="flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>🏨</span>
          Recepción
          <span class="ml-auto text-[10px]">Próximamente</span>
        </button>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>📅</span>
          Reservas
        </button>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>🛒</span>
          Tienda
        </button>
      </section>

      <!-- Administración -->
      <section>
        <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Administración
        </p>

        <RouterLink
          to="/rooms"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>🛏️</span>
          Habitaciones
        </RouterLink>

        <RouterLink
  to="/history/day"
  class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
  active-class="bg-blue-50 text-blue-700"
>
  <span>📋</span>
  Historial del día
</RouterLink>

        <RouterLink
  to="/room-types"
  class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
  active-class="bg-blue-50 text-blue-700"
>
  <span>🛏️</span>
  Tipos de habitación
</RouterLink>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>🧹</span>
          Limpieza
        </button>

        <RouterLink
          :to="{ name: 'rates' }"
          class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>💲</span>
          Tarifas
        </RouterLink>

        <RouterLink
          to="/products"
          class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>📦</span>
          Productos e inventario
        </RouterLink>

        <RouterLink
          to="/services"
          class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>🛎️</span>
          Servicios
        </RouterLink>


        <RouterLink
        :to="{ name: 'hotel-settings' }"
        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-slate-100"
        >
        <span aria-hidden="true">⚙️</span>
        <span>Datos del hotel</span>
        </RouterLink>

      </section>

      <!-- Facturación -->
      <section>
        <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Facturación
        </p>

        <button
          type="button"
          disabled
          class="flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>🧾</span>
          Comprobantes
        </button>
      </section>

      <!-- Consultas -->
      <section>
        <p class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">
          Consultas
        </p>

        <RouterLink
          to="/expenses"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>💰</span>
          Gastos
        </RouterLink>

        <RouterLink
          to="/history/day"
          class="mt-1 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-600 transition hover:bg-blue-50 hover:text-blue-600"
          active-class="bg-blue-50 text-blue-700"
        >
          <span>📋</span>
          Historial del día
        </RouterLink>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>👤</span>
          Huéspedes
        </button>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>💳</span>
          Cuentas
        </button>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>📊</span>
          Ventas
        </button>

        <button
          type="button"
          disabled
          class="mt-1 flex w-full cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-400"
        >
          <span>📦</span>
          Kardex
        </button>
      </section>

    </nav>
  </aside>
</template>
```