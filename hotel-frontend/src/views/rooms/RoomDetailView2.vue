<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  getRoomDetail,
  type RoomDetailResponse,
} from '@/services/room-detail'
import { addPaymentToStay, addProductToStay, addServiceToStay, checkoutStay, updateChargeQuantity } from '@/services/stay-charges'
import { getHotelProducts, type HotelProduct } from '@/services/products'
import { getHotelServices, type HotelService } from '@/services/services'
import {
  completeRoomCleaning,
  getLatestRoomStay,
  type HistoricalRoomStay,
} from '@/services/room-lifecycle'
import {
  checkInRoom,
  getHotelGuests,
  type HotelGuest,
} from '@/services/stay-checkin'
import { extendStay } from '@/services/stay-extension'
import { getHotelRates, type HotelRoomRate } from '@/services/room-rates'

const route = useRoute()

// Cada operación se ejecuta bajo el hotel asignado al usuario autenticado.
const auth = useAuthStore()

function getRequiredHotelId(): number {
  const id = auth.currentHotelId
  if (id === null || !Number.isSafeInteger(id) || id <= 0) {
    throw new Error('Tu usuario no tiene un hotel activo válido. Inicia sesión nuevamente.')
  }
  return id
}

const detail = ref<RoomDetailResponse | null>(null)
const hotelRateCatalog = ref<HotelRoomRate[]>([])
const rateCatalogLoading = ref(false)
const rateCatalogError = ref('')
const loading = ref(true)
const errorMessage = ref('')
const historicalStay = ref<HistoricalRoomStay | null>(null)
const historicalStayError = ref('')
const cleaningSubmitting = ref(false)
const cleaningError = ref('')
const activeAction = ref<null | 'product' | 'service' | 'payment' | 'extension' | 'checkout' | 'checkout-after-payment' | 'checkin'>(null)

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
const checkoutSubmitting = ref(false)
const checkoutError = ref('')
const extensionSubmitting = ref(false)
const extensionError = ref('')
const extensionExpectedCheckOutAt = ref('')
const extensionAdditionalCharge = ref<number | string>('')
const extensionAgreementNotes = ref('')

// Estado del formulario de check-in.
type CheckInMode = 'existing' | 'new'
const checkInMode = ref<CheckInMode>('existing')
const guestCatalog = ref<HotelGuest[]>([])
const guestSearch = ref('')
const selectedGuestId = ref<number | null>(null)
const guestsLoading = ref(false)
const checkInSubmitting = ref(false)
const checkInError = ref('')
const selectedCheckInRateId = ref<number | 'manual' | null>(null)
const manualRateName = ref('Tarifa especial')
const manualRateDuration = ref<number | string>(24)
const manualRateUnit = ref('horas')
const manualRatePrice = ref<number | string>('')
const expectedCheckOutAt = ref('')
const checkInNotes = ref('')
const newGuest = ref({
  first_name: '',
  last_name: '',
  phone: '',
  email: '',
  document_type: '',
  document_number: '',
})

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
    extension: {
      title: 'Extender o corregir salida',
      message: 'Puedes actualizar la salida prevista sin cargo si solo corriges un horario, o capturar un importe si se autorizó una extensión. El sistema no cerrará automáticamente la estancia.',
    },
    checkout: {
      title: 'Registrar check-out',
      message: 'Confirma el cierre de la estancia. La cuenta debe estar liquidada y no debe haber cantidades modificadas sin guardar.',
    },
    'checkout-after-payment': {
      title: 'Cuenta liquidada',
      message: 'El pago se registró correctamente y la cuenta quedó en $0.00. ¿Deseas registrar el check-out ahora?',
    },
    checkin: {
      title: 'Registrar check-in',
      message: 'Selecciona un huésped, elige una tarifa y confirma la salida prevista para iniciar una nueva estancia.',
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

const formatGuestName = (guest: { first_name?: string | null; last_name?: string | null } | null | undefined) => {
  const name = [guest?.first_name?.trim(), guest?.last_name?.trim()]
    .filter((part): part is string => Boolean(part))
    .join(' ')

  return name || 'Huésped sin nombre registrado'
}

const currentStay = computed(() => detail.value?.current_stay ?? null)
const displayStay = computed(() => currentStay.value ?? historicalStay.value)
const historyLink = computed(() => {
  const room = detail.value?.room.number
  const recentDate = !currentStay.value
    ? (historicalStay.value?.actual_check_out_at ?? historicalStay.value?.check_in_at)
    : null

  let date: string | undefined
  if (recentDate) {
    const parsed = new Date(recentDate)
    if (!Number.isNaN(parsed.getTime())) {
      const year = parsed.getFullYear()
      const month = String(parsed.getMonth() + 1).padStart(2, '0')
      const day = String(parsed.getDate()).padStart(2, '0')
      date = `${year}-${month}-${day}`
    }
  }

  return {
    name: 'daily-history',
    query: {
      ...(room !== undefined ? { room: String(room) } : {}),
      ...(date ? { date } : {}),
    },
  }
})
const recentPayments = computed(() =>
  [...(displayStay.value?.payments ?? [])]
    .sort((a, b) => new Date(b.received_at).getTime() - new Date(a.received_at).getTime())
    .slice(0, 3),
)
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
const paymentAppliedAmount = computed(() => {
  const balance = Math.max(0, Number(currentStay.value?.account.balance ?? 0))
  const amount = Number(paymentAmount.value)
  if (!Number.isFinite(amount) || amount <= 0) return 0
  return Math.min(Math.round(amount * 100) / 100, balance)
})
const paymentChangeDue = computed(() => {
  if (paymentMethod.value !== 'cash') return 0
  const amount = Number(paymentAmount.value)
  const balance = Math.max(0, Number(currentStay.value?.account.balance ?? 0))
  if (!Number.isFinite(amount) || amount <= balance) return 0
  return Math.round((amount - balance) * 100) / 100
})
const paymentPreviewBalance = computed(() => {
  const balance = Number(currentStay.value?.account.balance ?? 0)
  return Math.max(0, Math.round((balance - paymentAppliedAmount.value) * 100) / 100)
})
const isValidPaymentAmount = computed(() => {
  const amount = Number(paymentAmount.value)
  const balance = Number(currentStay.value?.account.balance ?? 0)

  return Number.isFinite(amount)
    && amount >= 0.01
    && (paymentMethod.value === 'cash' || Math.round(amount * 100) / 100 <= balance)
})
const availableRates = computed(() => hotelRateCatalog.value.filter(rate => rate.active))

const selectedCheckInRate = computed(() => {
  if (typeof selectedCheckInRateId.value !== 'number') return null
  return availableRates.value.find(rate => rate.id === selectedCheckInRateId.value) ?? null
})
const isManualRateSelected = computed(() => selectedCheckInRateId.value === 'manual')
const manualRateIsValid = computed(() => {
  const name = manualRateName.value.trim()
  const duration = Number(manualRateDuration.value)
  const price = Number(manualRatePrice.value)
  const validUnits = ['minutos', 'horas', 'días', 'noches', 'semanas']

  return name.length > 0
    && name.length <= 100
    && Number.isInteger(duration)
    && duration >= 1
    && duration <= 10000
    && validUnits.includes(manualRateUnit.value)
    && Number.isFinite(price)
    && price > 0
    && price <= 99999999.99
})
const checkInRateIsReady = computed(() =>
  isManualRateSelected.value ? manualRateIsValid.value : selectedCheckInRate.value !== null,
)
const checkInInitialPrice = computed(() => {
  if (isManualRateSelected.value) {
    const price = Number(manualRatePrice.value)
    return manualRateIsValid.value ? Math.round(price * 100) / 100 : 0
  }
  return Number(selectedCheckInRate.value?.price ?? 0)
})

const onCheckInRateChange = () => {
  if (isManualRateSelected.value) {
    expectedCheckOutAt.value = suggestedCheckoutForRate({
      duration: Number(manualRateDuration.value) || 1,
      unit: manualRateUnit.value,
    })
    return
  }
  expectedCheckOutAt.value = suggestedCheckoutForRate(selectedCheckInRate.value)
}

const updateManualRateCheckout = () => {
  if (!isManualRateSelected.value) return
  expectedCheckOutAt.value = suggestedCheckoutForRate({
    duration: Number(manualRateDuration.value) || 1,
    unit: manualRateUnit.value,
  })
}

const toLocalDateTimeInput = (date: Date): string => {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
}

// datetime-local no incluye zona horaria; interpretamos sus componentes como hora local.
const parseLocalDateTimeInput = (value: string): Date => {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value)
  if (!match) return new Date(Number.NaN)

  const [, year, month, day, hours, minutes] = match
  const parsed = new Date(
    Number(year), Number(month) - 1, Number(day), Number(hours), Number(minutes), 0, 0,
  )

  // Evita aceptar fechas imposibles que JavaScript normalizaría automáticamente.
  if (
    parsed.getFullYear() !== Number(year)
    || parsed.getMonth() !== Number(month) - 1
    || parsed.getDate() !== Number(day)
    || parsed.getHours() !== Number(hours)
    || parsed.getMinutes() !== Number(minutes)
  ) return new Date(Number.NaN)

  return parsed
}

