<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  createCatalogService,
  getCatalogServices,
  updateCatalogService,
  type CatalogService,
  type ServicePayload,
} from '@/services/hotel-catalog'

const auth = useAuthStore()
const services = ref<CatalogService[]>([])
const loading = ref(false)
const saving = ref(false)
const busyId = ref<number | null>(null)
const pageError = ref('')
const pageSuccess = ref('')
const search = ref('')
const statusFilter = ref<'all' | 'active' | 'inactive'>('all')
const modalOpen = ref(false)
const editingId = ref<number | null>(null)
const formError = ref('')
const blankForm = (): ServicePayload => ({ name: '', description: null, price: 1, active: true })
const form = reactive<ServicePayload>(blankForm())

const visibleServices = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('es-MX')
  return services.value.filter((service) => {
    const matchesText = !term || [service.name, service.description ?? '']
      .some((value) => value.toLocaleLowerCase('es-MX').includes(term))
    const matchesStatus = statusFilter.value === 'all'
      || (statusFilter.value === 'active' && service.active)
      || (statusFilter.value === 'inactive' && !service.active)
    return matchesText && matchesStatus
  })
})
const activeCount = computed(() => services.value.filter((item) => item.active).length)
const inactiveCount = computed(() => services.value.length - activeCount.value)
const formatCurrency = (amount: number | string) => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(amount || 0))
function errorText(error: unknown): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response
  return response?.data?.errors?.name?.[0] ?? response?.data?.errors?.price?.[0]
    ?? response?.data?.message ?? 'No fue posible completar la operación. Revisa la conexión y vuelve a intentarlo.'
}
async function loadServices(hotelId: number | null = auth.currentHotelId) {
  pageError.value = ''
  if (!hotelId || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    services.value = []
    pageError.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión y vuelve a entrar.'
    loading.value = false
    return
  }
  loading.value = true
  try {
    services.value = await getCatalogServices(hotelId)
  } catch (error) {
    console.error('Error al cargar catálogo de servicios:', error)
    services.value = []
    pageError.value = errorText(error)
  } finally { loading.value = false }
}
function openCreate() { editingId.value = null; Object.assign(form, blankForm()); formError.value = ''; pageSuccess.value = ''; modalOpen.value = true }
function openEdit(service: CatalogService) {
  editingId.value = service.id
  Object.assign(form, { name: service.name, description: service.description, price: Number(service.price), active: service.active })
  formError.value = ''; pageSuccess.value = ''; modalOpen.value = true
}
function closeModal() { if (!saving.value) modalOpen.value = false }
const formIsValid = computed(() => form.name.trim().length > 0 && form.name.trim().length <= 120 && Number.isFinite(Number(form.price)) && Number(form.price) > 0 && Number(form.price) <= 99999999.99)
async function submitForm() {
  const hotelId = auth.currentHotelId
  formError.value = ''; pageSuccess.value = ''
  if (!hotelId) { formError.value = 'No hay un hotel activo en la sesión.'; return }
  if (!formIsValid.value) { formError.value = 'Indica un nombre y un precio mayor que cero.'; return }
  saving.value = true
  const payload: ServicePayload = { name: form.name.trim(), description: form.description?.trim() || null, price: Math.round(Number(form.price) * 100) / 100, active: Boolean(form.active) }
  try {
    if (editingId.value === null) { await createCatalogService(hotelId, payload); pageSuccess.value = 'Servicio creado correctamente.' }
    else { await updateCatalogService(hotelId, editingId.value, payload); pageSuccess.value = 'Servicio actualizado correctamente.' }
    modalOpen.value = false
    await loadServices(hotelId)
  } catch (error) { console.error('Error al guardar servicio:', error); formError.value = errorText(error) }
  finally { saving.value = false }
}
async function toggleActive(service: CatalogService) {
  const hotelId = auth.currentHotelId
  if (!hotelId) return
  busyId.value = service.id; pageError.value = ''; pageSuccess.value = ''
  try {
    await updateCatalogService(hotelId, service.id, { name: service.name, description: service.description, price: Number(service.price), active: !service.active })
    pageSuccess.value = service.active ? `Servicio “${service.name}” desactivado.` : `Servicio “${service.name}” activado.`
    await loadServices(hotelId)
  } catch (error) { pageError.value = errorText(error) }
  finally { busyId.value = null }
}
watch(() => auth.currentHotelId, (hotelId) => void loadServices(hotelId), { immediate: true })
</script>

