<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  role: {
    type: String,
    default: '',
  },
})

const backdrop = ref(null)
const decorationGroups = []
let decorationObserver = null
let parallaxFrame = 0

const roleClass = computed(() => ({
  'is-professor': props.role === 'Professor',
  'is-staff': ['Registrar Staff', 'Admin'].includes(props.role),
}))

function collectDecorationGroup(element) {
  if (element && !decorationGroups.includes(element)) decorationGroups.push(element)
}

function updateParallax() {
  parallaxFrame = 0
  if (!backdrop.value) return

  const mobile = window.matchMedia('(max-width: 600px)').matches
  const scrollY = Math.max(0, window.scrollY)
  const speeds = mobile ? [0.008, 0.014, 0.02] : [0.02, 0.04, 0.06]
  const maximums = mobile ? [6, 10, 14] : [18, 34, 52]

  backdrop.value.style.setProperty('--portal-parallax-far', `${Math.min(scrollY * speeds[0], maximums[0])}px`)
  backdrop.value.style.setProperty('--portal-parallax-mid', `${Math.min(scrollY * speeds[1], maximums[1])}px`)
  backdrop.value.style.setProperty('--portal-parallax-near', `${Math.min(scrollY * speeds[2], maximums[2])}px`)
}

function queueParallax() {
  if (!parallaxFrame) parallaxFrame = window.requestAnimationFrame(updateParallax)
}

onMounted(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reducedMotion || !('IntersectionObserver' in window)) {
    decorationGroups.forEach(element => element.classList.add('is-visible'))
    return
  }

  decorationObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return
      entry.target.classList.add('is-visible')
      decorationObserver?.unobserve(entry.target)
    })
  }, { threshold: 0.12 })

  decorationGroups.forEach(element => decorationObserver.observe(element))
  window.addEventListener('scroll', queueParallax, { passive: true })
  updateParallax()
})

onBeforeUnmount(() => {
  decorationObserver?.disconnect()
  window.removeEventListener('scroll', queueParallax)
  if (parallaxFrame) window.cancelAnimationFrame(parallaxFrame)
})
</script>

<template>
  <div ref="backdrop" class="portal-animated-backdrop" :class="roleClass" aria-hidden="true">
    <div :ref="collectDecorationGroup" class="portal-decoration-cluster cluster-ics parallax-far" aria-hidden="true">
      <svg class="portal-decoration-icon icon-green delay-0" viewBox="0 0 64 64" focusable="false"><path d="M10 14h44v30H10zM5 50h54M22 24l-7 6 7 6M42 24l7 6-7 6M36 20l-8 20" /></svg>
      <svg class="portal-decoration-icon icon-gold delay-1" viewBox="0 0 64 64" focusable="false"><path d="M17 12h30v40H17zM24 20h16v16H24zM10 20h7M10 30h7M10 40h7M47 20h7M47 30h7M47 40h7M24 5v7M32 5v7M40 5v7M24 52v7M32 52v7M40 52v7" /></svg>
      <span class="portal-gold-ring delay-2"></span>
    </div>

    <div :ref="collectDecorationGroup" class="portal-decoration-cluster cluster-ite parallax-mid" aria-hidden="true">
      <svg class="portal-decoration-icon icon-green delay-0" viewBox="0 0 64 64" focusable="false"><path d="M32 22a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM32 7v8M32 49v8M7 32h8M49 32h8M14 14l6 6M44 44l6 6M50 14l-6 6M20 44l-6 6" /></svg>
      <svg class="portal-decoration-icon icon-gold delay-1" viewBox="0 0 64 64" focusable="false"><path d="M17 12h30v40H17zM24 20h16v16H24zM10 20h7M10 30h7M10 40h7M47 20h7M47 30h7M47 40h7" /></svg>
      <span class="portal-dot-cluster delay-2"><i></i><i></i><i></i><i></i></span>
    </div>

    <div :ref="collectDecorationGroup" class="portal-decoration-cluster cluster-ibe parallax-near" aria-hidden="true">
      <svg class="portal-decoration-icon icon-gold delay-0" viewBox="0 0 64 64" focusable="false"><path d="M10 52h44M16 46V34h8v12M28 46V25h8v21M40 46V16h8v30M14 27l14-11 10 6 14-12" /></svg>
      <svg class="portal-decoration-icon icon-green delay-1" viewBox="0 0 64 64" focusable="false"><path d="M8 22h48v32H8zM23 22v-7h18v7M8 34h48M27 31h10v7H27z" /></svg>
      <svg class="portal-decoration-icon icon-green delay-2" viewBox="0 0 64 64" focusable="false"><path d="M16 7h25l9 9v41H16zM41 7v12h9M24 30h18M24 39h18M24 48h12" /></svg>
    </div>

    <div :ref="collectDecorationGroup" class="portal-decoration-cluster cluster-campus parallax-mid" aria-hidden="true">
      <svg class="portal-decoration-icon icon-gold delay-0" viewBox="0 0 64 64" focusable="false"><path d="M11 16h42v38H11zM20 9v14M44 9v14M11 28h42M22 39h1M32 39h1M42 39h1" /></svg>
      <svg class="portal-decoration-icon icon-green delay-1" viewBox="0 0 64 64" focusable="false"><path d="m32 8 5 15 15 5-15 5-5 15-5-15-15-5 15-5z" /></svg>
    </div>
  </div>
