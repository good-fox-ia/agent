import { createRouter, createWebHashHistory } from 'vue-router';
import { onBackButton, setBackButton } from '@/telegram';

// Hash-історія: працює без SPA-fallback на статичному вебсервері
// і дружить зі start_param від Telegram.
export const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    { path: '/', name: 'profile', component: () => import('@/views/ProfileView.vue') },
    { path: '/topup', name: 'topup', component: () => import('@/views/TopupView.vue') },
    { path: '/chats', name: 'chats', component: () => import('@/views/ChatsView.vue') },
    { path: '/settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
});

// Системна «Назад» Telegram: один обробник + перемикання видимості.
onBackButton(() => router.back());
router.afterEach((to) => {
  setBackButton(to.name !== 'profile');
});
