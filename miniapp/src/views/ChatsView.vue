<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '@/api/client';
import { haptic } from '@/telegram';
import type { ChatSummary } from '@/types';

const chats = ref<ChatSummary[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const switchingId = ref<string | null>(null);

onMounted(load);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    chats.value = await api.chats();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не вдалося завантажити чати.';
  } finally {
    loading.value = false;
  }
}

async function activate(chat: ChatSummary): Promise<void> {
  if (chat.isActive || switchingId.value) return;
  switchingId.value = chat.id;
  haptic('light');
  try {
    chats.value = await api.activateChat(chat.id);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не вдалося перемкнути чат.';
  } finally {
    switchingId.value = null;
  }
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('uk-UA', { day: 'numeric', month: 'short' });
}
</script>

<template>
  <div class="stack">
    <h1 class="page-title">Чати</h1>

    <div v-if="loading" class="center"><div class="spinner" /></div>
    <p v-else-if="error" class="hint">{{ error }}</p>

    <div v-else class="card">
      <div
        v-for="chat in chats"
        :key="chat.id"
        class="list-item"
        @click="activate(chat)"
      >
        <div>
          <div style="font-weight: 600">{{ chat.title }}</div>
          <div class="hint">{{ chat.messageCount }} повідомл. · {{ formatDate(chat.updatedAt) }}</div>
        </div>
        <span v-if="switchingId === chat.id" class="hint">…</span>
        <span v-else-if="chat.isActive" class="badge active">активний</span>
        <span v-else class="hint">›</span>
      </div>
    </div>
  </div>
</template>
