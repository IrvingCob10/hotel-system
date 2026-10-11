import api from './api'

export interface HotelProduct {
  id: number
  hotel_id: number
  name: string
  description: string | null
  sku: string | null
  price: number | string
  stock: number
  active: boolean
}

/** Consultar los productos activos del hotel autenticado/autorizado. */
export async function getHotelProducts(hotelId: number): Promise<HotelProduct[]> {
  const response = await api.get<HotelProduct[]>(`/hotels/${hotelId}/products`)
  return response.data
}
