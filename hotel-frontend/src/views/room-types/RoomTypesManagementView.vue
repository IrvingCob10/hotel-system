<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import type { RoomTypeOption } from '@/services/rooms'
import { createRoomType, getManagedRoomTypes, updateRoomType } from '@/services/room-types'

const auth = useAuthStore()
const roomTypes = ref<RoomTypeOption[]>([])
const loading = ref(true)
const saving = ref(false)
const busyId = ref<number | null>(null)
const pageError = ref('')
const pageSuccess = ref('')
const formError = ref('')
const search = ref('')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')
const modalOpen = ref(false)
const editingId = ref<number | null>(null)

const form = reactive({
  name: '',
  description: '',
  capacity: '2',
  active: true,
})

const activeCount = computed(() => roomTypes.value.filter(item => item.active).length)
const inactiveCount = computed(() => roomTypes.value.filter(item => !item.active).length)
const filteredTypes = computed(() => {
  const query = search.value.trim().toLocaleLowerCase('es-MX')
  return roomTypes.value.filter(item => {
    const statusMatches = statusFilter.value === 'all'
      || (statusFilter.value === 'active' ? item.active : !item.active)
    const text = `${item.name} ${item.description ?? ''} ${item.capacity}`.toLocaleLowerCase('es-MX')
    return statusMatches && (!query || text.includes(query))
  })
})

function getRequiredHotelId(): number {
  const id = auth.currentHotelId
  if (id === null || !Number.isSafeInteger(id) || id <= 0) {
    throw new Error('Tu usuario no tiene un hotel activo asignado. Cierra sesión e inicia sesión nuevamente.')
  }
  return id
}

function errorMessage(error: unknown): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response
  const errors = response?.data?.errors
  return errors?.name?.[0]
    ?? errors?.capacity?.[0]
    ?? errors?.description?.[0]
    ?? response?.data?.message
    ?? (error instanceof Error ? error.message : 'No fue posible completar la operación.')
}

async function loadData(): Promise<void> {
  loading.value = true
  pageError.value = ''
  const hotelId = auth.currentHotelId
  if (hotelId === null || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    roomTypes.value = []
    pageError.value = 'Tu usuario no tiene un hotel activo asignado.'
    loading.value = false
    return
  }
  try {
    roomTypes.value = await getManagedRoomTypes(hotelId)
  } catch (error) {
    console.error('Error al cargar tipos de habitación:', error)
    pageError.value = errorMessage(error)
  } finally {
    loading.value = false
  }
}

function resetForm(): void {
  form.name = ''
  form.description = ''
  form.capacity = '2'
  form.active = true
  formError.value = ''
}

function openCreate(): void {
  editingId.value = null
  resetForm()
  pageSuccess.value = ''
  modalOpen.value = true
}

function openEdit(item: RoomTypeOption): void {
  editingId.value = item.id
  form.name = item.name
  form.description = item.description ?? ''
  form.capacity = String(item.capacity)
  form.active = item.active
  formError.value = ''
  pageSuccess.value = ''
  modalOpen.value = true
}

function closeModal(): void {
  if (saving.value) return
  modalOpen.value = false
  formError.value = ''
}

async function submitForm(): Promise<void> {
  formError.value = ''
  pageError.value = ''
  pageSuccess.value = ''

  const name = form.name.trim()
  const capacity = Number(form.capacity)
  if (!name) {
    formError.value = 'Escribe el nombre del tipo de habitación.'
    return
  }
  if (!Number.isInteger(capacity) || capacity < 1 || capacity > 1000) {
    formError.value = 'La capacidad debe ser un número entero entre 1 y 1000.'
    return
  }
  if (roomTypes.value.some(item => item.name.toLocaleLowerCase('es-MX') === name.toLocaleLowerCase('es-MX') && item.id !== editingId.value)) {
    formError.value = 'Ya existe un tipo con ese nombre en este hotel.'
    return
  }

  let hotelId: number
  try {
    hotelId = getRequiredHotelId()
  } catch (error) {
    formError.value = errorMessage(error)
    return
  }

  saving.value = true
  try {
    const payload = {
      name,
      description: form.description.trim() || null,
      capacity,
      active: form.active,
    }
    if (editingId.value !== null) {
      const saved = await updateRoomType(hotelId, editingId.value, payload)
      pageSuccess.value = saved.message
    } else {
      const saved = await createRoomType(hotelId, payload)
      pageSuccess.value = saved.message
    }
    modalOpen.value = false
    await loadData()
  } catch (error) {
    console.error('Error al guardar tipo de habitación:', error)
    formError.value = errorMessage(error)
  } finally {
    saving.value = false
  }
}

async function toggleActive(item: RoomTypeOption): Promise<void> {
  let hotelId: number
  try {
    hotelId = getRequiredHotelId()
  } catch (error) {
    pageError.value = errorMessage(error)
    return
  }
  busyId.value = item.id
  pageError.value = ''
  pageSuccess.value = ''
  try {
    const saved = await updateRoomType(hotelId, item.id, {
      name: item.name,
      description: item.description,
      capacity: item.capacity,
      active: !item.active,
    })
    roomTypes.value = roomTypes.value.map(type => type.id === saved.room_type.id ? saved.room_type : type)
    pageSuccess.value = saved.message
  } catch (error) {
    console.error('Error al cambiar estado del tipo de habitación:', error)
    pageError.value = errorMessage(error)
  } finally {
    busyId.value = null
  }
}

watch(() => auth.currentHotelId, () => { void loadData() }, { immediate: true })
</script>

