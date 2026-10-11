import api from '@/services/api'

export type DailyActivityType = 'check_in' | 'check_out' | 'charge' | 'payment'

export interface DailyActivity {
  id: string
  type: DailyActivityType
  title: string
  description: string | null
  occurred_at: string
  amount: number | null
}

export interface DailyStayActivity {
  id: number
  room_number: string
  guest_name: string
  status: string
  check_in_at: string | null
  actual_check_out_at: string | null
  expected_check_out_at: string | null
  rate_name: string
  rate_price: number
  account: {
    charges_total: number
    paid_total: number
    balance: number
  }
  day_charges_total: number
  day_paid_total: number
  activities: DailyActivity[]
}

export interface DailyHistoryResponse {
  date: string
  timezone: string
  summary: {
    check_ins: number
    check_outs: number
    charges_total: number
    payments_total: number
    cash_received_total: number
    change_given_total: number
  }
  data: DailyStayActivity[]
  pagination: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface DailyHistoryParams {
  hotelId: number
  date: string
  room?: string
  search?: string
  page?: number
  perPage?: number
}

export async function getDailyHistory(params: DailyHistoryParams): Promise<DailyHistoryResponse> {
  const { hotelId, date, room, search, page = 1, perPage = 20 } = params

  if (!Number.isSafeInteger(hotelId) || hotelId <= 0) {
    throw new Error('No hay un hotel activo válido para consultar el historial.')
  }

  const response = await api.get<DailyHistoryResponse>(`/hotels/${hotelId}/history/day`, {
    params: {
      date,
      room: room?.trim() || undefined,
      search: search?.trim() || undefined,
      page,
      per_page: perPage,
    },
  })

  return response.data
}
