import api from './api'

export interface HotelIdentity {
  id: number
  customer_id: number
  name: string
  commercial_name: string | null
  email: string | null
  phone: string | null
  address: string | null
  city: string | null
  state: string | null
  country: string
  postal_code: string | null
  timezone: string
  logo: string | null
  logo_url: string | null
  active: boolean
}

export interface HotelCustomerIdentity {
  id: number
  name: string
  legal_name: string | null
  tax_id: string | null
  email: string | null
  phone: string | null
}

export interface HotelProfileResponse {
  message?: string
  hotel: HotelIdentity
  customer: HotelCustomerIdentity | null
  needs_setup: boolean
  missing_hotel_fields: string[]
}

export interface HotelProfileForm {
  hotel_id?: number
  name: string
  commercial_name: string
  email: string
  phone: string
  address: string
  city: string
  state: string
  country: string
  postal_code: string
  timezone: string
  customer_name: string
  legal_name: string
  tax_id: string
  customer_email: string
  customer_phone: string
}

function rememberHotel(profile: HotelProfileResponse): void {
  localStorage.setItem('hotel_id', String(profile.hotel.id))
  localStorage.setItem('hotel_name', profile.hotel.commercial_name || profile.hotel.name)
  if (profile.hotel.logo_url) {
    localStorage.setItem('hotel_logo_url', profile.hotel.logo_url)
  } else {
    localStorage.removeItem('hotel_logo_url')
  }
}

export async function getMyHotelProfile(hotelId?: number): Promise<HotelProfileResponse> {
  const response = await api.get<HotelProfileResponse>('/my/hotel', {
    params: hotelId ? { hotel_id: hotelId } : undefined,
  })
  rememberHotel(response.data)
  return response.data
}

export async function updateMyHotelProfile(
  form: HotelProfileForm,
  logoFile: File | null,
  removeLogo: boolean,
): Promise<HotelProfileResponse> {
  const data = new FormData()
  data.append('_method', 'PATCH')

  if (form.hotel_id) data.append('hotel_id', String(form.hotel_id))

  const fields: Array<keyof HotelProfileForm> = [
    'name', 'commercial_name', 'email', 'phone', 'address', 'city', 'state',
    'country', 'postal_code', 'timezone', 'customer_name', 'legal_name',
    'tax_id', 'customer_email', 'customer_phone',
  ]

  for (const field of fields) {
    data.append(field, form[field] ?? '')
  }

  if (logoFile) data.append('logo', logoFile)
  data.append('remove_logo', removeLogo ? '1' : '0')

  // Se usa POST + _method=PATCH para que PHP procese el archivo multipart.
  const response = await api.post<HotelProfileResponse>('/my/hotel', data, {
    headers: { 'Content-Type': 'multipart/form-data', 'Accept': 'application/json' },
  })

  rememberHotel(response.data)
  return response.data
}
