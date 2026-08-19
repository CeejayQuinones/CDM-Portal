<template>
  <section class="unauthorized">
    <p class="page-kicker">Access restricted</p>
    <h1 class="page-title">You are not authorized to view this page.</h1>
    <RouterLink to="/" class="return-link">Return to dashboard</RouterLink>
  </section>
</template>

<style scoped>
.unauthorized { max-width: 640px; margin: 64px auto; text-align: center; }.return-link { display: inline-block; margin-top: 22px; border-radius: 8px; background: var(--color-dartmouth-green); color: #fff; padding: 12px 16px; font-weight: 700; }
</style>
