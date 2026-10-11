import api from './api'
import type { RoomTypeOption } from './rooms'

export interface RoomTypePayload {
  name: string
  description: string | null
  capacity: number
  active?: boolean
}

function assertValidHotelId(hotelId: number): void {
  if (!Number.isSafeInteger(hotelId) || hotelId <= 0) {
    throw new Error('No hay un hotel activo válido. Inicia sesión nuevamente antes de administrar tipos de habitación.')
  }
}

/** Incluye tipos activos e inactivos para que el dueño pueda administrarlos. */
export async function getManagedRoomTypes(hotelId: number): Promise<RoomTypeOption[]> {
  assertValidHotelId(hotelId)
  const response = await api.get<RoomTypeOption[]>(`/hotels/${hotelId}/room-types`, {
    params: { include_inactive: 1 },
  })
  return response.data
}

export async function createRoomType(
  hotelId: number,
  payload: RoomTypePayload,
): Promise<{ message: string; room_type: RoomTypeOption }> {
  assertValidHotelId(hotelId)
  const response = await api.post<{ message: string; room_type: RoomTypeOption }>(
    `/hotels/${hotelId}/room-types`,
    payload,
  )
  return response.data
}

export async function updateRoomType(
  hotelId: number,
  roomTypeId: number,
  payload: RoomTypePayload,
): Promise<{ message: string; room_type: RoomTypeOption }> {
  assertValidHotelId(hotelId)
  const response = await api.put<{ message: string; room_type: RoomTypeOption }>(
    `/hotels/${hotelId}/room-types/${roomTypeId}`,
    payload,
  )
  return response.data
}
