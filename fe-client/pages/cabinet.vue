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
          <nav class="app-tabs" role="tablist">
            <button
              type="button"
              role="tab"
              class="app-tab"
              :class="{ active: appTab(a.id) === 'fairies' }"
              :aria-selected="appTab(a.id) === 'fairies'"
              @click="setAppTab(a.id, 'fairies')"
            >
              Феи
            </button>
            <button
              type="button"
              role="tab"
              class="app-tab"
              :class="{ active: appTab(a.id) === 'events' }"
              :aria-selected="appTab(a.id) === 'events'"
              @click="setAppTab(a.id, 'events')"
            >
              События
              <span v-if="a.events?.length" class="tab-count">{{ a.events.length }}</span>
            </button>
            <button
              type="button"
              role="tab"
              class="app-tab"
              :class="{ active: appTab(a.id) === 'text' }"
              :aria-selected="appTab(a.id) === 'text'"
              @click="onTextTab(a.id)"
            >
              Текст
              <span v-if="textWidgets[a.id]?.length" class="tab-count">{{ textWidgets[a.id].length }}</span>
            </button>
            <button
              type="button"
              role="tab"
              class="app-tab"
              :class="{ active: appTab(a.id) === 'survey' }"
              :aria-selected="appTab(a.id) === 'survey'"
              @click="onSurveyTab(a.id)"
            >
              Опросы
              <span v-if="surveyWidgets[a.id]?.length" class="tab-count">{{ surveyWidgets[a.id].length }}</span>
            </button>
            <button
              type="button"
              role="tab"
              class="app-tab"
              :class="{ active: appTab(a.id) === 'video' }"
              :aria-selected="appTab(a.id) === 'video'"
              @click="onVideoTab(a.id)"
            >
              Видео
              <span v-if="videoWidgets[a.id]?.length" class="tab-count">{{ videoWidgets[a.id].length }}</span>
            </button>
            <button
              type="button"
              role="tab"
              class="app-tab"
              :class="{ active: appTab(a.id) === 'failures' }"
              :aria-selected="appTab(a.id) === 'failures'"
              @click="onFailuresTab(a.id)"
            >
              Журнал сбоев
              <span v-if="failureRows[a.id]?.length" class="tab-count warn">{{ failureRows[a.id].length }}</span>
            </button>
          </nav>

          <div v-show="appTab(a.id) === 'fairies'" class="tab-panel" role="tabpanel">
          <p class="muted small help-top">
            На сайт вставляется один скрипт. При вызове <code>myLittleFairyWidget.show("ключ")</code> сервер выбирает
            свободную фею с назначенным событием. Автоприветствие — событие <code>_standard</code> (вкладка «События»).
          </p>

          <div v-if="a.embed_snippet" class="embed panel-block">
            <p>Код для сайта:</p>
            <pre>{{ a.embed_snippet }}</pre>
          </div>

          <div v-for="f in a.fairies" :key="f.id" class="fairy-block panel-block">
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
                    <span class="ev-type-badge">{{ e.action_type_label || "Текст" }}</span>
                    <span class="phrase-preview">{{ eventPreviewText(e) }}</span>
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
            <p v-else class="muted small">Сначала добавьте события во вкладке «События», затем назначьте их феям.</p>
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

          <p v-if="a.fairies.length === 0" class="muted small panel-block">
            Ожидайте одобрения модератором — появится первая фея.
          </p>
          </div>

          <div v-show="appTab(a.id) === 'events'" class="tab-panel" role="tabpanel">
            <h3 class="panel-title">События</h3>
            <p class="muted small">
              Ключи для <code>show("ключ")</code>. Выберите тип и виджет из соответствующей вкладки. Новое событие
              назначается всем феям заявки.
            </p>
            <ul v-if="a.events?.length" class="evlist ev-cards">
              <li v-for="e in a.events" :key="e.id" class="ev-card" :class="{ editing: editingEventId(a.id) === e.id }">
                <div class="ev-card-main">
                  <code>{{ e.event_key }}</code>
                  <span class="ev-type-badge">{{ e.action_type_label || "Текст" }}</span>
                  <p class="ev-phrase">{{ eventPreviewText(e) }}</p>
                  <span class="ev-pos">{{ formatEventPosition(e.position) }}</span>
                </div>
                <div class="ev-card-actions">
                  <button type="button" class="btn sm secondary" @click="fillEventForm(a.id, e)">Изменить</button>
                  <button
                    v-if="e.event_key !== '_standard'"
                    type="button"
                    class="btn sm danger"
                    :disabled="eventDeletePending[e.id]"
                    @click="deleteEvent(a, e)"
                  >
                    Удалить
                  </button>
                  <span v-else class="muted small">системное</span>
                </div>
              </li>
            </ul>
            <p v-else class="muted small">Пока нет событий — создайте первое ниже.</p>
            <form class="evform panel-block" @submit.prevent="addEvent(a)">
              <p v-if="editingEventId(a.id)" class="ev-form-hint">
                Редактирование: <code>{{ eventForms[a.id].key }}</code>
              </p>
              <p v-else class="ev-form-hint muted small">Новое событие</p>
              <label class="ev-field">
                Тип действия
                <select v-model="eventForms[a.id].action_type" :disabled="eventForms[a.id].key === '_standard'">
                  <option v-for="t in actionTypes" :key="t.code" :value="t.code">{{ t.label }}</option>
                </select>
              </label>
              <input
                v-model="eventForms[a.id].key"
                placeholder="ключ, напр. promo"
                pattern="[a-zA-Z0-9_-]{1,64}"
                :readonly="!!editingEventId(a.id)"
                required
              />
              <label class="ev-field">
                Виджет
                <select v-model.number="eventForms[a.id].widget_id" required>
                  <option :value="0" disabled>Выберите виджет</option>
                  <option
                    v-for="w in widgetsForEventType(a.id, eventForms[a.id].action_type)"
                    :key="w.id"
                    :value="w.id"
                  >
                    {{ w.name }}
                  </option>
                </select>
              </label>
              <p v-if="!widgetsForEventType(a.id, eventForms[a.id].action_type).length" class="muted small">
                Создайте виджет во вкладке «{{ eventTypeTabLabel(eventForms[a.id].action_type) }}».
              </p>
              <fieldset class="pos-fieldset">
                <legend class="muted small">Позиция приземления феи</legend>
                <div class="pos-row">
                  <label class="pos-label">
                    По горизонтали
                    <span class="pos-inline">
                      <input
                        v-model.number="eventForms[a.id].position.x"
                        type="number"
                        min="0"
                        :max="eventForms[a.id].position.unit === 'percent' ? 100 : 10000"
                        step="any"
                        required
                      />
                      <select v-model="eventForms[a.id].position.unit">
                        <option value="px">px</option>
                        <option value="percent">%</option>
                      </select>
                      от
                      <select v-model="eventForms[a.id].position.horizontal">
                        <option value="left">левого</option>
                        <option value="right">правого</option>
                      </select>
                      края
                    </span>
                  </label>
                  <label class="pos-label">
                    По вертикали
                    <span class="pos-inline">
                      <input
                        v-model.number="eventForms[a.id].position.y"
                        type="number"
                        min="0"
                        :max="eventForms[a.id].position.unit === 'percent' ? 100 : 10000"
                        step="any"
                        required
                      />
                      <select v-model="eventForms[a.id].position.unit">
                        <option value="px">px</option>
                        <option value="percent">%</option>
                      </select>
                      от
                      <select v-model="eventForms[a.id].position.vertical">
                        <option value="top">верхнего</option>
                        <option value="bottom">нижнего</option>
                      </select>
                      края
                    </span>
                  </label>
                </div>
              </fieldset>
              <div class="evform-actions">
                <button type="submit" class="btn primary sm" :disabled="eventPending[a.id]">
                  {{ editingEventId(a.id) ? "Сохранить" : "Создать" }}
                </button>
                <button
                  v-if="editingEventId(a.id)"
                  type="button"
                  class="btn sm secondary"
                  @click="clearEventForm(a.id)"
                >
                  Отмена
                </button>
              </div>
            </form>
          </div>

          <div v-show="appTab(a.id) === 'text'" class="tab-panel" role="tabpanel">
            <h3 class="panel-title">Текстовые виджеты</h3>
            <ul v-if="textWidgets[a.id]?.length" class="widget-list">
              <li
                v-for="w in textWidgets[a.id]"
                :key="w.id"
                class="widget-card"
                :class="{ editing: editingTextWidgetId(a.id) === w.id }"
              >
                <template v-if="editingTextWidgetId(a.id) === w.id">
                  <p class="ev-form-hint muted small">«{{ w.name }}»</p>
                  <form class="widget-edit-form" @submit.prevent="updateTextWidget(a, w)">
                    <textarea v-model="textEditForms[w.id].body" rows="3" required maxlength="2000" />
                    <div class="widget-card-actions">
                      <button type="submit" class="btn primary sm" :disabled="textEditPending[w.id]">Сохранить</button>
                      <button type="button" class="btn sm secondary" @click="cancelTextEdit(a.id)">Отмена</button>
                    </div>
                  </form>
                </template>
                <template v-else>
                  <strong>{{ w.name }}</strong>
                  <p class="widget-preview">{{ w.body }}</p>
                  <p class="widget-stats muted small">Показов: {{ w.stats.impressions }}</p>
                  <div class="widget-card-actions">
                    <button type="button" class="btn sm secondary" @click="startTextEdit(a.id, w)">Изменить</button>
                    <button type="button" class="btn sm danger" @click="deleteTextWidget(a, w)">Удалить</button>
                  </div>
                </template>
              </li>
            </ul>
            <p v-else class="muted small">Пока нет текстовых виджетов</p>
            <form class="panel-block evform" @submit.prevent="saveTextWidget(a)">
              <input v-model="textForms[a.id].name" placeholder="Название" maxlength="128" required />
              <textarea v-model="textForms[a.id].body" placeholder="Текст" rows="3" required />
              <button type="submit" class="btn primary sm" :disabled="textPending[a.id]">Добавить</button>
            </form>
          </div>

          <div v-show="appTab(a.id) === 'survey'" class="tab-panel" role="tabpanel">
            <h3 class="panel-title">Виджеты опросов</h3>
            <ul v-if="surveyWidgets[a.id]?.length" class="widget-list">
              <li
                v-for="w in surveyWidgets[a.id]"
                :key="w.id"
                class="widget-card"
                :class="{ editing: editingSurveyWidgetId(a.id) === w.id }"
              >
                <template v-if="editingSurveyWidgetId(a.id) === w.id">
                  <p class="ev-form-hint muted small">«{{ w.name }}»</p>
                  <form class="widget-edit-form" @submit.prevent="updateSurveyWidget(a, w)">
                    <input v-model="surveyEditForms[w.id].title" placeholder="Заголовок" maxlength="512" required />
                    <textarea
                      v-model="surveyEditForms[w.id].description"
                      placeholder="Описание (необязательно)"
                      rows="2"
                      maxlength="2000"
                    />
                    <label class="ev-field">
                      Авто-уход феи через (сек)
                      <input
                        v-model.number="surveyEditForms[w.id].dismiss_after_sec"
                        type="number"
                        min="0"
                        max="86400"
                        placeholder="0 — не улетать по таймеру"
                      />
                    </label>
                    <p class="muted small ev-form-hint">0 — только после оценки или «Отмена»</p>
                    <div class="widget-card-actions">
                      <button type="submit" class="btn primary sm" :disabled="surveyEditPending[w.id]">Сохранить</button>
                      <button type="button" class="btn sm secondary" @click="cancelSurveyEdit(a.id)">Отмена</button>
                    </div>
                  </form>
                </template>
                <template v-else>
                  <strong>{{ w.name }}</strong>
                  <p class="widget-preview">{{ w.title }}</p>
                  <p v-if="w.description" class="muted small">{{ w.description }}</p>
                  <p v-if="w.dismiss_after_ms" class="muted small">
                    Авто-уход через {{ msToSec(w.dismiss_after_ms) }} с
                  </p>
                  <p class="widget-stats muted small">
                    Показов: {{ w.stats.impressions }} · Оценок: {{ w.stats.ratings_count }}
                    <template v-if="w.stats.avg_rating != null"> · Средняя: {{ w.stats.avg_rating }}</template>
                    · Отмен: {{ w.stats.cancellations }}
                  </p>
                  <div class="widget-card-actions">
                    <button type="button" class="btn sm secondary" @click="startSurveyEdit(a.id, w)">Изменить</button>
                    <button type="button" class="btn sm danger" @click="deleteSurveyWidget(a, w)">Удалить</button>
                  </div>
                </template>
              </li>
            </ul>
            <p v-else class="muted small">Пока нет опросов</p>
            <form class="panel-block evform" @submit.prevent="saveSurveyWidget(a)">
              <input v-model="surveyForms[a.id].name" placeholder="Название" maxlength="128" required />
              <input v-model="surveyForms[a.id].title" placeholder="Заголовок опроса" maxlength="512" required />
              <textarea v-model="surveyForms[a.id].description" placeholder="Описание (необязательно)" rows="2" />
              <label class="ev-field">
                Авто-уход феи через (сек)
                <input
                  v-model.number="surveyForms[a.id].dismiss_after_sec"
                  type="number"
                  min="0"
                  max="86400"
                  placeholder="0 — не улетать по таймеру"
                />
              </label>
              <p class="muted small ev-form-hint">0 — только после оценки или «Отмена»</p>
              <button type="submit" class="btn primary sm" :disabled="surveyPending[a.id]">Добавить</button>
            </form>
          </div>

          <div v-show="appTab(a.id) === 'video'" class="tab-panel" role="tabpanel">
            <h3 class="panel-title">Видео-виджеты</h3>
            <p class="muted small">MP4, WebM или MOV, не более 10 МБ. Видео загружается при создании виджета.</p>
            <ul v-if="videoWidgets[a.id]?.length" class="widget-list">
              <li
                v-for="w in videoWidgets[a.id]"
                :key="w.id"
                class="widget-card"
                :class="{ editing: editingVideoWidgetId(a.id) === w.id }"
              >
                <template v-if="editingVideoWidgetId(a.id) === w.id">
                  <p class="ev-form-hint muted small">«{{ w.name }}» · {{ w.original_filename }}</p>
                  <form class="widget-edit-form" @submit.prevent="updateVideoWidget(a, w)">
                    <input
                      v-model="videoEditForms[w.id].link_url"
                      type="url"
                      placeholder="Ссылка «Подробнее» (оставьте пустым, чтобы убрать)"
                    />
                    <fieldset class="leave-mode-fieldset">
                      <legend class="muted small">Когда фея улетает</legend>
                      <label class="radio-row">
                        <input v-model="videoEditForms[w.id].leave_mode" type="radio" value="video_end" />
                        После окончания видео
                      </label>
                      <label class="radio-row">
                        <input v-model="videoEditForms[w.id].leave_mode" type="radio" value="timer" />
                        По таймеру
                      </label>
                    </fieldset>
                    <label v-if="videoEditForms[w.id].leave_mode === 'timer'" class="ev-field">
                      Таймер (сек)
                      <input
                        v-model.number="videoEditForms[w.id].leave_timer_sec"
                        type="number"
                        min="1"
                        max="86400"
                        required
                      />
                    </label>
                    <div class="widget-card-actions">
                      <button type="submit" class="btn primary sm" :disabled="videoEditPending[w.id]">Сохранить</button>
                      <button type="button" class="btn sm secondary" @click="cancelVideoEdit(a.id)">Отмена</button>
                    </div>
                  </form>
                </template>
                <template v-else>
                  <strong>{{ w.name }}</strong>
                  <p class="muted small">{{ w.original_filename }}</p>
                  <p class="muted small">
                    Длительность: {{ formatDurationMs(w.duration_ms) }}
                    · Уход:
                    {{
                      w.leave_mode === "timer" && w.leave_timer_ms
                        ? "таймер " + msToSec(w.leave_timer_ms) + " с"
                        : "после видео"
                    }}
                  </p>
                  <p v-if="w.link_url" class="muted small widget-link">Ссылка: {{ w.link_url }}</p>
                  <p class="widget-stats muted small">
                    Показов: {{ w.stats.impressions }} · Досмотров: {{ w.stats.completed_full_count }} · Кликов:
                    {{ w.stats.link_clicks }} · Отмен: {{ w.stats.dismissals }}
                    <template v-if="w.stats.avg_watch_ms != null">
                      · Ср. просмотр: {{ formatWatchMs(w.stats.avg_watch_ms) }}
                    </template>
                  </p>
                  <div class="widget-card-actions">
                    <button type="button" class="btn sm secondary" @click="startVideoEdit(a.id, w)">Изменить</button>
                    <button type="button" class="btn sm danger" @click="deleteVideoWidget(a, w)">Удалить</button>
                  </div>
                </template>
              </li>
            </ul>
            <p v-else class="muted small">Пока нет видео-виджетов</p>
            <form
              :key="'video-create-' + a.id + '-' + (videoFormResetKey[a.id] ?? 0)"
              class="panel-block evform"
              @submit.prevent="saveVideoWidget(a)"
            >
              <input v-model="videoForms[a.id].name" placeholder="Название виджета" maxlength="128" required />
              <label class="ev-field file-field">
                Видеофайл
                <input
                  type="file"
                  accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov"
                  required
                  @change="onVideoCreateFilePick(a.id, $event)"
                />
              </label>
              <p v-if="videoCreateFile[a.id]" class="muted small">
                {{ videoCreateFile[a.id]!.name }} ({{ formatBytes(videoCreateFile[a.id]!.size) }})
                <template v-if="videoCreateDurationMs[a.id] !== undefined">
                  · длительность:
                  {{
                    videoCreateDurationMs[a.id]
                      ? formatDurationMs(videoCreateDurationMs[a.id])
                      : "не удалось определить (будет предупреждение в виджете)"
                  }}
                </template>
              </p>
              <input v-model="videoForms[a.id].link_url" type="url" placeholder="Ссылка «Подробнее» (необязательно)" />
              <fieldset class="leave-mode-fieldset">
                <legend class="muted small">Когда фея улетает</legend>
                <label class="radio-row">
                  <input v-model="videoForms[a.id].leave_mode" type="radio" value="video_end" />
                  После окончания видео
                </label>
                <label class="radio-row">
                  <input v-model="videoForms[a.id].leave_mode" type="radio" value="timer" />
                  По таймеру
                </label>
              </fieldset>
              <label v-if="videoForms[a.id].leave_mode === 'timer'" class="ev-field">
                Таймер (сек)
                <input
                  v-model.number="videoForms[a.id].leave_timer_sec"
                  type="number"
                  min="1"
                  max="86400"
                  required
                />
              </label>
              <button
                type="submit"
                class="btn primary sm"
                :disabled="videoPending[a.id] || !videoCreateFile[a.id]"
              >
                Создать виджет
              </button>
            </form>
          </div>

          <div v-show="appTab(a.id) === 'failures'" class="tab-panel" role="tabpanel">
            <div class="panel-head">
              <h3 class="panel-title">Журнал сбоев выполнения</h3>
              <button type="button" class="btn sm secondary" @click="loadFailures(a.id)">Обновить</button>
            </div>
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

