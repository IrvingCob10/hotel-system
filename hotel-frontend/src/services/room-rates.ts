import api from './api'

export interface HotelRoomRate {
  id: number
  hotel_id: number
  /** Null para las tarifas globales; solo tarifas manuales/históricas conservan room_id. */
  room_id: number | null
  name: string
  duration: number
  unit: string
  price: number | string
  active: boolean
  created_at?: string
  updated_at?: string
}

export interface RoomRatePayload {
  name: string
  duration: number
  unit: string
  price: number
  active?: boolean
}

export interface UpdateRoomRatePayload {
  name: string
  duration: number
  unit: string
  price: number
  active: boolean
}

/** Devuelve las tarifas globales del hotel, no tarifas asignadas a habitaciones físicas. */
function assertValidHotelId(hotelId: number): void {
  if (!Number.isSafeInteger(hotelId) || hotelId <= 0) {
    throw new Error('No hay un hotel activo válido. Inicia sesión nuevamente antes de consultar tarifas.')
  }
}

export async function getHotelRates(hotelId: number): Promise<HotelRoomRate[]> {
  assertValidHotelId(hotelId)
  const response = await api.get<{ rates: HotelRoomRate[] }>(`/hotels/${hotelId}/rates`)
  return response.data.rates
}

export async function createHotelRate(
  hotelId: number,
  payload: RoomRatePayload,
): Promise<HotelRoomRate> {
  assertValidHotelId(hotelId)
  const response = await api.post<{ message: string; rate: HotelRoomRate }>(
    `/hotels/${hotelId}/rates`,
    payload,
  )
  return response.data.rate
}

export async function updateHotelRate(
  hotelId: number,
  rateId: number,
  payload: UpdateRoomRatePayload,
): Promise<HotelRoomRate> {
  assertValidHotelId(hotelId)
  const response = await api.put<{ message: string; rate: HotelRoomRate }>(
    `/hotels/${hotelId}/rates/${rateId}`,
    payload,
  )
  return response.data.rate
}
