<script setup>
import { ref,onMounted,onBeforeUnmount } from 'vue'
import { apiClient } from '../../services/apiClient'
import { termLabel,safeError } from './enrollment'
import EnrollmentSchedule from './EnrollmentSchedule.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmCard from '../../design-system/CdmCard.vue'
import CdmEmptyState from '../../design-system/CdmEmptyState.vue'
import CdmLoadingState from '../../design-system/CdmLoadingState.vue'
import CdmPageHeader from '../../design-system/CdmPageHeader.vue'
import CdmTable from '../../design-system/CdmTable.vue'
const data=ref(null),roster=ref(null),selected=ref(null),error=ref(''),busy=ref(false),page=ref(1),rosterPage=ref(1);let epoch=0
async function load(){const v=++epoch;busy.value=true;error.value='';try{const r=await apiClient.get('/enrollment/academic/professor',{params:{page:page.value}});if(v===epoch)data.value=r.data.data}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
async function inspect(s){const v=++epoch;busy.value=true;error.value='';try{const r=await apiClient.get(`/enrollment/academic/professor/sections/${s.id}`,{params:{page:rosterPage.value}});if(v!==epoch)return;selected.value=s;roster.value=r.data.data}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
onMounted(load);onBeforeUnmount(()=>{epoch++})
</script>
<template>
  <div class="cdm-professor-assignments">
  <CdmPageHeader eyebrow="Professor workspace" title="Assigned classes and schedules" description="Review verified teaching assignments, class meeting details, and account-scoped student rosters." />
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
</style>
