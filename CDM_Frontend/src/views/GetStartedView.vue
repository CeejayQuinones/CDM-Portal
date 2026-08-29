<script setup>
import { computed, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { getStartedSteps } from '../content/getStartedSteps'

const router = useRouter()
const index = ref(0)

const step = computed(() => getStartedSteps[index.value])
const isFirst = computed(() => index.value === 0)
const isLast = computed(() => index.value === getStartedSteps.length - 1)
const progress = computed(() => ((index.value + 1) / getStartedSteps.length) * 100)

const next = () => {
  if (isLast.value) {
    router.push({ name: 'register' })
    return
  }
  index.value += 1
}

const back = () => {
  if (!isFirst.value) index.value -= 1
}

const skip = () => {
  router.push({ name: 'register' })
}

const goTo = (i) => {
  index.value = i
}
</script>

<template>
  <main class="guide">
    <section class="guide-panel" aria-labelledby="guide-title">
      <div class="progress" aria-hidden="true">
        <span class="progress-bar" :style="{ width: `${progress}%` }" />
      </div>

      <div class="step-dots" role="tablist" aria-label="Get started steps">
        <button
          v-for="(item, i) in getStartedSteps"
          :key="item.id"
          type="button"
          class="dot"
          :class="{ active: i === index, done: i < index }"
          :aria-label="`Go to ${item.title}`"
          :aria-current="i === index ? 'step' : undefined"
          @click="goTo(i)"
        />
      </div>

      <p class="kicker">{{ step.kicker }}</p>
      <h1 id="guide-title">{{ step.title }}</h1>
      <p class="body">{{ step.body }}</p>

      <ul class="points">
        <li v-for="point in step.points" :key="point">{{ point }}</li>
      </ul>

      <div class="actions">
        <button v-if="!isFirst" type="button" class="btn-ghost" @click="back">Back</button>
        <button type="button" class="btn-ghost" @click="skip">Skip</button>
        <div class="spacer" />
        <button type="button" class="btn-primary" @click="next">
          {{ isLast ? 'Create account' : 'Next' }}
        </button>
      </div>

      <p class="footer-note">
        Already have an account?
        <RouterLink :to="{ name: 'login' }">Sign in</RouterLink>
        ·
        <RouterLink :to="{ name: 'landing' }">Back to home</RouterLink>
      </p>
    </section>
  </main>
</template>

<style scoped>
.guide {
  display: grid;
  place-items: center;
  min-height: calc(100vh - 160px);
  padding: clamp(24px, 5vh, 48px) clamp(18px, 4vw, 48px);
}

.guide-panel {
  width: min(100%, 640px);
  border: 1px solid rgba(16, 106, 46, 0.14);
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.88);
  box-shadow: var(--shadow-soft);
  padding: clamp(22px, 4vw, 36px);
  animation: rise 420ms ease both;
}

.progress {
  height: 4px;
  border-radius: 999px;
  background: rgba(16, 106, 46, 0.12);
  overflow: hidden;
  margin-bottom: 18px;
}

.progress-bar {
  display: block;
  height: 100%;
  background: linear-gradient(90deg, var(--color-dartmouth-green), var(--color-dark-spring-green));
  transition: width 280ms ease;
}

.step-dots {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
}

.dot {
  width: 10px;
  height: 10px;
  border: 0;
  border-radius: 50%;
  background: #d7e3db;
  cursor: pointer;
  padding: 0;
}

.dot.active {
  background: var(--color-dartmouth-green);
  transform: scale(1.15);
}

.dot.done {
  background: var(--color-dark-spring-green);
}

.kicker {
  margin: 0 0 8px;
  color: var(--color-dartmouth-green);
  font-size: 0.8rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

h1 {
  margin: 0;
  font-family: var(--font-display);
  font-size: clamp(1.7rem, 3vw, 2.2rem);
  line-height: 1.15;
}

.body {
  margin: 12px 0 0;
  color: var(--color-muted);
  line-height: 1.65;
}

.points {
  margin: 20px 0 0;
  padding-left: 18px;
  color: var(--color-eerie-black);
  line-height: 1.65;
}

.points li + li {
  margin-top: 6px;
}

.actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 28px;
}

.spacer {
  flex: 1;
}

.btn-primary,
.btn-ghost {
  min-height: 42px;
  border-radius: 8px;
  font-weight: 700;
  padding: 0 16px;
  cursor: pointer;
}

.btn-primary {
  border: 0;
  background: var(--color-dartmouth-green);
  color: #fff;
}

.btn-ghost {
  border: 1px solid rgba(16, 106, 46, 0.22);
  background: transparent;
  color: var(--color-dark-spring-green);
}

.footer-note {
  margin: 18px 0 0;
  color: var(--color-muted);
  font-size: 0.92rem;
}

.footer-note a {
  color: var(--color-dark-spring-green);
  font-weight: 700;
}

@keyframes rise {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
