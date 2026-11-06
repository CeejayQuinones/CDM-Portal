<script setup>
import WebsitePageHeading from '../../components/website/WebsitePageHeading.vue'
import WebsiteWorkspace from '../../components/website/WebsiteWorkspace.vue'
import WebsiteAcademicJourneyNav from '../../components/website/WebsiteAcademicJourneyNav.vue'

const stages = [
  { number: '01', icon: 'DOC', title: 'Document Submission', description: 'Provide only the documents configured by the Registrar for your active period and classification.', note: 'Requirements vary by student type.', to: { name: 'enrollment-status' } },
  { number: '02', icon: 'ADM', title: 'Admission Qualification', description: 'New applicants complete only the Admission requirements officially assigned to them before Enrollment.', note: 'Already completed for continuing Students.', to: { name: 'enrollment-status' } },
  { number: '03', icon: 'REV', title: 'Registrar Review', description: 'The Registrar reviews the submitted application and may request corrections or additional verified information.', note: 'No general interview is scheduled by this page.', to: { name: 'enrollment-status' } },
  { number: '04', icon: 'CUR', title: 'Course Qualification', description: 'Your verified program, curriculum, year level, and proposed subjects are checked against the academic record.', note: 'Students cannot self-assign a program or section.', to: { name: 'enrollment-courses' } },
  { number: '05', icon: 'APP', title: 'Enrollment Approval', description: 'Approved applications proceed to staff-controlled subject preparation, section assignment, and final review.', note: 'CDM Portal has no tuition payment workflow.', to: { name: 'enrollment-subjects' } },
  { number: '06', icon: 'COR', title: 'Final Registration', description: 'After authorized finalization, your official subject load and Certificate of Registration become available. Class times appear when Scheduling completes them.', note: 'COR is unavailable until finalization.', to: { name: 'enrollment-cor' } },
]
</script>

<template>
  <WebsiteWorkspace class="enrollment-journey-workspace">
    <WebsitePageHeading eyebrow="Enrollment guide" title="The Enrollment Journey" description="Follow the verified CDM Portal process from configured requirements to an official finalized enrollment." />
    <WebsiteAcademicJourneyNav />
    <p class="enrollment-journey-intro">This six-stage guide explains the broader process. It is separate from the three-step Online Enrollment form used to prepare and submit an application.</p>
    <ol class="enrollment-journey-grid">
      <li v-for="stage in stages" :key="stage.number" class="enrollment-journey-card">
        <div class="enrollment-journey-icon" aria-hidden="true">{{ stage.icon }}</div>
        <span>Step {{ stage.number }}</span>
        <h2>{{ stage.title }}</h2>
        <p>{{ stage.description }}</p>
        <small>{{ stage.note }}</small>
        <RouterLink :to="stage.to">Learn Requirements <span aria-hidden="true">→</span></RouterLink>
      </li>
    </ol>
    <div class="enrollment-journey-actions"><RouterLink :to="{ name: 'enrollment-courses' }">Explore Courses Offered</RouterLink><RouterLink class="primary" :to="{ name: 'enrollment-status' }">Open Online Enrollment</RouterLink></div>
  </WebsiteWorkspace>
</template>

<style src="./enrollment.css"></style>
