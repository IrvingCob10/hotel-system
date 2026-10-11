import api from './api'

export interface ExtendStayResponse {
  message: string
  stay: {
    id: number
    status: string
    expected_check_out_at: string
  }
  charge: {
    id: number
    type: string
    description: string
    quantity: number
    unit_price: number
    total: number
    created_at: string
  } | null
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
}

interface ExtendStayParams {
  hotelId: number
  roomNumber: string
  stayId: number
  expectedCheckOutAt: string
  additionalCharge: number | null
  agreementNotes?: string | null
}

/**
 * Actualiza la salida prevista y registra el movimiento de extensión.
 * datetime-local se convierte a ISO 8601 con zona explícita antes de enviarse.
 */
export async function extendStay({
  hotelId,
  roomNumber,
  stayId,
  expectedCheckOutAt,
  additionalCharge,
  agreementNotes = null,
}: ExtendStayParams): Promise<ExtendStayResponse> {
  const selectedDate = new Date(expectedCheckOutAt)
  if (Number.isNaN(selectedDate.getTime())) {
    throw new Error('La nueva salida prevista no es válida.')
  }

  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/stays/${stayId}/extension`
  const response = await api.patch<ExtendStayResponse>(url, {
    expected_check_out_at: selectedDate.toISOString(),
    additional_charge: additionalCharge === null ? null : Math.round(additionalCharge * 100) / 100,
    agreement_notes: agreementNotes,
  })

  return response.data
}