type EventLandPosition = {
  horizontal: "left" | "right";
  vertical: "top" | "bottom";
  unit: "px" | "percent";
  x: number;
  y: number;
};

type ActionTypeCode = "text" | "survey" | "video";

type ActionTypeRow = { code: ActionTypeCode; label: string };

type TextWidgetRow = {
  id: number;
  name: string;
  body: string;
  stats: { impressions: number };
};

type SurveyWidgetRow = {
  id: number;
  name: string;
  title: string;
  description: string | null;
  dismiss_after_ms: number | null;
  stats: {
    impressions: number;
    ratings_count: number;
    avg_rating: number | null;
    cancellations: number;
  };
};

type VideoLeaveMode = "video_end" | "timer";

type VideoWidgetRow = {
  id: number;
  name: string;
  media_id: number;
  link_url: string | null;
  leave_mode: VideoLeaveMode;
  leave_timer_ms: number | null;
  duration_ms: number | null;
  original_filename: string;
  stats: {
    impressions: number;
    completed_full_count: number;
    link_clicks: number;
    dismissals: number;
    avg_watch_ms: number | null;
  };
};

type WidgetEventRow = {
  id: number;
  event_key: string;
  action_type: ActionTypeCode;
  action_type_label?: string;
  widget_id: number;
  widget_name?: string;
  phrase?: string;
  survey_title?: string | null;
  survey_description?: string | null;
  position?: EventLandPosition;
};

