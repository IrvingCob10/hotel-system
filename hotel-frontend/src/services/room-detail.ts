import api from './api'

export interface RoomRate {
  id: number
  room_id: number
  name: string
  duration: number
  unit: string
  price: string | number
  active: boolean
}

export interface RoomCharge {
  id: number
  type: string
  description: string
  quantity: number
  unit_price: number
  total: number
  product: { id: number; name: string } | null
  service: { id: number; name: string } | null
}

export interface StayPayment {
  id: number
  amount: number
  method: string
  reference: string | null
  received_at: string
}

export interface RoomExpense {
  id: number
  room_id: number | null
  category: string
  description: string
  amount: string | number
  expense_date: string
  payment_method: string | null
  vendor: string | null
  notes: string | null
}

export interface RoomStayHistory {
  id: number
  guest_name: string
  check_in_at: string
  expected_check_out_at: string | null
  actual_check_out_at: string | null
  status: string
  rate_name: string
  rate_price: number
  charges_total: number
  paid_total: number
  balance: number
}

export interface RoomDetailResponse {
  hotel: {
    id: number
    name: string
  }

  room: {
    id: number
    hotel_id: number
    room_type_id: number
    number: string
    floor: number | null
    status: string
    active: boolean
    room_type: {
      id: number
      name: string
      description: string | null
      capacity: number
    }
    rates: RoomRate[]
  }

  current_stay: null | {
    id: number
    status: string
    guest: {
      id: number
      first_name: string
      last_name: string
      phone: string | null
      email: string | null
    }
    check_in_at: string
    expected_check_out_at: string | null
    rate_name: string
    rate_price: number
    charges: RoomCharge[]
    payments: StayPayment[]
    account: {
      charges_total: number
      paid_total: number
      balance: number
    }
  }

  room_expenses: {
    total: number
    items: RoomExpense[]
  }

  stay_history: RoomStayHistory[]
}

export async function getRoomDetail(
  hotelId: number,
  roomNumber: string,
): Promise<RoomDetailResponse> {
  const response = await api.get<RoomDetailResponse>(
    `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}`,
  )

  return response.data
}