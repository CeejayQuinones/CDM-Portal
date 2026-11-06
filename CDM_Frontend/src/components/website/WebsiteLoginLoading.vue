<script setup>
import CDMSeal from './CDMSeal.vue'
</script>

<template>
  <div
    class="website-login-loading"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    aria-label="Signing you in"
  >
    <div class="website-login-loading__content">
      <div class="website-login-loading__seal-stage" aria-hidden="true">
        <span class="website-login-loading__glow"></span>
        <CDMSeal class="website-login-loading__seal" decorative />
      </div>

      <div class="website-login-loading__copy">
        <strong>Signing you in</strong>
        <span>Connecting securely to CDM Portal…</span>
      </div>

      <span class="website-login-loading__progress" aria-hidden="true">
        <span></span>
      </span>
    </div>
  </div>
</template>

<style scoped>
.website-login-loading {
  position: fixed;
  z-index: 2500;
  inset: 0;
  display: grid;
  min-width: 0;
  min-height: 100dvh;
  place-items: center;
  overflow: hidden;
  padding: max(32px, env(safe-area-inset-top)) max(24px, env(safe-area-inset-right)) max(32px, env(safe-area-inset-bottom)) max(24px, env(safe-area-inset-left));
  background: #042017;
  color: #f4fbf7;
  text-align: center;
  isolation: isolate;
}

.website-login-loading__content {
  display: grid;
  width: min(100%, 320px);
  justify-items: center;
  gap: 0;
}

.website-login-loading__seal-stage {
  position: relative;
  display: grid;
  width: clamp(112px, 15vw, 148px);
  aspect-ratio: 1;
  place-items: center;
  margin-block-end: 28px;
}

.website-login-loading__glow {
  position: absolute;
  z-index: -1;
  width: 132%;
  aspect-ratio: 1;
  pointer-events: none;
  background: radial-gradient(circle, rgba(16, 185, 129, 0.22) 0%, rgba(52, 211, 153, 0.1) 45%, rgba(4, 32, 23, 0) 73%);
  filter: blur(32px);
  opacity: 0.56;
  animation: website-login-glow-breathe 2.8s ease-in-out infinite;
}

.website-login-loading__seal {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  animation: website-login-seal-arrive 220ms ease-out both;
}

.website-login-loading__copy {
  display: grid;
  gap: 8px;
}

.website-login-loading__copy strong {
  color: #fff;
  font-family: var(--font-display, Inter, system-ui, sans-serif);
  font-size: clamp(1.25rem, 4vw, 1.55rem);
  font-weight: 720;
  letter-spacing: -0.025em;
  line-height: 1.2;
}

.website-login-loading__copy span {
  color: rgba(229, 248, 238, 0.76);
  font-size: 0.9rem;
  line-height: 1.55;
}

.website-login-loading__progress {
  position: relative;
  display: block;
  width: 96px;
  height: 2px;
  margin-block-start: 24px;
  overflow: hidden;
  background: rgba(167, 243, 208, 0.12);
}

.website-login-loading__progress > span {
  position: absolute;
  inset-block: 0;
  left: 0;
  width: 44%;
  background: linear-gradient(90deg, rgba(52, 211, 153, 0), rgba(52, 211, 153, 0.82), rgba(52, 211, 153, 0));
  animation: website-login-progress 1.45s ease-in-out infinite;
}

.website-login-loading-enter-active,
.website-login-loading-leave-active {
  transition: opacity 220ms ease;
}

.website-login-loading-enter-from,
.website-login-loading-leave-to {
  opacity: 0;
}

@keyframes website-login-glow-breathe {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 0.75; }
}

@keyframes website-login-seal-arrive {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes website-login-progress {
  from { transform: translateX(-110%); }
  to { transform: translateX(225%); }
}

@media (max-width: 480px) {
  .website-login-loading__seal-stage {
    width: clamp(104px, 34vw, 128px);
    margin-block-end: 24px;
  }

  .website-login-loading__content {
    width: min(100%, 280px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .website-login-loading,
  .website-login-loading__seal,
  .website-login-loading__glow,
  .website-login-loading__progress > span {
    animation: none;
    transition: none;
  }

  .website-login-loading__glow {
    opacity: 0.56;
  }

  .website-login-loading__progress > span {
    left: 28%;
  }
}
</style>
