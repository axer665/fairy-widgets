<template>
  <section>
    <h1>Регистрация</h1>
    <form class="form" @submit.prevent="submit">
      <label>
        Логин
        <input v-model="login" required autocomplete="username" />
      </label>
      <label>
        Email
        <input v-model="email" type="email" required autocomplete="email" />
      </label>
      <label>
        Пароль
        <input v-model="password" type="password" required minlength="6" autocomplete="new-password" />
      </label>
      <p v-if="error" class="err">{{ error }}</p>
      <button type="submit" class="btn primary" :disabled="pending">Создать аккаунт</button>
    </form>
  </section>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { useStore } from "vuex";

const store = useStore();
const { api } = useApi();

const login = ref("");
const email = ref("");
const password = ref("");
const error = ref("");
const pending = ref(false);

async function submit() {
  error.value = "";
  pending.value = true;
  try {
    const res = await api<{ token: string; user: Record<string, unknown> }>("/api/register", {
      method: "POST",
      body: JSON.stringify({
        login: login.value,
        email: email.value,
        password: password.value,
      }),
    });
    store.commit("setAuth", { token: res.token, user: res.user });
    await navigateTo("/cabinet");
  } catch (e: unknown) {
    const err = e as { data?: { message?: string } };
    error.value = err.data?.message ?? "Ошибка регистрации";
  } finally {
    pending.value = false;
  }
}
</script>

<style scoped>
h1 {
  margin-bottom: 20px;
}
.form {
  display: flex;
  flex-direction: column;
  gap: 14px;
  max-width: 400px;
}
label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.9rem;
  color: #bdc1c6;
}
input {
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid #3c4043;
  background: #1f222a;
  color: #e8eaed;
}
.btn {
  margin-top: 8px;
  padding: 10px 18px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  font-weight: 600;
}
.btn.primary {
  background: #1a73e8;
  color: #fff;
}
.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.err {
  color: #f28b82;
  margin: 0;
}
</style>
