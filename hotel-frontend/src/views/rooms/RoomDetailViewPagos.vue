<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  getRoomDetail,
  type RoomDetailResponse,
} from '@/services/room-detail'
import { addPaymentToStay, addProductToStay, addServiceToStay, updateChargeQuantity } from '@/services/stay-charges'
import { getHotelProducts, type HotelProduct } from '@/services/products'
import { getHotelServices, type HotelService } from '@/services/services'

const route = useRoute()

// Hotel de demostración. Posteriormente vendrá del contexto autenticado.
const hotelId = 1

const detail = ref<RoomDetailResponse | null>(null)
const loading = ref(true)
const errorMessage = ref('')
const activeAction = ref<null | 'product' | 'service' | 'payment' | 'checkout' | 'checkin'>(null)

// Estado del formulario para agregar productos del inventario a la estancia.
const productCatalog = ref<HotelProduct[]>([])
const selectedProductId = ref<number | null>(null)
const productQuantity = ref(1)
const productsLoading = ref(false)
const addingProduct = ref(false)
const productError = ref('')

// Estado del formulario para agregar servicios a la estancia.
const serviceCatalog = ref<HotelService[]>([])
const selectedServiceId = ref<number | null>(null)
const serviceQuantity = ref(1)
const servicesLoading = ref(false)
const addingService = ref(false)
const serviceError = ref('')

// Estado del formulario para registrar pagos o abonos.
type PaymentMethod = 'cash' | 'card' | 'transfer' | 'other'
const paymentAmount = ref<number | string>('')
const paymentMethod = ref<PaymentMethod>('cash')
const paymentReference = ref('')
const paymentNotes = ref('')
const submittingPayment = ref(false)
const paymentError = ref('')

// Cantidades editables en pantalla. Los cambios solo se guardan al confirmar.
const draftQuantities = ref<Record<number, number | string>>({})
const savingChargeIds = ref<number[]>([])
const chargeErrors = ref<Record<number, string>>({})
const chargeNotice = ref('')
let chargeNoticeTimeout: ReturnType<typeof setTimeout> | undefined

// Mostrar confirmaciones temporalmente para mantener limpia la ficha.
const showChargeNotice = (message: string) => {
  if (chargeNoticeTimeout) {
    clearTimeout(chargeNoticeTimeout)
  }

  chargeNotice.value = message
  chargeNoticeTimeout = setTimeout(() => {
    chargeNotice.value = ''
    chargeNoticeTimeout = undefined
  }, 5000)
}

const clearChargeNotice = () => {
  if (chargeNoticeTimeout) {
    clearTimeout(chargeNoticeTimeout)
    chargeNoticeTimeout = undefined
  }

  chargeNotice.value = ''
}

onBeforeUnmount(() => {
  clearChargeNotice()
})

const actionInfo = computed(() => {
  const descriptions = {
    product: {
      title: 'Agregar producto',
      message: 'La siguiente etapa conectará esta acción con el catálogo del hotel, validará existencias y registrará el cargo en la cuenta de la estancia.',
    },
    service: {
      title: 'Agregar servicio',
      message: 'Selecciona un servicio activo del hotel y la cantidad que deseas cargar a la estancia.',
    },
    payment: {
      title: 'Registrar pago',
      message: 'Registra un abono parcial o liquida el saldo pendiente de esta estancia.',
    },
    checkout: {
      title: 'Registrar check-out',
      message: 'Antes de implementarlo, validaremos el saldo pendiente y confirmaremos el cierre de la estancia y el cambio de estado de la habitación.',
    },
    checkin: {
      title: 'Registrar check-in',
      message: 'La siguiente etapa permitirá seleccionar huésped y tarifa, crear la estancia y cambiar el estado de la habitación.',
    },
  }

  return activeAction.value ? descriptions[activeAction.value] : null
})

const currencyFormatter = new Intl.NumberFormat('es-MX', {
  style: 'currency',
  currency: 'MXN',
})

const formatCurrency = (value: number | string | null | undefined) =>
  currencyFormatter.format(Number(value ?? 0))

