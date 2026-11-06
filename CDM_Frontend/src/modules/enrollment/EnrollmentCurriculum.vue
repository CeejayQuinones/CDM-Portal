<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import WebsiteEmptyState from '../../components/website/WebsiteEmptyState.vue'
import WebsiteLoadingState from '../../components/website/WebsiteLoadingState.vue'
import WebsitePageHeading from '../../components/website/WebsitePageHeading.vue'
import WebsiteWorkspace from '../../components/website/WebsiteWorkspace.vue'
import WebsiteAcademicJourneyNav from '../../components/website/WebsiteAcademicJourneyNav.vue'
import { curriculumGroups, getEnrollmentCatalog } from './enrollmentCatalog'

const route = useRoute()
const catalog = ref({ programs: [] })
const selectedCurriculumId = ref(null)
const loading = ref(true)
const error = ref('')
const program = computed(() => catalog.value.programs.find(item => String(item.id) === String(route.params.courseId)))
const curriculum = computed(() => program.value?.curricula.find(item => item.id === selectedCurriculumId.value) || program.value?.curricula[0] || null)
const groups = computed(() => curriculumGroups(curriculum.value))
const unitTotal = computed(() => (curriculum.value?.subjects || []).reduce((total, subject) => total + Number(subject.units || 0), 0))

async function loadCurriculum() {
  loading.value = true
  error.value = ''
  try {
    catalog.value = await getEnrollmentCatalog()
    selectedCurriculumId.value = program.value?.curricula[0]?.id || null
  } catch (reason) {
    error.value = reason.response?.data?.message || 'The curriculum could not be loaded. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(loadCurriculum)
</script>

<template>
  <WebsiteWorkspace class="enrollment-curriculum-workspace">
    <RouterLink class="enrollment-back-link" :to="{ name: 'enrollment-courses' }">← Back to Courses Offered</RouterLink>
    <WebsitePageHeading eyebrow="Verified curriculum" :title="program?.title || 'Program curriculum'" :description="program ? `${program.code} · ${program.department || 'Department not configured'}` : 'Review the active curriculum configured for this academic program.'" />
    <WebsiteAcademicJourneyNav />
    <div v-if="error" class="enrollment-catalog-error" role="alert"><span>{{ error }}</span><button type="button" @click="loadCurriculum">Try again</button></div>
    <WebsiteLoadingState v-if="loading" label="Loading curriculum" />
    <WebsiteEmptyState v-else-if="!program" title="Program unavailable" message="This active academic program could not be found. Return to Courses Offered and select an available program." />
    <WebsiteEmptyState v-else-if="!program.curricula.length" title="Curriculum not configured" message="This program is active, but no active curriculum is currently available in CDM Portal. No subjects or units have been invented." />

    <template v-else>
      <section class="enrollment-curriculum-summary">
        <div><small>Program duration</small><strong>{{ program.duration_years ? `${program.duration_years} Years` : 'Not configured' }}</strong></div>
        <div><small>Active curricula</small><strong>{{ program.curricula.length }}</strong></div>
        <div><small>Configured units</small><strong>{{ unitTotal }}</strong></div>
        <label v-if="program.curricula.length > 1">Curriculum<select v-model="selectedCurriculumId"><option v-for="item in program.curricula" :key="item.id" :value="item.id">{{ item.name }} · {{ item.effective_year }}</option></select></label>
        <div v-else><small>Curriculum</small><strong>{{ curriculum.name }}</strong><span>{{ curriculum.code }} · Effective {{ curriculum.effective_year }}</span></div>
      </section>

      <WebsiteEmptyState v-if="!groups.length" title="No active subjects configured" message="The selected curriculum contains no active subjects available for display." />
      <section v-else class="enrollment-curriculum-groups" aria-label="Curriculum subjects by year and semester">
        <article v-for="group in groups" :key="group.key" class="enrollment-curriculum-group">
          <header><div><small>Year {{ group.yearLevel }}</small><h2>{{ group.semester }}</h2></div><span>{{ group.subjects.length }} subject{{ group.subjects.length === 1 ? '' : 's' }}</span></header>
          <div class="enrollment-curriculum-table-wrap">
            <table>
              <thead><tr><th scope="col">Code</th><th scope="col">Subject</th><th scope="col">Units</th></tr></thead>
              <tbody><tr v-for="subject in group.subjects" :key="subject.id"><td>{{ subject.code }}</td><td>{{ subject.name }}</td><td>{{ subject.units }}</td></tr></tbody>
            </table>
          </div>
        </article>
      </section>
      <div class="enrollment-curriculum-next"><div><small>Continue your enrollment journey</small><strong>Your program choice remains controlled by your verified academic record.</strong></div><RouterLink :to="{ name: 'enrollment-status' }">Open Online Enrollment</RouterLink></div>
    </template>
  </WebsiteWorkspace>
</template>

<style src="./enrollment.css"></style>
