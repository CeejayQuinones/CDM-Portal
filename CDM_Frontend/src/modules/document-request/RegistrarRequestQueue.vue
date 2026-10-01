<script setup>
import PaginationControls from '../../components/PaginationControls.vue'
import DocumentRequestEmptyState from './DocumentRequestEmptyState.vue'
import DocumentRequestStatusBadge from './DocumentRequestStatusBadge.vue'
import DocumentRequestTableSkeleton from './DocumentRequestTableSkeleton.vue'
import { formatExactDate, formatExactDateTime, requestReference, studentName } from './documentRequestPresentation'
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
const requestedTimestamp = (item) => item?.request_date || item?.created_at || null
const updatedTimestamp = (item) => item?.updated_at || item?.approved_at || item?.created_at || null
const requestStudentName = (item) => studentName(item?.student) || 'Student name unavailable'
</script>

<template>
  <section class="dr-table-panel active-request-table-panel">
    <header class="dr-records-heading">
      <div><h2>{{ title }}</h2><p>{{ description }}</p></div>
      <strong class="active-request-count">{{ total }}</strong>
    </header>

    <DocumentRequestTableSkeleton v-if="loading && !items.length" :columns="7" :rows="5" label="Loading active document requests" />
    <DocumentRequestEmptyState v-else-if="!items.length" :message="emptyMessage" />

    <div v-else class="dr-table-scroll">
      <table class="dr-table active-request-table">
        <thead>
          <tr><th scope="col">Request ID</th><th scope="col">Student</th><th scope="col">Document</th><th scope="col">Requested</th><th scope="col">Status</th><th scope="col">Updated</th><th scope="col">Action</th></tr>
        </thead>
        <tbody>
          <tr
            v-for="item in items"
            :id="`request-${item.id}`"
            :key="item.id"
            :class="{ 'is-selected': selectedId === item.id, 'focused-request': highlightedId === item.id }"
          >
            <td><strong class="history-request-reference">{{ referenceFor(item) }}</strong></td>
            <td><strong>{{ requestStudentName(item) }}</strong><small>{{ requestStudentNumber(item) }}</small></td>
            <td><strong>{{ requestDocumentName(item) }}</strong><small v-if="item.purpose">{{ item.purpose }}</small></td>
            <td>{{ formatExactDate(requestedTimestamp(item)) }}</td>
            <td><DocumentRequestStatusBadge :status="item.status" /></td>
            <td><time v-if="updatedTimestamp(item)" :datetime="updatedTimestamp(item)">{{ formatExactDateTime(updatedTimestamp(item)) }}</time><span v-else>—</span></td>
            <td>
              <div class="dr-table-actions">
                <button type="button" class="dr-button dr-button--secondary" @click="$emit('select', item)">View / Process</button>
                <button v-if="actionLabel" type="button" class="dr-button dr-button--primary" :disabled="actionBusyId === item.id" @click="$emit('action', item)">
                  {{ actionBusyId === item.id ? 'Updating…' : actionLabel }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
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
