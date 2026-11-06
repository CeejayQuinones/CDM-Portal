<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import WebsiteLoadingState from '../../components/website/WebsiteLoadingState.vue'
import { groupReadinessIssues, summarizeReadinessGroups } from './assessmentPresentation'
import { gradingError, gradingService } from './gradingService'
import './grading.css'

const route=useRoute(),router=useRouter()
const loading=ref(true),saving=ref(false),error=ref(''),notice=ref(''),classes=ref([]),workspace=ref(null),readiness=ref(null)
const selectedClassId=ref(route.query.class?String(route.query.class):'')
const submissionWindow=computed(()=>{const state=workspace.value?.schedule?.finals_state?.state;return state==='Open'?'Open':state==='Closed'?'Closed':'Not Open'})
const issueGroups=computed(()=>groupReadinessIssues(readiness.value?.errors||[],workspace.value?.sheet?.assessments||[]))
const issueSummary=computed(()=>summarizeReadinessGroups(issueGroups.value))
const finalWeightTotal=computed(()=>Number(workspace.value?.sheet?.midterm_weight||0)+Number(workspace.value?.sheet?.finals_weight||0))
const editable=computed(()=>!saving.value&&['draft','returned'].includes(workspace.value?.sheet?.status)&&(workspace.value?.sheet?.status==='returned'||workspace.value?.schedule?.finals_state?.editable))
const canSubmit=computed(()=>readiness.value?.ready&&submissionWindow.value==='Open'&&['draft','returned'].includes(workspace.value?.sheet?.status))
const blockedReason=computed(()=>{if(canSubmit.value)return 'All grading requirements are complete and the submission window is open.';if(!readiness.value?.ready)return `Resolve ${issueSummary.value.total} ${issueSummary.value.total===1?'requirement':'requirements'} before submitting.`;if(submissionWindow.value!=='Open')return `Submission is unavailable because the submission window is ${submissionWindow.value.toLowerCase()}.`;return 'This grade sheet cannot be submitted in its current status.'})
const fmt=value=>value?new Intl.DateTimeFormat(undefined,{dateStyle:'medium',timeStyle:'short'}).format(new Date(value)):'Not configured'

async function loadWorkspace(classId){if(!classId){workspace.value=null;readiness.value=null;return}loading.value=true;error.value='';try{const assigned=classes.value.find(item=>String(item.id)===String(classId));if(!assigned)throw new Error('Select one of your assigned classes.');workspace.value=await gradingService.workspace(assigned.id);readiness.value=await gradingService.readiness(workspace.value.sheet.id);selectedClassId.value=String(assigned.id);await router.replace({name:'grading-submission',query:{class:assigned.id}})}catch(exception){workspace.value=null;readiness.value=null;error.value=gradingError(exception)}finally{loading.value=false}}
async function load(){loading.value=true;error.value='';try{const page=await gradingService.classes();classes.value=page.data||[];if(selectedClassId.value)await loadWorkspace(selectedClassId.value)}catch(exception){error.value=gradingError(exception)}finally{loading.value=false}}
async function chooseClass(){notice.value='';await loadWorkspace(selectedClassId.value)}
async function saveFinalWeights(){if(!workspace.value||saving.value)return;saving.value=true;error.value='';notice.value='';try{await gradingService.saveFinalWeights(workspace.value.sheet.id,{midterm_weight:Number(workspace.value.sheet.midterm_weight),finals_weight:Number(workspace.value.sheet.finals_weight),version:workspace.value.sheet.version});notice.value='Final-grade weights saved.';await loadWorkspace(workspace.value.class.id)}catch(exception){error.value=gradingError(exception)}finally{saving.value=false}}
async function submitSheet(){if(!canSubmit.value||saving.value)return;const action=workspace.value.sheet.status==='returned'?'resubmit':'submit';if(!window.confirm(`Ready to ${action} this grade sheet to the Registrar? Scores cannot be edited while it is under review.`))return;saving.value=true;error.value='';notice.value='';const wasReturned=workspace.value.sheet.status==='returned';try{await gradingService.submit(workspace.value.sheet.id,workspace.value.sheet.version);notice.value=wasReturned?'Grade sheet resubmitted to the Registrar.':'Grade sheet submitted to the Registrar.';await loadWorkspace(workspace.value.class.id)}catch(exception){error.value=gradingError(exception)}finally{saving.value=false}}

onMounted(load)
</script>

