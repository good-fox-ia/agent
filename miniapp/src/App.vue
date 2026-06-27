<script setup lang="ts">
import { onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useSessionStore } from '@/stores/session';

const session = useSessionStore();
const { ready, loading, error, mock } = storeToRefs(session);

onMounted(() => {
  session.init();
});
</script>

<template>
  <div v-if="loading && !ready" class="center">
    <div class="spinner" />
    <p class="hint">Завантаження…</p>
  </div>

  <div v-else-if="error && !ready" class="center">
    <h2>Помилка</h2>
    <p class="hint">{{ error }}</p>
    <button class="btn" @click="session.init()">Спробувати ще</button>
  </div>

  <template v-else-if="ready">
    <div v-if="mock" class="mock-banner">DEV: mock-дані (бекенд не підключено)</div>
    <RouterView />
  </template>
</template>