const expectedCheckOutMin = computed(() => {
  // Mantener un pequeño margen para que la fecha no venza durante la confirmación.
  const minimum = new Date()
  minimum.setMinutes(minimum.getMinutes() + 5, 0, 0)
  return toLocalDateTimeInput(minimum)
})
const extensionCheckOutMin = computed(() => {
  // Campo de importe vacío = corrección administrativa; puede ajustar la hora
  // en cualquier dirección, pero nunca al pasado. Con importe explícito, solo
  // se permite extender más allá de la salida actual.
  const chargeInput = String(extensionAdditionalCharge.value).trim()
  if (chargeInput === '') return expectedCheckOutMin.value

  const current = currentStay.value?.expected_check_out_at
  const currentTime = current ? new Date(current).getTime() : 0
  const minimumTime = Math.max(Date.now(), Number.isFinite(currentTime) ? currentTime : 0)
  return toLocalDateTimeInput(new Date(minimumTime))
})
const extensionChargeValue = computed(() => {
  if (String(extensionAdditionalCharge.value).trim() === '') return null
  const amount = Number(extensionAdditionalCharge.value)
  if (!Number.isFinite(amount) || amount < 0) return null
  return Math.round(amount * 100) / 100
})
const extensionBalancePreview = computed(() =>
  Math.round((Number(currentStay.value?.account.balance ?? 0) + Number(extensionChargeValue.value ?? 0)) * 100) / 100,
)
const isValidStayExtension = computed(() => {
  if (!currentStay.value || !extensionExpectedCheckOutAt.value) return false

  // Un importe vacío es válido: solo se corregirá la salida prevista.
  // Si sí se escribió algo, debe ser un importe numérico no negativo.
  const chargeInput = String(extensionAdditionalCharge.value).trim()
  if (chargeInput !== '' && extensionChargeValue.value === null) return false

  const newCheckOut = parseLocalDateTimeInput(extensionExpectedCheckOutAt.value)
  if (Number.isNaN(newCheckOut.getTime()) || newCheckOut.getTime() <= Date.now()) return false
  const currentCheckOut = currentStay.value.expected_check_out_at
    ? new Date(currentStay.value.expected_check_out_at)
    : null
  if (chargeInput !== '' && currentCheckOut && newCheckOut.getTime() <= currentCheckOut.getTime()) return false
  return true
})
const isValidCheckIn = computed(() => {
  if (!detail.value || detail.value.room.status !== 'available' || !checkInRateIsReady.value) return false
  const checkOut = parseLocalDateTimeInput(expectedCheckOutAt.value)
  if (!expectedCheckOutAt.value || Number.isNaN(checkOut.getTime()) || checkOut.getTime() <= Date.now()) return false
  if (checkInMode.value === 'existing') return selectedGuestId.value !== null
  // Los campos personales del huésped son opcionales; la tarifa y la salida sí son obligatorias.
  return true
})

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

const hasUnsavedChargeQuantities = computed(() =>
  currentStay.value?.charges.some(charge => {
    if (charge.type !== 'product') return false
    return !isValidDraftQuantity(charge.id) || isQuantityChanged(charge)
  }) ?? false,
)

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
      hotelId: getRequiredHotelId(),
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

const stayStatusLabel = (status: string) => {
  const labels: Record<string, string> = {
    active: 'Activa',
    completed: 'Cerrada',
    cancelled: 'Cancelada',
  }
  return labels[status] ?? status
}

