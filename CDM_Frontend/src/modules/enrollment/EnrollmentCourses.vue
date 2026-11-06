<script setup>
import { onMounted, ref } from 'vue'
import WebsiteWorkspace from '../../components/website/WebsiteWorkspace.vue'
import WebsiteAcademicJourneyNav from '../../components/website/WebsiteAcademicJourneyNav.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmCard from '../../design-system/CdmCard.vue'
import CdmEmptyState from '../../design-system/CdmEmptyState.vue'
import CdmLoadingState from '../../design-system/CdmLoadingState.vue'
import CdmPageHeader from '../../design-system/CdmPageHeader.vue'
import { getEnrollmentCatalog } from './enrollmentCatalog'

const catalog = ref({ academic_year: null, programs: [], metadata: {} })
const loading = ref(true)
const error = ref('')

async function loadCatalog() {
  loading.value = true
  error.value = ''
  try {
    catalog.value = await getEnrollmentCatalog()
  } catch (reason) {
    error.value = reason.response?.data?.message || 'The academic program catalog could not be loaded. Please try again.'
  } finally {
    loading.value = false
  }
}

onMounted(loadCatalog)
</script>

<template>
  <WebsiteWorkspace class="enrollment-catalog-workspace">
    <CdmPageHeader eyebrow="Courses offered" title="Academic Programs" description="Explore the active programs and verified curricula currently configured in CDM Portal.">
      <template #actions>
        <div class="enrollment-catalog-year" aria-label="Current academic year">
          <span aria-hidden="true">AY</span>
          <strong>{{ catalog.academic_year?.school_year || 'Academic year not configured' }}</strong>
        </div>
      </template>
    </CdmPageHeader>

    <WebsiteAcademicJourneyNav />

    <div v-if="error" class="enrollment-catalog-error" role="alert"><span>{{ error }}</span><button type="button" @click="loadCatalog">Try again</button></div>
    <CdmLoadingState v-if="loading" label="Loading academic programs" />
    <CdmEmptyState v-else-if="!catalog.programs.length" title="No active programs available" message="The academic catalog has no active programs configured. Contact the Registrar for verified program information." />

    <section v-else class="enrollment-program-grid" aria-label="Active academic programs">
      <CdmCard v-for="program in catalog.programs" :key="program.id" class="enrollment-program-card" :padded="false">
        <div class="enrollment-program-accent" aria-hidden="true"></div>
        <div class="enrollment-program-card-body">
          <header>
            <span class="enrollment-program-icon" aria-hidden="true">{{ program.code?.slice(0, 2) || 'CD' }}</span>
            <div class="enrollment-program-duration"><small>Duration</small><strong>{{ program.duration_years ? `${program.duration_years} Years` : 'Not configured' }}</strong></div>
          </header>
          <div class="enrollment-program-copy">
            <p>{{ program.code || 'Program code unavailable' }}</p>
            <h2>{{ program.title }}</h2>
            <span>{{ program.department || 'Department not configured' }}</span>
          </div>
          <dl class="enrollment-program-meta">
            <div><dt>Curricula</dt><dd>{{ program.curricula.length }}</dd></div>
            <div><dt>Study mode</dt><dd>Not configured</dd></div>
          </dl>
          <CdmButton class="enrollment-curriculum-action" variant="outline" size="sm" :to="{ name: 'enrollment-curriculum', params: { courseId: program.id } }">View Curriculum <span aria-hidden="true">→</span></CdmButton>
        </div>
      </CdmCard>
    </section>

    <p class="enrollment-catalog-disclosure">Program descriptions, study mode, and slot capacity are omitted when CDM Portal has no authoritative configured values. The catalog never substitutes reference-repository examples for official academic data.</p>
  </WebsiteWorkspace>
</template>

<style src="./enrollment.css"></style>