const formatDateTime = (value: string | null | undefined) => {
  if (!value) return 'No definido'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Fecha no disponible'
  return new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

const formatDateOnly = (value: string | null | undefined) => {
  if (!value) return 'Fecha no disponible'
  const [year, month, day] = value.slice(0, 10).split('-').map(Number)
  if (!year || !month || !day) return value
  return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium' })
    .format(new Date(year, month - 1, day, 12))
}

const currentStay = computed(() => detail.value?.current_stay ?? null)
const selectedProduct = computed(() =>
  productCatalog.value.find(product => product.id === Number(selectedProductId.value)) ?? null,
)
const productPreviewTotal = computed(() =>
  Number(selectedProduct.value?.price ?? 0) * Number(productQuantity.value || 0),
)
const selectedService = computed(() =>
  serviceCatalog.value.find(service => service.id === Number(selectedServiceId.value)) ?? null,
)
const servicePreviewTotal = computed(() =>
  Math.round(Number(selectedService.value?.price ?? 0) * Number(serviceQuantity.value || 0) * 100) / 100,
)
const paymentPreviewBalance = computed(() => {
  const balance = Number(currentStay.value?.account.balance ?? 0)
  const amount = Number(paymentAmount.value)

  if (!Number.isFinite(amount) || amount <= 0) return balance
  return Math.max(0, Math.round((balance - amount) * 100) / 100)
})
const isValidPaymentAmount = computed(() => {
  const amount = Number(paymentAmount.value)
  const balance = Number(currentStay.value?.account.balance ?? 0)

  return Number.isFinite(amount)
    && amount >= 0.01
    && Math.round(amount * 100) / 100 <= balance
})
const availableRates = computed(() =>
  (detail.value?.room.rates ?? []).filter(rate => rate.active),
)

// Totales de vista previa: cambian al editar la cantidad, antes de guardar.
const previewChargesTotal = computed(() => {
  if (!currentStay.value) return 0

  return currentStay.value.charges.reduce((sum, charge) => {
    if (charge.type !== 'product') return sum + Number(charge.total)

    const draftValue = draftQuantities.value[charge.id]
    const quantity = Number(draftValue ?? charge.quantity)
    const valid = Number.isInteger(quantity) && quantity >= 1 && quantity <= 1000
    const lineTotal = valid
      ? Math.round(Number(charge.unit_price) * quantity * 100) / 100
      : Number(charge.total)

    return sum + lineTotal
  }, 0)
})

const previewBalance = computed(() => {
  if (!currentStay.value) return 0
  return Math.max(0, Math.round((previewChargesTotal.value - Number(currentStay.value.account.paid_total)) * 100) / 100)
})

const isValidDraftQuantity = (chargeId: number): boolean => {
  const value = Number(draftQuantities.value[chargeId])
  return Number.isInteger(value) && value >= 1 && value <= 1000
}

const isQuantityChanged = (charge: { id: number; type: string; quantity: number }): boolean => {
  if (charge.type !== 'product' || !isValidDraftQuantity(charge.id)) return false
  return Number(draftQuantities.value[charge.id]) !== Number(charge.quantity)
}

const resetChargeQuantity = (charge: { id: number; quantity: number }) => {
  draftQuantities.value[charge.id] = Number(charge.quantity)
  delete chargeErrors.value[charge.id]
}

const getErrorMessage = (error: unknown): string => {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response
  return response?.data?.errors?.quantity?.[0]
    ?? response?.data?.message
    ?? 'No fue posible guardar la cantidad. La información no se modificó en pantalla.'
}

const saveChargeQuantity = async (charge: { id: number; type: string; quantity: number; unit_price: number; total: number; description: string }) => {
  if (!detail.value || !currentStay.value || charge.type !== 'product') return

  const newQuantity = Number(draftQuantities.value[charge.id])
  if (!Number.isInteger(newQuantity) || newQuantity < 1 || newQuantity > 1000) {
    chargeErrors.value[charge.id] = 'La cantidad debe ser un número entero entre 1 y 1000.'
    return
  }

  if (newQuantity === Number(charge.quantity)) {
    delete chargeErrors.value[charge.id]
    return
  }

  savingChargeIds.value = [...savingChargeIds.value, charge.id]
  delete chargeErrors.value[charge.id]
  clearChargeNotice()

  try {
    const result = await updateChargeQuantity({
      hotelId,
      roomNumber: detail.value.room.number,
      stayId: currentStay.value.id,
      chargeId: charge.id,
      quantity: newQuantity,
    })

    // Sincronizar con los valores confirmados por Laravel.
    charge.quantity = Number(result.charge.quantity)
    charge.total = Number(result.charge.total)
    draftQuantities.value[charge.id] = Number(result.charge.quantity)
    currentStay.value.account.charges_total = Number(result.account.charges_total)
    currentStay.value.account.paid_total = Number(result.account.paid_total)
    currentStay.value.account.balance = Number(result.account.balance)

    const historyItem = detail.value.stay_history.find(item => item.id === currentStay.value?.id)
    if (historyItem) {
      historyItem.charges_total = Number(result.account.charges_total)
      historyItem.paid_total = Number(result.account.paid_total)
      historyItem.balance = Number(result.account.balance)
    }

    showChargeNotice(`${charge.description}: cantidad actualizada correctamente.`)
  } catch (error) {
    console.error('Error al actualizar la cantidad del cargo:', error)
    chargeErrors.value[charge.id] = getErrorMessage(error)
    // Revertir la vista previa para reflejar el último estado confirmado.
    draftQuantities.value[charge.id] = Number(charge.quantity)
  } finally {
    savingChargeIds.value = savingChargeIds.value.filter(id => id !== charge.id)
  }
}

const roomStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    available: 'Disponible',
    occupied: 'Ocupada',
    cleaning: 'En limpieza',
    maintenance: 'En mantenimiento',
    out_of_service: 'Fuera de servicio',
  }
  return labels[status] ?? status
}

const roomStatusClass = (status: string) => {
  const classes: Record<string, string> = {
    available: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    occupied: 'bg-red-50 text-red-700 ring-red-200',
    cleaning: 'bg-blue-50 text-blue-700 ring-blue-200',
    maintenance: 'bg-orange-50 text-orange-700 ring-orange-200',
    out_of_service: 'bg-gray-100 text-gray-700 ring-gray-300',
  }
  return classes[status] ?? 'bg-gray-100 text-gray-700 ring-gray-200'
}

const chargeTypeLabel = (type: string) => {
  const labels: Record<string, string> = {
    room: 'Habitación',
    product: 'Producto',
    service: 'Servicio',
    other: 'Otro',
  }
  return labels[type] ?? type
}

const paymentMethodLabel = (method: string) => {
  const labels: Record<string, string> = {
    cash: 'Efectivo',
    transfer: 'Transferencia',
    card: 'Tarjeta',
    credit_card: 'Tarjeta de crédito',
    debit_card: 'Tarjeta de débito',
    other: 'Otro',
  }
  return labels[method] ?? method
}

const expenseCategoryLabel = (category: string) => {
  const labels: Record<string, string> = {
    utilities: 'Servicios públicos',
    payroll: 'Nómina',
    supplies: 'Insumos',
    maintenance: 'Mantenimiento',
    amenities: 'Amenidades',
    other: 'Otros gastos',
  }
  return labels[category] ?? category
}