type EventFormState = {
  key: string;
  action_type: ActionTypeCode;
  widget_id: number;
  position: EventLandPosition;
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

type AppTabId = "fairies" | "events" | "text" | "survey" | "video" | "failures";

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

const actionTypes = ref<ActionTypeRow[]>([
  { code: "text", label: "Текст" },
  { code: "survey", label: "Опрос удовлетворённости" },
  { code: "video", label: "Видео" },
]);

const apps = ref<AppRow[]>([]);
const siteUrl = ref("");
const loadError = ref("");
const createError = ref("");
const createPending = ref(false);
const eventForms = ref<Record<number, EventFormState>>({});

function defaultEventPosition(): EventLandPosition {
  return { horizontal: "right", vertical: "bottom", unit: "px", x: 150, y: 130 };
}
const eventPending = ref<Record<number, boolean>>({});
const assignPending = ref<Record<number, boolean>>({});
const fairyCreatePending = ref<Record<number, boolean>>({});
const eventSelection = ref<Record<number, number[]>>({});
const failureRows = ref<Record<number, FailureRow[]>>({});
const appTabs = ref<Record<number, AppTabId>>({});
const editingEventIds = ref<Record<number, number | null>>({});
const eventDeletePending = ref<Record<number, boolean>>({});
const textWidgets = ref<Record<number, TextWidgetRow[]>>({});
const surveyWidgets = ref<Record<number, SurveyWidgetRow[]>>({});
const videoWidgets = ref<Record<number, VideoWidgetRow[]>>({});
const textForms = ref<Record<number, { name: string; body: string }>>({});
const surveyForms = ref<Record<number, { name: string; title: string; description: string; dismiss_after_sec: number }>>({});
const videoForms = ref<Record<number, { name: string; link_url: string; leave_mode: VideoLeaveMode; leave_timer_sec: number }>>({});
const videoCreateFile = ref<Record<number, File | null>>({});
const videoCreateDurationMs = ref<Record<number, number | null | undefined>>({});
const videoFormResetKey = ref<Record<number, number>>({});
const textPending = ref<Record<number, boolean>>({});
const surveyPending = ref<Record<number, boolean>>({});
const videoPending = ref<Record<number, boolean>>({});
const editingTextWidgetIds = ref<Record<number, number | null>>({});
const editingSurveyWidgetIds = ref<Record<number, number | null>>({});
const editingVideoWidgetIds = ref<Record<number, number | null>>({});
const textEditForms = ref<Record<number, { body: string }>>({});
const surveyEditForms = ref<Record<number, { title: string; description: string; dismiss_after_sec: number }>>({});
const videoEditForms = ref<Record<number, { link_url: string; leave_mode: VideoLeaveMode; leave_timer_sec: number }>>({});
const textEditPending = ref<Record<number, boolean>>({});
const surveyEditPending = ref<Record<number, boolean>>({});
const videoEditPending = ref<Record<number, boolean>>({});

function appTab(appId: number): AppTabId {
  return appTabs.value[appId] ?? "fairies";
}

function setAppTab(appId: number, tab: AppTabId) {
  appTabs.value[appId] = tab;
}

function onFailuresTab(appId: number) {
  setAppTab(appId, "failures");
  void loadFailures(appId);
}

function onTextTab(appId: number) {
  setAppTab(appId, "text");
  void loadTextWidgets(appId);
}

function onSurveyTab(appId: number) {
  setAppTab(appId, "survey");
  void loadSurveyWidgets(appId);
}

function onVideoTab(appId: number) {
  setAppTab(appId, "video");
  void loadVideoWidgets(appId);
}

function defaultEventForm(): EventFormState {
  return {
    key: "",
    action_type: "text",
    widget_id: 0,
    position: defaultEventPosition(),
  };
}

function defaultTextForm() {
  return { name: "", body: "" };
}

function defaultSurveyForm() {
  return { name: "", title: "", description: "", dismiss_after_sec: 0 };
}

function defaultVideoForm() {
  return { name: "", link_url: "", leave_mode: "video_end" as VideoLeaveMode, leave_timer_sec: 30 };
}

function ensureWidgetForms(appId: number) {
  if (!textForms.value[appId]) textForms.value[appId] = defaultTextForm();
  if (!surveyForms.value[appId]) surveyForms.value[appId] = defaultSurveyForm();
  if (!videoForms.value[appId]) videoForms.value[appId] = defaultVideoForm();
}

function eventTypeTabLabel(t: ActionTypeCode): string {
  if (t === "survey") return "Опросы";
  if (t === "video") return "Видео";
  return "Текст";
}

function widgetsForEventType(
  appId: number,
  t: ActionTypeCode,
): { id: number; name: string }[] {
  if (t === "survey") return (surveyWidgets.value[appId] || []).map((w) => ({ id: w.id, name: w.name }));
  if (t === "video") return (videoWidgets.value[appId] || []).map((w) => ({ id: w.id, name: w.name }));
  return (textWidgets.value[appId] || []).map((w) => ({ id: w.id, name: w.name }));
}

function formatWatchMs(ms: number): string {
  if (ms < 1000) return ms + " мс";
  const s = Math.round(ms / 1000);
  if (s < 60) return s + " с";
  return Math.floor(s / 60) + " мин " + (s % 60) + " с";
}

function msToSec(ms: number | null | undefined): number {
  if (!ms || ms < 1) return 0;
  return Math.round(ms / 1000);
}

function formatDurationMs(ms: number | null | undefined): string {
  if (!ms || ms < 1) return "неизвестна";
  const total = Math.round(ms / 1000);
  const m = Math.floor(total / 60);
  const s = total % 60;
  if (m > 0) return m + ":" + String(s).padStart(2, "0");
  return s + " с";
}

function readVideoDuration(file: File): Promise<number | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const video = document.createElement("video");
    video.preload = "metadata";
    video.onloadedmetadata = () => {
      URL.revokeObjectURL(url);
      const d = video.duration;
      resolve(Number.isFinite(d) && d > 0 ? Math.round(d * 1000) : null);
    };
    video.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };
    video.src = url;
  });
}