<template>
  <main class="space-y-5 text-sm text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administración</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Tipos de habitación</h1>
        <p class="mt-1 text-sm text-slate-500">Define categorías y capacidad para las habitaciones de este hotel. Cada dueño administra su propio catálogo.</p>
      </div>
      <div class="flex gap-2">
        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" :disabled="loading" @click="loadData">Actualizar</button>
        <button type="button" class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50" :disabled="loading || !auth.currentHotelId" @click="openCreate">+ Nuevo tipo</button>
      </div>
    </header>

    <div v-if="pageError" role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ pageError }}</div>
    <div v-if="pageSuccess" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ pageSuccess }}</div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Tipos registrados</p><p class="mt-2 text-2xl font-bold">{{ roomTypes.length }}</p></section>
      <section class="rounded-xl border border-emerald-100 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Activos</p><p class="mt-2 text-2xl font-bold text-emerald-600">{{ activeCount }}</p></section>
      <section class="rounded-xl border border-amber-100 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Inactivos</p><p class="mt-2 text-2xl font-bold text-amber-600">{{ inactiveCount }}</p></section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-4">
        <div><h2 class="font-bold text-slate-900">Catálogo del hotel</h2><p class="mt-1 text-xs text-slate-500">Los tipos inactivos no se ofrecen para nuevas habitaciones; no se eliminan registros históricos.</p></div>
        <div class="flex flex-wrap gap-2">
          <input v-model="search" type="search" placeholder="Buscar tipo" aria-label="Buscar tipo" class="rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-blue-500" />
          <select v-model="statusFilter" aria-label="Filtrar estado" class="rounded-lg border border-slate-300 bg-white px-3 py-2"><option value="all">Todos los estados</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select>
        </div>
      </div>
      <div v-if="loading" class="p-8 text-center text-slate-500">Cargando tipos de habitación…</div>
      <div v-else-if="!filteredTypes.length" class="p-10 text-center">
        <p class="font-semibold text-slate-800">{{ roomTypes.length ? 'No hay coincidencias' : 'Aún no hay tipos de habitación' }}</p>
        <p class="mt-1 text-slate-500">{{ roomTypes.length ? 'Cambia la búsqueda o el filtro.' : 'Crea el primer tipo para poder registrar habitaciones en este hotel.' }}</p>
        <button v-if="!roomTypes.length && !pageError" type="button" class="mt-4 rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white" @click="openCreate">Crear primer tipo</button>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-100">
          <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Capacidad</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3 text-right">Acciones</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="item in filteredTypes" :key="item.id" class="hover:bg-slate-50/70">
              <td class="px-4 py-3"><p class="font-semibold text-slate-900">{{ item.name }}</p><p class="mt-0.5 text-xs text-slate-500">{{ item.description || 'Sin descripción' }}</p></td>
              <td class="px-4 py-3">{{ item.capacity }} personas</td>
              <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="item.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ item.active ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="px-4 py-3"><div class="flex justify-end gap-2"><button type="button" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold hover:bg-slate-50" @click="openEdit(item)">Editar</button><button type="button" class="rounded-lg border px-3 py-1.5 text-xs font-semibold disabled:opacity-50" :class="item.active ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'" :disabled="busyId === item.id" @click="toggleActive(item)">{{ busyId === item.id ? 'Guardando…' : item.active ? 'Desactivar' : 'Activar' }}</button></div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!loading && roomTypes.length" class="border-t border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-500">Mostrando {{ filteredTypes.length }} de {{ roomTypes.length }} tipos del hotel.</div>
    </section>

    <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/40 p-0 sm:items-center sm:p-4" @click.self="closeModal">
      <section class="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-t-2xl bg-white p-5 shadow-2xl sm:rounded-2xl">
        <div class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-bold text-slate-900">{{ editingId === null ? 'Nuevo tipo de habitación' : 'Editar tipo de habitación' }}</h2><p class="mt-1 text-sm text-slate-500">Este registro solo pertenece al hotel activo.</p></div><button type="button" class="rounded-lg px-2 py-1 text-xl text-slate-500 hover:bg-slate-100" aria-label="Cerrar" @click="closeModal">×</button></div>
        <form class="mt-5 space-y-4" @submit.prevent="submitForm">
          <div><label class="mb-1 block font-semibold text-slate-700">Nombre del tipo *</label><input v-model="form.name" required maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-blue-500" placeholder="Ej. Standard" /></div>
          <div><label class="mb-1 block font-semibold text-slate-700">Descripción (opcional)</label><textarea v-model="form.description" rows="3" maxlength="1000" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-blue-500" placeholder="Características o detalles" /></div>
          <div><label class="mb-1 block font-semibold text-slate-700">Capacidad (personas) *</label><input v-model="form.capacity" type="number" min="1" max="1000" step="1" required class="w-full rounded-lg border border-slate-300 px-3 py-2.5 outline-none focus:border-blue-500" /></div>
          <label v-if="editingId !== null" class="flex items-center gap-2 text-sm text-slate-700"><input v-model="form.active" type="checkbox" class="rounded border-slate-300" /> Tipo activo para nuevas habitaciones</label>
          <div v-if="formError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ formError }}</div>
          <div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" class="rounded-lg border border-slate-200 px-4 py-2 font-semibold" :disabled="saving" @click="closeModal">Cancelar</button><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50" :disabled="saving">{{ saving ? 'Guardando…' : editingId === null ? 'Crear tipo' : 'Guardar cambios' }}</button></div>
        </form>
      </section>
    </div>
  </main>
</template>
