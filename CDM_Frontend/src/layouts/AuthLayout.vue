<template>
  <main class="auth-layout">
    <RouterView />
  </main>
</template>

<style scoped>
.auth-layout {
  display: grid;
  min-height: 100vh;
  place-items: center;
  padding: 24px;
  background: radial-gradient(circle at 15% 15%, rgba(244,211,94,.28), transparent 28%), linear-gradient(135deg, var(--color-dartmouth-green), var(--color-dark-spring-green));
}
</style>
