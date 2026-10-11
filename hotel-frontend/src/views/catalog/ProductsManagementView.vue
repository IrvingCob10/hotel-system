<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  createCatalogProduct,
  getCatalogProducts,
  updateCatalogProduct,
  type CatalogProduct,
  type ProductPayload,
} from '@/services/hotel-catalog'

const auth = useAuthStore()
const products = ref<CatalogProduct[]>([])
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

const blankForm = (): ProductPayload => ({
  name: '', description: null, sku: null, price: 1, stock: 0, active: true,
})
const form = reactive<ProductPayload>(blankForm())

const visibleProducts = computed(() => {
  const term = search.value.trim().toLocaleLowerCase('es-MX')
  return products.value.filter((product) => {
    const matchesText = !term || [product.name, product.sku ?? '', product.description ?? '']
      .some((value) => value.toLocaleLowerCase('es-MX').includes(term))
    const matchesStatus = statusFilter.value === 'all'
      || (statusFilter.value === 'active' && product.active)
      || (statusFilter.value === 'inactive' && !product.active)
    return matchesText && matchesStatus
  })
})
const activeCount = computed(() => products.value.filter((item) => item.active).length)
const inactiveCount = computed(() => products.value.length - activeCount.value)
const stockUnits = computed(() => products.value.filter((item) => item.active).reduce((sum, item) => sum + Number(item.stock || 0), 0))

const formatCurrency = (amount: number | string) => new Intl.NumberFormat('es-MX', {
  style: 'currency', currency: 'MXN',
}).format(Number(amount || 0))

function errorText(error: unknown): string {
  const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response
  const errors = response?.data?.errors
  return errors?.name?.[0] ?? errors?.sku?.[0] ?? errors?.price?.[0] ?? errors?.stock?.[0]
    ?? response?.data?.message ?? 'No fue posible completar la operación. Revisa la conexión y vuelve a intentarlo.'
}

