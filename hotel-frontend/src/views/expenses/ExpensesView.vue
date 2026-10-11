<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  getExpenses,
  type ExpenseReport,
} from '@/services/expenses'

const hotelId = 1

const report = ref<ExpenseReport | null>(null)
const loading = ref(true)
const errorMessage = ref('')

const currencyFormatter = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
})

const formatCurrency = (value: string | number) => {
  return currencyFormatter.format(Number(value) || 0)
}

const formatDate = (value: string) => {
  const datePart = value.slice(0, 10)
  const [year, month, day] = datePart.split('-').map(Number)

  if (!year || !month || !day) {
    return value
  }

  return new Intl.DateTimeFormat('es-MX', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(new Date(year, month - 1, day, 12))
}

const categoryLabels: Record<string, string> = {
  utilities: 'Servicios públicos',
  payroll: 'Nómina',
  supplies: 'Insumos',
  maintenance: 'Mantenimiento',
  amenities: 'Amenidades',
  other: 'Otros gastos',
}

const getCategoryLabel = (category: string) => {
  return categoryLabels[category] ?? category
}

const paymentMethodLabels: Record<string, string> = {
  cash: 'Efectivo',
  transfer: 'Transferencia',
  card: 'Tarjeta',
  credit_card: 'Tarjeta de crédito',
  debit_card: 'Tarjeta de débito',
}

const getPaymentMethodLabel = (method: string | null) => {
  if (!method) return 'No especificado'

  return paymentMethodLabels[method] ?? method
}

const generalExpenses = computed(() =>
  Number(report.value?.summary.general_expenses ?? 0),
)

const roomExpenses = computed(() =>
  Number(report.value?.summary.room_expenses ?? 0),
)

const totalExpenses = computed(() =>
  Number(report.value?.summary.total_expenses ?? 0),
)

const loadExpenses = async () => {
  try {
    loading.value = true
    errorMessage.value = ''

    report.value = await getExpenses(hotelId)
  } catch (error) {
    console.error('Error al consultar gastos:', error)

    errorMessage.value =
      'No fue posible cargar los gastos. Verifica tu sesión y los permisos del hotel.'
  } finally {
    loading.value = false
  }
}

onMounted(loadExpenses)
</script>

<template>
  <div class="space-y-6">
    <!-- Encabezado -->
    <section
      class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
      <div>
        <p class="text-sm font-medium text-gray-500">
          Administración financiera
        </p>

        <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900">
          Finanzas del hotel
        </h1>

        <p class="mt-1 text-sm text-gray-500">
          {{ report?.hotel.name ?? 'Consulta de gastos y costos operativos' }}
        </p>
      </div>

      <button
        type="button"
        class="inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="loading"
        @click="loadExpenses"
      >
        {{ loading ? 'Actualizando...' : 'Actualizar información' }}
      </button>
    </section>

    <!-- Error -->
    <div
      v-if="errorMessage"
      class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700"
    >
      {{ errorMessage }}

      <button
        type="button"
        class="ml-2 font-semibold underline"
        @click="loadExpenses"
      >
        Reintentar
      </button>
    </div>

    <!-- Cargando -->
    <div
      v-if="loading"
      class="grid grid-cols-1 gap-4 md:grid-cols-3"
    >
      <div
        v-for="item in 3"
        :key="item"
        class="h-32 animate-pulse rounded-2xl border border-gray-200 bg-white"
      />
    </div>

    <template v-else-if="report">
      <!-- Resumen financiero -->
      <section class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <article
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-sm font-medium text-gray-500">
                Gastos generales
              </p>

              <p class="mt-3 text-2xl font-bold text-gray-900">
                {{ formatCurrency(generalExpenses) }}
              </p>

              <p class="mt-2 text-xs text-gray-500">
                Electricidad, nómina e insumos generales.
              </p>
            </div>

            <div class="rounded-xl bg-blue-50 p-3 text-blue-600">
              <svg
                class="h-6 w-6"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="1.8"
                  d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01"
                />
              </svg>
            </div>
          </div>
        </article>

        <article
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-sm font-medium text-gray-500">
                Gastos de habitaciones
              </p>

              <p class="mt-3 text-2xl font-bold text-orange-600">
                {{ formatCurrency(roomExpenses) }}
              </p>

              <p class="mt-2 text-xs text-gray-500">
                Reparaciones y costos asignados a habitaciones.
              </p>
            </div>

            <div class="rounded-xl bg-orange-50 p-3 text-orange-600">
              <svg
                class="h-6 w-6"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="1.8"
                  d="M3 21h18M5 21V9a2 2 0 012-2h10a2 2 0 012 2v12M8 12h8M8 16h8"
                />
              </svg>
            </div>
          </div>
        </article>

        <article
          class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm"
        >
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="text-sm font-medium text-gray-500">
                Total de gastos
              </p>

              <p class="mt-3 text-2xl font-bold text-gray-900">
                {{ formatCurrency(totalExpenses) }}
              </p>

              <p class="mt-2 text-xs text-gray-500">
                Suma de todos los gastos registrados.
              </p>
            </div>

            <div class="rounded-xl bg-gray-100 p-3 text-gray-700">
              <svg
                class="h-6 w-6"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="1.8"
                  d="M12 8c-2 0-3 1-3 2s1 2 3 2 3 1 3 2-1 2-3 2m0-10V6m0 12v-2m9-4a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
            </div>
          </div>
        </article>
      </section>

      <!-- Totales por habitación -->
      <section
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
      >
        <div class="border-b border-gray-100 px-5 py-4">
          <h2 class="text-lg font-bold text-gray-900">
            Gastos por habitación
          </h2>

          <p class="mt-1 text-sm text-gray-500">
            Costos operativos asignados a cada habitación.
          </p>
        </div>

        <div
          v-if="report.room_totals.length"
          class="divide-y divide-gray-100"
        >
          <div
            v-for="room in report.room_totals"
            :key="room.room_id"
            class="flex items-center justify-between gap-4 px-5 py-4"
          >
            <div>
              <p class="font-semibold text-gray-900">
                Habitación {{ room.room_number }}
              </p>

              <p class="mt-1 text-xs text-gray-500">
                Gastos registrados
              </p>
            </div>

            <p class="text-lg font-bold text-gray-900">
              {{ formatCurrency(room.total) }}
            </p>
          </div>
        </div>

        <div
          v-else
          class="px-5 py-10 text-center text-sm text-gray-500"
        >
          No hay gastos asignados a habitaciones.
        </div>
      </section>

      <!-- Historial de gastos -->
      <section
        class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm"
      >
        <div class="border-b border-gray-100 px-5 py-4">
          <h2 class="text-lg font-bold text-gray-900">
            Historial de gastos
          </h2>

          <p class="mt-1 text-sm text-gray-500">
            Detalle de los registros financieros del hotel.
          </p>
        </div>

        <div
          v-if="report.expenses.length"
          class="overflow-x-auto"
        >
          <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
              <tr>
                <th
                  class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                  Fecha
                </th>

                <th
                  class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                  Concepto
                </th>

                <th
                  class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                  Categoría
                </th>

                <th
                  class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                  Asignación
                </th>

                <th
                  class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                  Proveedor
                </th>

                <th
                  class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                  Importe
                </th>
              </tr>
            </thead>

            <tbody class="divide-y divide-gray-100">
              <tr
                v-for="expense in report.expenses"
                :key="expense.id"
                class="transition hover:bg-gray-50"
              >
                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                  {{ formatDate(expense.expense_date) }}
                </td>

                <td class="min-w-48 px-5 py-4">
                  <p class="text-sm font-semibold text-gray-900">
                    {{ expense.description }}
                  </p>

                  <p
                    v-if="expense.notes"
                    class="mt-1 text-xs text-gray-500"
                  >
                    {{ expense.notes }}
                  </p>
                </td>

                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                  {{ getCategoryLabel(expense.category) }}
                </td>

                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                  <span v-if="expense.room_id === null">
                    General del hotel
                  </span>

                  <span v-else-if="expense.room">
                    Habitación {{ expense.room.number }}
                  </span>

                  <span v-else>
                    Habitación no disponible
                  </span>
                </td>

                <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">
                  {{ expense.vendor || '—' }}

                  <p
                    v-if="expense.payment_method"
                    class="mt-1 text-xs text-gray-400"
                  >
                    {{ getPaymentMethodLabel(expense.payment_method) }}
                  </p>
                </td>

                <td
                  class="whitespace-nowrap px-5 py-4 text-right text-sm font-bold text-gray-900"
                >
                  {{ formatCurrency(expense.amount) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div
          v-else
          class="px-5 py-12 text-center"
        >
          <h3 class="font-semibold text-gray-900">
            No hay gastos registrados
          </h3>

          <p class="mt-1 text-sm text-gray-500">
            Los gastos que se registren aparecerán aquí.
          </p>
        </div>
      </section>
    </template>
  </div>
</template>