const chargeTypeLabel = (type: string) => {
  const labels: Record<string, string> = {
    room: 'Habitación',
    product: 'Producto',
    service: 'Servicio',
    other: 'Cargo adicional',
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

const loadDetail = async () => {
  const roomNumber = String(route.params.id ?? '')
  const activeHotelId = auth.currentHotelId
  if (activeHotelId === null || !Number.isSafeInteger(activeHotelId) || activeHotelId <= 0) {
    detail.value = null
    historicalStay.value = null
    errorMessage.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente.'
    loading.value = false
    return
  }
  if (!roomNumber) {
    errorMessage.value = 'No se indicó el número de habitación.'
    loading.value = false
    return
  }

  loading.value = true
  errorMessage.value = ''

  try {
    detail.value = await getRoomDetail(activeHotelId, roomNumber)
    historicalStay.value = null
    historicalStayError.value = ''
    cleaningError.value = ''

    // Cuando ya no existe una estancia activa, traer el detalle de la última
    // estancia cerrada para conservar visibles sus cargos y pagos.
    if (!detail.value.current_stay) {
      try {
        historicalStay.value = await getLatestRoomStay(activeHotelId, roomNumber)
      } catch (historyError) {
        console.error('Error al cargar el detalle de la última estancia:', historyError)
        historicalStayError.value = 'No fue posible cargar el detalle financiero de la última estancia.'
      }
    }

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
    productCatalog.value = await getHotelProducts(getRequiredHotelId())

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
    serviceCatalog.value = await getHotelServices(getRequiredHotelId())
    selectedServiceId.value = serviceCatalog.value[0]?.id ?? null
  } catch (error) {
    console.error('Error al cargar el catálogo de servicios:', error)
    serviceError.value = getServiceErrorMessage(error)
  } finally {
    servicesLoading.value = false
  }
}

const loadGuests = async (search = guestSearch.value) => {
  guestsLoading.value = true
  checkInError.value = ''

  try {
    guestCatalog.value = await getHotelGuests(getRequiredHotelId(), search)
    if (selectedGuestId.value !== null && !guestCatalog.value.some(guest => guest.id === selectedGuestId.value)) {
      selectedGuestId.value = null
    }
    const onlyGuest = guestCatalog.value[0]
    if (selectedGuestId.value === null && onlyGuest) {
      selectedGuestId.value = onlyGuest.id
    }
  } catch (error) {
    console.error('Error al cargar huéspedes del hotel:', error)
    checkInError.value = getCheckInErrorMessage(error)
  } finally {
    guestsLoading.value = false
  }
}

const suggestedCheckoutForRate = (rate: { duration?: number; unit?: string } | null): string => {
  const date = new Date()
  const duration = Math.max(1, Number(rate?.duration ?? 1))
  const unit = String(rate?.unit ?? '').toLowerCase()

  if (/(hora|hour|hr)/.test(unit)) {
    date.setHours(date.getHours() + duration)
  } else if (/(minut|min)/.test(unit)) {
    date.setMinutes(date.getMinutes() + duration)
  } else if (/(semana|week)/.test(unit)) {
    date.setDate(date.getDate() + duration * 7)
  } else {
    // Para tarifas expresadas en días/noches se suma la duración en días.
    date.setDate(date.getDate() + duration)
  }

  return toLocalDateTimeInput(date)
}

const loadGlobalRateCatalog = async () => {
  if (rateCatalogLoading.value) return
  const activeHotelId = auth.currentHotelId
  if (activeHotelId === null || !Number.isSafeInteger(activeHotelId) || activeHotelId <= 0) {
    hotelRateCatalog.value = []
    rateCatalogError.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente.'
    rateCatalogLoading.value = false
    if (activeAction.value === 'checkin') checkInError.value = rateCatalogError.value
    return
  }
  rateCatalogLoading.value = true
  rateCatalogError.value = ''
  try {
    hotelRateCatalog.value = (await getHotelRates(activeHotelId))
      .filter(rate => !rate.name.startsWith('MANUAL#') && !rate.name.startsWith('LEGACY#'))
      .sort((a, b) => Number(b.active) - Number(a.active) || a.duration - b.duration || a.name.localeCompare(b.name, 'es-MX'))

    // Al abrir check-in selecciona la primera tarifa global activa, si existe.
    if (activeAction.value === 'checkin') {
      const firstRate = availableRates.value[0]
      selectedCheckInRateId.value = firstRate?.id ?? 'manual'
      expectedCheckOutAt.value = firstRate
        ? suggestedCheckoutForRate(firstRate)
        : suggestedCheckoutForRate({ duration: 24, unit: 'horas' })
    }
  } catch (error) {
    console.error('Error al cargar las tarifas globales del hotel:', error)
    rateCatalogError.value = 'No fue posible cargar el catálogo de tarifas del hotel. Puedes usar una tarifa manual o intentar recargar.'
    if (activeAction.value === 'checkin') {
      checkInError.value = rateCatalogError.value
    }
  } finally {
    rateCatalogLoading.value = false
  }
}

const getCheckInErrorMessage = (error: unknown): string => {
  const response = (error as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[]>
      }
    }
  })?.response
  const errors = response?.data?.errors

  return errors?.room?.[0]
    ?? errors?.room_rate_id?.[0]
    ?? errors?.['manual_rate.name']?.[0]
    ?? errors?.['manual_rate.duration']?.[0]
    ?? errors?.['manual_rate.unit']?.[0]
    ?? errors?.['manual_rate.price']?.[0]
    ?? errors?.guest_id?.[0]
    ?? errors?.['guest.first_name']?.[0]
    ?? errors?.['guest.last_name']?.[0]
    ?? errors?.expected_check_out_at?.[0]
    ?? response?.data?.message
    ?? 'No fue posible registrar el check-in. Verifica los datos e inténtalo de nuevo.'
}

const getExtensionErrorMessage = (error: unknown): string => {
  const response = (error as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[]>
      }
    }
  })?.response
  const errors = response?.data?.errors

  return errors?.stay?.[0]
    ?? errors?.expected_check_out_at?.[0]
    ?? errors?.additional_charge?.[0]
    ?? errors?.agreement_notes?.[0]
    ?? response?.data?.message
    ?? 'No fue posible extender la estancia. Verifica la información e inténtalo nuevamente.'
}

const suggestedExtensionCheckOut = (): string => {
  const currentValue = currentStay.value?.expected_check_out_at
  let target = currentValue ? new Date(currentValue) : new Date()
  if (Number.isNaN(target.getTime()) || target.getTime() < Date.now()) {
    target = new Date()
  }
  target.setHours(target.getHours() + 1)
  return toLocalDateTimeInput(target)
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

  if (action === 'checkout') {
    checkoutError.value = ''
  }

  if (action === 'extension') {
    extensionError.value = ''
    extensionExpectedCheckOutAt.value = suggestedExtensionCheckOut()
    extensionAdditionalCharge.value = ''
    extensionAgreementNotes.value = ''
  }

  if (action === 'checkin') {
    checkInMode.value = 'existing'
    guestCatalog.value = []
    guestSearch.value = ''
    selectedGuestId.value = null
    guestsLoading.value = false
    checkInError.value = ''
    rateCatalogError.value = ''
    selectedCheckInRateId.value = availableRates.value[0]?.id ?? 'manual'
    manualRateName.value = 'Tarifa especial'
    manualRateDuration.value = 24
    manualRateUnit.value = 'horas'
    manualRatePrice.value = ''
    expectedCheckOutAt.value = availableRates.value.length
      ? suggestedCheckoutForRate(availableRates.value[0])
      : suggestedCheckoutForRate({ duration: 24, unit: 'horas' })
    checkInNotes.value = ''
    newGuest.value = {
      first_name: '',
      last_name: '',
      phone: '',
      email: '',
      document_type: '',
      document_number: '',
    }
    void loadGuests('')
    void loadGlobalRateCatalog()
  }
}

