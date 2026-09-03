<script setup>
import { nextTick, ref, watch } from 'vue'
import { useStepUpAuth } from '../composables/useStepUpAuth'

const password = ref('')
const passwordInput = ref(null)
const { isOpen, verifying, verificationError, verify, cancel } = useStepUpAuth()

watch(isOpen, async (open) => {
  if (!open) {
    password.value = ''
    return
  }

  await nextTick()
  passwordInput.value?.focus()
})

async function submit() {
  if (!password.value || verifying.value) return

  const verified = await verify(password.value)
  if (verified) password.value = ''
}

function close() {
  if (verifying.value) return

  password.value = ''
  cancel()
}
</script>

<template>
  <Teleport to="body">
    <div v-if="isOpen" class="step-up-backdrop" role="presentation" @mousedown.self="close">
      <section
        class="step-up-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="step-up-title"
        aria-describedby="step-up-description"
      >
        <p class="step-up-kicker">Security check</p>
        <h2 id="step-up-title">Confirm your identity</h2>
        <p id="step-up-description">For your security, enter your password to continue.</p>

        <form @submit.prevent="submit">
          <label for="step-up-password">Password</label>
          <input
            id="step-up-password"
            ref="passwordInput"
            v-model="password"
            type="password"
            autocomplete="current-password"
            :disabled="verifying"
            required
          />
          <p v-if="verificationError" class="step-up-error" role="alert">{{ verificationError }}</p>

          <div class="step-up-actions">
            <button type="button" class="cancel-button" :disabled="verifying" @click="close">Cancel</button>
            <button type="submit" class="verify-button" :disabled="verifying || !password">
              {{ verifying ? 'Verifying…' : 'Verify' }}
            </button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.step-up-backdrop {
  align-items: center;
  background: rgba(15, 30, 22, 0.58);
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 20px;
  position: fixed;
  z-index: 2000;
}
.step-up-modal {
  background: var(--color-surface, #fff);
  border: 1px solid var(--color-border, #d6ded8);
  border-radius: 14px;
  box-shadow: 0 24px 60px rgba(20, 45, 31, 0.24);
  max-width: 440px;
  padding: 26px;
  width: 100%;
}
.step-up-kicker {
  color: var(--color-dark-spring-green, #17713d);
  font-size: 0.75rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  margin: 0 0 6px;
  text-transform: uppercase;
}
h2 {
  color: var(--color-eerie-black, #1f1f1f);
  margin: 0;
}
#step-up-description {
  color: var(--color-muted, #66736b);
  margin: 9px 0 20px;
}
form,
label {
  display: grid;
}
form {
  gap: 8px;
}
label {
  color: var(--color-eerie-black, #1f1f1f);
  font-size: 0.85rem;
  font-weight: 700;
}
input {
  border: 1px solid var(--color-border, #cad5cd);
  border-radius: 8px;
  min-height: 44px;
  padding: 9px 11px;
  width: 100%;
}
input:focus {
  border-color: var(--color-dartmouth-green, #006b3c);
  box-shadow: 0 0 0 3px rgba(0, 107, 60, 0.14);
  outline: none;
}
.step-up-error {
  background: #fff0f0;
  border-radius: 7px;
  color: #b42318;
  margin: 4px 0 0;
  padding: 9px 11px;
}
.step-up-actions {
  display: flex;
  gap: 9px;
  justify-content: flex-end;
  margin-top: 14px;
}
button {
  border: 0;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 700;
  min-height: 40px;
  padding: 8px 15px;
}
button:disabled {
  cursor: not-allowed;
  opacity: 0.58;
}
.cancel-button {
  background: var(--color-green-tint, #e8f4ec);
  color: var(--color-dartmouth-green, #006b3c);
}
.verify-button {
  background: var(--color-dartmouth-green, #006b3c);
  color: #fff;
}
</style>
