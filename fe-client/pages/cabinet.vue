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

        <template v-if="a.status === 'approved'">
          <p class="muted small help-top">
            На сайт вставляется один скрипт с токеном заявки. Феи и события настраиваются только здесь; при вызове
            <code>myLittleFairyWidget.show("ключ")</code> сервер сам выбирает фею, проверяет занятость и блокировку
            события. Автоприветствие — это событие <code>_standard</code>: отредактируйте текст в блоке событий и
            отметьте его у нужных фей; после загрузки страницы виджет сам вызовет его (как
            <code>show("_standard")</code>).
          </p>

          <div v-if="a.embed_snippet" class="embed card-inner">
            <p>Код для сайта:</p>
            <pre>{{ a.embed_snippet }}</pre>
          </div>

          <div v-for="f in a.fairies" :key="f.id" class="fairy-block card-inner">
            <div class="fairy-head">
              <input
                v-model="f.name"
                class="fairy-name-input"
                type="text"
                maxlength="128"
                @blur="saveFairyName(f)"
              />
            </div>
            <div v-if="a.events?.length" class="assign">
              <p class="muted small">События для этой феи (можно несколько):</p>
              <ul class="chk-list">
                <li v-for="e in a.events" :key="e.id">
                  <label>
                    <input
                      type="checkbox"
                      :checked="isEventChecked(f.id, e.id)"
                      @change="toggleEvent(f.id, e.id, $event)"
                    />
                    <code>{{ e.event_key }}</code>
                    <span class="phrase-preview">{{ e.phrase }}</span>
                  </label>
                </li>
              </ul>
              <button
                type="button"
                class="btn primary sm"
                :disabled="assignPending[f.id]"
                @click="saveAssignments(a, f)"
              >
                Сохранить назначения
              </button>
            </div>
            <p v-else class="muted small">Сначала добавьте события в блоке ниже — затем назначьте их феям.</p>
          </div>

          <button
            v-if="a.fairies.length > 0"
            type="button"
            class="btn sm secondary"
            :disabled="fairyCreatePending[a.id]"
            @click="addFairy(a)"
          >
            Добавить фею
          </button>

          <div v-if="a.fairies.length === 0" class="muted small">Ожидайте одобрения модератором — появится первая фея.</div>

          <div class="events card-inner">
            <h3>События (ключи для <code>show("ключ")</code>)</h3>
            <p class="muted small">Несколько событий с разными ключами; повтор ключа обновляет текст. Новое событие по умолчанию назначается всем феям этой заявки.</p>
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

          <div class="failures card-inner">
            <h3>Журнал сбоев выполнения</h3>
            <p v-if="!failureRows[a.id]?.length" class="muted small">Пока записей нет</p>
            <ul v-else class="fail-list">
              <li v-for="row in failureRows[a.id]" :key="row.id">
                <time class="fail-time">{{ formatFailTime(row.created_at) }}</time>
                <span class="fail-fairy">{{ row.fairy_name }}</span>
                —
                <span class="fail-key">{{ formatEventKey(row.event_key) }}</span>
                <span class="fail-reason">({{ reasonLabel(row.reason_code) }})</span>
                <p v-if="row.detail" class="fail-detail">{{ row.detail }}</p>
                <p v-if="row.blocker_event_key || row.blocker_execution_id" class="fail-blocker">
                  {{ failureBlockerText(row) }}
                </p>
              </li>
            </ul>
          </div>
        </template>
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

type FairyRow = {
  id: number;
  name: string;
  assigned_event_ids: number[];
};

type AppRow = {
  id: number;
  site_url: string;
  status: string;
  moderator_note: string | null;
  embed_snippet?: string;
  fairies: FairyRow[];
  events?: WidgetEventRow[];
};

type FailureRow = {
  id: number;
  fairy_id: number;
  fairy_name: string;
  widget_event_id: number | null;
  event_key: string;
  reason_code: string;
  detail: string | null;
  blocker_execution_id: number | null;
  blocker_fairy_id: number | null;
  blocker_widget_event_id: number | null;
  blocker_event_key: string | null;
  created_at: string;
};

const { api } = useApi();

const apps = ref<AppRow[]>([]);
const siteUrl = ref("");
const loadError = ref("");
const createError = ref("");
const createPending = ref(false);
const eventForms = ref<Record<number, { key: string; phrase: string }>>({});
const eventPending = ref<Record<number, boolean>>({});
const assignPending = ref<Record<number, boolean>>({});
const fairyCreatePending = ref<Record<number, boolean>>({});
const eventSelection = ref<Record<number, number[]>>({});
const failureRows = ref<Record<number, FailureRow[]>>({});

function ensureEventForm(id: number) {
  if (!eventForms.value[id]) eventForms.value[id] = { key: "", phrase: "" };
}

function ensureSelection(fairyId: number, ids: number[]) {
  eventSelection.value[fairyId] = [...ids].sort((x, y) => x - y);
}

function isEventChecked(fairyId: number, eventId: number) {
  return (eventSelection.value[fairyId] ?? []).includes(eventId);
}

function toggleEvent(fairyId: number, eventId: number, ev: Event) {
  const checked = (ev.target as HTMLInputElement).checked;
  const cur = [...(eventSelection.value[fairyId] ?? [])];
  if (checked) {
    if (!cur.includes(eventId)) cur.push(eventId);
  } else {
    const i = cur.indexOf(eventId);
    if (i >= 0) cur.splice(i, 1);
  }
  eventSelection.value[fairyId] = cur;
}

