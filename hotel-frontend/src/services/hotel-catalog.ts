import api from '@/services/api'

export interface CatalogProduct {
  id: number
  hotel_id: number
  name: string
  description: string | null
  sku: string | null
  price: number | string
  stock: number
  active: boolean
  created_at?: string
  updated_at?: string
}

export interface CatalogService {
  id: number
  hotel_id: number
  name: string
  description: string | null
  price: number | string
  active: boolean
  created_at?: string
  updated_at?: string
}

export interface ProductPayload {
  name: string
  description: string | null
  sku: string | null
  price: number
  stock: number
  active: boolean
}

export interface ServicePayload {
  name: string
  description: string | null
  price: number
  active: boolean
}

export async function getCatalogProducts(hotelId: number): Promise<CatalogProduct[]> {
  const response = await api.get<{ products: CatalogProduct[] }>(`/hotels/${hotelId}/catalog/products`)
  return response.data.products
}

export async function createCatalogProduct(hotelId: number, payload: ProductPayload): Promise<CatalogProduct> {
  const response = await api.post<{ product: CatalogProduct }>(`/hotels/${hotelId}/catalog/products`, payload)
  return response.data.product
}

export async function updateCatalogProduct(
  hotelId: number,
  productId: number,
  payload: ProductPayload,
): Promise<CatalogProduct> {
  const response = await api.put<{ product: CatalogProduct }>(
    `/hotels/${hotelId}/catalog/products/${productId}`,
    payload,
  )
  return response.data.product
}

export async function getCatalogServices(hotelId: number): Promise<CatalogService[]> {
  const response = await api.get<{ services: CatalogService[] }>(`/hotels/${hotelId}/catalog/services`)
  return response.data.services
}

export async function createCatalogService(hotelId: number, payload: ServicePayload): Promise<CatalogService> {
  const response = await api.post<{ service: CatalogService }>(`/hotels/${hotelId}/catalog/services`, payload)
  return response.data.service
}

export async function updateCatalogService(
  hotelId: number,
  serviceId: number,
  payload: ServicePayload,
): Promise<CatalogService> {
  const response = await api.put<{ service: CatalogService }>(
    `/hotels/${hotelId}/catalog/services/${serviceId}`,
    payload,
  )
  return response.data.service
}
