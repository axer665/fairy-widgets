<template>
  <section>
    <h1>Личный кабинет клиента</h1>
    <p class="lead">
      Зарегистрируйтесь, укажите сайт и отправьте заявку на виджет. После одобрения вы получите код для вставки.
    </p>
    <div class="actions">
      <NuxtLink class="btn primary" to="/register">Регистрация</NuxtLink>
      <NuxtLink class="btn" to="/login">Вход</NuxtLink>
      <NuxtLink class="btn" to="/cabinet">Мои заявки</NuxtLink>
    </div>
  </section>
</template>

<style scoped>
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
