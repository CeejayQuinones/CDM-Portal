<script setup>
import { computed, ref,onMounted,onBeforeUnmount } from 'vue'
import { apiClient } from '../../services/apiClient'
import { termLabel,safeError,hasSavedSchedule } from './enrollment'
import EnrollmentSchedule from './EnrollmentSchedule.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmCard from '../../design-system/CdmCard.vue'
import CdmEmptyState from '../../design-system/CdmEmptyState.vue'
import CdmLoadingState from '../../design-system/CdmLoadingState.vue'
import CdmPageHeader from '../../design-system/CdmPageHeader.vue'
import CdmTable from '../../design-system/CdmTable.vue'
const data=ref(null),roster=ref(null),selected=ref(null),error=ref(''),busy=ref(false),page=ref(1),rosterPage=ref(1);let epoch=0
const timetable=ref(null),timetableError=ref('')
const days=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']
const rows=computed(()=>timetable.value?.flatMap(assignment=>{
  const meetings=assignment.meetings?.length ? assignment.meetings : hasSavedSchedule(assignment) ? [assignment] : []
  return meetings.map(meeting=>({assignment,meeting}))
}).sort((a,b)=>days.indexOf(a.meeting.day)-days.indexOf(b.meeting.day)||String(a.meeting.start_time).localeCompare(String(b.meeting.start_time))||String(a.assignment.section?.section_name).localeCompare(String(b.assignment.section?.section_name)))||[])
const pending=computed(()=>timetable.value?.filter(assignment=>!hasSavedSchedule(assignment))||[])
const tableRows=computed(()=>[...rows.value,...pending.value.filter(assignment=>!rows.value.some(row=>row.assignment.id===assignment.id)).map(assignment=>({assignment,meeting:null}))])
const clock=value=>String(value||'').slice(0,5)
const printTimetable=()=>window.print()
async function loadTimetable(){timetableError.value='';try{const response=await apiClient.get('/enrollment/academic/professor/timetable');timetable.value=response.data.data}catch(e){timetableError.value=safeError(e)}}
async function load(){const v=++epoch;busy.value=true;error.value='';try{const r=await apiClient.get('/enrollment/academic/professor',{params:{page:page.value}});if(v===epoch)data.value=r.data.data}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
async function inspect(s){const v=++epoch;busy.value=true;error.value='';try{const r=await apiClient.get(`/enrollment/academic/professor/sections/${s.id}`,{params:{page:rosterPage.value}});if(v!==epoch)return;selected.value=s;roster.value=r.data.data}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
onMounted(()=>{load();loadTimetable()});onBeforeUnmount(()=>{epoch++})
</script>
<template>
  <div class="cdm-professor-assignments">
  <CdmPageHeader eyebrow="Professor workspace" title="Assigned classes and schedules" description="Review verified teaching assignments, class meeting details, and account-scoped student rosters." />
  <section class="professor-weekly" aria-labelledby="professor-weekly-title">
    <div class="professor-weekly-heading"><div><span class="professor-weekly-kicker">All assigned classes</span><h2 id="professor-weekly-title">Weekly teaching schedule</h2><p>One view of your subjects, sections, year levels, rooms, and meeting times.</p></div><CdmButton class="professor-print" variant="outline" size="sm" :disabled="!tableRows.length" @click="printTimetable">Print full schedule</CdmButton></div>
    <div v-if="timetableError" class="cdm-assignment-alert" role="alert"><span>{{ timetableError }}</span><CdmButton variant="outline" size="sm" @click="loadTimetable">Retry</CdmButton></div>
    <p v-else-if="!timetable" class="professor-weekly-note">Loading your full schedule…</p>
    <template v-else><div v-if="tableRows.length" class="professor-weekly-scroll" role="region" aria-label="Complete weekly teaching schedule" tabindex="0"><table><thead><tr><th>Day</th><th>Time</th><th>Subject</th><th>Program / year / section</th><th>Room</th><th>Academic term</th></tr></thead><tbody><tr v-for="({assignment,meeting},index) in tableRows" :key="`${assignment.id}-${meeting?.id||index}`"><td><strong>{{ meeting?.day || 'Pending' }}</strong></td><td class="professor-time">{{ meeting ? `${clock(meeting.start_time)}–${clock(meeting.end_time)}` : 'TBA' }}</td><td><strong>{{ assignment.subject?.subject_code }}</strong><span>{{ assignment.subject?.subject_name }}</span></td><td><strong>{{ assignment.section?.course?.course_code || 'Program' }} · Year {{ assignment.section?.year_level || '—' }} · {{ assignment.section?.section_name || 'Section' }}</strong></td><td>{{ meeting?.room || 'TBA' }}</td><td>{{ termLabel(assignment.section) }}</td></tr></tbody></table></div><p v-else class="professor-weekly-note">No assigned subjects yet.</p><p v-if="pending.length" class="professor-weekly-note">{{ pending.length }} assigned {{ pending.length===1?'subject is':'subjects are' }} still waiting for a complete meeting schedule.</p></template>
  </section>
  <div v-if="error" class="cdm-assignment-alert" role="alert"><span>{{ error }}</span><CdmButton variant="outline" size="sm" @click="load">Reload</CdmButton></div><CdmLoadingState v-if="busy && !data" label="Loading teaching assignments…" />
  <template v-if="data">
    <CdmEmptyState v-if="!data.data.length" title="No assigned classes" message="Verified classes will appear after Scheduling assigns you to an active section and subject." />
    <div class="teaching-assignment-grid">
      <CdmCard v-for="s in data.data" :key="s.id" class="teaching-assignment-card"><template #header><div class="assignment-card-heading"><div><small>Assigned section</small><h2>{{ s.section_name }}</h2></div><strong>{{ termLabel(s) }}</strong></div></template><EnrollmentSchedule :schedules="s.schedules"/><template #footer><CdmButton :disabled="busy" size="sm" @click="rosterPage=1;inspect(s)">View enrolled Students</CdmButton></template></CdmCard>
    </div>
    <div class="assignment-pagination"><CdmButton variant="outline" size="sm" :disabled="busy||page<=1" @click="page--;load()">Previous</CdmButton><span>Page {{ data.current_page }} of {{ data.last_page }}</span><CdmButton variant="outline" size="sm" :disabled="busy||page>=data.last_page" @click="page++;load()">Next</CdmButton></div>
  </template>
  <CdmCard v-if="roster" class="teaching-roster" :title="`${selected.section_name} roster`" description="Students officially enrolled in this assigned section."><CdmTable label="Assigned class student roster" :empty="!roster.data.length" empty-message="No enrolled Students are available for this section."><table><thead><tr><th>Student number</th><th>Name</th></tr></thead><tbody><tr v-for="r in roster.data" :key="r.id"><td>{{ r.student.student_number }}</td><td>{{ r.student.user_profile.first_name }} {{ r.student.user_profile.last_name }}</td></tr></tbody></table></CdmTable><template #footer><CdmButton variant="outline" size="sm" :disabled="busy||rosterPage<=1" @click="rosterPage--;inspect(selected)">Previous Students</CdmButton><CdmButton variant="outline" size="sm" :disabled="busy||rosterPage>=roster.last_page" @click="rosterPage++;inspect(selected)">More Students</CdmButton></template></CdmCard>
  </div>
</template>
<style scoped>
.cdm-professor-assignments{display:grid;gap:20px;max-width:1200px;margin:0 auto;color:var(--cdm-text)}.cdm-assignment-alert{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--cdm-danger);border-radius:var(--cdm-radius-md);background:var(--cdm-danger-soft);color:var(--cdm-danger);font-size:13px}.teaching-assignment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(360px,100%),1fr));gap:16px}.assignment-card-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.assignment-card-heading small{color:var(--cdm-primary);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.assignment-card-heading h2{margin:3px 0 0;font-size:17px}.assignment-card-heading>strong{color:var(--cdm-text-secondary);font-size:11px;text-align:right}.assignment-pagination{display:flex;align-items:center;justify-content:flex-end;gap:10px;color:var(--cdm-text-muted);font-size:12px}.teaching-roster{margin-top:4px}@media(max-width:600px){.assignment-pagination{justify-content:space-between}.cdm-assignment-alert{align-items:stretch;flex-direction:column}}
.professor-weekly{padding:20px;border:1px solid var(--cdm-border);border-radius:var(--cdm-radius-md);background:var(--cdm-surface,#fff)}.professor-weekly-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.professor-weekly-kicker{color:var(--cdm-primary);font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.professor-weekly h2{margin:4px 0;font-size:21px}.professor-weekly p{margin:0;color:var(--cdm-text-secondary);font-size:13px}.professor-weekly-scroll{overflow-x:auto}.professor-weekly table{width:100%;border-collapse:collapse;text-align:left;font-size:13px}.professor-weekly th,.professor-weekly td{padding:11px 12px;border-bottom:1px solid var(--cdm-border);vertical-align:top}.professor-weekly th{background:var(--cdm-surface-muted,#f4f7f5);font-size:11px;text-transform:uppercase;letter-spacing:.04em}.professor-weekly td span{display:block;color:var(--cdm-text-secondary);font-size:12px}.professor-time{white-space:nowrap}.professor-weekly .professor-weekly-note{margin-top:12px}
@media(max-width:600px){.professor-weekly-heading{flex-direction:column}.professor-weekly{padding:14px}}
@media print{:global(body *){visibility:hidden!important}.professor-weekly,.professor-weekly *{visibility:visible!important}.professor-weekly{position:absolute;left:0;top:0;width:100%;border:0;padding:0;color:#000;background:#fff;box-shadow:none}.professor-print{display:none!important}.professor-weekly-scroll{overflow:visible}.professor-weekly table{font-size:10pt}.professor-weekly th,.professor-weekly td{border:1px solid #bbb;padding:6px;break-inside:avoid}.professor-weekly thead{display:table-header-group}}
</style>
