<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { apiClient } from '../../services/apiClient'
import { clientPlatform } from '../../config/clientPlatform'
import { label, termLabel, safeError } from './enrollment'
import EnrollmentLoad from './EnrollmentLoad.vue'
import EnrollmentCor from './EnrollmentCor.vue'
import EnrollmentOvrfActions from './EnrollmentOvrfActions.vue'
import EnrollmentSchedule from './EnrollmentSchedule.vue'
import WebsiteEmptyState from '../../components/website/WebsiteEmptyState.vue'
import WebsiteLoadingState from '../../components/website/WebsiteLoadingState.vue'
import WebsiteModuleHeader from '../../components/website/WebsiteModuleHeader.vue'
import WebsiteSectionPanel from '../../components/website/WebsiteSectionPanel.vue'
import WebsiteAcademicJourneyNav from '../../components/website/WebsiteAcademicJourneyNav.vue'
import WebsiteAcademicRecordCard from '../../components/website/WebsiteAcademicRecordCard.vue'
const isWebsitePresentation=clientPlatform==='web'
const route=useRoute(),auth=useAuthStore(),data=ref(null),record=ref(null),application=ref(null),busy=ref(false),error=ref(''),page=ref(1)
const subjects=computed(()=>route.name==='enrollment-subjects'),cor=computed(()=>route.name==='enrollment-cor')
const pageTitle=computed(()=>subjects.value?'My Subjects':cor.value?'Certificate of Registration':'My Schedule')
const pageDescription=computed(()=>subjects.value?'Review the finalized subject load attached to your approved enrollment.':cor.value?'Open and print an official registration record without changing its required content.':'Review enrolled subjects now; verified class times and rooms appear when Scheduling completes them.')
const sectionLabel=value=>typeof value?.section==='string'?value.section:value?.section?.section_name||'Not assigned'
const scheduleRows=computed(()=>(record.value?.subjects||[]).map(subject=>subject.schedule?{...subject.schedule,subject}:{id:`pending-${subject.id}`,subject,schedule_status:'pending',meetings:[],day:null,start_time:null,end_time:null,room:null,professor:null}))
let epoch=0
async function load(){const v=++epoch;busy.value=true;error.value='';record.value=null;application.value=null;try{const r=await apiClient.get(subjects.value?'/enrollment/applications/mine':'/enrollment/academic/records',{params:{page:page.value}});if(v!==epoch)return;data.value=r.data.data;if(!subjects.value && route.query.record)await openRecord(Number(route.query.record))}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
async function openRecord(id){const v=++epoch;busy.value=true;error.value='';try{const r=await apiClient.get(`/enrollment/academic/records/${id}`);if(v===epoch)record.value=r.data.data}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
watch(()=>[route.name,auth.currentUser?.id],()=>{data.value=null;page.value=1;load()},{immediate:true});onBeforeUnmount(()=>{epoch++})
</script>
<template>
  <WebsiteModuleHeader v-if="isWebsitePresentation" eyebrow="Enrollment" :title="pageTitle" :description="pageDescription" />
  <WebsiteAcademicJourneyNav v-if="isWebsitePresentation" />
  <header v-else class="page-header"><p class="page-kicker">Enrollment</p><h1 class="page-title">{{ pageTitle }}</h1></header>
  <p v-if="error" class="en-card" role="alert">{{ error }} <button @click="load">Reload</button></p>
  <WebsiteLoadingState v-if="isWebsitePresentation && busy && !data" :label="`Loading ${pageTitle}`" />
  <p v-else-if="busy" role="status">Loading…</p>
  <WebsiteSectionPanel v-if="isWebsitePresentation && data" :title="subjects?'Approved subject loads':'Available academic records'" description="Choose a verified term to open its academic information.">
    <WebsiteEmptyState v-if="!data.data.length" :title="`No ${subjects?'subject loads':'academic records'} available`" message="This workspace updates after Enrollment is finalized for an academic term." />
    <div v-if="data.data.length" class="ui4-record-list">
      <WebsiteAcademicRecordCard v-for="r in data.data" :key="r.id"
        :eyebrow="subjects?'Enrollment application':'Finalized academic record'"
        :title="termLabel(subjects?r.period:r)"
        :detail="subjects?'Open the approved subject load for this application.':`Section ${sectionLabel(r)}`"
        :status="r.status"
        :action-label="subjects && ['approved','enrolled'].includes(r.status)?'View subject load':!subjects && ['enrolled','completed'].includes(r.status)?cor?'View COR':'View schedule':''"
        :disabled="busy" @open="subjects?application=r.id:openRecord(r.id)" />
    </div>
    <div v-if="data.last_page>1" class="en-actions"><button :disabled="busy||page<=1" @click="page--;load()">Previous</button><span>Page {{ page }} of {{ data.last_page }}</span><button :disabled="busy||page>=data.last_page" @click="page++;load()">Next</button></div>
  </WebsiteSectionPanel>
  <div v-else-if="data" class="en-card"><p v-if="!data.data.length">No {{ subjects?'applications':'academic enrollment records' }} available.</p><div v-for="r in data.data" :key="r.id" class="en-actions"><span>{{ termLabel(subjects?r.period:r) }} · {{ label(r.status) }}</span><button v-if="subjects && ['approved','enrolled'].includes(r.status)" :disabled="busy" @click="application=r.id">View subject load</button><button v-else-if="!subjects && ['enrolled','completed'].includes(r.status)" :disabled="busy" @click="openRecord(r.id)">{{ cor?'View COR':'View schedule' }}</button></div><div v-if="data.last_page>1" class="en-actions"><button :disabled="busy||page<=1" @click="page--;load()">Previous</button><span>Page {{ page }} of {{ data.last_page }}</span><button :disabled="busy||page>=data.last_page" @click="page++;load()">Next</button></div></div>
  <div v-if="application && isWebsitePresentation" class="ui4-selected-record"><span>Viewing approved subject load</span><button type="button" @click="application=null">Close subject load</button></div>
  <EnrollmentLoad v-if="application" :application-id="application"/>
  <div v-if="record && isWebsitePresentation" class="ui4-selected-record"><span>Viewing {{ termLabel(record) }}</span><button type="button" @click="record=null">Close record</button></div>
  <EnrollmentCor v-if="record && cor" :record="record"/>
  <EnrollmentOvrfActions v-if="record && cor" :enrollment-id="record.id" :student-name="record.name" :academic-term="`${record.academic_year} · ${record.semester}`" />
  <WebsiteSectionPanel v-else-if="record && isWebsitePresentation" :title="`${record.section} · ${record.academic_year}`" :description="record.semester"><EnrollmentSchedule :schedules="scheduleRows"/></WebsiteSectionPanel>
  <div v-else-if="record" class="en-card"><h2>{{ record.section }} · {{ record.academic_year }} · {{ record.semester }}</h2><EnrollmentSchedule :schedules="scheduleRows"/></div>
</template>
<style scoped>
.ui4-record-list { display: grid; gap: 10px; }
.ui4-selected-record { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 20px 0 12px; padding: 10px 14px; border: 1px solid var(--website-success-border); border-radius: 9px; background: var(--website-success-bg); color: var(--website-success); font-size: 13px; font-weight: 700; }
.ui4-selected-record button { min-height: 40px; padding: 0 12px; border: 1px solid currentColor; border-radius: 7px; background: transparent; color: inherit; font: inherit; cursor: pointer; }
.ui4-selected-record button:focus-visible { outline: 2px solid var(--website-focus); outline-offset: 2px; }
@media (max-width: 640px) { .ui4-selected-record { flex-wrap: wrap; } .ui4-selected-record button { min-height: 44px; } }
</style>