function eventPreviewText(e: WidgetEventRow): string {
  const label = e.widget_name ? e.widget_name + ": " : "";
  if (e.action_type === "survey") return label + (e.survey_title || "");
  if (e.action_type === "video") return label + "видео";
  return label + (e.phrase || "");
}

function formatBytes(n: number): string {
  if (n < 1024) return n + " B";
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + " KB";
  return (n / (1024 * 1024)).toFixed(1) + " MB";
}

async function onVideoCreateFilePick(appId: number, ev: Event) {
  const input = ev.target as HTMLInputElement;
  const file = input.files?.[0] ?? null;
  videoCreateFile.value[appId] = file;
  videoCreateDurationMs.value[appId] = undefined;
  if (file) {
    videoCreateDurationMs.value[appId] = await readVideoDuration(file);
  }
}

function editingEventId(appId: number): number | null {
  return editingEventIds.value[appId] ?? null;
}

function ensureEventForm(id: number) {
  if (!eventForms.value[id]) {
    eventForms.value[id] = defaultEventForm();
  }
}

function fillEventForm(appId: number, e: WidgetEventRow) {
  ensureEventForm(appId);
  const pos = e.position ?? defaultEventPosition();
  eventForms.value[appId] = {
    key: e.event_key,
    action_type: e.action_type || "text",
    widget_id: e.widget_id || 0,
    position: { ...pos },
  };
  editingEventIds.value[appId] = e.id;
  setAppTab(appId, "events");
}

