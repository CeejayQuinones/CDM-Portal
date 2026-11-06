<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  src: { type: String, default: '' },
  initials: { type: String, default: 'CDM' },
  label: { type: String, default: 'Account profile picture' },
  revision: { type: Number, default: 0 },
})

const imageFailed = ref(false)
const imageLoaded = ref(false)

watch(() => [props.src, props.revision], () => {
  imageFailed.value = false
  imageLoaded.value = false
}, { flush: 'sync' })
</script>

<template>
  <span class="website-user-avatar" role="img" :aria-label="label">
    <img
      v-if="src && !imageFailed"
      :key="`${src}:${revision}`"
      :src="src"
      alt=""
      :class="{ loaded: imageLoaded }"
      @load="imageLoaded = true"
      @error="imageFailed = true"
    />
    <span v-if="!src || imageFailed || !imageLoaded" aria-hidden="true">{{ initials }}</span>
  </span>
</template>

<style scoped>
.website-user-avatar {
  width: 100%;
  height: 100%;
  display: grid;
  place-items: center;
  position: relative;
  overflow: hidden;
  border-radius: inherit;
  background: var(--website-deep-green);
  color: var(--website-on-primary);
  font: inherit;
  font-weight: 800;
}
.website-user-avatar img {
  position: absolute;
  inset: 0;
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  opacity: 0;
}
.website-user-avatar img.loaded { opacity: 1; }
</style>
