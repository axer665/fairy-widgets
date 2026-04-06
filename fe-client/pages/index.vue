<template>
  <section class="hero">
    <div class="hero-copy">
      <h1>Личный кабинет клиента</h1>
      <p class="lead">
        Зарегистрируйтесь, укажите сайт и отправьте заявку на виджет. После одобрения вы получите код для вставки.
      </p>
      <div class="actions">
        <NuxtLink class="btn primary" to="/register">Регистрация</NuxtLink>
        <NuxtLink class="btn" to="/login">Вход</NuxtLink>
        <NuxtLink class="btn" to="/cabinet">Мои заявки</NuxtLink>
      </div>
    </div>
    <div class="hero-art" aria-hidden="true">
      <ClientOnly>
        <FairyFlight :display-width="fairyWidth" />
        <template #fallback>
          <div class="fairy-fallback" :style="{ width: fairyWidth + 'px', height: fairyFallbackHeight + 'px' }" />
        </template>
      </ClientOnly>
    </div>
  </section>
</template>

<script setup lang="ts">
/** Базовые размеры спрайта — см. public/sprites/fairy-flight-sheet.json */
const fairyWidth = 200;
const fairyFallbackHeight = Math.round((855 * fairyWidth) / 568);
</script>

<style scoped>
.hero {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 28px 36px;
}
.hero-copy {
  flex: 1 1 260px;
  min-width: 0;
}
.hero-art {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto;
}
.fairy-fallback {
  border-radius: 12px;
  background: linear-gradient(145deg, #1e2230 0%, #252a3a 100%);
  opacity: 0.6;
}
h1 {
  font-size: 1.75rem;
  margin-bottom: 12px;
}
.lead {
  color: #9aa0a6;
  line-height: 1.5;
  margin-bottom: 24px;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.btn {
  display: inline-block;
  padding: 10px 18px;
  border-radius: 8px;
  text-decoration: none;
  color: #e8eaed;
  border: 1px solid #3c4043;
}
.btn.primary {
  background: #1a73e8;
  border-color: #1a73e8;
  color: #fff;
}
.btn:hover {
  filter: brightness(1.08);
}
</style>
