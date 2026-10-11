<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  getMyHotelProfile,
  updateMyHotelProfile,
  type HotelProfileForm,
  type HotelProfileResponse,
} from '@/services/hotel-profile'

const auth = useAuthStore()

const form = reactive<HotelProfileForm>({
  name: '', commercial_name: '', email: '', phone: '', address: '', city: '',
  state: '', country: 'Mexico', postal_code: '', timezone: 'America/Merida',
  customer_name: '', legal_name: '', tax_id: '', customer_email: '', customer_phone: '',
})
const hotelId = ref<number | undefined>(undefined)
const currentLogoUrl = ref('')
const selectedLogo = ref<File | null>(null)
const removeLogo = ref(false)
const loading = ref(true)
const saving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const needsSetup = ref(false)
const missingFields = ref<string[]>([])
const logoPreview = computed(() => selectedLogo.value ? URL.createObjectURL(selectedLogo.value) : currentLogoUrl.value)

function applyProfile(profile: HotelProfileResponse): void {
  // Mantiene el encabezado y los servicios sincronizados tras cargar/guardar el perfil.
  auth.setCurrentHotel(profile.hotel)
  auth.setHotelSetupStatus(profile.needs_setup)
  hotelId.value = profile.hotel.id
  form.hotel_id = profile.hotel.id
  form.name = profile.hotel.name ?? ''
  form.commercial_name = profile.hotel.commercial_name ?? ''
  form.email = profile.hotel.email ?? ''
  form.phone = profile.hotel.phone ?? ''
  form.address = profile.hotel.address ?? ''
  form.city = profile.hotel.city ?? ''
  form.state = profile.hotel.state ?? ''
  form.country = profile.hotel.country || 'Mexico'
  form.postal_code = profile.hotel.postal_code ?? ''
  form.timezone = profile.hotel.timezone || 'America/Merida'
  form.customer_name = profile.customer?.name ?? ''
  form.legal_name = profile.customer?.legal_name ?? ''
  form.tax_id = profile.customer?.tax_id ?? ''
  form.customer_email = profile.customer?.email ?? ''
  form.customer_phone = profile.customer?.phone ?? ''
  currentLogoUrl.value = profile.hotel.logo_url ?? ''
  needsSetup.value = profile.needs_setup
  missingFields.value = profile.missing_hotel_fields ?? []
  selectedLogo.value = null
  removeLogo.value = false
}

async function loadProfile(): Promise<void> {
  loading.value = true
  errorMessage.value = ''
  try {
    applyProfile(await getMyHotelProfile(auth.currentHotelId ?? undefined))
  } catch (error: any) {
    errorMessage.value = error?.response?.data?.message
      ?? 'No fue posible cargar los datos del hotel. Verifica que tu usuario tenga un hotel asignado.'
  } finally {
    loading.value = false
  }
}

function onLogoChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  if (!file) return
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    errorMessage.value = 'El logotipo debe ser JPG, PNG o WEBP.'
    input.value = ''
    return
  }
  if (file.size > 2 * 1024 * 1024) {
    errorMessage.value = 'El logotipo no debe superar 2 MB.'
    input.value = ''
    return
  }
  selectedLogo.value = file
  removeLogo.value = false
  errorMessage.value = ''
}

async function saveProfile(): Promise<void> {
  saving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const profile = await updateMyHotelProfile(form, selectedLogo.value, removeLogo.value)
    applyProfile(profile)
    successMessage.value = profile.message ?? 'Datos guardados correctamente.'
  } catch (error: any) {
    const body = error?.response?.data
    if (body?.errors) {
      errorMessage.value = Object.values(body.errors).flat().join(' ')
    } else {
      errorMessage.value = body?.message ?? 'No fue posible guardar los datos del hotel.'
    }
  } finally {
    saving.value = false
  }
}

function clearLogo(): void {
  selectedLogo.value = null
  removeLogo.value = true
  currentLogoUrl.value = ''
}