</template>

<style scoped>
.portal-animated-backdrop {
  --portal-decoration-opacity: 0.19;
  --portal-gold-opacity: 0.23;
  --portal-parallax-far: 0px;
  --portal-parallax-mid: 0px;
  --portal-parallax-near: 0px;

  position: absolute;
  inset: 0;
  z-index: 0;
  min-width: 0;
  min-height: 100%;
  overflow: hidden;
  pointer-events: none;
  background:
    radial-gradient(circle at 8% 12%, color-mix(in srgb, var(--accent) 9%, transparent), transparent 24rem),
    radial-gradient(circle at 94% 54%, color-mix(in srgb, var(--color-naples-yellow) 9%, transparent), transparent 22rem),
    radial-gradient(circle at 12% 86%, color-mix(in srgb, var(--accent) 7%, transparent), transparent 28rem),
    linear-gradient(145deg, #fbf8ed 0%, color-mix(in srgb, var(--bg-page) 84%, #e8f2e9) 52%, #f7f2df 100%);
}

.portal-animated-backdrop::before,
.portal-animated-backdrop::after {
  position: absolute;
  width: clamp(14rem, 25vw, 24rem);
  aspect-ratio: 1;
  border-radius: 44% 56% 63% 37% / 46% 35% 65% 54%;
  background: color-mix(in srgb, var(--accent) 6%, transparent);
  content: '';
}

.portal-animated-backdrop::before { top: 8%; left: -10%; transform: rotate(24deg); }
.portal-animated-backdrop::after { right: -12%; bottom: 6%; background: color-mix(in srgb, var(--color-naples-yellow) 7%, transparent); transform: rotate(-18deg); }

.portal-animated-backdrop.is-professor { --portal-decoration-opacity: 0.15; --portal-gold-opacity: 0.18; }
.portal-animated-backdrop.is-staff { --portal-decoration-opacity: 0.11; --portal-gold-opacity: 0.14; }

.portal-decoration-cluster {
  position: absolute;
  z-index: 1;
  display: flex;
  gap: clamp(0.45rem, 1.4vw, 0.9rem);
  align-items: center;
  will-change: transform;
}

.portal-decoration-cluster.parallax-far { transform: translate3d(0, var(--portal-parallax-far), 0); }
.portal-decoration-cluster.parallax-mid { transform: translate3d(0, var(--portal-parallax-mid), 0); }
.portal-decoration-cluster.parallax-near { transform: translate3d(0, var(--portal-parallax-near), 0); }
.cluster-ics { top: clamp(18rem, 42vh, 28rem); right: clamp(1rem, 3vw, 3rem); }
.cluster-ite { top: 96vh; left: clamp(1rem, 3vw, 3rem); }
.cluster-ibe { top: 148vh; right: clamp(1rem, 4vw, 4rem); }
.cluster-campus { top: 218vh; left: clamp(1rem, 5vw, 5rem); }

.portal-decoration-icon {
  width: clamp(2.5rem, 4.5vw, 4.25rem);
  height: auto;
  opacity: 0;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 3;
  transform: translate3d(0, 1.5rem, 0) scale(0.9);
}

.portal-decoration-cluster.is-visible .portal-decoration-icon,
.portal-decoration-cluster.is-visible :is(.portal-gold-ring, .portal-dot-cluster) {
  animation: portal-decoration-pop 720ms cubic-bezier(0.22, 1, 0.36, 1) both;
}

.delay-1 { animation-delay: 110ms; }
.delay-2 { animation-delay: 220ms; }
.icon-green { color: var(--accent, #106a2e); }
.icon-gold { --portal-decoration-opacity: var(--portal-gold-opacity); color: var(--color-naples-yellow, #f4d35e); }
.portal-gold-ring { --portal-decoration-opacity: var(--portal-gold-opacity); width: 1.1rem; aspect-ratio: 1; opacity: 0; border: 3px solid var(--color-naples-yellow, #f4d35e); border-radius: 50%; transform: translate3d(0, 1.5rem, 0) scale(0.9); }
.portal-dot-cluster { display: grid; grid-template-columns: repeat(2, 0.35rem); gap: 0.3rem; opacity: 0; transform: translate3d(0, 1.5rem, 0) scale(0.9); }
.portal-dot-cluster i { width: 0.35rem; aspect-ratio: 1; background: var(--color-naples-yellow, #f4d35e); border-radius: 50%; }

@keyframes portal-decoration-pop {
  0% { opacity: 0; transform: translate3d(0, 1.5rem, 0) scale(0.9); }
  72% { opacity: var(--portal-decoration-opacity); transform: translate3d(0, -0.1rem, 0) scale(1.03); }
  100% { opacity: var(--portal-decoration-opacity); transform: translate3d(0, 0, 0) scale(1); }
}

:global(html[data-student-theme='dark'] .portal-animated-backdrop) {
  background: #070a08;
}

:global(html[data-student-theme='dark'] .portal-animated-backdrop::before) {
  inset: 0;
  width: auto;
  aspect-ratio: auto;
  border-radius: 0;
  background-color: transparent;
  background-image:
    linear-gradient(rgb(20 130 80 / 9%) 1px, transparent 1px),
    linear-gradient(90deg, rgb(20 130 80 / 9%) 1px, transparent 1px);
  background-position: center top;
  background-size: 64px 64px;
  transform: none;
}

:global(html[data-student-theme='dark'] .portal-animated-backdrop::after),
:global(html[data-student-theme='dark'] .portal-decoration-cluster) {
  display: none;
}

@media (prefers-color-scheme: dark) {
  :global(html[data-student-theme='system'] .portal-animated-backdrop) {
    background: #070a08;
  }

  :global(html[data-student-theme='system'] .portal-animated-backdrop::before) {
    inset: 0;
    width: auto;
    aspect-ratio: auto;
    border-radius: 0;
    background-color: transparent;
    background-image:
      linear-gradient(rgb(20 130 80 / 9%) 1px, transparent 1px),
      linear-gradient(90deg, rgb(20 130 80 / 9%) 1px, transparent 1px);
    background-position: center top;
    background-size: 64px 64px;
    transform: none;
  }

  :global(html[data-student-theme='system'] .portal-animated-backdrop::after),
  :global(html[data-student-theme='system'] .portal-decoration-cluster) {
    display: none;
  }
}

@media (max-width: 700px) {
  .portal-animated-backdrop {
    --portal-decoration-opacity: 0.055;
    --portal-gold-opacity: 0.07;
  }
  .portal-animated-backdrop::before { opacity: 0.45; }
  .portal-animated-backdrop::after { display: none; }
  .portal-decoration-icon { width: 2.5rem; }
  .portal-decoration-cluster { gap: 0.3rem; }
  .portal-decoration-cluster .portal-decoration-icon:nth-child(n + 3),
  .portal-dot-cluster { display: none; }
  .cluster-ibe,
  .cluster-campus { display: none; }
  .cluster-ite { top: 115vh; }
}

@media (prefers-reduced-motion: reduce) {
  .portal-decoration-cluster,
  .portal-decoration-cluster.parallax-far,
  .portal-decoration-cluster.parallax-mid,
  .portal-decoration-cluster.parallax-near {
    transform: none;
    will-change: auto;
  }

  .portal-decoration-icon,
  .portal-decoration-cluster.is-visible .portal-decoration-icon,
  .portal-decoration-cluster.is-visible :is(.portal-gold-ring, .portal-dot-cluster) {
    opacity: var(--portal-decoration-opacity);
    transform: none;
    animation: none;
  }
}
</style>
