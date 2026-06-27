<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { api } from '@/api/client';
import { useSessionStore } from '@/stores/session';
import { haptic } from '@/telegram';
import type { TtsVoiceOption } from '@/types';

const session = useSessionStore();
const { profile } = storeToRefs(session);

const voiceReply = ref(false);
const ttsVoice = ref<string | null>(null);
const systemPrompt = ref('');
const voices = ref<TtsVoiceOption[]>([]);

const loading = ref(true);
const saving = ref(false);
const savedAt = ref<number | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
  voiceReply.value = profile.value?.voiceReply ?? false;
  ttsVoice.value = profile.value?.ttsVoice ?? null;
  try {
    const [v, prompt] = await Promise.all([api.voices(), api.systemPrompt()]);
    voices.value = v;
    systemPrompt.value = prompt;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не вдалося завантажити налаштування.';
  } finally {
    loading.value = false;
  }
});

async function save(): Promise<void> {
  if (saving.value) return;
  saving.value = true;
  error.value = null;
  haptic('medium');
  try {
    await session.saveSettings({
      voiceReply: voiceReply.value,
      ttsVoice: ttsVoice.value,
      systemPrompt: systemPrompt.value,
    });
    savedAt.value = Date.now();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Не вдалося зберегти.';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="stack">
    <h1 class="page-title">Налаштування</h1>

    <div v-if="loading" class="center"><div class="spinner" /></div>

    <template v-else>
      <div class="card">
        <div class="row between">
          <div>
            <div style="font-weight: 600">Голосові відповіді</div>
            <div class="hint">Озвучувати відповіді бота</div>
          </div>
          <label class="switch">
            <input v-model="voiceReply" type="checkbox" />
            <span class="track" />
          </label>
        </div>
      </div>

      <div class="card field">
        <label for="voice">Голос (TTS)</label>
        <select id="voice" v-model="ttsVoice" class="select" :disabled="!voiceReply">
          <option :value="null">— за замовчуванням —</option>
          <option v-for="v in voices" :key="v.value" :value="v.value">
            {{ v.value }} — {{ v.description }}
          </option>
        </select>
      </div>

      <div class="card field">
        <label for="prompt">Системний промпт</label>
        <textarea
          id="prompt"
          v-model="systemPrompt"
          class="textarea"
          placeholder="Інструкції для асистента…"
        />
      </div>

      <button class="btn block" :disabled="saving" @click="save">
        {{ saving ? 'Збереження…' : 'Зберегти' }}
      </button>

      <p v-if="error" class="hint" style="color: var(--destructive); text-align: center">
        {{ error }}
      </p>
      <p v-else-if="savedAt" class="hint" style="text-align: center">Збережено ✓</p>
    </template>
  </div>
</template>
