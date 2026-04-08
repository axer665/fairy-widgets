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
          <p v-if="a.widget_call_hint" class="hint">{{ a.widget_call_hint }}</p>
        </div>
        <label v-if="a.status === 'approved' && a.embed_snippet" class="std-behavior">
          <input
            type="checkbox"
            :checked="a.standard_behavior"
            :disabled="stdBehaviorPending[a.id]"
            @change="setStandardBehavior(a, $event)"
          />
          <span>Стандартное поведение: через несколько секунд фея сама вылетает, говорит приветствие и улетает (как раньше). Без галочки фея реагирует только на <code>show("ключ")</code>.</span>
        </label>
        <div v-if="a.status === 'approved'" class="events card-inner">
          <h3>События феи</h3>
          <p class="muted small">Ключ (латиница, цифры, _ и -) передаётся в <code>myLittleFairyWidget.show("ключ")</code></p>
          <ul v-if="a.events?.length" class="evlist">
            <li v-for="e in a.events" :key="e.id">
              <code>{{ e.event_key }}</code> — {{ e.phrase }}
            </li>
          </ul>
          <p v-else class="muted small">Пока нет событий</p>
          <form class="evform" @submit.prevent="addEvent(a)">
            <input v-model="eventForms[a.id].key" placeholder="ключ, напр. promo" pattern="[a-zA-Z0-9_-]{1,64}" required />
            <textarea v-model="eventForms[a.id].phrase" placeholder="Текст для феи" rows="2" required />
            <button type="submit" class="btn primary sm" :disabled="eventPending[a.id]">Добавить / обновить</button>
          </form>
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

type WidgetEventRow = {
  id: number;
  event_key: string;
  phrase: string;
};

type AppRow = {
  id: number;
  site_url: string;
  status: string;
  embed_snippet: string | null;
  widget_call_hint: string | null;
  moderator_note: string | null;
  standard_behavior: boolean;
  events?: WidgetEventRow[];
};

const { api } = useApi();

const apps = ref<AppRow[]>([]);
const siteUrl = ref("");
const loadError = ref("");
const createError = ref("");
const createPending = ref(false);
const eventForms = ref<Record<number, { key: string; phrase: string }>>({});
const eventPending = ref<Record<number, boolean>>({});
const stdBehaviorPending = ref<Record<number, boolean>>({});

function ensureEventForm(id: number) {
  if (!eventForms.value[id]) eventForms.value[id] = { key: "", phrase: "" };
}

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
    for (const a of apps.value) {
      ensureEventForm(a.id);
      if (a.status !== "approved") continue;
      try {
        const ev = await api<{ events: WidgetEventRow[] }>(`/api/applications/${a.id}/events`, { method: "GET" });
        a.events = ev.events;
      } catch {
        a.events = [];
      }
    }
  } catch {
    loadError.value = "Не удалось загрузить заявки";
  }
}

async function setStandardBehavior(a: AppRow, ev: Event) {
  const input = ev.target as HTMLInputElement;
  const next = input.checked;
  const prev = a.standard_behavior;
  a.standard_behavior = next;
  stdBehaviorPending.value[a.id] = true;
  try {
    await api<{ ok: boolean; standard_behavior: boolean }>(`/api/applications/${a.id}`, {
      method: "PUT",
      body: JSON.stringify({ standard_behavior: next }),
    });
  } catch {
    a.standard_behavior = prev;
    input.checked = prev;
  } finally {
    stdBehaviorPending.value[a.id] = false;
  }
}

async function addEvent(a: AppRow) {
  ensureEventForm(a.id);
  const f = eventForms.value[a.id];
  if (!f.key.trim() || !f.phrase.trim()) return;
  eventPending.value[a.id] = true;
  try {
    await api(`/api/applications/${a.id}/events`, {
      method: "POST",
      body: JSON.stringify({ event_key: f.key.trim(), phrase: f.phrase.trim() }),
    });
    f.key = "";
    f.phrase = "";
    await load();
  } catch {
    /* handled by api */
  } finally {
    eventPending.value[a.id] = false;
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
.embed .hint {
  margin-top: 10px;
  font-size: 0.85rem;
  color: #9aa0a6;
}
.std-behavior {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  margin: 14px 0 0;
  padding: 12px 14px;
  border-radius: 8px;
  border: 1px solid #2e3238;
  background: #171a20;
  font-size: 0.88rem;
  color: #bdc1c6;
  cursor: pointer;
}
.std-behavior input {
  margin-top: 3px;
  flex-shrink: 0;
  accent-color: #1a73e8;
}
.std-behavior span code {
  font-size: 0.9em;
}
.card-inner {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid #2e3238;
}
.card-inner h3 {
  margin: 0 0 8px;
  font-size: 1rem;
}
.muted.small {
  font-size: 0.82rem;
  margin: 0 0 10px;
}
.evlist {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  font-size: 0.88rem;
  color: #bdc1c6;
}
.evlist li {
  margin-bottom: 6px;
  word-break: break-word;
}
.evform {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: flex-start;
}
.evform input,
.evform textarea {
  flex: 1 1 140px;
  min-width: 120px;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #3c4043;
  background: #0f1115;
  color: #e8eaed;
  font: inherit;
}
.evform textarea {
  flex: 2 1 200px;
  min-height: 56px;
  resize: vertical;
}
.btn.sm {
  padding: 8px 14px;
  font-size: 0.85rem;
  margin-top: 0;
}
code {
  font-size: 0.85em;
  background: #0f1115;
  padding: 2px 6px;
  border-radius: 4px;
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
