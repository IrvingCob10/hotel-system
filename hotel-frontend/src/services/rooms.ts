import api from './api'

export interface RoomTypeOption {
  id: number
  hotel_id: number
  name: string
  description: string | null
  capacity: number
  active: boolean
}

export interface RoomRateItem {
  id: number
  name: string
  duration: number
  unit: string
  price: number | string
  active: boolean
}

export type ManagedRoomStatus =
  | 'available'
  | 'occupied'
  | 'cleaning'
  | 'maintenance'
  | 'out_of_service'
  | string

export interface ManagedRoom {
  id: number
  hotel_id: number
  room_type_id: number
  number: string
  floor: number | null
  status: ManagedRoomStatus
  notes: string | null
  active: boolean
  // Laravel serializa por defecto la relación roomType como room_type.
  roomType?: RoomTypeOption | null
  room_type?: RoomTypeOption | null
  rates?: RoomRateItem[]
}

export interface CreateManagedRoomPayload {
  room_type_id: number
  number: string
  floor: number | null
  notes: string | null
  // Las tarifas se administran por separado en el catálogo global del hotel.
  // Se conserva como opcional por compatibilidad temporal con clientes anteriores.
  initial_rate?: {
    name: string
    duration: number
    unit: string
    price: number
  }
}

export interface CreateManagedRoomResponse {
  message: string
  room: ManagedRoom
}

export interface UpdateManagedRoomPayload {
  number: string
  room_type_id: number
  floor: number | null
  notes: string | null
}

export interface UpdateManagedRoomResponse {
  message: string
  room: ManagedRoom
}

/** Normaliza las relaciones por si Laravel devuelve room_type o roomType. */
export function normalizeManagedRoom(room: ManagedRoom): ManagedRoom {
  const relatedType = room.roomType ?? room.room_type ?? null
  return {
    ...room,
    roomType: relatedType,
    room_type: relatedType,
    rates: room.rates ?? [],
  }
}

function assertValidHotelId(hotelId: number): void {
  if (!Number.isSafeInteger(hotelId) || hotelId <= 0) {
    throw new Error(
      'No hay un hotel activo válido. Inicia sesión nuevamente antes de consultar habitaciones.'
    )
  }
}

export async function getHotelRooms(hotelId: number): Promise<ManagedRoom[]> {
  assertValidHotelId(hotelId)
  const response = await api.get<ManagedRoom[]>(`/hotels/${hotelId}/rooms`)
  return response.data.map(normalizeManagedRoom)
}

/**
 * Consulta las habitaciones del hotel indicado explícitamente por el contexto
 * autenticado. No existe un hotel predeterminado para evitar cruces de datos
 * entre propietarios distintos.
 */
export async function getRooms(hotelId: number): Promise<ManagedRoom[]> {
  return getHotelRooms(hotelId)
}

export async function getHotelRoomTypes(hotelId: number): Promise<RoomTypeOption[]> {
  assertValidHotelId(hotelId)
  const response = await api.get<RoomTypeOption[]>(`/hotels/${hotelId}/room-types`)
  return response.data
}

export async function createHotelRoom(
  hotelId: number,
  payload: CreateManagedRoomPayload,
): Promise<CreateManagedRoomResponse> {
  assertValidHotelId(hotelId)
  const response = await api.post<CreateManagedRoomResponse>(
    `/hotels/${hotelId}/rooms`,
    payload,
  )

  return { ...response.data, room: normalizeManagedRoom(response.data.room) }
}

export async function updateHotelRoom(
  hotelId: number,
  currentRoomNumber: string,
  payload: UpdateManagedRoomPayload,
): Promise<UpdateManagedRoomResponse> {
  assertValidHotelId(hotelId)
  const response = await api.put<UpdateManagedRoomResponse>(
    `/hotels/${hotelId}/rooms/${encodeURIComponent(currentRoomNumber)}`,
    payload,
  )

  return { ...response.data, room: normalizeManagedRoom(response.data.room) }
}