const loadDetail = async () => {
  const roomNumber = String(route.params.id ?? '')
  if (!roomNumber) {
    errorMessage.value = 'No se indicó el número de habitación.'
    loading.value = false
    return
  }

  loading.value = true
  errorMessage.value = ''

  try {
    detail.value = await getRoomDetail(hotelId, roomNumber)
    draftQuantities.value = Object.fromEntries(
      (detail.value.current_stay?.charges ?? [])
        .filter(charge => charge.type === 'product')
        .map(charge => [charge.id, Number(charge.quantity)]),
    )
    chargeErrors.value = {}
    clearChargeNotice()
  } catch (error) {
    console.error('Error al cargar el detalle de habitación:', error)
    errorMessage.value = 'No fue posible cargar la habitación. Verifica tu sesión y la conexión con el servidor.'
  } finally {
    loading.value = false
  }
}

const getProductErrorMessage = (error: unknown): string => {
  const response = (error as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[]>
      }
    }
  })?.response

  return response?.data?.errors?.product_id?.[0]
    ?? response?.data?.errors?.quantity?.[0]
    ?? response?.data?.message
    ?? 'No fue posible agregar el producto. Verifica la conexión e inténtalo nuevamente.'
}

const loadProductCatalog = async () => {
  productsLoading.value = true
  productError.value = ''

  try {
    productCatalog.value = await getHotelProducts(hotelId)

    const firstAvailable = productCatalog.value.find(product => product.active && product.stock > 0)
    selectedProductId.value = firstAvailable?.id ?? null
  } catch (error) {
    console.error('Error al cargar el catálogo de productos:', error)
    productError.value = getProductErrorMessage(error)
  } finally {
    productsLoading.value = false
  }
}

const getServiceErrorMessage = (error: unknown): string => {
  const response = (error as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[]>
      }
    }
  })?.response

  return response?.data?.errors?.service_id?.[0]
    ?? response?.data?.errors?.quantity?.[0]
    ?? response?.data?.message
    ?? 'No fue posible agregar el servicio. Verifica la conexión e inténtalo nuevamente.'
}

const loadServiceCatalog = async () => {
  servicesLoading.value = true
  serviceError.value = ''

  try {
    serviceCatalog.value = await getHotelServices(hotelId)
    selectedServiceId.value = serviceCatalog.value[0]?.id ?? null
  } catch (error) {
    console.error('Error al cargar el catálogo de servicios:', error)
    serviceError.value = getServiceErrorMessage(error)
  } finally {
    servicesLoading.value = false
  }
}

const openAction = (action: NonNullable<typeof activeAction.value>) => {
  activeAction.value = action

  if (action === 'product') {
    productCatalog.value = []
    selectedProductId.value = null
    productQuantity.value = 1
    productError.value = ''
    void loadProductCatalog()
  }

  if (action === 'service') {
    serviceCatalog.value = []
    selectedServiceId.value = null
    serviceQuantity.value = 1
    serviceError.value = ''
    void loadServiceCatalog()
  }

  if (action === 'payment') {
    paymentAmount.value = ''
    paymentMethod.value = 'cash'
    paymentReference.value = ''
    paymentNotes.value = ''
    paymentError.value = ''
  }
}

const submitProduct = async () => {
  if (!detail.value || !currentStay.value || !selectedProduct.value) {
    productError.value = 'Selecciona un producto disponible.'
    return
  }

  const product = selectedProduct.value
  const quantity = Number(productQuantity.value)

  if (!Number.isInteger(quantity) || quantity < 1) {
    productError.value = 'La cantidad debe ser un número entero mayor que cero.'
    return
  }

  if (quantity > product.stock) {
    productError.value = `Solo hay ${product.stock} unidades disponibles.`
    return
  }

  addingProduct.value = true
  productError.value = ''

  try {
    const result = await addProductToStay({
      hotelId,
      roomNumber: detail.value.room.number,
      stayId: currentStay.value.id,
      productId: product.id,
      quantity,
    })

    const saved = result.charge
    const newCharge = {
      id: Number(saved.id),
      type: 'product',
      description: saved.description,
      quantity: Number(saved.quantity),
      unit_price: Number(saved.unit_price),
      total: Number(saved.total),
      product: saved.product
        ? { id: Number(saved.product.id), name: saved.product.name }
        : { id: product.id, name: product.name },
      service: null,
    }

    currentStay.value.charges.push(newCharge)
    draftQuantities.value[newCharge.id] = Number(saved.quantity)

    currentStay.value.account.charges_total = Number(result.account.charges_total)
    currentStay.value.account.paid_total = Number(result.account.paid_total)
    currentStay.value.account.balance = Number(result.account.balance)

    const historyItem = detail.value.stay_history.find(
      item => item.id === currentStay.value?.id,
    )

    if (historyItem) {
      historyItem.charges_total = Number(result.account.charges_total)
      historyItem.paid_total = Number(result.account.paid_total)
      historyItem.balance = Number(result.account.balance)
    }

    const catalogProduct = productCatalog.value.find(item => item.id === product.id)
    if (catalogProduct) {
      catalogProduct.stock = Number(result.stock_remaining)
    }

    activeAction.value = null
    showChargeNotice(`${product.name}: agregado a la cuenta correctamente.`)
  } catch (error) {
    console.error('Error al agregar producto a la estancia:', error)
    productError.value = getProductErrorMessage(error)
  } finally {
    addingProduct.value = false
  }
}

