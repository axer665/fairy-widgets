<template>
  <div class="layout">
    <header class="header">
      <NuxtLink to="/" class="logo">Кабинет</NuxtLink>
      <ClientOnly>
        <nav>
          <NuxtLink v-if="!token" to="/login">Вход</NuxtLink>
          <NuxtLink v-if="!token" to="/register">Регистрация</NuxtLink>
          <NuxtLink v-if="token" to="/cabinet">Заявки</NuxtLink>
          <button v-if="token" type="button" class="linkish" @click="logout">Выход</button>
        </nav>
        <template #fallback>
          <nav class="muted">…</nav>
        </template>
      </ClientOnly>
    </header>
    <main class="main">
      <slot />
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useStore } from "vuex";

const store = useStore();
const token = computed(() => store.state.token as string | null);

function logout() {
  store.commit("logout");
  navigateTo("/login");
}
</script>

<style scoped>
.layout {
  min-height: 100vh;
  background: #0f1115;
  color: #e8eaed;
  font-family: system-ui, sans-serif;
}
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 24px;
  border-bottom: 1px solid #252830;
}
.logo {
  font-weight: 700;
  color: #8ab4ff;
  text-decoration: none;
}
nav {
  display: flex;
  gap: 16px;
  align-items: center;
}
nav a {
  color: #bdc1c6;
  text-decoration: none;
}
nav a:hover {
  color: #fff;
}
.main {
  max-width: 720px;
  margin: 0 auto;
  padding: 32px 20px 48px;
}
.linkish {
  background: none;
  border: none;
  color: #bdc1c6;
  cursor: pointer;
  font: inherit;
  padding: 0;
}
.linkish:hover {
  color: #fff;
}
.muted {
  color: #5f6368;
}
</style>