function clearEventForm(appId: number) {
  ensureEventForm(appId);
  eventForms.value[appId] = defaultEventForm();
  editingEventIds.value[appId] = null;
}

function formatEventPosition(pos?: EventLandPosition) {
  const p = pos ?? defaultEventPosition();
  const unit = p.unit === "percent" ? "%" : "px";
  const h = p.horizontal === "left" ? "слева" : "справа";
  const v = p.vertical === "top" ? "сверху" : "снизу";
  return `(${p.x}${unit} ${h}, ${p.y}${unit} ${v})`;
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
    all_fairies_busy: "в этой сессии не осталось свободной феи для показа",
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


async function loadTextWidgets(appId: number) {
  try {
    const res = await api<{ widgets: TextWidgetRow[] }>(`/api/applications/${appId}/text-widgets`, {
      method: "GET",
    });
    textWidgets.value[appId] = res.widgets;
  } catch {
    textWidgets.value[appId] = [];
  }
}

async function loadSurveyWidgets(appId: number) {
  try {
    const res = await api<{ widgets: SurveyWidgetRow[] }>(`/api/applications/${appId}/survey-widgets`, {
      method: "GET",
    });
    surveyWidgets.value[appId] = res.widgets;
  } catch {
    surveyWidgets.value[appId] = [];
  }
}

async function loadVideoWidgets(appId: number) {
  try {
    const res = await api<{ widgets: VideoWidgetRow[] }>(`/api/applications/${appId}/video-widgets`, {
      method: "GET",
    });
    videoWidgets.value[appId] = res.widgets;
  } catch {
    videoWidgets.value[appId] = [];
  }
}

function editingTextWidgetId(appId: number): number | null {
  return editingTextWidgetIds.value[appId] ?? null;
}

function editingSurveyWidgetId(appId: number): number | null {
  return editingSurveyWidgetIds.value[appId] ?? null;
}

function editingVideoWidgetId(appId: number): number | null {
  return editingVideoWidgetIds.value[appId] ?? null;
}

function startTextEdit(appId: number, w: TextWidgetRow) {
  textEditForms.value[w.id] = { body: w.body };
  editingTextWidgetIds.value[appId] = w.id;
}

function cancelTextEdit(appId: number) {
  editingTextWidgetIds.value[appId] = null;
}

function startSurveyEdit(appId: number, w: SurveyWidgetRow) {
  surveyEditForms.value[w.id] = {
    title: w.title,
    description: w.description || "",
    dismiss_after_sec: msToSec(w.dismiss_after_ms),
  };
  editingSurveyWidgetIds.value[appId] = w.id;
}

function cancelSurveyEdit(appId: number) {
  editingSurveyWidgetIds.value[appId] = null;
}

function startVideoEdit(appId: number, w: VideoWidgetRow) {
  videoEditForms.value[w.id] = {
    link_url: w.link_url || "",
    leave_mode: w.leave_mode || "video_end",
    leave_timer_sec: msToSec(w.leave_timer_ms) || 30,
  };
  editingVideoWidgetIds.value[appId] = w.id;
}

function cancelVideoEdit(appId: number) {
  editingVideoWidgetIds.value[appId] = null;
}

async function updateTextWidget(a: AppRow, w: TextWidgetRow) {
  const f = textEditForms.value[w.id];
  if (!f?.body.trim()) return;
  textEditPending.value[w.id] = true;
  try {
    await api(`/api/applications/${a.id}/text-widgets/${w.id}`, {
      method: "PUT",
      body: JSON.stringify({ body: f.body.trim() }),
    });
    cancelTextEdit(a.id);
    await loadTextWidgets(a.id);
    await load();
  } catch {
    /* */
  } finally {
    textEditPending.value[w.id] = false;
  }
}

async function updateSurveyWidget(a: AppRow, w: SurveyWidgetRow) {
  const f = surveyEditForms.value[w.id];
  if (!f?.title.trim()) return;
  surveyEditPending.value[w.id] = true;
  try {
    await api(`/api/applications/${a.id}/survey-widgets/${w.id}`, {
      method: "PUT",
      body: JSON.stringify({
        title: f.title.trim(),
        description: f.description.trim() || undefined,
        dismiss_after_sec: f.dismiss_after_sec > 0 ? f.dismiss_after_sec : 0,
      }),
    });
    cancelSurveyEdit(a.id);
    await loadSurveyWidgets(a.id);
    await load();
  } catch {
    /* */
  } finally {
    surveyEditPending.value[w.id] = false;
  }
}

async function updateVideoWidget(a: AppRow, w: VideoWidgetRow) {
  const f = videoEditForms.value[w.id];
  if (!f) return;
  videoEditPending.value[w.id] = true;
  try {
    await api(`/api/applications/${a.id}/video-widgets/${w.id}`, {
      method: "PUT",
      body: JSON.stringify({
        link_url: f.link_url.trim(),
        leave_mode: f.leave_mode,
        leave_timer_sec: f.leave_mode === "timer" ? f.leave_timer_sec : 0,
      }),
    });
    cancelVideoEdit(a.id);
    await loadVideoWidgets(a.id);
  } catch {
    /* */
  } finally {
    videoEditPending.value[w.id] = false;
  }
}

async function saveTextWidget(a: AppRow) {
  ensureWidgetForms(a.id);
  const f = textForms.value[a.id];
  if (!f.name.trim() || !f.body.trim()) return;
  textPending.value[a.id] = true;
  try {
    await api(`/api/applications/${a.id}/text-widgets`, {
      method: "POST",
      body: JSON.stringify({ name: f.name.trim(), body: f.body.trim() }),
    });
    textForms.value[a.id] = defaultTextForm();
    await loadTextWidgets(a.id);
  } catch {
    /* */
  } finally {
    textPending.value[a.id] = false;
  }
}

async function saveSurveyWidget(a: AppRow) {
  ensureWidgetForms(a.id);
  const f = surveyForms.value[a.id];
  if (!f.name.trim() || !f.title.trim()) return;
  surveyPending.value[a.id] = true;
  try {
    await api(`/api/applications/${a.id}/survey-widgets`, {
      method: "POST",
      body: JSON.stringify({
        name: f.name.trim(),
        title: f.title.trim(),
        description: f.description.trim() || undefined,
        dismiss_after_sec: f.dismiss_after_sec > 0 ? f.dismiss_after_sec : 0,
      }),
    });
    surveyForms.value[a.id] = defaultSurveyForm();
    await loadSurveyWidgets(a.id);
  } catch {
    /* */
  } finally {
    surveyPending.value[a.id] = false;
  }
}

async function saveVideoWidget(a: AppRow) {
  ensureWidgetForms(a.id);
  const f = videoForms.value[a.id];
  const file = videoCreateFile.value[a.id];
  if (!f.name.trim() || !file) return;
  if (f.leave_mode === "timer" && f.leave_timer_sec < 1) return;
  if (file.size > 10 * 1024 * 1024) {
    alert("Файл больше 10 МБ");
    return;
  }
  videoPending.value[a.id] = true;
  try {
    const fd = new FormData();
    fd.append("file", file);
    const dur = videoCreateDurationMs.value[a.id];
    if (dur && dur > 0) fd.append("duration_ms", String(dur));
    const uploaded = await api<{ id: number }>(`/api/applications/${a.id}/media`, {
      method: "POST",
      body: fd,
    });
    await api(`/api/applications/${a.id}/video-widgets`, {
      method: "POST",
      body: JSON.stringify({
        name: f.name.trim(),
        media_id: uploaded.id,
        link_url: f.link_url.trim() || undefined,
        leave_mode: f.leave_mode,
        leave_timer_sec: f.leave_mode === "timer" ? f.leave_timer_sec : 0,
      }),
    });
    videoForms.value[a.id] = defaultVideoForm();
    videoCreateFile.value[a.id] = null;
    delete videoCreateDurationMs.value[a.id];
    videoFormResetKey.value[a.id] = (videoFormResetKey.value[a.id] ?? 0) + 1;
    await loadVideoWidgets(a.id);
  } catch {
    /* */
  } finally {
    videoPending.value[a.id] = false;
  }
}

async function deleteTextWidget(a: AppRow, w: TextWidgetRow) {
  if (!confirm(`Удалить текстовый виджет «${w.name}»?`)) return;
  try {
    await api(`/api/applications/${a.id}/text-widgets/${w.id}`, { method: "DELETE" });
    await load();
  } catch {
    /* */
  }
}

async function deleteSurveyWidget(a: AppRow, w: SurveyWidgetRow) {
  if (!confirm(`Удалить опрос «${w.name}»?`)) return;
  try {
    await api(`/api/applications/${a.id}/survey-widgets/${w.id}`, { method: "DELETE" });
    await load();
  } catch {
    /* */
  }
}

async function deleteVideoWidget(a: AppRow, w: VideoWidgetRow) {
  if (!confirm(`Удалить видео-виджет «${w.name}»?`)) return;
  try {
    await api(`/api/applications/${a.id}/video-widgets/${w.id}`, { method: "DELETE" });
    await load();
  } catch {
    /* */
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
      ensureWidgetForms(a.id);
      await loadTextWidgets(a.id);
      await loadSurveyWidgets(a.id);
      await loadVideoWidgets(a.id);
    }
  } catch {
    loadError.value = "Не удалось загрузить заявки";
  }
  try {
    const types = await api<{ action_types: ActionTypeRow[] }>("/api/action-types", { method: "GET" });
    if (types.action_types?.length) actionTypes.value = types.action_types;
  } catch {
    /* defaults */
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
  if (!f.key.trim() || f.widget_id < 1) return;
  eventPending.value[a.id] = true;
  try {
    await api(`/api/applications/${a.id}/events`, {
      method: "POST",
      body: JSON.stringify({
        event_key: f.key.trim(),
        action_type: f.key.trim() === "_standard" ? "text" : f.action_type,
        widget_id: f.widget_id,
        position: { ...f.position },
      }),
    });
    clearEventForm(a.id);
    await load();
  } catch {
    /* handled by api */
  } finally {
    eventPending.value[a.id] = false;
  }
}

async function deleteEvent(a: AppRow, e: WidgetEventRow) {
  if (e.event_key === "_standard") return;
  const label = e.event_key === "_standard" ? "стандартное приветствие" : e.event_key;
  if (!confirm(`Удалить событие «${label}»?`)) return;
  eventDeletePending.value[e.id] = true;
  try {
    await api(`/api/applications/${a.id}/events/${e.id}`, { method: "DELETE" });
    if (editingEventId(a.id) === e.id) clearEventForm(a.id);
    await load();
  } catch {
    /* */
  } finally {
    eventDeletePending.value[e.id] = false;
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
.app-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 14px 0 0;
  padding-top: 14px;
  border-top: 1px solid #2e3238;
}
.app-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 8px;
  border: 1px solid #3c4043;
  background: #0f1115;
  color: #bdc1c6;
  font-size: 0.88rem;
  font-weight: 600;
  cursor: pointer;
}
.app-tab:hover {
  background: #2a2e36;
  color: #e8eaed;
}
.app-tab.active {
  background: #1a3a5c;
  border-color: #1a73e8;
  color: #e8f0fe;
}
.tab-count {
  font-size: 0.75rem;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 999px;
  background: #3c4043;
  color: #e8eaed;
}
.tab-count.warn {
  background: #5c2b2b;
  color: #f28b82;
}
.tab-panel {
  margin-top: 14px;
}
.panel-block {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid #2e3238;
}
.panel-block:first-child {
  border-top: none;
  padding-top: 0;
  margin-top: 0;
}
.panel-title {
  margin: 0 0 8px;
  font-size: 1rem;
}
.panel-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
}
.panel-head .panel-title {
  margin: 0;
}
.ev-cards {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ev-card {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 8px;
  border: 1px solid #2e3238;
  background: #0f1115;
}
.ev-card.editing {
  border-color: #1a73e8;
}
.ev-card-main {
  flex: 1 1 200px;
  min-width: 0;
}
.ev-phrase {
  margin: 6px 0 4px;
  font-size: 0.88rem;
  color: #bdc1c6;
  word-break: break-word;
}
.ev-card-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}
.ev-form-hint {
  flex: 1 1 100%;
  margin: 0 0 4px;
  font-size: 0.86rem;
}
.evform-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  flex: 1 1 100%;
}
.btn.danger {
  background: #5c2b2b;
  color: #f8d7da;
  border: 1px solid #8b3a3a;
}
.btn.danger:hover:not(:disabled) {
  filter: brightness(1.1);
}
.link-btn {
  margin-left: 10px;
  padding: 0;
  border: none;
  background: none;
  color: #8ab4f8;
  font-size: inherit;
  cursor: pointer;
  text-decoration: underline;
}
.ev-type-badge {
  display: inline-block;
  margin-left: 8px;
  font-size: 0.72rem;
  padding: 2px 8px;
  border-radius: 999px;
  background: #2a3a4a;
  color: #8ab4f8;
  vertical-align: middle;
}
.ev-field {
  flex: 1 1 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.88rem;
  color: #bdc1c6;
}
.ev-field select {
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid #3c4043;
  background: #0f1115;
  color: #e8eaed;
}
.upload-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
}
.upload-form input[type="file"] {
  flex: 1 1 200px;
  font-size: 0.85rem;
  color: #bdc1c6;
}
.widget-list {
  list-style: none;
  padding: 0;
  margin: 0 0 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.widget-card {
  padding: 12px 14px;
  border-radius: 8px;
  border: 1px solid #2e3238;
  background: #0f1115;
}
.widget-card.editing {
  border-color: #1a73e8;
}
.widget-card-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.widget-edit-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.widget-edit-form textarea,
.widget-edit-form input {
  width: 100%;
}
.widget-link {
  word-break: break-all;
}
.widget-preview {
  margin: 6px 0;
  font-size: 0.88rem;
  color: #bdc1c6;
  word-break: break-word;
}
.widget-stats {
  margin: 4px 0 8px;
}
.leave-mode-fieldset {
  margin: 0;
  padding: 8px 10px;
  border: 1px solid #3c4043;
  border-radius: 8px;
}
.leave-mode-fieldset legend {
  padding: 0 4px;
}
.radio-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 6px 0;
  font-size: 0.88rem;
  cursor: pointer;
}
.radio-row input {
  width: auto;
  margin: 0;
}
.media-list.compact .media-item {
  padding: 8px 0;
}
.media-list {
  list-style: none;
  padding: 0;
  margin: 0;
}
.media-item {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 0;
  border-bottom: 1px solid #2a2d33;
}
.media-meta strong {
  display: block;
  margin-bottom: 4px;
}
.media-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
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
.ev-edit {
  display: block;
  width: 100%;
  text-align: left;
  padding: 6px 8px;
  margin: 0 -8px;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.ev-edit:hover {
  background: #2a2e36;
}
.ev-pos {
  display: block;
  font-size: 0.78rem;
  color: #9aa0a6;
  margin-top: 2px;
}
.pos-fieldset {
  flex: 1 1 100%;
  margin: 0;
  padding: 10px 12px;
  border: 1px solid #2e3238;
  border-radius: 8px;
}
.pos-fieldset legend {
  padding: 0 4px;
}
.pos-row {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.pos-label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 0.82rem;
  color: #bdc1c6;
}
.pos-inline {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.pos-inline input[type="number"] {
  width: 72px;
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid #3c4043;
  background: #0f1115;
  color: #e8eaed;
}
.pos-inline select {
  padding: 6px 8px;
  border-radius: 6px;
  border: 1px solid #3c4043;
  background: #0f1115;
  color: #e8eaed;
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