const submitService = async () => {
  if (!detail.value || !currentStay.value || !selectedService.value) {
    serviceError.value = 'Selecciona un servicio disponible.'
    return
  }

  const service = selectedService.value
  const quantity = Number(serviceQuantity.value)

  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 1000) {
    serviceError.value = 'La cantidad debe ser un número entero entre 1 y 1000.'
    return
  }

  addingService.value = true
  serviceError.value = ''

  try {
    const result = await addServiceToStay({
      hotelId,
      roomNumber: detail.value.room.number,
      stayId: currentStay.value.id,
      serviceId: service.id,
      quantity,
    })

    const saved = result.charge
    const newCharge = {
      id: Number(saved.id),
      type: 'service',
      description: saved.description,
      quantity: Number(saved.quantity),
      unit_price: Number(saved.unit_price),
      total: Number(saved.total),
      product: null,
      service: saved.service
        ? { id: Number(saved.service.id), name: saved.service.name }
        : { id: service.id, name: service.name },
    }

    currentStay.value.charges.push(newCharge)
    currentStay.value.account.charges_total = Number(result.account.charges_total)
    currentStay.value.account.paid_total = Number(result.account.paid_total)
    currentStay.value.account.balance = Number(result.account.balance)

    const historyItem = detail.value.stay_history.find(
      item => item.id === currentStay.value?.id,
    )

    if (historyItem) {
      historyItem.charges_total = Number(result.account.charges_total)
      historyItem.paid_total = Number(result.account.paid_total)
      historyItem.balance = Number(result.account.balance)
    }

    activeAction.value = null
    showChargeNotice(`${service.name}: agregado a la cuenta correctamente.`)
  } catch (error) {
    console.error('Error al agregar servicio a la estancia:', error)
    serviceError.value = getServiceErrorMessage(error)
  } finally {
    addingService.value = false
  }
}

const getPaymentErrorMessage = (error: unknown): string => {
  const response = (error as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[]>
      }
    }
  })?.response

  return response?.data?.errors?.amount?.[0]
    ?? response?.data?.errors?.method?.[0]
    ?? response?.data?.errors?.reference?.[0]
    ?? response?.data?.errors?.stay?.[0]
    ?? response?.data?.message
    ?? 'No fue posible registrar el pago. Verifica la conexión e inténtalo nuevamente.'
}

const fillPaymentBalance = () => {
  paymentAmount.value = Number(currentStay.value?.account.balance ?? 0)
}

const submitPayment = async () => {
  if (!detail.value || !currentStay.value) {
    paymentError.value = 'No hay una estancia activa para registrar el pago.'
    return
  }

  const amount = Math.round(Number(paymentAmount.value) * 100) / 100
  const balance = Number(currentStay.value.account.balance)

  if (!Number.isFinite(amount) || amount < 0.01) {
    paymentError.value = 'Ingresa un importe mayor que cero.'
    return
  }

  if (amount > balance) {
    paymentError.value = `El pago no puede superar el saldo pendiente de ${formatCurrency(balance)}.`
    return
  }

  submittingPayment.value = true
  paymentError.value = ''

  try {
    const result = await addPaymentToStay({
      hotelId,
      roomNumber: detail.value.room.number,
      stayId: currentStay.value.id,
      amount,
      method: paymentMethod.value,
      reference: paymentReference.value.trim() || null,
      notes: paymentNotes.value.trim() || null,
    })

    const saved = result.payment
    currentStay.value.payments.unshift({
      id: Number(saved.id),
      amount: Number(saved.amount),
      method: saved.method,
      reference: saved.reference ?? null,
      received_at: saved.received_at,
    })

    currentStay.value.account.charges_total = Number(result.account.charges_total)
    currentStay.value.account.paid_total = Number(result.account.paid_total)
    currentStay.value.account.balance = Number(result.account.balance)

    const historyItem = detail.value.stay_history.find(
      item => item.id === currentStay.value?.id,
    )

    if (historyItem) {
      historyItem.charges_total = Number(result.account.charges_total)
      historyItem.paid_total = Number(result.account.paid_total)
      historyItem.balance = Number(result.account.balance)
    }

    activeAction.value = null
    showChargeNotice(`Pago de ${formatCurrency(saved.amount)} registrado correctamente.`)
  } catch (error) {
    console.error('Error al registrar el pago:', error)
    paymentError.value = getPaymentErrorMessage(error)
  } finally {
    submittingPayment.value = false
  }
}

onMounted(loadDetail)
watch(() => route.params.id, () => loadDetail())
</script>