const submitCheckIn = async () => {
  if (!detail.value) {
    checkInError.value = 'No se pudo identificar la habitación.'
    return
  }

  if (detail.value.room.status !== 'available') {
    checkInError.value = 'La habitación debe estar disponible para registrar una entrada.'
    return
  }

  const rate = selectedCheckInRate.value
  const manualRateSelected = isManualRateSelected.value
  if (!manualRateSelected && !rate) {
    checkInError.value = 'Selecciona una tarifa activa o elige otra tarifa para capturarla manualmente.'
    return
  }
  if (manualRateSelected && !manualRateIsValid.value) {
    checkInError.value = 'Completa el concepto, duración, unidad y un precio válido para la tarifa manual.'
    return
  }

  const checkoutDate = parseLocalDateTimeInput(expectedCheckOutAt.value)
  if (!expectedCheckOutAt.value || Number.isNaN(checkoutDate.getTime()) || checkoutDate.getTime() <= Date.now()) {
    checkInError.value = 'La salida prevista debe ser posterior a la fecha y hora actuales. Selecciona una hora futura y vuelve a intentarlo.'
    return
  }

  if (checkInMode.value === 'existing' && selectedGuestId.value === null) {
    checkInError.value = 'Selecciona un huésped existente o registra uno nuevo.'
    return
  }

  checkInSubmitting.value = true
  checkInError.value = ''

  try {
    const result = await checkInRoom({
      hotelId: getRequiredHotelId(),
      roomNumber: String(detail.value.room.number),
      ...(manualRateSelected
        ? {
            manualRate: {
              name: manualRateName.value.trim(),
              duration: Number(manualRateDuration.value),
              unit: manualRateUnit.value,
              price: Math.round(Number(manualRatePrice.value) * 100) / 100,
            },
          }
        : { roomRateId: rate!.id }),
      expectedCheckOutAt: checkoutDate.toISOString(),
      notes: checkInNotes.value.trim() || null,
      ...(checkInMode.value === 'existing'
        ? { guestId: Number(selectedGuestId.value) }
        : {
            guest: {
              first_name: newGuest.value.first_name.trim() || null,
              last_name: newGuest.value.last_name.trim() || null,
              phone: newGuest.value.phone.trim() || null,
              email: newGuest.value.email.trim() || null,
              document_type: newGuest.value.document_type.trim() || null,
              document_number: newGuest.value.document_number.trim() || null,
            },
          }),
    })

    activeAction.value = null
    await loadDetail()
    showChargeNotice(result.message)
  } catch (error) {
    console.error('Error al registrar el check-in:', error)
    checkInError.value = getCheckInErrorMessage(error)
  } finally {
    checkInSubmitting.value = false
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
      hotelId: getRequiredHotelId(),
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
      hotelId: getRequiredHotelId(),
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

  if (amount > balance && paymentMethod.value !== 'cash') {
    paymentError.value = `El pago con tarjeta o transferencia no puede superar el saldo pendiente de ${formatCurrency(balance)}.`
    return
  }

  submittingPayment.value = true
  paymentError.value = ''

  try {
    const result = await addPaymentToStay({
      hotelId: getRequiredHotelId(),
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

    const balanceAfterPayment = Math.round(Number(result.account.balance) * 100) / 100
    if (balanceAfterPayment === 0) {
      // La cuenta ya está liquidada. Preguntamos antes de cerrar la estancia:
      // el huésped puede seguir ocupando la habitación aunque ya haya pagado.
      checkoutError.value = ''
      activeAction.value = 'checkout-after-payment'
    } else {
      activeAction.value = null
      // El cambio ya se previsualiza dentro del formulario antes de confirmar.
      showChargeNotice(`Pago de ${formatCurrency(saved.amount)} registrado correctamente.`)
    }
  } catch (error) {
    console.error('Error al registrar el pago:', error)
    paymentError.value = getPaymentErrorMessage(error)
  } finally {
    submittingPayment.value = false
  }
}

const submitExtension = async () => {
  if (!detail.value || !currentStay.value) {
    extensionError.value = 'No hay una estancia activa para extender.'
    return
  }

  const additionalCharge = extensionChargeValue.value
  const chargeInput = String(extensionAdditionalCharge.value).trim()
  if (chargeInput !== '' && additionalCharge === null) {
    extensionError.value = 'El cargo adicional debe ser un importe válido igual o mayor que cero.'
    return
  }

  const newCheckOutDate = new Date(extensionExpectedCheckOutAt.value)
  const currentCheckOutDate = currentStay.value.expected_check_out_at
    ? new Date(currentStay.value.expected_check_out_at)
    : null
  const isCorrectionOnly = chargeInput === ''

  if (
    Number.isNaN(newCheckOutDate.getTime())
    || newCheckOutDate.getTime() <= Date.now()
    || (!isCorrectionOnly && currentCheckOutDate && newCheckOutDate.getTime() <= currentCheckOutDate.getTime())
  ) {
    extensionError.value = isCorrectionOnly
      ? 'La fecha y hora corregidas deben ser posteriores al momento actual.'
      : 'La nueva salida debe ser posterior a la salida prevista actual cuando se registra un cargo.'
    return
  }

  extensionSubmitting.value = true
  extensionError.value = ''

  try {
    const result = await extendStay({
      hotelId: getRequiredHotelId(),
      roomNumber: String(detail.value.room.number),
      stayId: currentStay.value.id,
      expectedCheckOutAt: extensionExpectedCheckOutAt.value,
      additionalCharge,
      agreementNotes: extensionAgreementNotes.value.trim() || null,
    })

    activeAction.value = null
    await loadDetail()
    showChargeNotice(result.message)
  } catch (error) {
    console.error('Error al extender la estancia:', error)
    extensionError.value = getExtensionErrorMessage(error)
  } finally {
    extensionSubmitting.value = false
  }
}

const submitCheckout = async () => {
  if (!detail.value || !currentStay.value) {
    checkoutError.value = 'No hay una estancia activa para cerrar.'
    return
  }

  if (hasUnsavedChargeQuantities.value) {
    checkoutError.value = 'Guarda o cancela los cambios de cantidades antes de realizar el check-out.'
    return
  }

  const balance = Math.round(Number(currentStay.value.account.balance) * 100) / 100
  if (balance > 0) {
    checkoutError.value = `No es posible cerrar la estancia. Falta liquidar ${formatCurrency(balance)}.`
    return
  }
  if (balance < 0) {
    checkoutError.value = 'La cuenta presenta un saldo a favor. Revisa los cargos y pagos antes de continuar.'
    return
  }

  checkoutSubmitting.value = true
  checkoutError.value = ''

  try {
    const result = await checkoutStay({
      hotelId: getRequiredHotelId(),
      roomNumber: String(detail.value.room.number),
      stayId: currentStay.value.id,
    })

    activeAction.value = null
    await loadDetail()
    showChargeNotice(result.message)
  } catch (error) {
    console.error('Error al realizar el check-out:', error)
    const response = (error as {
      response?: {
        data?: {
          message?: string
          errors?: Record<string, string[]>
        }
      }
    })?.response

    checkoutError.value = response?.data?.errors?.balance?.[0]
      ?? response?.data?.errors?.stay?.[0]
      ?? response?.data?.message
      ?? 'No fue posible realizar el check-out. Verifica la cuenta e inténtalo nuevamente.'
  } finally {
    checkoutSubmitting.value = false
  }
}

const completeCleaning = async () => {
  if (!detail.value || detail.value.room.status !== 'cleaning') return

  cleaningSubmitting.value = true
  cleaningError.value = ''

  try {
    const result = await completeRoomCleaning(
      getRequiredHotelId(),
      String(detail.value.room.number),
    )

    detail.value.room.status = result.room.status
    showChargeNotice(result.message)
  } catch (error) {
    console.error('Error al finalizar la limpieza:', error)
    const response = (error as {
      response?: {
        data?: {
          message?: string
          errors?: Record<string, string[]>
        }
      }
    })?.response

    cleaningError.value = response?.data?.errors?.room?.[0]
      ?? response?.data?.message
      ?? 'No fue posible finalizar la limpieza. Inténtalo de nuevo.'
  } finally {
    cleaningSubmitting.value = false
  }
}

watch(
  () => [String(route.params.id ?? ''), auth.currentHotelId] as const,
  () => {
    detail.value = null
    historicalStay.value = null
    hotelRateCatalog.value = []
    productCatalog.value = []
    serviceCatalog.value = []
    guestCatalog.value = []
    void loadDetail()
    void loadGlobalRateCatalog()
  },
  { immediate: true },
)
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

    <div v-if="chargeNotice" role="status" class="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-2 text-xs text-emerald-800">
      {{ chargeNotice }}
    </div>

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
          <p class="text-[11px] text-gray-500">{{ currentStay ? 'Huésped actual' : historicalStay ? 'Último huésped' : 'Huésped actual' }}</p>
          <p class="mt-1 truncate font-semibold text-gray-900">
            {{ currentStay ? formatGuestName(currentStay.guest) : historicalStay ? formatGuestName(historicalStay.guest) : 'Sin huésped' }}
          </p>
        </article>

        <article class="rounded-xl border border-gray-200 bg-white px-3 py-2.5 shadow-sm">
          <p class="text-[11px] text-gray-500">{{ currentStay ? 'Tarifa contratada' : historicalStay ? 'Última tarifa contratada' : 'Tarifa disponible desde' }}</p>
          <p class="mt-1 font-semibold text-gray-900">
            {{ currentStay ? `${currentStay.rate_name} · ${formatCurrency(currentStay.rate_price)}` : historicalStay ? `${historicalStay.rate_name} · ${formatCurrency(historicalStay.rate_price)}` : formatCurrency(availableRates[0]?.price ?? 0) }}
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
              <button type="button" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100" @click="openAction('extension')">
                Extender estancia
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

        <!-- Estado operativo cuando no hay una estancia activa -->
        <section v-else class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm xl:col-span-2">
          <template v-if="detail.room.status === 'cleaning'">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 class="font-bold text-gray-900">Limpieza pendiente</h2>
                <p class="mt-1 text-xs text-gray-500">
                  La estancia anterior está cerrada. Verifica la habitación y márcala disponible cuando termine la limpieza.
                </p>
              </div>
              <button
                type="button"
                class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="cleaningSubmitting"
                @click="completeCleaning"
              >
                {{ cleaningSubmitting ? 'Guardando…' : 'Marcar habitación limpia' }}
              </button>
            </div>
            <div v-if="cleaningError" class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
              {{ cleaningError }}
            </div>
          </template>

          <template v-else-if="detail.room.status === 'available'">
            <div class="flex items-center justify-between gap-3">
              <div>
                <h2 class="font-bold text-gray-900">Sin estancia activa</h2>
                <p class="mt-0.5 text-xs text-gray-500">La habitación está disponible para recibir un nuevo huésped.</p>
              </div>
              <button type="button" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700" @click="openAction('checkin')">
                Registrar check-in
              </button>
            </div>
          </template>

          <template v-else>
            <h2 class="font-bold text-gray-900">{{ roomStatusLabel(detail.room.status) }}</h2>
            <p class="mt-1 text-xs text-gray-500">No se puede registrar un check-in mientras la habitación no esté disponible.</p>
          </template>
          <div v-if="historicalStayError" class="mt-3 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            {{ historicalStayError }}
          </div>
        </section>

        <aside class="space-y-3">
          <!-- Pagos recibidos -->
          <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-3.5 py-3">
              <h2 class="font-bold text-gray-900">Pagos recibidos</h2>
              <span class="text-[11px] text-gray-500">{{ displayStay?.payments.length ?? 0 }} registros</span>
            </div>
            <div v-if="displayStay && displayStay.payments.length" class="divide-y divide-gray-100">
              <div v-for="payment in recentPayments" :key="payment.id" class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                <div class="min-w-0">
                  <p class="truncate text-xs font-semibold text-gray-900">{{ paymentMethodLabel(payment.method) }}</p>
                  <p class="mt-0.5 text-[10px] text-gray-500">{{ formatDateTime(payment.received_at) }}</p>
                </div>
                <p class="whitespace-nowrap text-sm font-bold text-emerald-700">{{ formatCurrency(payment.amount) }}</p>
              </div>
            </div>
            <p v-else class="px-3.5 py-4 text-xs text-gray-500">Aún no hay pagos para la estancia mostrada.</p>
          </section>

          <!-- Acceso al historial diario: los gastos internos se consultan en su propio módulo -->
          <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-3.5 py-3">
              <div class="flex min-w-0 items-start gap-2">
                <span class="mt-0.5 text-gray-400" aria-hidden="true">◷</span>
                <div class="min-w-0">
                  <h2 class="font-bold text-gray-900">Historial del día</h2>
                  <p class="mt-0.5 text-[10px] leading-4 text-gray-500">
                    Check-ins, check-outs, cargos y pagos por fecha.
                  </p>
                </div>
              </div>
              <RouterLink
                :to="historyLink"
                class="shrink-0 rounded-lg bg-gray-900 px-3 py-2 text-[11px] font-semibold text-white transition hover:bg-gray-800"
              >
                Abrir
              </RouterLink>
            </div>
            <div class="px-3.5 py-3">
              <p class="text-xs leading-5 text-gray-500">
                Consulta los movimientos operativos y financieros sin saturar la ficha de esta habitación.
              </p>
            </div>
          </section>
        </aside>
      </div>

      <!-- La ficha conserva solamente el resumen de la última estancia.
           Los movimientos detallados se consultan en el módulo Historial del día. -->
      <section
        v-if="!currentStay && historicalStay"
        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
      >
        <div class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="font-bold text-gray-900">Última estancia</h2>
              <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-600">
                {{ stayStatusLabel(historicalStay.status) }}
              </span>
            </div>
            <p class="mt-1 truncate text-xs text-gray-700">
              {{ formatGuestName(historicalStay.guest) }}
            </p>
            <p class="mt-0.5 text-[11px] text-gray-500">
              Entrada {{ formatDateTime(historicalStay.check_in_at) }}
              <span class="mx-1">·</span>
              Salida {{ formatDateTime(historicalStay.actual_check_out_at ?? historicalStay.expected_check_out_at) }}
            </p>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-2 border-t border-gray-100 bg-gray-50 px-4 py-3">
          <div>
            <p class="text-[10px] text-gray-500">Total de cargos</p>
            <p class="mt-0.5 font-bold text-gray-900">{{ formatCurrency(historicalStay.account.charges_total) }}</p>
          </div>
          <div>
            <p class="text-[10px] text-gray-500">Total pagado</p>
            <p class="mt-0.5 font-bold text-emerald-700">{{ formatCurrency(historicalStay.account.paid_total) }}</p>
          </div>
          <div class="text-right">
            <p class="text-[10px] text-gray-500">Saldo final</p>
            <p class="mt-0.5 font-bold" :class="historicalStay.account.balance > 0 ? 'text-red-600' : 'text-emerald-700'">
              {{ formatCurrency(historicalStay.account.balance) }}
            </p>
          </div>
        </div>
        <p class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-500">
          Se muestra únicamente el resumen más reciente. Los cargos y movimientos anteriores se consultan por fecha en Historial del día.
        </p>
      </section>

    </template>

    <!-- Formulario conectado al catálogo real para agregar productos. -->
    <div
      v-if="activeAction && actionInfo"
      class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-gray-950/40 p-4"
      @click.self="!addingProduct && !addingService && !submittingPayment && !extensionSubmitting && !checkoutSubmitting && !checkInSubmitting && (activeAction = null)"
    >
      <section
        role="dialog"
        aria-modal="true"
        :aria-label="actionInfo.title"
        class="my-auto max-h-[calc(100vh-2rem)] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl"
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
            :disabled="addingProduct || addingService || submittingPayment || extensionSubmitting || checkoutSubmitting || checkInSubmitting"
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
            <div v-if="paymentMethod === 'cash'" class="mt-2 flex items-center justify-between gap-3 border-t border-gray-200 pt-2">
              <span class="text-xs text-gray-500">Cambio a entregar</span>
              <span
                class="text-sm font-bold tabular-nums"
                :class="paymentChangeDue > 0 ? 'text-emerald-700' : 'text-gray-500'"
              >{{ formatCurrency(paymentChangeDue) }}</span>
            </div>
            <button
              type="button"
              class="mt-2 text-xs font-semibold text-blue-700 hover:text-blue-800 disabled:opacity-50"
              :disabled="!currentStay || Number(currentStay.account.balance) <= 0 || submittingPayment"
              @click="fillPaymentBalance"
            >Aplicar pago del saldo completo</button>
            <p v-if="paymentMethod !== 'cash' && Number(paymentAmount) > Number(currentStay?.account.balance ?? 0)" class="mt-2 text-xs text-red-600">
              El pago con tarjeta o transferencia no puede superar el saldo pendiente.
            </p>
          </div>

          <div>
            <label for="payment-amount" class="mb-1.5 block text-sm font-medium text-gray-700">{{ paymentMethod === 'cash' ? 'Efectivo recibido' : 'Importe del pago' }}</label>
            <input
              id="payment-amount"
              v-model.number="paymentAmount"
              type="number"
              min="0.01"
              :max="paymentMethod === 'cash' ? undefined : (currentStay?.account.balance ?? 0)"
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

        <form v-else-if="activeAction === 'extension'" class="mt-4 space-y-4" @submit.prevent="submitExtension">
          <div v-if="extensionError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ extensionError }}
          </div>

          <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <p class="text-xs text-gray-500">Huésped</p>
            <p class="mt-1 text-sm font-semibold text-gray-900">
              {{ currentStay ? formatGuestName(currentStay.guest) : 'Sin estancia activa' }}
            </p>
            <div class="mt-3 flex items-center justify-between gap-3 border-t border-gray-200 pt-3">
              <span class="text-xs text-gray-500">Salida prevista actual</span>
              <span class="text-sm font-semibold text-gray-900">{{ formatDateTime(currentStay?.expected_check_out_at) }}</span>
            </div>
          </div>

          <div>
            <label for="extension-checkout" class="mb-1.5 block text-sm font-medium text-gray-700">Nueva salida prevista</label>
            <input
              id="extension-checkout"
              v-model="extensionExpectedCheckOutAt"
              type="datetime-local"
              :min="extensionCheckOutMin"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="extensionSubmitting"
              required
            />
            <p class="mt-1.5 text-xs text-gray-500">La nueva fecha y hora deben ser posteriores a la salida prevista actual.</p>
          </div>

          <div>
            <label for="extension-charge" class="mb-1.5 block text-sm font-medium text-gray-700">Cargo adicional acordado (MXN) <span class="font-normal text-gray-400">(opcional)</span></label>
            <input
              id="extension-charge"
              v-model="extensionAdditionalCharge"
              type="number"
              min="0"
              max="99999999.99"
              step="0.01"
              inputmode="decimal"
              placeholder="Déjalo vacío si solo corriges la hora"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="extensionSubmitting"
            />
            <p class="mt-1.5 text-xs text-gray-500">Déjalo vacío si solo necesitas corregir la salida prevista, sin crear un cargo. Ingresa 0 si quieres registrar una extensión autorizada sin costo o captura el importe adicional aprobado.</p>
          </div>

          <div>
            <label for="extension-agreement" class="mb-1.5 block text-sm font-medium text-gray-700">
              Acuerdo o autorización <span class="font-normal text-gray-400">(opcional)</span>
            </label>
            <textarea
              id="extension-agreement"
              v-model.trim="extensionAgreementNotes"
              rows="2"
              maxlength="1000"
              placeholder="Ej. Extensión autorizada por el propietario por dos horas adicionales"
              class="w-full resize-y rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="extensionSubmitting"
            />
          </div>

          <div class="rounded-lg border border-amber-100 bg-amber-50 p-3">
            <div class="flex items-center justify-between gap-3">
              <span class="text-xs text-amber-900">{{ String(extensionAdditionalCharge).trim() === '' ? 'Corrección de horario (sin cargo)' : 'Cargo por esta extensión' }}</span>
              <span class="text-sm font-bold text-amber-900">{{ formatCurrency(extensionChargeValue ?? 0) }}</span>
            </div>
            <div class="mt-2 flex items-center justify-between gap-3 border-t border-amber-200 pt-2">
              <span class="text-xs text-amber-900">Saldo estimado después del cargo</span>
              <span class="text-sm font-bold text-amber-900">{{ formatCurrency(extensionBalancePreview) }}</span>
            </div>
          </div>

          <p class="text-xs leading-5 text-gray-500">La operación no cierra la estancia. Deja el importe vacío para corregir una fecha u hora mal capturada, incluso si necesitas adelantar la salida prevista: se actualizará sin crear un cargo. Si capturas un importe, debe ser una extensión posterior y se registrará en la cuenta y en «Historial del día». Si capturas 0, quedará constancia de una extensión sin costo. El acuerdo o autorización es opcional.</p>

          <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              :disabled="extensionSubmitting"
              @click="activeAction = null"
            >Cancelar</button>
            <button
              type="submit"
              class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="extensionSubmitting || !isValidStayExtension"
            >
              {{ extensionSubmitting ? 'Guardando extensión…' : 'Confirmar extensión' }}
            </button>
          </div>
        </form>

        <form v-else-if="activeAction === 'checkin'" class="mt-4 space-y-4" @submit.prevent="submitCheckIn">
          <div v-if="checkInError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ checkInError }}
          </div>

          <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <p class="text-xs text-gray-500">Habitación</p>
            <p class="mt-1 text-sm font-bold text-gray-900">{{ detail?.room.number }} · {{ detail?.room.room_type.name }}</p>
            <p class="mt-1 text-xs text-gray-500">Solo puedes iniciar una estancia si la habitación está disponible.</p>
          </div>

          <div>
            <p class="mb-2 text-sm font-medium text-gray-700">Huésped</p>
            <div class="grid grid-cols-2 gap-2">
              <button
                type="button"
                class="rounded-lg border px-3 py-2 text-xs font-semibold transition"
                :class="checkInMode === 'existing' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                :disabled="checkInSubmitting"
                @click="checkInMode = 'existing'; checkInError = ''"
              >Seleccionar existente</button>
              <button
                type="button"
                class="rounded-lg border px-3 py-2 text-xs font-semibold transition"
                :class="checkInMode === 'new' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-600 hover:bg-gray-50'"
                :disabled="checkInSubmitting"
                @click="checkInMode = 'new'; checkInError = ''"
              >Registrar nuevo</button>
            </div>
          </div>

          <div v-if="checkInMode === 'existing'" class="space-y-2">
            <label for="checkin-guest-search" class="block text-sm font-medium text-gray-700">Buscar huésped</label>
            <div class="flex gap-2">
              <input
                id="checkin-guest-search"
                v-model="guestSearch"
                type="search"
                placeholder="Nombre, teléfono, correo o documento"
                class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                :disabled="checkInSubmitting || guestsLoading"
                @keydown.enter.prevent="loadGuests(guestSearch)"
              />
              <button
                type="button"
                class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                :disabled="checkInSubmitting || guestsLoading"
                @click="loadGuests(guestSearch)"
              >Buscar</button>
            </div>
            <label for="checkin-guest" class="block text-sm font-medium text-gray-700">Huésped existente</label>
            <select
              id="checkin-guest"
              v-model.number="selectedGuestId"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="checkInSubmitting || guestsLoading || !guestCatalog.length"
              required
            >
              <option :value="null" disabled>Selecciona un huésped</option>
              <option v-for="guest in guestCatalog" :key="guest.id" :value="guest.id">
                {{ formatGuestName(guest) }}{{ guest.phone ? ` · ${guest.phone}` : '' }}
              </option>
            </select>
            <p v-if="guestsLoading" class="text-xs text-gray-500">Cargando huéspedes…</p>
            <p v-else-if="!guestCatalog.length" class="text-xs text-gray-500">No hay coincidencias. Busca otro término o registra un huésped nuevo.</p>
            <p v-else class="text-xs text-gray-500">Se muestran hasta 100 coincidencias. Puedes buscar por nombre, teléfono, correo o documento.</p>
          </div>

          <div v-else class="space-y-3">
            <p class="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs text-blue-800">Todos los datos personales son opcionales. Registra únicamente la información que el huésped proporcione.</p>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label for="new-guest-first-name" class="mb-1 block text-sm font-medium text-gray-700">Nombre(s) (opcional)</label>
              <input id="new-guest-first-name" v-model.trim="newGuest.first_name" type="text" maxlength="120" autocomplete="given-name" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
            </div>
            <div>
              <label for="new-guest-last-name" class="mb-1 block text-sm font-medium text-gray-700">Apellidos (opcional)</label>
              <input id="new-guest-last-name" v-model.trim="newGuest.last_name" type="text" maxlength="120" autocomplete="family-name" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
            </div>
            <div>
              <label for="new-guest-phone" class="mb-1 block text-sm font-medium text-gray-700">Teléfono (opcional)</label>
              <input id="new-guest-phone" v-model.trim="newGuest.phone" type="tel" maxlength="50" autocomplete="tel" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
            </div>
            <div>
              <label for="new-guest-email" class="mb-1 block text-sm font-medium text-gray-700">Correo electrónico (opcional)</label>
              <input id="new-guest-email" v-model.trim="newGuest.email" type="email" maxlength="255" autocomplete="email" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
            </div>
            <div>
              <label for="new-guest-document-type" class="mb-1 block text-sm font-medium text-gray-700">Tipo de documento (opcional)</label>
              <select id="new-guest-document-type" v-model="newGuest.document_type" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting">
                <option value="">Sin especificar</option>
                <option value="INE">INE</option>
                <option value="Pasaporte">Pasaporte</option>
                <option value="Licencia">Licencia de conducir</option>
                <option value="Otro">Otro</option>
              </select>
            </div>
            <div>
              <label for="new-guest-document-number" class="mb-1 block text-sm font-medium text-gray-700">Número de documento (opcional)</label>
              <input id="new-guest-document-number" v-model.trim="newGuest.document_number" type="text" maxlength="100" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
            </div>
            </div>
          </div>

          <div>
            <label for="checkin-rate" class="mb-1.5 block text-sm font-medium text-gray-700">Tarifa de habitación</label>
            <select
              id="checkin-rate"
              v-model="selectedCheckInRateId"
              class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100"
              :disabled="checkInSubmitting || rateCatalogLoading"
              @change="onCheckInRateChange"
              required
            >
              <option :value="null" disabled>Selecciona una tarifa</option>
              <option v-for="rate in availableRates" :key="rate.id" :value="rate.id">
                {{ rate.name }} · {{ formatCurrency(rate.price) }}
              </option>
              <option value="manual">Otra tarifa / captura manual</option>
            </select>
            <p v-if="rateCatalogLoading" class="mt-1 text-xs text-gray-500">Cargando tarifas generales del hotel…</p>
            <p v-else-if="rateCatalogError" class="mt-1 text-xs text-amber-700">{{ rateCatalogError }}</p>
            <p v-else-if="!availableRates.length" class="mt-1 text-xs text-gray-500">No hay tarifas guardadas en el catálogo general. Puedes elegir “Otra tarifa” para capturar una solo para esta estancia.</p>
            <p v-else-if="selectedCheckInRate" class="mt-1 text-xs text-gray-500">La tarifa seleccionada se ofrece en cualquier habitación del hotel y se cargará inicialmente a la cuenta. Verifica la salida prevista antes de confirmar.</p>
          </div>

          <div v-if="isManualRateSelected" class="rounded-xl border border-amber-200 bg-amber-50 p-4 space-y-3">
            <div>
              <h3 class="text-sm font-semibold text-amber-900">Tarifa especial para esta estancia</h3>
              <p class="mt-1 text-xs text-amber-800">No modifica las tarifas del catálogo. El precio y concepto se guardarán en el historial de esta estancia.</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label for="checkin-manual-rate-name" class="mb-1 block text-xs font-semibold text-gray-700">Concepto de tarifa *</label>
                <input id="checkin-manual-rate-name" v-model.trim="manualRateName" type="text" maxlength="100" placeholder="Ej. Promoción autorizada" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
              </div>
              <div>
                <label for="checkin-manual-rate-price" class="mb-1 block text-xs font-semibold text-gray-700">Precio (MXN) *</label>
                <input id="checkin-manual-rate-price" v-model="manualRatePrice" type="number" min="0.01" max="99999999.99" step="0.01" placeholder="Ej. 750.00" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
              </div>
              <div>
                <label for="checkin-manual-rate-duration" class="mb-1 block text-xs font-semibold text-gray-700">Duración *</label>
                <input id="checkin-manual-rate-duration" v-model.number="manualRateDuration" type="number" min="1" max="10000" step="1" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" @change="updateManualRateCheckout" />
              </div>
              <div>
                <label for="checkin-manual-rate-unit" class="mb-1 block text-xs font-semibold text-gray-700">Unidad *</label>
                <select id="checkin-manual-rate-unit" v-model="manualRateUnit" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" @change="updateManualRateCheckout">
                  <option value="minutos">Minutos</option>
                  <option value="horas">Horas</option>
                  <option value="días">Días</option>
                  <option value="noches">Noches</option>
                  <option value="semanas">Semanas</option>
                </select>
              </div>
            </div>
          </div>

          <div>
            <label for="checkin-checkout" class="mb-1.5 block text-sm font-medium text-gray-700">Salida prevista</label>
            <input
              id="checkin-checkout"
              v-model="expectedCheckOutAt"
              type="datetime-local"
              :min="expectedCheckOutMin"
              class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
              :disabled="checkInSubmitting"
              required
            />
          </div>

          <div>
            <label for="checkin-notes" class="mb-1.5 block text-sm font-medium text-gray-700">Notas <span class="font-normal text-gray-400">(opcional)</span></label>
            <textarea id="checkin-notes" v-model.trim="checkInNotes" rows="2" maxlength="2000" placeholder="Observaciones de la estancia" class="w-full resize-y rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" :disabled="checkInSubmitting" />
          </div>

          <div v-if="checkInRateIsReady" class="rounded-lg border border-blue-100 bg-blue-50 p-3">
            <div class="flex items-center justify-between gap-3">
              <span class="text-xs text-blue-800">Cargo inicial de habitación</span>
              <span class="text-sm font-bold text-blue-800">{{ formatCurrency(checkInInitialPrice) }}</span>
            </div>
          </div>

          <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <button type="button" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50" :disabled="checkInSubmitting" @click="activeAction = null">Cancelar</button>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50" :disabled="checkInSubmitting || guestsLoading || !isValidCheckIn">
              {{ checkInSubmitting ? 'Registrando entrada…' : 'Confirmar check-in' }}
            </button>
          </div>
        </form>

        <div v-else-if="activeAction === 'checkout-after-payment'" class="mt-4 space-y-4">
          <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-sm font-semibold text-emerald-800">Pago registrado correctamente.</p>
            <p class="mt-1 text-sm text-emerald-700">La cuenta de {{ currentStay ? formatGuestName(currentStay.guest) : 'este huésped' }} está liquidada. El saldo pendiente es {{ formatCurrency(currentStay?.account.balance ?? 0) }}.</p>
          </div>

          <div v-if="checkoutError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ checkoutError }}
          </div>

          <p class="text-sm leading-6 text-gray-600">
            Si el huésped ya salió, registra el check-out para cerrar la estancia y enviar la habitación a limpieza. Si seguirá ocupándola, continúa sin cerrar la estancia.
          </p>

          <div class="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              :disabled="checkoutSubmitting"
              @click="activeAction = null; showChargeNotice('Pago registrado correctamente. La estancia continúa activa y la habitación sigue ocupada.')"
            >Continuar estancia</button>
            <button
              type="button"
              class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="checkoutSubmitting || !currentStay || Math.round(Number(currentStay.account.balance) * 100) / 100 !== 0 || hasUnsavedChargeQuantities"
              @click="submitCheckout"
            >
              {{ checkoutSubmitting ? 'Registrando check-out…' : 'Sí, registrar check-out' }}
            </button>
          </div>
        </div>

        <form v-else-if="activeAction === 'checkout'" class="mt-4 space-y-4" @submit.prevent="submitCheckout">
          <div v-if="checkoutError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ checkoutError }}
          </div>

          <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
            <p class="text-sm font-semibold text-gray-900">
              {{ currentStay ? formatGuestName(currentStay.guest) : 'Sin estancia activa' }}
            </p>
            <div class="mt-3 space-y-2">
              <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-gray-500">Total de cargos</span>
                <span class="text-sm font-semibold text-gray-900">{{ formatCurrency(currentStay?.account.charges_total ?? 0) }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span class="text-xs text-gray-500">Total pagado</span>
                <span class="text-sm font-semibold text-emerald-700">{{ formatCurrency(currentStay?.account.paid_total ?? 0) }}</span>
              </div>
              <div class="flex items-center justify-between gap-3 border-t border-gray-200 pt-2">
                <span class="text-xs font-semibold text-gray-700">Saldo pendiente</span>
                <span class="text-base font-bold" :class="Number(currentStay?.account.balance ?? 0) > 0 ? 'text-red-600' : 'text-emerald-700'">
                  {{ formatCurrency(currentStay?.account.balance ?? 0) }}
                </span>
              </div>
            </div>
          </div>

          <p v-if="currentStay && Number(currentStay.account.balance) > 0" class="text-xs leading-5 text-amber-700">
            Para continuar, cancela esta ventana, registra el pago restante y vuelve a intentar el check-out.
          </p>
          <p v-else-if="currentStay && Number(currentStay.account.balance) < 0" class="text-xs leading-5 text-red-700">
            La cuenta tiene un saldo a favor. Revisa los importes antes de cerrar la estancia.
          </p>
          <p v-else-if="hasUnsavedChargeQuantities" class="text-xs leading-5 text-amber-700">
            Hay cantidades modificadas sin guardar. Guarda o cancela esos cambios antes de cerrar la estancia.
          </p>
          <p v-else class="text-xs leading-5 text-gray-600">
            Al confirmar, la estancia quedará cerrada y la habitación pasará al estado «En limpieza».
          </p>

          <div class="flex justify-end gap-2 border-t border-gray-100 pt-4">
            <button
              type="button"
              class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              :disabled="checkoutSubmitting"
              @click="activeAction = null"
            >Cancelar</button>
            <button
              type="submit"
              class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
              :disabled="checkoutSubmitting || !currentStay || Math.round(Number(currentStay.account.balance) * 100) / 100 !== 0 || hasUnsavedChargeQuantities"
            >
              {{ checkoutSubmitting ? 'Cerrando estancia…' : 'Confirmar check-out' }}
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
