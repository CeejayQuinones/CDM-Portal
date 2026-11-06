<script setup>
import { onMounted, ref } from 'vue'
import { clientPlatform } from '../../config/clientPlatform'
import { gradingError, gradingService } from './gradingService'
import WebsiteDataTable from '../../components/website/WebsiteDataTable.vue'
import WebsiteEmptyState from '../../components/website/WebsiteEmptyState.vue'
import WebsiteFilterToolbar from '../../components/website/WebsiteFilterToolbar.vue'
import WebsiteLoadingState from '../../components/website/WebsiteLoadingState.vue'
import WebsiteModuleHeader from '../../components/website/WebsiteModuleHeader.vue'
import WebsiteAcademicJourneyNav from '../../components/website/WebsiteAcademicJourneyNav.vue'
const isWebsitePresentation=clientPlatform==='web'

const loading=ref(true),exporting=ref(false),error=ref(''),data=ref(null),year=ref(''),semester=ref('')
const label=value=>String(value||'').replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase())
const fmt=value=>value?new Intl.DateTimeFormat(undefined,{dateStyle:'medium'}).format(new Date(value)):'—'
async function load(params={}){loading.value=true;error.value='';try{data.value=await gradingService.studentGrades(params);year.value=String(data.value.selected_term?.academic_year_id||'');semester.value=String(data.value.selected_term?.semester_id||'')}catch(e){error.value=gradingError(e)}finally{loading.value=false}}
async function apply(){await load({academic_year_id:year.value||undefined,semester_id:semester.value||undefined})}
function changeYear(){semester.value=String(data.value?.terms?.find(term=>String(term.academic_year_id)===String(year.value))?.semester_id||'')}
function filename(response,fallback){return response.headers['content-disposition']?.match(/filename="?([^";]+)"?/)?.[1]||fallback}
async function download(){exporting.value=true;error.value='';try{const response=await gradingService.studentExport({academic_year_id:year.value||undefined,semester_id:semester.value||undefined});const url=URL.createObjectURL(response.data),a=document.createElement('a');a.href=url;a.download=filename(response,'academic-grade-summary.csv');a.click();URL.revokeObjectURL(url)}catch(e){error.value=gradingError(e)}finally{exporting.value=false}}
onMounted(load)
</script>

<template>
  <main class="grading-page student-grades">
    <WebsiteModuleHeader v-if="isWebsitePresentation" eyebrow="Published academic record" title="My Grades" description="Review grades officially released by the Registrar. Draft and unreleased results remain private.">
      <template #actions><button class="grading-button primary" :disabled="exporting||!data?.grades?.length" @click="download">{{exporting?'Preparing…':'Export Grade Report'}}</button></template>
    </WebsiteModuleHeader>
    <WebsiteAcademicJourneyNav v-if="isWebsitePresentation" />
    <header v-else class="grading-hero"><div><p class="grading-eyebrow">Published academic record</p><h1>My Grades</h1><p>View official grades released by the Registrar and contact the Professor for a published subject.</p></div><button class="grading-button primary" :disabled="exporting||!data?.grades?.length" @click="download">{{exporting?'Preparing…':'Export Grade Report'}}</button></header>
    <p v-if="error" class="grading-alert error" role="alert">{{error}}</p>
    <WebsiteLoadingState v-if="isWebsitePresentation&&loading" label="Loading published grades" />
    <div v-else-if="loading" class="grading-skeleton student-grades-skeleton" aria-label="Loading grades"><section class="grading-skeleton-summary"><span v-for="item in 4" :key="item"></span></section><span class="grading-skeleton-line wide"></span><span class="grading-skeleton-line"></span><span class="grading-skeleton-line"></span><span class="sr-only">Loading grades…</span></div>
    <template v-else-if="data">
      <section class="grading-summary student-grade-summary" aria-label="Published term summary"><div><small>GWA</small><strong>{{data.summary.gwa.available?data.summary.gwa.value:'Not available'}}</strong><span>{{data.summary.gwa.message}}</span></div><div><small>Published / Enrolled</small><strong>{{data.summary.published_subjects}} / {{data.summary.expected_subjects}}</strong></div><div><small>Total Units</small><strong>{{data.summary.total_units}}</strong></div><div><small>Completion</small><strong>{{label(data.summary.completion)}}</strong><span>Student status: {{label(data.student.status)}}</span></div></section>
      <div v-if="isWebsitePresentation" class="ui4-grade-context"><div><small>Official results</small><h2>Published subject grades</h2><p>Choose a term to review released results. Unpublished grades are not included.</p></div><span>Read-only record</span></div>
      <component :is="isWebsitePresentation?WebsiteFilterToolbar:'form'" class="grading-review-filters student-term-filter" label="Academic term filters" @submit.prevent="apply"><label>Academic Year<select v-model="year" @change="changeYear"><option v-for="term in data.terms" :key="`y-${term.academic_year_id}`" :value="term.academic_year_id">{{term.academic_year}}</option></select></label><label>Semester<select v-model="semester"><option v-for="term in data.terms.filter(t=>String(t.academic_year_id)===String(year))" :key="term.semester_id" :value="term.semester_id">{{term.semester}}</option></select></label><button class="grading-button primary">View Term</button></component>
      <p v-if="data.summary.completion==='incomplete'" class="grading-alert warning">This term is incomplete. GWA is not calculated from partially published grades.</p>
      <WebsiteEmptyState v-if="isWebsitePresentation&&!data.grades.length" title="No published grades" message="Draft, submitted, returned, and approved grades remain private until official release." />
      <div v-else-if="!data.grades.length" class="grading-state"><strong>No published grades</strong><span>Draft, Submitted, Returned, and Approved grades remain private until release.</span></div>
      <component :is="isWebsitePresentation?WebsiteDataTable:'div'" v-else label="Published grade results" class="grading-table-wrap student-grade-table"><table><thead><tr><th>Subject</th><th>Professor</th><th>Units</th><th>Midterm</th><th>Finals</th><th>Final Grade</th><th>Grade Point</th><th>Remarks</th><th>Published</th></tr></thead><tbody><tr v-for="row in data.grades" :key="row.grade_sheet_id"><td data-label="Subject"><strong>{{row.subject_code}}</strong><small>{{row.subject_name}} · {{row.section}}</small></td><td data-label="Professor">{{row.professor}}</td><td data-label="Units">{{row.units}}</td><td data-label="Midterm">{{row.midterm_grade}}</td><td data-label="Finals">{{row.finals_grade}}</td><td data-label="Final Grade"><strong>{{row.final_grade}}</strong></td><td data-label="Grade Point">{{row.grade_point??'N/A'}}</td><td data-label="Remarks">{{row.remarks??'Not configured'}}</td><td data-label="Published">{{fmt(row.published_at)}}</td></tr></tbody></table></component>
    </template>
  </main>
</template>
<style scoped>
.ui4-grade-context { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin: 20px 0 12px; }
.ui4-grade-context small { color: var(--website-success); font-size: 11px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
.ui4-grade-context h2 { margin: 3px 0 2px; color: var(--website-ink); font-size: 18px; }
.ui4-grade-context p { margin: 0; color: var(--website-muted); font-size: 13px; }
.ui4-grade-context>span { padding: 6px 9px; border: 1px solid var(--website-border); border-radius: 6px; color: var(--website-muted); font-size: 11px; font-weight: 700; white-space: nowrap; }
@media (max-width: 640px) { .ui4-grade-context { align-items: flex-start; flex-direction: column; gap: 8px; } }
</style>
