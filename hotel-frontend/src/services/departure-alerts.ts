import api from './api'

export interface DepartureAlert {
  stay_id: number
  room_number: string
  room_status: string
  room_type: string
  guest_name: string
  guest_phone: string | null
  expected_check_out_at: string
  type: 'upcoming' | 'overdue'
  minutes_until_checkout: number
  minutes_overdue: number
  charges_total: number
  paid_total: number
  balance: number
}

export interface DepartureAlertsResponse {
  server_time: string
  window_minutes: number
  summary: {
    total: number
    overdue: number
    upcoming: number
  }
  alerts: DepartureAlert[]
}

/** Snapshot inmutable de una alerta que queda en la bandeja hasta que se revise. */
export interface DepartureNotificationItem {
  id: string
  signature: string
  alert: DepartureAlert
  created_at: string
  read: boolean
}

const MAX_INBOX_ITEMS = 100
const MAX_SEEN_SIGNATURES = 500

const inboxStorageKey = (hotelId: number) => `hotel:${hotelId}:departure-alerts:inbox:v2`
const seenStorageKey = (hotelId: number) => `hotel:${hotelId}:departure-alerts:seen:v2`

/** Firma de un estado concreto de salida: cambio de fecha u opción próxima/vencida crea otro evento. */
export function departureAlertSignature(alert: DepartureAlert): string {
  const timestamp = new Date(alert.expected_check_out_at).getTime()
  return `${alert.stay_id}:${alert.type}:${Number.isFinite(timestamp) ? timestamp : alert.expected_check_out_at}`
}

function readJsonArray<T>(key: string): T[] {
  try {
    const value: unknown = JSON.parse(localStorage.getItem(key) ?? '[]')
    return Array.isArray(value) ? value as T[] : []
  } catch {
    return []
  }
}

function writeJson(key: string, value: unknown): void {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch (error) {
    console.warn('No se pudo guardar la bandeja de alertas en este navegador:', error)
  }
}

export function getDepartureNotificationInbox(hotelId: number): DepartureNotificationItem[] {
  return readJsonArray<DepartureNotificationItem>(inboxStorageKey(hotelId))
    .filter(item => item && typeof item.id === 'string' && item.alert && typeof item.signature === 'string')
}

function announceInboxChanged(hotelId: number): void {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new CustomEvent('hotel-departure-inbox-updated', { detail: { hotelId } }))
  }
}

function appendIfNew(
  hotelId: number,
  alert: DepartureAlert,
): DepartureNotificationItem[] {
  const signature = departureAlertSignature(alert)
  const seen = readJsonArray<string>(seenStorageKey(hotelId))
  const inbox = getDepartureNotificationInbox(hotelId)

  if (seen.includes(signature)) return inbox

  const item: DepartureNotificationItem = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
    signature,
    alert: { ...alert },
    created_at: new Date().toISOString(),
    read: false,
  }

  const nextInbox = [item, ...inbox].slice(0, MAX_INBOX_ITEMS)
  const nextSeen = [...seen, signature].slice(-MAX_SEEN_SIGNATURES)
  writeJson(inboxStorageKey(hotelId), nextInbox)
  writeJson(seenStorageKey(hotelId), nextSeen)
  announceInboxChanged(hotelId)
  return nextInbox
}

/** Detecta nuevos estados de salida al consultar la API, sin descartar avisos anteriores no revisados. */
export function syncDepartureNotificationInbox(
  hotelId: number,
  alerts: DepartureAlert[],
): DepartureNotificationItem[] {
  let inbox = getDepartureNotificationInbox(hotelId)
  const known = new Set(readJsonArray<string>(seenStorageKey(hotelId)))

  for (const alert of alerts) {
    const signature = departureAlertSignature(alert)
    if (!known.has(signature)) {
      inbox = appendIfNew(hotelId, alert)
      known.add(signature)
    }
  }

  return getDepartureNotificationInbox(hotelId)
}

/**
 * Registra el estado nuevo inmediatamente tras una extensión/corrección.
 * Así dos ajustes hechos entre sondeos no se pierden. Solo genera alerta
 * cuando la salida ya está vencida o cae dentro de los próximos 10 minutos.
 */
export function recordDepartureAlertAfterExtension(
  hotelId: number,
  alert: DepartureAlert,
): DepartureNotificationItem[] {
  const checkout = new Date(alert.expected_check_out_at).getTime()
  if (!Number.isFinite(checkout) || checkout > Date.now() + 10 * 60 * 1000) {
    return getDepartureNotificationInbox(hotelId)
  }

  return appendIfNew(hotelId, alert)
}

export function markDepartureNotificationInboxRead(hotelId: number): DepartureNotificationItem[] {
  const next = getDepartureNotificationInbox(hotelId).map(item => ({ ...item, read: true }))
  writeJson(inboxStorageKey(hotelId), next)
  announceInboxChanged(hotelId)
  return next
}

export function clearReadDepartureNotifications(hotelId: number): DepartureNotificationItem[] {
  const next = getDepartureNotificationInbox(hotelId).filter(item => !item.read)
  writeJson(inboxStorageKey(hotelId), next)
  announceInboxChanged(hotelId)
  return next
}

/** Consulta estancias activas con salida en los próximos 10 minutos o ya vencida. */
export async function getDepartureAlerts(hotelId: number): Promise<DepartureAlertsResponse> {
  const response = await api.get<DepartureAlertsResponse>(`/hotels/${hotelId}/departure-alerts`)
  return response.data
}
