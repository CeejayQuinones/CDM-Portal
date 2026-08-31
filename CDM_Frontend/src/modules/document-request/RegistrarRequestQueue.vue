<script setup>
import PaginationControls from '../../components/PaginationControls.vue'
import {
  documentTypeAccentClass,
  formatExactDateTime,
  formatRelativeTime,
  requestReference,
  requestStatusAccentClass,
  studentName,
} from './documentRequestPresentation'
import { requestDocumentName, requestStudentNumber } from './documentRequestRow'

defineProps({
  title: { type: String, required: true },
  description: { type: String, required: true },
  items: { type: Array, required: true },
  total: { type: Number, required: true },
  currentPage: { type: Number, required: true },
  lastPage: { type: Number, required: true },
  loading: { type: Boolean, default: false },
  emptyMessage: { type: String, default: 'No matching requests.' },
  selectedId: { type: Number, default: null },
  highlightedId: { type: Number, default: null },
  actionLabel: { type: String, default: '' },
  actionBusyId: { type: Number, default: null },
})

defineEmits(['select', 'page-change', 'action'])

const referenceFor = (item) => item?.request_reference || requestReference(item?.id)
const requestTimestamp = (item) => item?.updated_at || item?.created_at || item?.request_date || null
const requestStudentName = (item) => studentName(item?.student) || 'Student'
</script>

<template>
  <section class="dr-panel work-queue-panel">
    <header class="work-queue-header">
      <div>
        <p class="record-eyebrow">Active work queue</p>
        <h2>{{ title }}</h2>
        <p>{{ description }}</p>
      </div>
      <strong class="work-queue-count" :aria-label="`${total} ${title.toLowerCase()}`">{{ total }}</strong>
    </header>

    <div class="work-queue-list">
      <p v-if="loading && !items.length" class="empty work-queue-empty">Loading requests&hellip;</p>
      <p v-else-if="!items.length" class="empty work-queue-empty">{{ emptyMessage }}</p>
      <article
        v-for="item in items"
        :id="`request-${item.id}`"
        :key="item.id"
        class="queue-item compact-request-item"
        :class="[
          requestStatusAccentClass(item.status),
          {
            'focused-record': selectedId === item.id,
            'focused-request': highlightedId === item.id,
          },
        ]"
      >
        <button class="queue-item-main" type="button" @click="$emit('select', item)">
          <span class="compact-request-content">
            <span class="compact-request-heading compact-student-heading">
              <strong class="compact-student-name">{{ requestStudentName(item) }}</strong>
              <time
                v-if="requestTimestamp(item)"
                :datetime="requestTimestamp(item)"
                :title="formatExactDateTime(requestTimestamp(item))"
              >
                {{ formatRelativeTime(requestTimestamp(item)) }}
              </time>
            </span>
            <span class="compact-request-reference">{{ referenceFor(item) }}</span>
            <span class="compact-request-meta">
              <span class="document-type-chip" :class="documentTypeAccentClass(requestDocumentName(item))">
                {{ requestDocumentName(item) }}
              </span>
              <span>{{ requestStudentNumber(item) }}</span>
            </span>
          </span>
        </button>
        <button
          v-if="actionLabel"
          class="queue-row-action"
          type="button"
          :disabled="actionBusyId === item.id"
          @click="$emit('action', item)"
        >
          {{ actionBusyId === item.id ? 'Updating…' : actionLabel }}
        </button>
      </article>
    </div>

    <footer class="work-queue-footer">
      <PaginationControls
        :current-page="currentPage"
        :last-page="lastPage"
        :total="total"
        total-label="requests"
        :busy="loading"
        :aria-label="`${title} pages`"
        @page-change="$emit('page-change', $event)"
      />
    </footer>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
