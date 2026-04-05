<template>
  <section>
    <h1>Заявки на виджет</h1>

    <form class="card" @submit.prevent="createApp">
      <h2>Новая заявка</h2>
      <label>
        URL сайта
        <input v-model="siteUrl" type="url" placeholder="https://example.com" required />
      </label>
      <p v-if="createError" class="err">{{ createError }}</p>
      <button type="submit" class="btn primary" :disabled="createPending">Отправить</button>
    </form>

    <div v-if="loadError" class="err">{{ loadError }}</div>

    <ul v-else class="list">
      <li v-for="a in apps" :key="a.id" class="card">
        <div class="row">
          <strong>#{{ a.id }}</strong>
          <span class="badge" :data-status="a.status">{{ statusLabel(a.status) }}</span>
        </div>
        <p class="url">{{ a.site_url }}</p>
        <p v-if="a.moderator_note" class="note">Комментарий: {{ a.moderator_note }}</p>
        <div v-if="a.embed_snippet" class="embed">
          <p>Код для сайта:</p>
          <pre>{{ a.embed_snippet }}</pre>
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from "vue";

definePageMeta({
  middleware: "auth",
});

type AppRow = {
  id: number;
  site_url: string;
  status: string;
  embed_snippet: string | null;
  moderator_note: string | null;
};

const { api } = useApi();

const apps = ref<AppRow[]>([]);
const siteUrl = ref("");
const loadError = ref("");
const createError = ref("");
const createPending = ref(false);

function statusLabel(s: string) {
  if (s === "pending") return "На модерации";
  if (s === "approved") return "Одобрено";
  if (s === "rejected") return "Отклонено";
  return s;
}

async function load() {
  loadError.value = "";
  try {
    const res = await api<{ applications: AppRow[] }>("/api/applications", { method: "GET" });
    apps.value = res.applications;
  } catch {
    loadError.value = "Не удалось загрузить заявки";
  }
}

async function createApp() {
  createError.value = "";
  createPending.value = true;
  try {
    await api("/api/applications", {
      method: "POST",
      body: JSON.stringify({ site_url: siteUrl.value }),
    });
    siteUrl.value = "";
    await load();
  } catch (e: unknown) {
    const err = e as { data?: { message?: string } };
    createError.value = err.data?.message ?? "Ошибка отправки";
  } finally {
    createPending.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
h1 {
  margin-bottom: 20px;
}
h2 {
  margin: 0 0 12px;
  font-size: 1.1rem;
}
.card {
  background: #1f222a;
  border: 1px solid #2e3238;
  border-radius: 12px;
  padding: 16px 18px;
  margin-bottom: 16px;
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
  background: #0f1115;
  color: #e8eaed;
}
.list {
  list-style: none;
  padding: 0;
  margin: 24px 0 0;
}
.row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.badge {
  font-size: 0.8rem;
  padding: 4px 10px;
  border-radius: 999px;
  background: #3c4043;
}
.badge[data-status="approved"] {
  background: #1e3a2f;
  color: #81c995;
}
.badge[data-status="rejected"] {
  background: #3c1f1f;
  color: #f28b82;
}
.badge[data-status="pending"] {
  background: #3d3a1f;
  color: #fdd663;
}
.url {
  word-break: break-all;
  color: #9aa0a6;
  margin: 0 0 8px;
}
.note {
  color: #f28b82;
  font-size: 0.9rem;
}
.embed pre {
  background: #0f1115;
  padding: 12px;
  border-radius: 8px;
  overflow-x: auto;
  font-size: 0.8rem;
  border: 1px solid #2e3238;
}
.btn {
  margin-top: 12px;
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
}
</style>
