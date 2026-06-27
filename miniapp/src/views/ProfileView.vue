<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useSessionStore } from '@/stores/session';
import { haptic } from '@/telegram';

const router = useRouter();
const session = useSessionStore();
const { profile, balance, displayName } = storeToRefs(session);

const initials = computed(() => {
  const name = displayName.value.trim();
  if (!name) return '?';
  const parts = name.split(/\s+/);
  return (parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '');
});

function go(path: string): void {
  haptic('light');
  router.push(path);
}
</script>

<template>
  <div class="stack">
    <div class="card row">
      <div class="avatar">{{ initials.toUpperCase() }}</div>
      <div>
        <h2 style="margin: 0">{{ displayName }}</h2>
        <div class="hint">
          <span v-if="profile?.username">@{{ profile.username }}</span>
          <span v-if="profile?.isPremium" class="badge active" style="margin-left: 6px">
            Premium
          </span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="hint">Баланс</div>
      <div class="row between" style="margin-top: 4px">
        <div class="balance-amount">{{ balance }} ★</div>
        <button class="btn" @click="go('/topup')">Поповнити</button>
      </div>
    </div>

    <div class="card">
      <div class="list-item" @click="go('/chats')">
        <span>Чати</span>
        <span class="hint">›</span>
      </div>
      <div class="list-item" @click="go('/settings')">
        <span>Налаштування</span>
        <span class="hint">›</span>
      </div>
    </div>
  </div>
</template>
