<script setup lang="ts">
import AppSidebar from './AppSidebar.vue'
import AppTopbar from './AppTopbar.vue'
</script>

<template>
  <div class="min-h-screen bg-slate-100">
    <AppTopbar />

    <AppSidebar />

    <main class="ml-64 pt-16 min-h-screen">
      <div class="p-6">
        <RouterView />
      </div>
    </main>
  </div>
</template>