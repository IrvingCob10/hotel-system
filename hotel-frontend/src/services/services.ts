import api from './api'

export interface HotelService {
  id: number
  hotel_id: number
  name: string
  description: string | null
  price: number | string
  active: boolean
}

/** Consulta los servicios activos configurados para un hotel. */
export async function getHotelServices(hotelId: number): Promise<HotelService[]> {
  const response = await api.get<HotelService[]>(`/hotels/${hotelId}/services`)
  return response.data
}
