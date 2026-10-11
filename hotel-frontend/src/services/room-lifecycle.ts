import api from './api'

export interface HistoricalRoomGuest {
  id: number | null
  first_name: string | null
  last_name: string | null
  phone: string | null
  email: string | null
}

export interface HistoricalRoomCharge {
  id: number
  type: string
  description: string
  quantity: number | string
  unit_price: number | string
  total: number | string
  product: { id: number; name: string } | null
  service: { id: number; name: string } | null
}

export interface HistoricalRoomPayment {
  id: number
  amount: number | string
  method: string
  reference: string | null
  received_at: string
  notes: string | null
}

export interface HistoricalRoomStay {
  id: number
  status: string
  guest: HistoricalRoomGuest
  check_in_at: string
  expected_check_out_at: string | null
  actual_check_out_at: string | null
  rate_name: string
  rate_price: number | string
  charges: HistoricalRoomCharge[]
  payments: HistoricalRoomPayment[]
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface LatestRoomStayResponse {
  stay: HistoricalRoomStay | null
}

interface CompleteCleaningResponse {
  message: string
  room: {
    id: number
    number: string
    status: string
  }
}

/** Consulta cargos y pagos de la última estancia, incluso después del check-out. */
export async function getLatestRoomStay(
  hotelId: number,
  roomNumber: string,
): Promise<HistoricalRoomStay | null> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/latest-stay`
  const response = await api.get<LatestRoomStayResponse>(url)
  return response.data.stay
}

/** Cambia la habitación de cleaning a available después de finalizar la limpieza. */
export async function completeRoomCleaning(
  hotelId: number,
  roomNumber: string,
): Promise<CompleteCleaningResponse> {
  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/cleaning/complete`
  const response = await api.post<CompleteCleaningResponse>(url)
  return response.data
}
