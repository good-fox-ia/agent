// Єдина точка доступу до бекенду. У mock-режимі делегує у mockApi,
// інакше робить реальні запити до Symfony /api з Bearer-токеном.

import type {
  AuthResponse,
  ChatSummary,
  InvoiceResponse,
  Me,
  SettingsPayload,
  TtsVoiceOption,
} from '@/types';
import { mockApi } from './mock';

const BASE = (import.meta.env.VITE_API_BASE_URL ?? '/api').replace(/\/$/, '');

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

let useMock = import.meta.env.VITE_USE_MOCK === 'true';
let token: string | null = null;

export function configureApi(opts: { mock: boolean; token?: string | null }): void {
  useMock = useMock || opts.mock;
  if (opts.token !== undefined) token = opts.token;
}

export function setToken(value: string | null): void {
  token = value;
}

export function isMockMode(): boolean {
  return useMock;
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const headers = new Headers(init?.headers);
  headers.set('Accept', 'application/json');
  if (init?.body) headers.set('Content-Type', 'application/json');
  if (token) headers.set('Authorization', `Bearer ${token}`);

  const res = await fetch(`${BASE}${path}`, { ...init, headers });

  if (!res.ok) {
    let message = `Запит ${path} повернув ${res.status}`;
    try {
      const data = await res.json();
      if (data?.message) message = String(data.message);
    } catch {
      /* тіло не JSON */
    }
    throw new ApiError(message, res.status);
  }

  if (res.status === 204) return undefined as T;
  return (await res.json()) as T;
}

export const api = {
  auth(rawInitData: string): Promise<AuthResponse> {
    if (useMock) return mockApi.auth();
    return request<AuthResponse>('/auth', {
      method: 'POST',
      body: JSON.stringify({ initData: rawInitData }),
    });
  },

  me(): Promise<Me> {
    if (useMock) return mockApi.me();
    return request<Me>('/me');
  },

  chats(): Promise<ChatSummary[]> {
    if (useMock) return mockApi.chats();
    return request<ChatSummary[]>('/chats');
  },

  activateChat(id: string): Promise<ChatSummary[]> {
    if (useMock) return mockApi.activateChat(id);
    return request<ChatSummary[]>(`/chats/${encodeURIComponent(id)}/activate`, {
      method: 'POST',
    });
  },

  systemPrompt(): Promise<string> {
    if (useMock) return mockApi.systemPrompt();
    return request<{ systemPrompt: string }>('/settings/system-prompt').then(
      (r) => r.systemPrompt,
    );
  },

  voices(): Promise<TtsVoiceOption[]> {
    if (useMock) return mockApi.voices();
    return request<TtsVoiceOption[]>('/settings/voices');
  },

  updateSettings(payload: SettingsPayload): Promise<Me> {
    if (useMock) return mockApi.updateSettings(payload);
    return request<Me>('/settings', {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  createInvoice(stars: number): Promise<InvoiceResponse> {
    if (useMock) return mockApi.createInvoice(stars);
    return request<InvoiceResponse>('/payments/invoice', {
      method: 'POST',
      body: JSON.stringify({ stars }),
    });
  },
};