<template>
  <main class="space-y-5 text-sm text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administración</p><h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Servicios</h1><p class="mt-1 text-sm text-slate-500">Este catálogo pertenece únicamente a {{ auth.currentHotelDisplayName }}.</p></div><div class="flex gap-2"><button class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 font-semibold hover:bg-slate-50 disabled:opacity-50" :disabled="loading" @click="loadServices()">Actualizar</button><button class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white hover:bg-blue-700" @click="openCreate">+ Nuevo servicio</button></div></header>
    <div v-if="pageSuccess" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800" role="status">{{ pageSuccess }}</div><div v-if="pageError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-700" role="alert">{{ pageError }}</div>
    <section class="grid gap-3 sm:grid-cols-3"><article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-slate-500">Servicios registrados</p><p class="mt-1 text-2xl font-bold">{{ services.length }}</p></article><article class="rounded-xl border border-emerald-100 bg-white p-4 shadow-sm"><p class="text-slate-500">Activos</p><p class="mt-1 text-2xl font-bold text-emerald-700">{{ activeCount }}</p></article><article class="rounded-xl border border-amber-100 bg-white p-4 shadow-sm"><p class="text-slate-500">Inactivos</p><p class="mt-1 text-2xl font-bold text-amber-700">{{ inactiveCount }}</p></article></section>
    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm"><div class="border-b border-slate-100 p-4"><div class="flex flex-wrap gap-3"><input v-model="search" class="min-w-56 flex-1 rounded-lg border border-slate-200 px-3 py-2.5 outline-none focus:border-blue-500" placeholder="Buscar servicio o descripción" /><select v-model="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2.5"><option value="all">Todos los estados</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select></div></div>
      <div v-if="loading" class="p-10 text-center text-slate-500">Cargando servicios…</div><div v-else-if="!visibleServices.length" class="p-10 text-center"><p class="font-semibold">{{ services.length ? 'No hay coincidencias' : 'Aún no hay servicios' }}</p><p class="mt-1 text-slate-500">Registra servicios que puedas cobrar durante una estancia.</p></div>
      <div v-else class="overflow-x-auto"><table class="w-full min-w-[700px] text-left"><thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Servicio</th><th class="px-4 py-3">Precio</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3 text-right">Acciones</th></tr></thead><tbody class="divide-y divide-slate-100"><tr v-for="service in visibleServices" :key="service.id"><td class="px-4 py-3"><p class="font-semibold">{{ service.name }}</p><p v-if="service.description" class="mt-0.5 max-w-sm truncate text-xs text-slate-500">{{ service.description }}</p></td><td class="px-4 py-3 font-semibold">{{ formatCurrency(service.price) }}</td><td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="service.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ service.active ? 'Activo' : 'Inactivo' }}</span></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><button class="rounded-lg border border-slate-200 px-3 py-1.5 font-semibold hover:bg-slate-50" @click="openEdit(service)">Editar</button><button class="rounded-lg border px-3 py-1.5 font-semibold disabled:opacity-50" :class="service.active ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'" :disabled="busyId === service.id" @click="toggleActive(service)">{{ busyId === service.id ? 'Guardando…' : service.active ? 'Desactivar' : 'Activar' }}</button></div></td></tr></tbody></table></div>
      <div v-if="!loading && visibleServices.length" class="border-t border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-500">Mostrando {{ visibleServices.length }} de {{ services.length }} servicios.</div>
    </section>
    <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4" @click.self="closeModal"><section class="w-full max-w-xl rounded-2xl bg-white p-5 shadow-2xl"><header class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-bold">{{ editingId === null ? 'Nuevo servicio' : 'Editar servicio' }}</h2><p class="mt-1 text-sm text-slate-500">Los cambios afectan únicamente al catálogo de este hotel.</p></div><button class="rounded-lg px-2 py-1 text-xl text-slate-400 hover:bg-slate-100" @click="closeModal">×</button></header><div v-if="formError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ formError }}</div><form class="mt-5 space-y-4" @submit.prevent="submitForm"><div><label class="mb-1 block font-semibold">Nombre *</label><input v-model="form.name" maxlength="120" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5" /></div><div><label class="mb-1 block font-semibold">Descripción (opcional)</label><textarea v-model="form.description" rows="3" maxlength="2000" class="w-full rounded-lg border border-slate-200 px-3 py-2.5"></textarea></div><div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block font-semibold">Precio MXN *</label><input v-model.number="form.price" type="number" min="0.01" step="0.01" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5" /></div><label class="flex items-center gap-2 self-end rounded-lg bg-slate-50 p-3"><input v-model="form.active" type="checkbox" class="h-4 w-4" /> Servicio activo para nuevas estancias</label></div><footer class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 font-semibold" :disabled="saving" @click="closeModal">Cancelar</button><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white disabled:opacity-50" :disabled="saving || !formIsValid">{{ saving ? 'Guardando…' : 'Guardar servicio' }}</button></footer></form></section></div>
  </main>
</template>
