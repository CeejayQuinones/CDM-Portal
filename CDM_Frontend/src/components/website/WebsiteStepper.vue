<script setup>
defineProps({ steps: { type: Array, required: true }, currentIndex: { type: Number, default: 0 } })
</script>
<template>
  <ol class="website-stepper" aria-label="Workflow progress">
    <li v-for="(step,index) in steps" :key="typeof step === 'string' ? step : step.label" :class="{ current:index===currentIndex, complete:index<currentIndex }" :aria-current="index===currentIndex?'step':undefined">
      <span aria-hidden="true">{{ index < currentIndex ? '✓' : index + 1 }}</span>
      <div><strong>{{ typeof step === 'string' ? step : step.label }}</strong><small>{{ index < currentIndex ? 'Completed' : index === currentIndex ? 'Current step' : 'Upcoming' }}</small></div>
    </li>
  </ol>
</template>
<style scoped>
.website-stepper { display: grid; grid-template-columns: repeat(var(--step-count,4),minmax(0,1fr)); gap: 8px; margin: 0 0 20px; padding: 0; list-style: none; }
.website-stepper li { position: relative; min-width: 0; display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--website-border); border-radius: 13px; background: var(--website-card); color: var(--website-muted); }
.website-stepper li > span { width: 28px; height: 28px; flex: 0 0 auto; display: grid; place-items: center; border-radius: 50%; background: var(--website-disabled); color: var(--website-muted); font-size: 12px; font-weight: 850; }
.website-stepper div { min-width: 0; display: grid; gap: 2px; }
.website-stepper strong { overflow: hidden; color: var(--website-ink); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.website-stepper small { font-size: 10px; }
.website-stepper li.current { border-color: rgba(217,169,62,.62); box-shadow: inset 0 -3px var(--website-gold); }
.website-stepper li.current > span { background: var(--website-deep-green); color: var(--website-on-primary); }
.website-stepper li.complete > span { background: var(--website-success-bg); color: var(--website-success); }
@media (max-width: 700px) { .website-stepper { display: flex; overflow-x: auto; scroll-snap-type: x proximity; padding-bottom: 5px; } .website-stepper li { min-width: 155px; scroll-snap-align: start; } }
</style>