onMounted(loadProfile)
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-6 p-4 md:p-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Configuración</p>
        <h1 class="mt-1 text-2xl font-bold text-slate-900">Datos del hotel</h1>
        <p class="mt-1 text-sm text-slate-500">Esta información podrá utilizarse en el encabezado del sistema y en los comprobantes del establecimiento.</p>
      </div>
      <button
        type="button"
        class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
        :disabled="loading || saving"
        @click="loadProfile"
      >Actualizar</button>
    </header>

    <div v-if="needsSetup && !loading" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      Completa al menos el teléfono, domicilio y ciudad del establecimiento para que sus datos estén disponibles al preparar comprobantes.
      <span v-if="missingFields.length" class="mt-1 block text-xs">Pendiente: {{ missingFields.join(', ') }}</span>
    </div>
    <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ errorMessage }}</div>
    <div v-if="successMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ successMessage }}</div>

    <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">Cargando datos del hotel…</div>

    <form v-else class="space-y-6" @submit.prevent="saveProfile">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Identidad y contacto</h2>
        <p class="mb-5 mt-1 text-sm text-slate-500">Nombre, logotipo y contacto que identifican al establecimiento.</p>

        <div class="grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">Nombre del hotel *
            <input v-model.trim="form.name" required maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Nombre comercial <span class="font-normal text-slate-400">(opcional)</span>
            <input v-model.trim="form.commercial_name" maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="Deja vacío si no aplica" />
            <span class="mt-1 block text-xs font-normal text-slate-500">Solo se usa si el establecimiento utiliza un nombre distinto al nombre del hotel.</span>
          </label>
          <label class="block text-sm font-medium text-slate-700">Correo del hotel
            <input v-model.trim="form.email" type="email" maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Teléfono
            <input v-model.trim="form.phone" maxlength="50" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
        </div>

        <div class="mt-5 grid gap-5 md:grid-cols-[180px_1fr]">
          <div>
            <p class="mb-2 text-sm font-medium text-slate-700">Logotipo</p>
            <div class="flex h-36 items-center justify-center overflow-hidden rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
              <img v-if="logoPreview" :src="logoPreview" alt="Logotipo del hotel" class="max-h-full max-w-full object-contain" />
              <span v-else class="text-center text-xs text-slate-400">Sin logotipo</span>
            </div>
          </div>
          <div class="flex flex-col items-start justify-center gap-3">
            <label class="block text-sm font-medium text-slate-700">Seleccionar imagen
              <input type="file" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full text-sm font-normal text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-semibold file:text-slate-700" @change="onLogoChange" />
            </label>
            <p class="text-xs text-slate-500">Formatos JPG, PNG o WEBP; máximo 2 MB.</p>
            <button v-if="logoPreview" type="button" class="text-sm font-medium text-red-600 hover:text-red-700" @click="clearLogo">Quitar logotipo</button>
          </div>
        </div>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Domicilio del establecimiento</h2>
        <p class="mb-5 mt-1 text-sm text-slate-500">Datos que pueden imprimirse como referencia de contacto en los comprobantes.</p>
        <div class="grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">Calle y número
            <input v-model.trim="form.address" maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Ciudad o municipio
            <input v-model.trim="form.city" maxlength="120" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Estado
            <input v-model.trim="form.state" maxlength="120" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Código postal
            <input v-model.trim="form.postal_code" maxlength="20" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">País
            <input v-model.trim="form.country" required maxlength="120" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">Zona horaria
            <input v-model.trim="form.timezone" required maxlength="100" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" placeholder="America/Merida" />
            <span class="mt-1 block text-xs font-normal text-slate-500">Usa un identificador IANA, por ejemplo America/Merida o America/Mexico_City.</span>
          </label>
        </div>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:p-6">
        <h2 class="text-lg font-semibold text-slate-900">Datos fiscales del titular</h2>
        <p class="mb-5 mt-1 text-sm text-slate-500">Se guardan en la ficha del cliente propietario, no en la habitación. No implica emisión de CFDI.</p>
        <div class="grid gap-4 md:grid-cols-2">
          <label class="block text-sm font-medium text-slate-700">Nombre del titular *
            <input v-model.trim="form.customer_name" required maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Razón social
            <input v-model.trim="form.legal_name" maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">RFC
            <input v-model.trim="form.tax_id" maxlength="30" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal uppercase outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700">Correo del titular
            <input v-model.trim="form.customer_email" type="email" maxlength="255" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
          <label class="block text-sm font-medium text-slate-700 md:col-span-2">Teléfono del titular
            <input v-model.trim="form.customer_phone" maxlength="50" class="mt-1.5 w-full rounded-lg border border-slate-300 px-3 py-2.5 font-normal outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
          </label>
        </div>
      </section>

      <div class="sticky bottom-0 flex justify-end border-t border-slate-200 bg-slate-50/95 py-3 backdrop-blur">
        <button type="submit" :disabled="saving" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
          {{ saving ? 'Guardando…' : 'Guardar datos del hotel' }}
        </button>
      </div>
    </form>
  </main>
</template>
