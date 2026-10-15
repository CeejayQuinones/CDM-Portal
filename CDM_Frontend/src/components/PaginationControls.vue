<script setup>
import { computed, ref, useId, watch } from 'vue'

const props = defineProps({
  currentPage: {
    type: Number,
    required: true,
  },
  lastPage: {
    type: Number,
    required: true,
  },
  busy: {
    type: Boolean,
    default: false,
  },
  total: {
    type: Number,
    default: null,
  },
  totalLabel: {
    type: String,
    default: 'records',
  },
  ariaLabel: {
    type: String,
    default: 'Pagination',
  },
})

const emit = defineEmits(['page-change'])
const pageInputId = useId()
const validationId = `${pageInputId}-validation`
const pageDraft = ref('')
const validationMessage = ref('')

const hasPreviousPage = computed(() => props.currentPage > 1)
const hasNextPage = computed(() => props.currentPage < props.lastPage)
const formattedTotal = computed(() =>
  props.total === null ? null : new Intl.NumberFormat('en-PH').format(props.total),
)

watch(
  [() => props.currentPage, () => props.lastPage],
  ([currentPage]) => {
    pageDraft.value = String(currentPage)
    validationMessage.value = ''
  },
  { immediate: true },
)

function emitPageChange(page) {
  validationMessage.value = ''

  if (props.busy || !Number.isSafeInteger(page) || page < 1 || page > props.lastPage || page === props.currentPage)
    return

  emit('page-change', page)
}

function submitPage() {
  const value = String(pageDraft.value ?? '').trim()

  if (!value) {
    validationMessage.value = 'Enter a page number.'
    return
  }

  if (!/^\d+$/.test(value)) {
    validationMessage.value = 'Enter a whole page number.'
    return
  }

  const page = Number(value)

  if (!Number.isSafeInteger(page)) {
    validationMessage.value = 'Enter a valid page number.'
    return
  }

  if (page < 1 || page > props.lastPage) {
    validationMessage.value = `Enter a page from 1 to ${props.lastPage}.`
    return
  }

  if (page === props.currentPage) {
    validationMessage.value = ''
    pageDraft.value = String(props.currentPage)
    return
  }

  emitPageChange(page)
}
</script>

<template>
  <nav v-if="lastPage > 1" class="pagination-controls" :aria-label="ariaLabel">
    <button
      class="page-button"
      type="button"
      :disabled="busy || !hasPreviousPage"
      aria-label="Go to previous page"
      @click="emitPageChange(currentPage - 1)"
    >
      Previous
    </button>

    <div class="page-summary" aria-live="polite">
      <strong>Page {{ currentPage }} of {{ lastPage }}</strong>
      <span v-if="formattedTotal !== null">{{ formattedTotal }} {{ totalLabel }}</span>
    </div>

    <form class="page-jump" novalidate @submit.prevent="submitPage">
      <label :for="pageInputId">Go to page</label>
      <div class="page-jump-fields">
        <input
          :id="pageInputId"
          v-model="pageDraft"
          type="number"
          inputmode="numeric"
          min="1"
          :max="lastPage"
          step="1"
          :disabled="busy"
          :aria-invalid="validationMessage ? 'true' : 'false'"
          :aria-describedby="validationMessage ? validationId : undefined"
          @input="validationMessage = ''"
        />
        <button class="go-button" type="submit" :disabled="busy">Go</button>
      </div>
      <small v-if="validationMessage" :id="validationId" class="validation-message" role="alert">
        {{ validationMessage }}
      </small>
    </form>

    <button
      class="page-button"
      type="button"
      :disabled="busy || !hasNextPage"
      aria-label="Go to next page"
      @click="emitPageChange(currentPage + 1)"
    >
      Next
    </button>
  </nav>
</template>

<style scoped>
.pagination-controls {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 18px;
}

.page-button,
.go-button {
  border: 1px solid transparent;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 700;
  min-height: 40px;
  padding: 8px 14px;
}

.page-button {
  background: var(--color-green-tint);
  color: var(--color-dartmouth-green);
}

.page-button:not(:disabled):hover {
  background: var(--color-dartmouth-green);
  border-color: #0b5927;
  box-shadow: 0 4px 10px rgba(16, 106, 46, 0.16);
  color: #fff;
}

.go-button {
  background: var(--color-naples-yellow);
  color: var(--color-dartmouth-green);
}

.go-button:not(:disabled):hover {
  background: #e9c43f;
  border-color: #caa52e;
  box-shadow: 0 4px 10px rgba(151, 115, 0, 0.14);
}

.page-button:not(:disabled):active,
.go-button:not(:disabled):active {
  box-shadow: inset 0 2px 4px rgba(18, 50, 31, 0.18);
}

.page-button:focus-visible,
.go-button:focus-visible,
.page-jump input:focus-visible {
  box-shadow: 0 0 0 4px rgba(13, 120, 86, 0.16);
  outline: 2px solid var(--color-dartmouth-green);
  outline-offset: 2px;
}

.page-button:disabled,
.go-button:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.page-summary {
  min-width: 112px;
  text-align: center;
}

.page-summary strong,
.page-summary span {
  display: block;
}

.page-summary strong {
  color: var(--color-eerie-black);
  font-size: 0.9rem;
}

.page-summary span {
  color: var(--color-muted);
  font-size: 0.75rem;
  margin-top: 2px;
}

.page-jump {
  align-items: start;
  display: grid;
  gap: 4px;
}

.page-jump label {
  color: var(--color-muted);
  font-size: 0.72rem;
  font-weight: 700;
}

.page-jump-fields {
  display: flex;
  gap: 6px;
}

.page-jump input {
  border: 1px solid var(--color-border);
  border-radius: 8px;
  min-height: 40px;
  padding: 7px 8px;
  text-align: center;
  width: 76px;
}

.page-jump input[aria-invalid='true'] {
  border-color: #b42318;
}

.validation-message {
  color: #b42318;
  font-size: 0.72rem;
  line-height: 1.25;
  max-width: 190px;
}

@media (max-width: 620px) {
  .pagination-controls {
    display: grid;
    grid-template-columns: 1fr 1fr;
  }

  .page-summary {
    grid-column: 1 / -1;
    grid-row: 1;
  }

  .page-jump {
    grid-column: 1 / -1;
    grid-row: 3;
  }

  .page-jump-fields {
    display: grid;
    grid-template-columns: 1fr auto;
  }

  .page-jump input {
    width: 100%;
  }

  .page-button {
    width: 100%;
  }
}
</style>