function failureBlockerText(row: FailureRow): string {
  let s = "Блокировка: выполнение #" + String(row.blocker_execution_id ?? "");
  if (row.blocker_event_key) s += ", событие «" + formatEventKey(row.blocker_event_key) + "»";
  if (row.blocker_fairy_id) s += " (фея id " + row.blocker_fairy_id + ")";
  return s;
}

function statusLabel(s: string) {
  if (s === "pending") return "На модерации";
  if (s === "approved") return "Одобрено";
  if (s === "rejected") return "Отклонено";
  return s;
}

function reasonLabel(code: string) {
  const m: Record<string, string> = {
    fairy_busy: "фея была занята другим показом",
    event_locked_other_fairy: "в этой сессии браузера это событие уже выполняется (или вкладка дублирует вызов)",
    event_not_assigned: "событие не назначено этой фее",
    event_not_found: "событие с таким ключом не найдено",
    standard_not_enabled: "стандартное поведение выключено",
    all_fairies_busy: "все подходящие феи были заняты",
  };
  return m[code] ?? code;
}

function formatEventKey(k: string) {
  if (k === "_standard") return "стандартное приветствие";
  return k;
}

function formatFailTime(iso: string) {
  try {
    const d = new Date(iso);
    return d.toLocaleString();
  } catch {
    return iso;
  }
}

async function loadFailures(appId: number) {
  try {
    const res = await api<{ failures: FailureRow[] }>(`/api/applications/${appId}/event-failures?limit=50`, {
      method: "GET",
    });
    failureRows.value[appId] = res.failures;
  } catch {
    failureRows.value[appId] = [];
  }
}

async function load() {
  loadError.value = "";
  try {
    const res = await api<{ applications: AppRow[] }>("/api/applications", { method: "GET" });
    apps.value = res.applications;
    for (const a of apps.value) {
      ensureEventForm(a.id);
      if (!a.fairies) a.fairies = [];
      for (const f of a.fairies) {
        ensureSelection(f.id, [...f.assigned_event_ids]);
      }
      if (a.status !== "approved") continue;
      try {
        const ev = await api<{ events: WidgetEventRow[] }>(`/api/applications/${a.id}/events`, { method: "GET" });
        a.events = ev.events;
      } catch {
        a.events = [];
      }
      await loadFailures(a.id);
    }
  } catch {
    loadError.value = "Не удалось загрузить заявки";
  }
}

async function saveFairyName(f: FairyRow) {
  const name = f.name.trim() || "Фея";
  f.name = name;
  try {
    await api(`/api/fairies/${f.id}`, {
      method: "PUT",
      body: JSON.stringify({ name }),
    });
  } catch {
    /* ignore */
  }
}

async function saveAssignments(_a: AppRow, f: FairyRow) {
  assignPending.value[f.id] = true;
  const ids = [...(eventSelection.value[f.id] ?? [])];
  try {
    await api(`/api/fairies/${f.id}/events`, {
      method: "PUT",
      body: JSON.stringify({ event_ids: ids }),
    });
    f.assigned_event_ids = [...ids].sort((x, y) => x - y);
  } catch {
    /* revert visual from server on reload */
    await load();
  } finally {
    assignPending.value[f.id] = false;
  }
}

async function addFairy(a: AppRow) {
  fairyCreatePending.value[a.id] = true;
  try {
    await api(`/api/applications/${a.id}/fairies`, {
      method: "POST",
      body: JSON.stringify({ name: `Фея ${a.fairies.length + 1}` }),
    });
    await load();
  } catch {
    /* */
  } finally {
    fairyCreatePending.value[a.id] = false;
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
.help-top {
  margin: 0 0 12px;
}
.fairy-block {
  margin-top: 12px;
}
.fairy-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}
.fairy-name-input {
  flex: 1 1 160px;
  max-width: 280px;
  padding: 8px 10px;
  font-weight: 600;
}
.embed pre {
  background: #0f1115;
  padding: 12px;
  border-radius: 8px;
  overflow-x: auto;
  font-size: 0.8rem;
  border: 1px solid #2e3238;
}
.assign {
  margin-top: 12px;
}
.chk-list {
  list-style: none;
  padding: 0;
  margin: 8px 0 12px;
  font-size: 0.86rem;
}
.chk-list li {
  margin-bottom: 8px;
}
.chk-list label {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 8px;
  cursor: pointer;
}
.chk-list input {
  margin-top: 3px;
}
.phrase-preview {
  color: #9aa0a6;
  flex: 1 1 100%;
  margin-left: 24px;
  font-size: 0.82rem;
}
.btn.secondary {
  margin-top: 8px;
  background: #3c4043;
  color: #e8eaed;
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
.fail-list {
  list-style: none;
  padding: 0;
  margin: 0;
  font-size: 0.85rem;
  color: #bdc1c6;
}
.fail-list li {
  margin-bottom: 14px;
  padding-bottom: 12px;
  border-bottom: 1px solid #2a2d33;
}
.fail-time {
  color: #9aa0a6;
  margin-right: 8px;
}
.fail-fairy {
  font-weight: 600;
  color: #e8eaed;
}
.fail-reason {
  color: #f28b82;
}
.fail-detail,
.fail-blocker {
  margin: 6px 0 0;
  font-size: 0.82rem;
  color: #9aa0a6;
}
.fail-blocker code {
  font-size: 0.9em;
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