<template>
  <div class="space-y-3 text-sm text-gray-800">
    <!-- Encabezado de una sola línea -->
    <header class="flex items-center gap-3">
      <RouterLink
        :to="{ name: 'dashboard' }"
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-lg text-gray-600 transition hover:bg-gray-50"
        aria-label="Regresar al dashboard"
      >
        ←
      </RouterLink>

      <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-lg font-bold tracking-tight text-gray-900">
            Habitación {{ detail?.room.number ?? route.params.id }}
          </h1>
          <span
            v-if="detail"
            class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset"
            :class="roomStatusClass(detail.room.status)"
          >
            {{ roomStatusLabel(detail.room.status) }}
          </span>
        </div>
        <p v-if="detail" class="truncate text-xs text-gray-500">
          {{ detail.hotel.name }} · {{ detail.room.room_type.name }}
        </p>
      </div>

      <button
        type="button"
        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:opacity-60"
        :disabled="loading"
        @click="loadDetail"
      >
        {{ loading ? 'Actualizando…' : 'Actualizar' }}
      </button>
    </header>

    <div v-if="loading" class="rounded-xl border border-gray-200 bg-white p-5 text-center text-sm text-gray-500">
      Cargando habitación…
    </div>

    <div v-else-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
      {{ errorMessage }}
      <button type="button" class="ml-2 font-semibold underline" @click="loadDetail">Reintentar</button>
    </div>

    <template v-else-if="detail">
      <!-- Resumen: sin repetir el saldo que ya se detalla en la cuenta -->
      <section class="grid grid-cols-2 gap-2 xl:grid-cols-4">
        <article class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm">
          <p class="text-[11px] text-gray-500">Tipo / capacidad</p>
          <p class="mt-1 truncate font-semibold text-gray-900">
            {{ detail.room.room_type.name }}
            <span class="font-normal text-gray-500">· {{ detail.room.room_type.capacity }} personas</span>
          </p>
        </article>

        <article class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm">
          <p class="text-[11px] text-gray-500">Ubicación</p>
          <p class="mt-1 truncate font-semibold text-gray-900">
            {{ detail.room.floor === null ? 'Piso sin definir' : detail.room.floor === 0 ? 'Planta baja' : `Piso ${detail.room.floor}` }}
            <span class="font-normal text-gray-500">· Hab. {{ detail.room.number }}</span>
          </p>
        </article>

        <article class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm">
          <p class="text-[11px] text-gray-500">Huésped actual</p>
          <p class="mt-1 truncate font-semibold text-gray-900">
            {{ currentStay ? `${currentStay.guest.first_name} ${currentStay.guest.last_name}` : 'Sin huésped' }}
          </p>
        </article>

        <article class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm">
          <p class="text-[11px] text-gray-500">{{ currentStay ? 'Tarifa contratada' : 'Tarifa disponible desde' }}</p>
          <p class="mt-1 font-semibold text-gray-900">
            {{ currentStay ? `${currentStay.rate_name} · ${formatCurrency(currentStay.rate_price)}` : formatCurrency(availableRates[0]?.price ?? 0) }}
          </p>
        </article>
      </section>

      <!-- Cuenta central + pagos y costos internos -->
      <div class="grid grid-cols-1 items-start gap-3 xl:grid-cols-3">
        <section
          v-if="currentStay"
          class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm xl:col-span-2"
        >
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3">
            <div>
              <h2 class="font-bold text-gray-900">Cuenta de la estancia</h2>
              <p class="mt-0.5 text-[11px] text-gray-500">
                Entrada {{ formatDateTime(currentStay.check_in_at) }}
                <span class="mx-1">·</span>
                Salida prevista {{ formatDateTime(currentStay.expected_check_out_at) }}
              </p>
            </div>
            <div class="flex flex-wrap gap-1.5">
              <button type="button" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700" @click="openAction('product')">
                + Producto
              </button>
              <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50" @click="openAction('service')">
                + Servicio
              </button>
              <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50" @click="openAction('payment')">
                Registrar pago
              </button>
              <button type="button" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100" @click="openAction('checkout')">
                Check-out
              </button>
            </div>
          </div>

          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">Concepto</th>
                  <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-gray-500">Cant.</th>
                  <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-gray-500">Precio</th>
                  <th class="px-4 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-gray-500">Importe</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="charge in currentStay.charges" :key="charge.id" class="hover:bg-gray-50">
                  <td class="px-4 py-2">
                    <p class="text-xs font-semibold text-gray-900">{{ charge.description }}</p>
                    <p class="mt-0.5 text-[10px] text-gray-500">{{ chargeTypeLabel(charge.type) }}</p>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2 text-right text-xs text-gray-600">
                    <template v-if="charge.type === 'product'">
                      <div class="flex items-center justify-end gap-1.5">
                        <input
                          v-model.number="draftQuantities[charge.id]"
                          type="number"
                          min="1"
                          max="1000"
                          step="1"
                          :aria-label="`Cantidad de ${charge.description}`"
                          class="w-16 rounded-md border border-gray-300 px-2 py-1 text-right text-xs text-gray-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                          :disabled="savingChargeIds.includes(charge.id)"
                        />
                        <button
                          v-if="isQuantityChanged(charge)"
                          type="button"
                          class="rounded-md bg-blue-600 px-2 py-1 text-[10px] font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                          :disabled="savingChargeIds.includes(charge.id)"
                          @click="saveChargeQuantity(charge)"
                        >
                          {{ savingChargeIds.includes(charge.id) ? 'Guardando…' : 'Guardar' }}
                        </button>
                        <button
                          v-if="isQuantityChanged(charge)"
                          type="button"
                          class="rounded-md border border-gray-200 px-2 py-1 text-[10px] text-gray-600 hover:bg-gray-50 disabled:opacity-50"
                          :disabled="savingChargeIds.includes(charge.id)"
                          @click="resetChargeQuantity(charge)"
                        >
                          Cancelar
                        </button>
                      </div>
                      <p
                        v-if="draftQuantities[charge.id] !== undefined && !isValidDraftQuantity(charge.id)"
                        class="mt-1 max-w-56 text-left text-[10px] text-red-600"
                      >
                        Ingresa una cantidad entera entre 1 y 1000.
                      </p>
                      <p v-if="chargeErrors[charge.id]" class="mt-1 max-w-56 text-left text-[10px] text-red-600">{{ chargeErrors[charge.id] }}</p>
                    </template>
                    <span v-else>{{ charge.quantity }}</span>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2 text-right text-xs text-gray-600">{{ formatCurrency(charge.unit_price) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right text-xs font-semibold text-gray-900">
                    {{ formatCurrency(charge.type === 'product' && isValidDraftQuantity(charge.id) ? Number(charge.unit_price) * Number(draftQuantities[charge.id] ?? charge.quantity) : charge.total) }}
                  </td>
                </tr>
                <tr v-if="!currentStay.charges.length">
                  <td colspan="4" class="px-4 py-5 text-center text-xs text-gray-500">No hay cargos registrados.</td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="chargeNotice" class="border-t border-emerald-100 bg-emerald-50 px-4 py-2 text-xs text-emerald-800">
            {{ chargeNotice }}
          </div>
          <div class="grid grid-cols-3 gap-2 border-t border-gray-100 bg-gray-50 px-4 py-3">
            <div>
              <p class="text-[10px] text-gray-500">Total cargos</p>
              <p class="mt-0.5 font-bold text-gray-900">{{ formatCurrency(previewChargesTotal) }}</p>
            </div>
            <div>
              <p class="text-[10px] text-gray-500">Pagado</p>
              <p class="mt-0.5 font-bold text-emerald-700">{{ formatCurrency(currentStay.account.paid_total) }}</p>
            </div>
            <div class="text-right">
              <p class="text-[10px] text-gray-500">Saldo pendiente</p>
              <p class="mt-0.5 text-base font-bold" :class="previewBalance > 0 ? 'text-red-600' : 'text-emerald-700'">
                {{ formatCurrency(previewBalance) }}
              </p>
            </div>
          </div>
        </section>

        <!-- Habitación libre: tarifas y acción de check-in -->
        <section v-else class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm xl:col-span-2">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="font-bold text-gray-900">Sin estancia activa</h2>
              <p class="mt-0.5 text-xs text-gray-500">Consulta las tarifas disponibles para esta habitación.</p>
            </div>
            <button type="button" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700" @click="openAction('checkin')">
              Registrar check-in
            </button>
          </div>
          <div v-if="availableRates.length" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
            <div v-for="rate in availableRates" :key="rate.id" class="rounded-lg border border-gray-200 p-3">
              <p class="text-xs text-gray-500">{{ rate.name }}</p>
              <p class="mt-1 font-bold text-gray-900">{{ formatCurrency(rate.price) }}</p>
            </div>
          </div>
        </section>

        <aside class="space-y-3">
          <!-- Pagos recibidos -->
          <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3.5 py-3">
              <h2 class="font-bold text-gray-900">Pagos recibidos</h2>
              <span class="text-[11px] text-gray-500">{{ currentStay?.payments.length ?? 0 }} registros</span>
            </div>
            <div v-if="currentStay?.payments.length" class="divide-y divide-gray-100">
              <div v-for="payment in currentStay.payments" :key="payment.id" class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                <div class="min-w-0">
                  <p class="truncate text-xs font-semibold text-gray-900">{{ paymentMethodLabel(payment.method) }}</p>
                  <p class="mt-0.5 text-[10px] text-gray-500">{{ formatDateTime(payment.received_at) }}</p>
                </div>
                <p class="whitespace-nowrap text-sm font-bold text-emerald-700">{{ formatCurrency(payment.amount) }}</p>
              </div>
            </div>
            <p v-else class="px-3.5 py-4 text-xs text-gray-500">Aún no hay pagos registrados.</p>
          </section>

          <!-- Gastos internos -->
          <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3.5 py-3">
              <div>
                <h2 class="font-bold text-gray-900">Gastos de habitación</h2>
                <p class="mt-0.5 text-[10px] text-gray-500">No se cobran al huésped</p>
              </div>
              <p class="whitespace-nowrap text-sm font-bold text-orange-600">{{ formatCurrency(detail.room_expenses.total) }}</p>
            </div>
            <div v-if="detail.room_expenses.items.length" class="divide-y divide-gray-100">
              <div v-for="expense in detail.room_expenses.items" :key="expense.id" class="flex items-start justify-between gap-3 px-3.5 py-2.5">
                <div class="min-w-0">
                  <p class="text-xs font-semibold text-gray-900">{{ expense.description }}</p>
                  <p class="mt-0.5 text-[10px] text-gray-500">{{ expenseCategoryLabel(expense.category) }} · {{ formatDateOnly(expense.expense_date) }}</p>
                  <p v-if="expense.vendor" class="mt-0.5 truncate text-[10px] text-gray-500">{{ expense.vendor }}</p>
                </div>
                <p class="whitespace-nowrap text-xs font-bold text-gray-900">{{ formatCurrency(expense.amount) }}</p>
              </div>
            </div>
            <p v-else class="px-3.5 py-4 text-xs text-gray-500">No hay gastos asignados.</p>
          </section>
        </aside>
      </div>

      <!-- Historial secundario, plegable para ahorrar espacio -->
      <details class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-gray-50">
          <div>
            <h2 class="font-bold text-gray-900">Historial de la habitación</h2>
            <p class="mt-0.5 text-[11px] text-gray-500">Estancias, tarifas, cargos, pagos y saldos anteriores.</p>
          </div>
          <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-medium text-gray-600">
            {{ detail.stay_history.length }} registros
          </span>
        </summary>

        <div class="overflow-x-auto border-t border-gray-100">
          <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">Huésped</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">Entrada / salida</th>
                <th class="px-3 py-2 text-left text-[10px] font-semibold uppercase tracking-wide text-gray-500">Tarifa</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-gray-500">Cargos</th>
                <th class="px-3 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-gray-500">Pagado</th>
                <th class="px-4 py-2 text-right text-[10px] font-semibold uppercase tracking-wide text-gray-500">Saldo</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr v-for="stay in detail.stay_history" :key="stay.id" class="hover:bg-gray-50">
                <td class="whitespace-nowrap px-4 py-2.5">
                  <p class="text-xs font-semibold text-gray-900">{{ stay.guest_name || 'Huésped sin nombre' }}</p>
                  <span class="mt-0.5 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="stay.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'">
                    {{ stay.status === 'active' ? 'Activa' : stay.status }}
                  </span>
                </td>
                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-gray-600">
                  <p>{{ formatDateTime(stay.check_in_at) }}</p>
                  <p class="mt-0.5 text-[10px] text-gray-400">Salida: {{ formatDateTime(stay.actual_check_out_at ?? stay.expected_check_out_at) }}</p>
                </td>
                <td class="whitespace-nowrap px-3 py-2.5 text-xs text-gray-600">
                  <p class="font-medium text-gray-800">{{ stay.rate_name }}</p>
                  <p class="mt-0.5">{{ formatCurrency(stay.rate_price) }}</p>
                </td>
                <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs text-gray-700">{{ formatCurrency(stay.charges_total) }}</td>
                <td class="whitespace-nowrap px-3 py-2.5 text-right text-xs font-medium text-emerald-700">{{ formatCurrency(stay.paid_total) }}</td>
                <td class="whitespace-nowrap px-4 py-2.5 text-right text-xs font-bold" :class="stay.balance > 0 ? 'text-red-600' : 'text-gray-800'">{{ formatCurrency(stay.balance) }}</td>
              </tr>
              <tr v-if="!detail.stay_history.length">
                <td colspan="6" class="px-4 py-6 text-center text-xs text-gray-500">Todavía no hay estancias registradas.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </template>

    <!-- Formulario conectado al catálogo real para agregar productos. -->
    <div
      v-if="activeAction && actionInfo"
      class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
      @click.self="!addingProduct && !addingService && !submittingPayment && (activeAction = null)"
    >
      <section
        role="dialog"
        aria-modal="true"
        :aria-label="actionInfo.title"
        class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl"
      >
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 class="text-lg font-bold text-gray-900">{{ actionInfo.title }}</h2>
            <p v-if="activeAction === 'product'" class="mt-1 text-sm text-gray-500">
              Selecciona un producto del inventario del hotel.
            </p>
            <p v-else-if="activeAction === 'service'" class="mt-1 text-sm text-gray-500">
              Selecciona un servicio activo del hotel. No se descontará inventario.
            </p>
            <p v-else-if="activeAction === 'payment'" class="mt-1 text-sm text-gray-500">
              Registra un abono parcial o el pago total del saldo pendiente.
            </p>
            <p v-else class="mt-2 text-sm leading-6 text-gray-600">
              {{ actionInfo.message }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-lg px-2 py-1 text-gray-500 hover:bg-gray-100 disabled:opacity-50"
            aria-label="Cerrar"
            :disabled="addingProduct || addingService || submittingPayment"
            @click="activeAction = null"
          >✕</button>
        </div>

        <form v-if="activeAction === 'product'" class="mt-4 space-y-4" @submit.prevent="submitProduct">
          <div v-if="productError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ productError }}
          </div>

          <div v-if="productsLoading" class="rounded-lg bg-gray-50 px-3 py-5 text-center text-sm text-gray-500">
            Cargando catálogo...
          </div>

          <template v-else>
            <div>
              <label for="hotel-product" class="mb-1.5 block text-sm font-medium text-gray-700">
                Producto
              </label>
              <select
                id="hotel-product"
                v-model.number="selectedProductId"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                :disabled="addingProduct || !productCatalog.length"
                required
              >
                <option :value="null" disabled>Selecciona un producto</option>
                <option
                  v-for="product in productCatalog"
                  :key="product.id"
                  :value="product.id"
                  :disabled="!product.active || product.stock < 1"
                >
                  {{ product.name }} · {{ formatCurrency(product.price) }} · Stock: {{ product.stock }}
                </option>
              </select>
              <p v-if="!productCatalog.length" class="mt-1.5 text-xs text-gray-500">
                No hay productos activos en el catálogo o no fue posible cargarlos.
              </p>
            </div>

            <div v-if="selectedProduct" class="rounded-lg border border-gray-200 bg-gray-50 p-3">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">{{ selectedProduct.name }}</p>
                  <p v-if="selectedProduct.sku" class="mt-0.5 text-xs text-gray-500">SKU: {{ selectedProduct.sku }}</p>
                  <p v-if="selectedProduct.description" class="mt-1 text-xs text-gray-500">{{ selectedProduct.description }}</p>
                </div>
                <div class="shrink-0 text-right">
                  <p class="text-sm font-bold text-gray-900">{{ formatCurrency(selectedProduct.price) }}</p>
                  <p class="mt-0.5 text-xs" :class="selectedProduct.stock > 0 ? 'text-emerald-700' : 'text-red-600'">
                    Existencias: {{ selectedProduct.stock }}
                  </p>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label for="product-quantity" class="mb-1.5 block text-sm font-medium text-gray-700">
                  Cantidad
                </label>
                <input
                  id="product-quantity"
                  v-model.number="productQuantity"
                  type="number"
                  min="1"
                  :max="selectedProduct?.stock ?? 1"
                  step="1"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                  :disabled="addingProduct || !selectedProduct || selectedProduct.stock < 1"
                  required
                />
              </div>
              <div>
                <p class="mb-1.5 block text-sm font-medium text-gray-700">Importe estimado</p>
                <div class="rounded-lg bg-blue-50 px-3 py-2.5 text-base font-bold text-blue-700">
                  {{ formatCurrency(productPreviewTotal) }}
                </div>
              </div>
            </div>
          </template>

          <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              :disabled="addingProduct || addingService"
              @click="activeAction = null"
            >Cancelar</button>
            <button
              type="submit"
              class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="productsLoading || addingProduct || !selectedProduct || selectedProduct.stock < 1 || !Number.isInteger(Number(productQuantity)) || Number(productQuantity) < 1 || Number(productQuantity) > selectedProduct.stock"
            >
              {{ addingProduct ? 'Agregando...' : 'Agregar a la cuenta' }}
            </button>
          </div>
        </form>

        <form v-else-if="activeAction === 'service'" class="mt-4 space-y-4" @submit.prevent="submitService">
          <div v-if="serviceError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ serviceError }}
          </div>

          <div v-if="servicesLoading" class="rounded-lg bg-gray-50 px-3 py-5 text-center text-sm text-gray-500">
            Cargando servicios...
          </div>

          <template v-else>
            <div>
              <label for="hotel-service" class="mb-1.5 block text-sm font-medium text-gray-700">
                Servicio
              </label>
              <select
                id="hotel-service"
                v-model.number="selectedServiceId"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
                :disabled="addingService || !serviceCatalog.length"
                required
              >
                <option :value="null" disabled>Selecciona un servicio</option>
                <option
                  v-for="service in serviceCatalog"
                  :key="service.id"
                  :value="service.id"
                  :disabled="!service.active"
                >
                  {{ service.name }} · {{ formatCurrency(service.price) }}
                </option>
              </select>
              <p v-if="!serviceCatalog.length && !serviceError" class="mt-1.5 text-xs text-gray-500">
                No hay servicios activos disponibles para este hotel.
              </p>
            </div>

            <div v-if="selectedService" class="rounded-lg border border-gray-200 bg-gray-50 p-3">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">{{ selectedService.name }}</p>
                  <p v-if="selectedService.description" class="mt-1 text-xs text-gray-500">
                    {{ selectedService.description }}
                  </p>
                </div>
                <p class="shrink-0 text-sm font-bold text-gray-900">
                  {{ formatCurrency(selectedService.price) }}
                </p>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
              <div>
                <label for="service-quantity" class="mb-1.5 block text-sm font-medium text-gray-700">
                  Cantidad
                </label>
                <input
                  id="service-quantity"
                  v-model.number="serviceQuantity"
                  type="number"
                  min="1"
                  max="1000"
                  step="1"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                  :disabled="addingService || !selectedService"
                  required
                />
              </div>
              <div>
                <p class="mb-1.5 text-sm font-medium text-gray-700">Importe estimado</p>
                <div class="rounded-lg bg-blue-50 px-3 py-2.5 text-base font-bold text-blue-700">
                  {{ formatCurrency(servicePreviewTotal) }}
                </div>
              </div>
            </div>
          </template>

          <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              :disabled="addingService"
              @click="activeAction = null"
            >Cancelar</button>
            <button
              type="submit"
              class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="servicesLoading || addingService || !selectedService || !Number.isInteger(Number(serviceQuantity)) || Number(serviceQuantity) < 1 || Number(serviceQuantity) > 1000"
            >
              {{ addingService ? 'Agregando...' : 'Agregar a la cuenta' }}
            </button>
          </div>
        </form>

        <form v-else-if="activeAction === 'payment'" class="mt-4 space-y-4" @submit.prevent="submitPayment">
          <div v-if="paymentError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ paymentError }}
          </div>

          <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <div class="flex items-center justify-between gap-3">
              <span class="text-xs text-gray-500">Saldo pendiente actual</span>
              <span class="text-sm font-bold text-red-600">{{ formatCurrency(currentStay?.account.balance ?? 0) }}</span>
            </div>
            <div class="mt-2 flex items-center justify-between gap-3">
              <span class="text-xs text-gray-500">Saldo después del pago</span>
              <span class="text-sm font-bold" :class="paymentPreviewBalance > 0 ? 'text-gray-900' : 'text-emerald-700'">{{ formatCurrency(paymentPreviewBalance) }}</span>
            </div>
            <button
              type="button"
              class="mt-2 text-xs font-semibold text-blue-700 hover:text-blue-800 disabled:opacity-50"
              :disabled="!currentStay || Number(currentStay.account.balance) <= 0 || submittingPayment"
              @click="fillPaymentBalance"
            >Aplicar pago del saldo completo</button>
          </div>

          <div>
            <label for="payment-amount" class="mb-1.5 block text-sm font-medium text-gray-700">Importe del pago</label>
            <input
              id="payment-amount"
              v-model.number="paymentAmount"
              type="number"
              min="0.01"
              :max="currentStay?.account.balance ?? 0"
              step="0.01"
              inputmode="decimal"
              placeholder="Ej. 100.00"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="submittingPayment || !currentStay || Number(currentStay.account.balance) <= 0"
              required
            />
          </div>

          <div>
            <label for="payment-method" class="mb-1.5 block text-sm font-medium text-gray-700">Método de pago</label>
            <select
              id="payment-method"
              v-model="paymentMethod"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="submittingPayment"
              required
            >
              <option value="cash">Efectivo</option>
              <option value="card">Tarjeta</option>
              <option value="transfer">Transferencia</option>
              <option value="other">Otro</option>
            </select>
          </div>

          <div>
            <label for="payment-reference" class="mb-1.5 block text-sm font-medium text-gray-700">Referencia <span class="font-normal text-gray-400">(opcional)</span></label>
            <input
              id="payment-reference"
              v-model.trim="paymentReference"
              type="text"
              maxlength="255"
              placeholder="Folio, autorización o referencia"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="submittingPayment"
            />
          </div>

          <div>
            <label for="payment-notes" class="mb-1.5 block text-sm font-medium text-gray-700">Notas <span class="font-normal text-gray-400">(opcional)</span></label>
            <textarea
              id="payment-notes"
              v-model.trim="paymentNotes"
              rows="2"
              maxlength="1000"
              placeholder="Observaciones del pago"
              class="w-full resize-y rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="submittingPayment"
            />
          </div>

          <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              :disabled="submittingPayment"
              @click="activeAction = null"
            >Cancelar</button>
            <button
              type="submit"
              class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="submittingPayment || !isValidPaymentAmount || !currentStay || Number(currentStay.account.balance) <= 0"
            >
              {{ submittingPayment ? 'Registrando…' : 'Confirmar pago' }}
            </button>
          </div>
        </form>

        <template v-else>
          <div class="mt-5 flex justify-end">
            <button type="button" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800" @click="activeAction = null">
              Entendido
            </button>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>