async function loadProducts(hotelId: number | null = auth.currentHotelId) {
  pageError.value = ''
  if (!hotelId || !Number.isSafeInteger(hotelId) || hotelId <= 0) {
    products.value = []
    pageError.value = 'Tu usuario no tiene un hotel activo asignado. Cierra sesión y vuelve a entrar.'
    loading.value = false
    return
  }
  loading.value = true
  try {
    products.value = await getCatalogProducts(hotelId)
  } catch (error) {
    console.error('Error al cargar catálogo de productos:', error)
    products.value = []
    pageError.value = errorText(error)
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editingId.value = null
  Object.assign(form, blankForm())
  formError.value = ''
  pageSuccess.value = ''
  modalOpen.value = true
}
function openEdit(product: CatalogProduct) {
  editingId.value = product.id
  Object.assign(form, {
    name: product.name,
    description: product.description,
    sku: product.sku,
    price: Number(product.price),
    stock: Number(product.stock),
    active: product.active,
  })
  formError.value = ''
  pageSuccess.value = ''
  modalOpen.value = true
}
function closeModal() {
  if (!saving.value) modalOpen.value = false
}

const formIsValid = computed(() => form.name.trim().length > 0
  && form.name.trim().length <= 120
  && Number.isFinite(Number(form.price))
  && Number(form.price) > 0
  && Number(form.price) <= 99999999.99
  && Number.isInteger(Number(form.stock))
  && Number(form.stock) >= 0
  && Number(form.stock) <= 2147483647)

async function submitForm() {
  const hotelId = auth.currentHotelId
  formError.value = ''
  pageSuccess.value = ''
  if (!hotelId) {
    formError.value = 'No hay un hotel activo en la sesión.'
    return
  }
  if (!formIsValid.value) {
    formError.value = 'Indica el nombre, un precio mayor que cero y existencias enteras no negativas.'
    return
  }
  saving.value = true
  const payload: ProductPayload = {
    name: form.name.trim(),
    description: form.description?.trim() || null,
    sku: form.sku?.trim() || null,
    price: Math.round(Number(form.price) * 100) / 100,
    stock: Number(form.stock),
    active: Boolean(form.active),
  }
  try {
    if (editingId.value === null) {
      await createCatalogProduct(hotelId, payload)
      pageSuccess.value = 'Producto creado correctamente.'
    } else {
      await updateCatalogProduct(hotelId, editingId.value, payload)
      pageSuccess.value = 'Producto actualizado correctamente.'
    }
    modalOpen.value = false
    await loadProducts(hotelId)
  } catch (error) {
    console.error('Error al guardar producto:', error)
    formError.value = errorText(error)
  } finally {
    saving.value = false
  }
}

async function toggleActive(product: CatalogProduct) {
  const hotelId = auth.currentHotelId
  if (!hotelId) return
  busyId.value = product.id
  pageError.value = ''
  pageSuccess.value = ''
  try {
    await updateCatalogProduct(hotelId, product.id, {
      name: product.name,
      description: product.description,
      sku: product.sku,
      price: Number(product.price),
      stock: Number(product.stock),
      active: !product.active,
    })
    pageSuccess.value = product.active ? `Producto “${product.name}” desactivado.` : `Producto “${product.name}” activado.`
    await loadProducts(hotelId)
  } catch (error) {
    pageError.value = errorText(error)
  } finally {
    busyId.value = null
  }
}

watch(() => auth.currentHotelId, (hotelId) => void loadProducts(hotelId), { immediate: true })
</script>

<template>
  <main class="space-y-5 text-sm text-slate-800">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Administración</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-900">Productos e inventario</h1>
        <p class="mt-1 text-sm text-slate-500">Este catálogo pertenece únicamente a {{ auth.currentHotelDisplayName }}.</p>
      </div>
      <div class="flex gap-2">
        <button class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 font-semibold hover:bg-slate-50 disabled:opacity-50" :disabled="loading" @click="loadProducts()">Actualizar</button>
        <button class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white hover:bg-blue-700" @click="openCreate">+ Nuevo producto</button>
      </div>
    </header>

    <div v-if="pageSuccess" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800" role="status">{{ pageSuccess }}</div>
    <div v-if="pageError" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-700" role="alert">{{ pageError }}</div>

    <section class="grid gap-3 sm:grid-cols-3">
      <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-slate-500">Productos registrados</p><p class="mt-1 text-2xl font-bold">{{ products.length }}</p></article>
      <article class="rounded-xl border border-emerald-100 bg-white p-4 shadow-sm"><p class="text-slate-500">Activos</p><p class="mt-1 text-2xl font-bold text-emerald-700">{{ activeCount }}</p></article>
      <article class="rounded-xl border border-amber-100 bg-white p-4 shadow-sm"><p class="text-slate-500">Unidades activas en inventario</p><p class="mt-1 text-2xl font-bold text-amber-700">{{ stockUnits }}</p></article>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
      <div class="flex flex-wrap gap-3 border-b border-slate-100 p-4">
        <input v-model="search" class="min-w-56 flex-1 rounded-lg border border-slate-200 px-3 py-2.5 outline-none focus:border-blue-500" placeholder="Buscar por nombre, SKU o descripción" />
        <select v-model="statusFilter" class="rounded-lg border border-slate-200 bg-white px-3 py-2.5"><option value="all">Todos los estados</option><option value="active">Activos</option><option value="inactive">Inactivos</option></select>
      </div>
      <div v-if="loading" class="p-10 text-center text-slate-500">Cargando productos…</div>
      <div v-else-if="!visibleProducts.length" class="p-10 text-center"><p class="font-semibold">{{ products.length ? 'No hay coincidencias' : 'Aún no hay productos' }}</p><p class="mt-1 text-slate-500">Registra los productos que el hotel ofrece a sus huéspedes.</p></div>
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[760px] text-left">
          <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-4 py-3">Producto</th><th class="px-4 py-3">SKU</th><th class="px-4 py-3">Precio</th><th class="px-4 py-3">Existencias</th><th class="px-4 py-3">Estado</th><th class="px-4 py-3 text-right">Acciones</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="product in visibleProducts" :key="product.id">
              <td class="px-4 py-3"><p class="font-semibold">{{ product.name }}</p><p v-if="product.description" class="mt-0.5 max-w-sm truncate text-xs text-slate-500">{{ product.description }}</p></td>
              <td class="px-4 py-3">{{ product.sku || '—' }}</td><td class="px-4 py-3 font-semibold">{{ formatCurrency(product.price) }}</td><td class="px-4 py-3">{{ product.stock }}</td>
              <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="product.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'">{{ product.active ? 'Activo' : 'Inactivo' }}</span></td>
              <td class="px-4 py-3"><div class="flex justify-end gap-2"><button class="rounded-lg border border-slate-200 px-3 py-1.5 font-semibold hover:bg-slate-50" @click="openEdit(product)">Editar</button><button class="rounded-lg border px-3 py-1.5 font-semibold disabled:opacity-50" :class="product.active ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'" :disabled="busyId === product.id" @click="toggleActive(product)">{{ busyId === product.id ? 'Guardando…' : product.active ? 'Desactivar' : 'Activar' }}</button></div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!loading && visibleProducts.length" class="border-t border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-500">Mostrando {{ visibleProducts.length }} de {{ products.length }} productos ({{ inactiveCount }} inactivos).</div>
    </section>

    <div v-if="modalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4" @click.self="closeModal">
      <section class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl">
        <header class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-bold">{{ editingId === null ? 'Nuevo producto' : 'Editar producto' }}</h2><p class="mt-1 text-sm text-slate-500">Los cambios afectan únicamente al inventario de este hotel.</p></div><button class="rounded-lg px-2 py-1 text-xl text-slate-400 hover:bg-slate-100" @click="closeModal">×</button></header>
        <div v-if="formError" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ formError }}</div>
        <form class="mt-5 space-y-4" @submit.prevent="submitForm">
          <div><label class="mb-1 block font-semibold">Nombre *</label><input v-model="form.name" maxlength="120" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5" /></div>
          <div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block font-semibold">SKU (opcional)</label><input v-model="form.sku" maxlength="100" class="w-full rounded-lg border border-slate-200 px-3 py-2.5" /></div><div><label class="mb-1 block font-semibold">Precio MXN *</label><input v-model.number="form.price" type="number" min="0.01" step="0.01" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5" /></div></div>
          <div><label class="mb-1 block font-semibold">Descripción (opcional)</label><textarea v-model="form.description" rows="2" maxlength="2000" class="w-full rounded-lg border border-slate-200 px-3 py-2.5"></textarea></div>
          <div class="grid gap-4 sm:grid-cols-2"><div><label class="mb-1 block font-semibold">Existencias *</label><input v-model.number="form.stock" type="number" min="0" step="1" required class="w-full rounded-lg border border-slate-200 px-3 py-2.5" /></div><label class="flex items-center gap-2 self-end rounded-lg bg-slate-50 p-3"><input v-model="form.active" type="checkbox" class="h-4 w-4" /> Producto activo y disponible para venta</label></div>
          <footer class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" class="rounded-lg border border-slate-200 px-4 py-2.5 font-semibold" :disabled="saving" @click="closeModal">Cancelar</button><button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 font-semibold text-white disabled:opacity-50" :disabled="saving || !formIsValid">{{ saving ? 'Guardando…' : 'Guardar producto' }}</button></footer>
        </form>
      </section>
    </div>
  </main>
</template>
