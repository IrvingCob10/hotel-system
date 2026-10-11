import { defineStore } from 'pinia'
import api from '@/services/api'

export interface AuthUser {
  id: number
  name: string
  email: string
  is_super_admin?: boolean
}

export interface HotelSummary {
  id: number
  customer_id?: number
  name: string
  commercial_name?: string | null
  email?: string | null
  phone?: string | null
  address?: string | null
  city?: string | null
  state?: string | null
  country?: string | null
  postal_code?: string | null
  timezone?: string | null
  logo?: string | null
  logo_url?: string | null
  active?: boolean
}

interface LoginResponse {
  message: string
  token: string
  user: AuthUser
  hotels?: HotelSummary[]
  current_hotel?: HotelSummary | null
  requires_hotel_selection?: boolean
  needs_hotel_setup?: boolean
}

function readStoredJson<T>(key: string, fallback: T): T {
  const raw = localStorage.getItem(key)
  if (!raw) return fallback

  try {
    return JSON.parse(raw) as T
  } catch {
    return fallback
  }
}

function readStoredHotel(): HotelSummary | null {
  const stored = readStoredJson<HotelSummary | null>('current_hotel', null)
  if (stored?.id && stored.name) return stored

  // Compatibilidad con la primera versión del módulo Datos del hotel.
  const id = Number(localStorage.getItem('hotel_id') || localStorage.getItem('active_hotel_id'))
  const name = localStorage.getItem('hotel_name')
  if (Number.isInteger(id) && id > 0 && name) {
    return { id, name, commercial_name: null }
  }

  return null
}

function persistHotelContext(hotel: HotelSummary | null): void {
  if (!hotel) {
    localStorage.removeItem('current_hotel')
    localStorage.removeItem('active_hotel_id')
    localStorage.removeItem('hotel_id')
    localStorage.removeItem('hotel_name')
    localStorage.removeItem('hotel_logo_url')
    return
  }

  localStorage.setItem('current_hotel', JSON.stringify(hotel))
  localStorage.setItem('active_hotel_id', String(hotel.id))
  // Compatibilidad con el servicio hotel-profile.ts y servicios anteriores.
  localStorage.setItem('hotel_id', String(hotel.id))
  localStorage.setItem('hotel_name', hotel.commercial_name?.trim() || hotel.name)

  if (hotel.logo_url) {
    localStorage.setItem('hotel_logo_url', hotel.logo_url)
  } else {
    localStorage.removeItem('hotel_logo_url')
  }
}

export const useAuthStore = defineStore('auth', {
  state: () => {
    const currentHotel = readStoredHotel()

    return {
      token: localStorage.getItem('auth_token') as string | null,
      user: readStoredJson<AuthUser | null>('auth_user', null),
      hotels: readStoredJson<HotelSummary[]>('auth_hotels', currentHotel ? [currentHotel] : []),
      currentHotel,
      requiresHotelSelection: localStorage.getItem('requires_hotel_selection') === 'true',
      needsHotelSetup: localStorage.getItem('needs_hotel_setup') === 'true',
      loading: false,
    }
  },

  getters: {
    isAuthenticated: (state) => !!state.token,
    currentHotelId: (state): number | null => state.currentHotel?.id ?? null,
    currentHotelDisplayName: (state): string =>
      state.currentHotel?.commercial_name?.trim() || state.currentHotel?.name?.trim() || 'Hotel Management',
  },

  actions: {
    async login(email: string, password: string): Promise<boolean> {
      this.loading = true

      try {
        const response = await api.post<LoginResponse>('/login', { email, password })
        const data = response.data
        const hotels = Array.isArray(data.hotels) ? data.hotels : []
        const currentHotel = data.current_hotel
          ?? hotels.find((hotel) => hotel.active !== false)
          ?? hotels[0]
          ?? null

        this.token = data.token
        this.user = data.user
        this.hotels = hotels
        this.currentHotel = currentHotel
        this.requiresHotelSelection = data.requires_hotel_selection ?? hotels.length > 1
        this.needsHotelSetup = data.needs_hotel_setup ?? !currentHotel

        localStorage.setItem('auth_token', data.token)
        localStorage.setItem('auth_user', JSON.stringify(data.user))
        localStorage.setItem('auth_hotels', JSON.stringify(hotels))
        localStorage.setItem('requires_hotel_selection', String(this.requiresHotelSelection))
        localStorage.setItem('needs_hotel_setup', String(this.needsHotelSetup))
        persistHotelContext(currentHotel)

        return true
      } finally {
        this.loading = false
      }
    },

    /** Actualiza el hotel activo en memoria y almacenamiento para que el layout reaccione. */
    setCurrentHotel(hotel: HotelSummary | null): void {
      this.currentHotel = hotel

      if (hotel) {
        const existingIndex = this.hotels.findIndex((item) => item.id === hotel.id)
        if (existingIndex >= 0) {
          this.hotels[existingIndex] = { ...this.hotels[existingIndex], ...hotel }
        } else {
          this.hotels = [...this.hotels, hotel]
        }
      }

      localStorage.setItem('auth_hotels', JSON.stringify(this.hotels))
      persistHotelContext(hotel)
    },

    setHotelSetupStatus(needsSetup: boolean): void {
      this.needsHotelSetup = needsSetup
      localStorage.setItem('needs_hotel_setup', String(needsSetup))
    },

    logout(): void {
      this.token = null
      this.user = null
      this.hotels = []
      this.currentHotel = null
      this.requiresHotelSelection = false
      this.needsHotelSetup = false

      localStorage.removeItem('auth_token')
      localStorage.removeItem('auth_user')
      localStorage.removeItem('auth_hotels')
      localStorage.removeItem('requires_hotel_selection')
      localStorage.removeItem('needs_hotel_setup')
      persistHotelContext(null)
    },
  },
})
