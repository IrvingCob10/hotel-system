import api from './api'

export interface HotelGuest {
  id: number
  first_name: string | null
  last_name: string | null
  phone: string | null
  email: string | null
  document_type: string | null
  document_number: string | null
}

export interface NewGuestPayload {
  first_name?: string | null
  last_name?: string | null
  phone?: string | null
  email?: string | null
  document_type?: string | null
  document_number?: string | null
}

export interface ManualRatePayload {
  name: string
  duration: number
  unit: string
  price: number
}

export interface CheckInRoomParams {
  hotelId: number
  roomNumber: string
  roomRateId?: number
  manualRate?: ManualRatePayload
  expectedCheckOutAt: string
  guestId?: number
  guest?: NewGuestPayload
  notes?: string | null
}

export interface CheckInRoomResponse {
  message: string
  room: {
    id: number
    number: string
    status: string
  }
  stay: {
    id: number
    status: string
    guest_id: number
    room_rate_id: number
    rate_name: string
    rate_price: number
    check_in_at: string
    expected_check_out_at: string
  }
}

export async function getHotelGuests(hotelId: number, search = ''): Promise<HotelGuest[]> {
  const response = await api.get<{ guests: HotelGuest[] }>(`/hotels/${hotelId}/guests`, {
    params: search.trim() ? { search: search.trim() } : undefined,
  })
  return response.data.guests
}

export async function checkInRoom({
  hotelId,
  roomNumber,
  roomRateId,
  manualRate,
  expectedCheckOutAt,
  guestId,
  guest,
  notes = null,
}: CheckInRoomParams): Promise<CheckInRoomResponse> {
  if (Boolean(roomRateId) === Boolean(manualRate)) {
    throw new Error('Selecciona una tarifa del catálogo o captura una tarifa manual, pero no ambas.')
  }

  const url = `/hotels/${hotelId}/rooms/${encodeURIComponent(roomNumber)}/check-in`
  const payload = {
    ...(manualRate
      ? {
          manual_rate: {
            name: manualRate.name,
            duration: manualRate.duration,
            unit: manualRate.unit,
            price: manualRate.price,
          },
        }
      : { room_rate_id: roomRateId }),
    expected_check_out_at: expectedCheckOutAt,
    notes,
    ...(guestId ? { guest_id: guestId } : { guest }),
  }

  const response = await api.post<CheckInRoomResponse>(url, payload)
  return response.data
}
