<script setup lang="ts">
import { ref } from 'vue';
import { storeToRefs } from 'pinia';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { haptic, openInvoice } from '@/telegram';

const session = useSessionStore();
const { balance } = storeToRefs(session);

const presets = [50, 100, 250, 500, 1000, 2500];
const selected = ref<number>(100);
const busy = ref(false);
const message = ref<string | null>(null);

async function topup(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  message.value = null;
  haptic('medium');

  try {
    const { invoiceLink } = await api.createInvoice(selected.value);
    const status = await openInvoice(invoiceLink);

    if (status === 'paid') {
      await session.refreshMe();
      message.value = 'Дякуємо! Баланс оновлено.';
    } else if (status === 'cancelled' || status === 'failed') {
      message.value = 'Оплату не завершено.';
    } else {
      // mock-режим або поза Telegram: інвойс не відкриється.
      message.value = 'Інвойс створено. У Telegram тут відкриється вікно оплати.';
    }
  } catch (e) {
    message.value = e instanceof Error ? e.message : 'Не вдалося створити інвойс.';
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="stack">
    <h1 class="page-title">Поповнення</h1>

    <div class="card">
      <div class="hint">Поточний баланс</div>
      <div class="balance-amount">{{ balance }} ★</div>
    </div>

    <div class="card">
      <div class="hint" style="margin-bottom: 10px">Оберіть кількість зірок</div>
      <div class="grid-amounts">
        <button
          v-for="amount in presets"
          :key="amount"
          class="amount-chip"
          :class="{ selected: selected === amount }"
          @click="((selected = amount), haptic('light'))"
        >
          {{ amount }} ★
        </button>
      </div>
    </div>

    <button class="btn block" :disabled="busy" @click="topup">
      {{ busy ? 'Обробка…' : `Поповнити на ${selected} ★` }}
    </button>

    <p v-if="message" class="hint" style="text-align: center">{{ message }}</p>
  </div>
</template>
