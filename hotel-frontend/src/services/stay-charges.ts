import api from './api'

export interface UpdateChargeQuantityResponse {
  message: string
  charge: {
    id: number
    quantity: number | string
    unit_price: number | string
    total: number | string
    inventory_deducted: boolean
    product: { id: number; name: string; price: number | string; stock: number } | null
  }
  stock_remaining: number
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface UpdateChargeQuantityParams {
  hotelId: number
  roomNumber: string
  stayId: number
  chargeId: number
  quantity: number
}

export async function updateChargeQuantity({
  hotelId,
  roomNumber,
  stayId,
  chargeId,
  quantity,
}: UpdateChargeQuantityParams): Promise<UpdateChargeQuantityResponse> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/stays/${stayId}/charges/${chargeId}/quantity`
  const response = await api.patch<UpdateChargeQuantityResponse>(url, { quantity })
  return response.data
}

export interface AddStayProductResponse {
  message: string
  charge: {
    id: number
    stay_id: number
    product_id: number
    type: string
    description: string
    quantity: number | string
    unit_price: number | string
    total: number | string
    inventory_deducted: boolean | number
    notes: string | null
    product: {
      id: number
      name: string
      price: number | string
      stock?: number
    } | null
  }
  stock_remaining: number
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface AddStayProductParams {
  hotelId: number
  roomNumber: string
  stayId: number
  productId: number
  quantity: number
}

export async function addProductToStay({
  hotelId,
  roomNumber,
  stayId,
  productId,
  quantity,
}: AddStayProductParams): Promise<AddStayProductResponse> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/stays/${stayId}/products`
  const response = await api.post<AddStayProductResponse>(url, {
    product_id: productId,
    quantity,
  })
  return response.data
}

export interface AddStayServiceResponse {
  message: string
  charge: {
    id: number
    stay_id: number
    service_id: number
    type: string
    description: string
    quantity: number | string
    unit_price: number | string
    total: number | string
    notes: string | null
    service: {
      id: number
      name: string
      price: number | string
    } | null
  }
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface AddStayServiceParams {
  hotelId: number
  roomNumber: string
  stayId: number
  serviceId: number
  quantity: number
}

/** Agrega un servicio a la cuenta del huésped; no modifica inventario. */
export async function addServiceToStay({
  hotelId,
  roomNumber,
  stayId,
  serviceId,
  quantity,
}: AddStayServiceParams): Promise<AddStayServiceResponse> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/stays/${stayId}/services`
  const response = await api.post<AddStayServiceResponse>(url, {
    service_id: serviceId,
    quantity,
  })
  return response.data
}

export type StayPaymentMethod = 'cash' | 'card' | 'transfer' | 'other'

export interface AddStayPaymentResponse {
  message: string
  payment: {
    id: number
    stay_id: number
    // amount es el importe aplicado a la cuenta; amount_received es lo entregado en caja.
    amount: number | string
    amount_received: number | string | null
    change_given: number | string
    method: StayPaymentMethod | string
    reference: string | null
    received_at: string
    notes: string | null
  }
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface AddStayPaymentParams {
  hotelId: number
  roomNumber: string
  stayId: number
  amount: number
  method: StayPaymentMethod
  reference?: string | null
  notes?: string | null
}

/** Registra un abono o liquidación en la cuenta de una estancia. */
export async function addPaymentToStay({
  hotelId,
  roomNumber,
  stayId,
  amount,
  method,
  reference = null,
  notes = null,
}: AddStayPaymentParams): Promise<AddStayPaymentResponse> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/stays/${stayId}/payments`
  const response = await api.post<AddStayPaymentResponse>(url, {
    amount,
    method,
    reference,
    notes,
  })
  return response.data
}

export interface CheckoutStayResponse {
  message: string
  stay: {
    id: number
    status: 'completed' | string
    actual_check_out_at: string
  }
  room: {
    id: number
    number: string | number
    status: string
  }
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface CheckoutStayParams {
  hotelId: number
  roomNumber: string
  stayId: number
}

/** Cierra una estancia liquidada y deja la habitación en limpieza. */
export async function checkoutStay({
  hotelId,
  roomNumber,
  stayId,
}: CheckoutStayParams): Promise<CheckoutStayResponse> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/stays/${stayId}/checkout`
  const response = await api.post<CheckoutStayResponse>(url)
  return response.data
}