<template>
  <main class="grading-page website-professor-grading grade-submission-page">
    <header class="grade-entry-header">
      <RouterLink class="grading-back" :to="workspace?{name:'grading',query:{class:workspace.class.id}}:{name:'grading'}">← Return to Grade Entry</RouterLink>
      <p class="grading-eyebrow">Professor grading</p>
      <h1>GRADE SUBMISSION</h1>
      <p>Review authoritative readiness checks and submit a completed grade sheet to the Registrar.</p>
    </header>

    <p v-if="notice" class="grading-alert success" role="status">{{notice}}</p>
    <p v-if="error" class="grading-alert error" role="alert">{{error}}</p>
    <WebsiteLoadingState v-if="loading" label="Loading grade submission workspace"/>

    <template v-else>
      <section v-if="!classes.length" class="grading-state"><strong>No assigned classes</strong><span>Grade Submission is available after Scheduling assigns an active Professor.</span></section>
      <section v-else-if="!workspace" class="submission-class-picker" aria-labelledby="submission-class-title">
        <div><p class="submission-readiness-kicker">Choose a grade sheet</p><h2 id="submission-class-title">Assigned class</h2><p>Open a class to review its current submission readiness.</p></div>
        <label><span>Subject and section</span><select v-model="selectedClassId" @change="chooseClass"><option value="" disabled>Select an assigned class</option><option v-for="item in classes" :key="item.id" :value="String(item.id)">{{item.subject_code}} — {{item.subject_name}} · {{item.section}}</option></select></label>
      </section>

      <template v-else>
        <nav class="grade-workflow-nav" aria-label="Professor grading workflow">
          <RouterLink :to="{name:'grading',query:{class:workspace.class.id}}">Grade Entry</RouterLink>
          <span aria-current="page">Grade Submission</span>
        </nav>

        <section class="grade-submission-context" aria-labelledby="submission-subject-title">
          <div><p>{{workspace.class.subject_code}} · {{workspace.class.section}}</p><h2 id="submission-subject-title">{{workspace.class.subject_name}}</h2><span>{{workspace.class.academic_year}} · {{workspace.class.semester}}</span></div>
          <dl><div><dt>Grade sheet</dt><dd>{{workspace.sheet.status}}</dd></div><div><dt>Students</dt><dd>{{workspace.roster.length}}</dd></div><div><dt>Finals deadline</dt><dd>{{fmt(workspace.schedule?.finals_state?.deadline)}}</dd></div></dl>
        </section>

        <section class="submission-readiness-panel expanded" aria-labelledby="submission-readiness-title">
          <header class="submission-readiness-header">
            <div><p class="submission-readiness-kicker">Registrar handoff</p><h2 id="submission-readiness-title">Submission readiness</h2><p>All checks shown here come from the existing grading readiness service.</p></div>
            <span class="submission-readiness-badge" :data-state="readiness?.ready?'Ready':'Incomplete'" role="status">{{readiness?.ready?'Ready':'Incomplete'}}</span>
          </header>

          <dl class="submission-status-overview">
            <div><dt>Grade data</dt><dd :data-state="readiness?.ready?'Complete':'Incomplete'">{{readiness?.ready?'Complete':'Incomplete'}}</dd></div>
            <div><dt>Submission window</dt><dd :data-state="submissionWindow">{{submissionWindow}}</dd></div>
          </dl>

          <section v-if="issueSummary.total" class="submission-requirements" aria-labelledby="missing-requirements-title">
            <div class="submission-section-heading"><div><h3 id="missing-requirements-title">Missing requirements</h3><p>{{issueSummary.total}} {{issueSummary.total===1?'item is':'items are'}} blocking submission.</p></div></div>
            <div class="submission-requirement-groups"><details v-for="group in issueGroups.filter(item=>item.issues.length)" :key="group.key" open><summary><span>{{group.label}}</span><strong>{{group.issues.length}} {{group.issues.length===1?'issue':'issues'}}</strong></summary><div class="submission-requirement-detail"><ul><li v-for="issue in group.issues" :key="issue">{{issue}}</li></ul><RouterLink v-if="group.period" class="submission-review-shortcut" :to="{name:'grading',query:{class:workspace.class.id,period:group.period}}">Review {{group.label}} assessments</RouterLink></div></details></div>
          </section>
          <section v-else class="submission-requirements-complete" aria-label="Grading requirements complete"><strong>Grade data is complete</strong><span>There are no outstanding grading requirements.</span></section>

          <section class="submission-final-weights" aria-labelledby="final-grade-weights-title">
            <div class="submission-section-heading"><div><h3 id="final-grade-weights-title">Final grade weights</h3><p>Configure the existing Midterm and Finals contribution to the final grade.</p></div><span class="submission-weight-total" :data-valid="finalWeightTotal===100">Total {{finalWeightTotal}}%</span></div>
            <div class="submission-weight-grid"><label><span>Midterm percentage</span><div><input v-model.number="workspace.sheet.midterm_weight" type="number" min="0" max="100" :disabled="!editable"><span aria-hidden="true">%</span></div></label><label><span>Finals percentage</span><div><input v-model.number="workspace.sheet.finals_weight" type="number" min="0" max="100" :disabled="!editable"><span aria-hidden="true">%</span></div></label><button type="button" class="grading-button secondary" :disabled="saving||!editable" @click="saveFinalWeights">{{saving?'Saving…':'Save final weights'}}</button></div>
          </section>

          <footer class="submission-readiness-footer"><p id="submission-block-reason">{{blockedReason}}</p><button class="grading-button primary" :disabled="saving||!canSubmit" aria-describedby="submission-block-reason" @click="submitSheet">{{workspace.sheet.status==='returned'?'Resubmit to Registrar':'Submit to Registrar'}}</button></footer>
        </section>
      </template>
    </template>
  </main>
</template>
