import { createRouter, createWebHistory } from 'vue-router'

import LoginView from '@/views/auth/LoginView.vue'

const router = createRouter({
  history: createWebHistory(),

  routes: [
    // ========================================
    // LOGIN
    // ========================================
    {
      path: '/login',
      name: 'login',
      component: LoginView,
    },

    // ========================================
    // APLICACIÓN PROTEGIDA
    // ========================================
    {
      path: '/',
      component: () => import('@/components/layout/AppLayout.vue'),
      meta: {
        requiresAuth: true,
      },
      children: [
        // Dashboard
        {
          path: '',
          redirect: '/dashboard',
        },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/dashboard/DashboardView.vue'),
        },

        // ========================================
        // GASTOS
        // ========================================
        {
          path: 'expenses',
          name: 'expenses',
          component: () => import('@/views/expenses/ExpensesView.vue'),
        },

        // ========================================
        // DATOS DEL HOTEL
        // ========================================
        {
          path: 'settings/hotel',
          name: 'hotel-settings',
          component: () => import('@/views/settings/HotelProfileView.vue'),
        },

        // ========================================
        // ADMINISTRACIÓN DE HABITACIONES
        // ========================================
        {
          path: 'rooms',
          name: 'rooms-management',
          component: () => import('@/views/rooms/RoomsManagementView.vue'),
        },
        {
          path: 'room-types',
          name: 'room-types-management',
          component: () => import('@/views/room-types/RoomTypesManagementView.vue'),
        },

        // ========================================
        // PRODUCTOS E INVENTARIO
        // ========================================
        {
          path: 'products',
          name: 'products-management',
          component: () => import('@/views/catalog/ProductsManagementView.vue'),
        },

        // ========================================
        // SERVICIOS
        // ========================================
        {
          path: 'services',
          name: 'services-management',
          component: () => import('@/views/catalog/ServicesManagementView.vue'),
        },

        // ========================================
        // TARIFAS
        // ========================================
        {
          path: 'rates',
          name: 'rates',
          component: () => import('@/views/rates/RateManagementView.vue'),
        },

        // ========================================
        // FICHA OPERATIVA DE HABITACIÓN
        // ========================================
        {
          path: 'rooms/:id',
          name: 'room-detail',
          component: () => import('@/views/rooms/RoomDetailView.vue'),
        },

        // ========================================
        // HISTORIAL DEL DÍA
        // ========================================
        {
          path: 'history/day',
          name: 'daily-history',
          component: () => import('@/views/history/HistoryDayView.vue'),
        },
      ],
    },
  ],
})

// ========================================
// PROTECCIÓN DE RUTAS
// ========================================
router.beforeEach((to) => {
  const token = localStorage.getItem('auth_token')

  // Redirigir al login si no existe un token.
  if (to.meta.requiresAuth && !token) {
    return '/login'
  }

  // Evitar que un usuario autenticado vuelva al login.
  if (to.name === 'login' && token) {
    return '/dashboard'
  }
})

export default router
