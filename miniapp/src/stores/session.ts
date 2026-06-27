import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { api, configureApi, isMockMode, setToken } from '@/api/client';
import { bootstrapTelegram } from '@/telegram';
import type { Me, Profile, SettingsPayload } from '@/types';

export const useSessionStore = defineStore('session', () => {
  const ready = ref(false);
  const loading = ref(false);
  const error = ref<string | null>(null);
  const inTelegram = ref(false);
  const mock = ref(false);
  const me = ref<Me | null>(null);

  const profile = computed<Profile | null>(() => me.value?.user ?? null);
  const balance = computed<number>(() => me.value?.balance.amount ?? 0);
  const displayName = computed<string>(() => {
    const u = profile.value;
    if (!u) return '';
    return [u.firstName, u.lastName].filter(Boolean).join(' ') || u.username || 'Користувач';
  });

  /** Завантажує/створює сесію: Telegram initData → /auth, або mock. */
  async function init(): Promise<void> {
    if (ready.value || loading.value) return;
    loading.value = true;
    error.value = null;

    const tg = bootstrapTelegram();
    inTelegram.value = tg.inTelegram;

    // Поза Telegram (немає initData) → mock-режим.
    const forceMock = !tg.rawInitData;
    configureApi({ mock: forceMock });
    mock.value = isMockMode();

    try {
      const auth = await api.auth(tg.rawInitData ?? '');
      setToken(auth.token);
      configureApi({ mock: mock.value, token: auth.token });
      me.value = auth.me;
      ready.value = true;
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Не вдалося авторизуватися';
    } finally {
      loading.value = false;
    }
  }

  async function refreshMe(): Promise<void> {
    me.value = await api.me();
  }

  async function saveSettings(payload: SettingsPayload): Promise<void> {
    me.value = await api.updateSettings(payload);
  }

  function applyMe(next: Me): void {
    me.value = next;
  }

  return {
    ready,
    loading,
    error,
    inTelegram,
    mock,
    me,
    profile,
    balance,
    displayName,
    init,
    refreshMe,
    saveSettings,
    applyMe,
  };
});
