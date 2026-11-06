<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  createGradeStudentSearchIndex,
  findIndexedGradeStudents,
  gradeStudentDisplayName,
} from './gradeFindNavigation'

const props = defineProps({
  roster: { type: Array, default: () => [] },
  assessments: { type: Array, default: () => [] },
})
const emit = defineEmits(['navigate'])
const root = ref(null)
const input = ref(null)
const query = ref('')
const assessmentId = ref('')
const selectedStudentId = ref('')
const open = ref(false)
const activeResultIndex = ref(-1)

const searchIndex = computed(() => createGradeStudentSearchIndex(props.roster))
const results = computed(() => findIndexedGradeStudents(searchIndex.value, query.value, 8))
const selectedStudent = computed(() => props.roster.find(student => String(student.enrollment_subject_id) === String(selectedStudentId.value)) || null)

watch(() => props.assessments, items => {
  if (!items.some(item => String(item.id) === String(assessmentId.value))) assessmentId.value = items[0]?.id ?? ''
}, { immediate: true })

watch(() => props.roster, items => {
  if (selectedStudentId.value && !items.some(item => String(item.enrollment_subject_id) === String(selectedStudentId.value))) clearSelection(false)
})

watch(results, items => {
  if (activeResultIndex.value >= items.length) activeResultIndex.value = -1
})

function handleQueryInput() {
  if (selectedStudentId.value) selectedStudentId.value = ''
  activeResultIndex.value = -1
  open.value = Boolean(query.value.trim())
}

function selectStudent(student) {
  selectedStudentId.value = String(student.enrollment_subject_id)
  query.value = gradeStudentDisplayName(student)
  activeResultIndex.value = -1
  open.value = false
  requestNavigation()
}

function clearSelection(refocus = true) {
  selectedStudentId.value = ''
  query.value = ''
  activeResultIndex.value = -1
  open.value = false
  if (refocus) nextTick(() => input.value?.focus())
}

function moveActiveResult(direction) {
  if (!results.value.length) return
  open.value = true
  if (activeResultIndex.value < 0) activeResultIndex.value = direction > 0 ? 0 : results.value.length - 1
  else activeResultIndex.value = (activeResultIndex.value + direction + results.value.length) % results.value.length
}

function handleInputKeydown(event) {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    moveActiveResult(event.key === 'ArrowDown' ? 1 : -1)
  } else if (event.key === 'Enter') {
    if (!open.value || activeResultIndex.value < 0) return
    event.preventDefault()
    selectStudent(results.value[activeResultIndex.value])
  } else if (event.key === 'Escape') {
    event.preventDefault()
    open.value = false
    activeResultIndex.value = -1
  }
}

function requestNavigation() {
  if (!selectedStudent.value || !props.assessments.some(item => String(item.id) === String(assessmentId.value))) return
  emit('navigate', {
    enrollmentSubjectId: selectedStudent.value.enrollment_subject_id,
    assessmentId: assessmentId.value,
  })
}

function handleAssessmentChange() {
  requestNavigation()
}

function handleOutsidePointer(event) {
  if (!root.value?.contains(event.target)) {
    open.value = false
    activeResultIndex.value = -1
  }
}

function focusSearch() {
  input.value?.focus()
  input.value?.select()
  open.value = Boolean(query.value.trim() && !selectedStudent.value)
}

defineExpose({ focusSearch })
onMounted(() => document.addEventListener('pointerdown', handleOutsidePointer))
onBeforeUnmount(() => document.removeEventListener('pointerdown', handleOutsidePointer))
</script>

<template>
  <section ref="root" class="grade-find-control" aria-label="Find Student and assessment">
    <div class="grade-find-search">
      <label for="grade-find-student">Find Student</label>
      <div class="grade-find-input">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
        <input
          id="grade-find-student"
          ref="input"
          v-model="query"
          type="search"
          role="combobox"
          autocomplete="off"
          aria-autocomplete="list"
          aria-controls="grade-find-results"
          :aria-expanded="open"
          :aria-activedescendant="open && activeResultIndex >= 0 ? `grade-find-result-${activeResultIndex}` : undefined"
          placeholder="Search student name or ID..."
          @input="handleQueryInput"
          @focus="open = Boolean(query.trim() && !selectedStudent)"
          @keydown="handleInputKeydown"
        >
        <button v-if="query" type="button" aria-label="Clear selected Student and search" title="Clear search" @click="clearSelection()">×</button>
      </div>
      <ul v-if="open" id="grade-find-results" class="grade-find-results" role="listbox" aria-label="Matching Students">
        <li
          v-for="(student,index) in results"
          :id="`grade-find-result-${index}`"
          :key="student.enrollment_subject_id"
          role="option"
          :aria-selected="index === activeResultIndex"
          :class="{active:index === activeResultIndex}"
          @pointerdown.prevent="selectStudent(student)"
        >
          <strong>{{gradeStudentDisplayName(student)}}</strong><span>{{student.student_number}}</span>
        </li>
        <li v-if="!results.length" class="grade-find-empty" role="status">No Students match this search.</li>
      </ul>
      <div v-if="selectedStudent" class="grade-find-selected" role="status">
        <span><strong>Selected:</strong> {{gradeStudentDisplayName(selectedStudent)}} · {{selectedStudent.student_number}}</span>
        <button type="button" @click="clearSelection()">Change</button>
      </div>
    </div>
    <label class="grade-find-assessment"><span>Assessment</span><select v-model="assessmentId" :disabled="!assessments.length" @change="handleAssessmentChange"><option v-for="assessment in assessments" :key="assessment.id" :value="assessment.id">{{assessment.label}}</option></select></label>
  </section>
</template>
