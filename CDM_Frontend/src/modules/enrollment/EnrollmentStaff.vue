<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import EnrollmentDialog from './EnrollmentDialog.vue'
import { apiClient } from '../../services/apiClient'
import { classifications, label, termLabel, safeError } from './enrollment'
const route=useRoute(), periodsPage=computed(()=>route.name==='enrollment-periods')
const data=ref(null), busy=ref(false), error=ref(''), notice=ref(''), detail=ref(null), requirements=ref([]), notes=ref(''), confirmation=ref(''), form=ref(null), page=ref(1)
const filters=ref({search:'',course_id:'',classification:'',status:'',period_id:'',sort:'newest'})
let epoch=0
const rows=computed(()=>periodsPage.value?data.value?.periods:data.value?.applications)
async function load() {
 const v=++epoch;busy.value=true;error.value=''
 try{const r=await apiClient.get(periodsPage.value?'/enrollment/periods':'/enrollment/applications',{params:{...filters.value,page:page.value}});if(v===epoch)data.value=r.data.data}
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function inspect(a) {
 const v=++epoch;busy.value=true;error.value=''
 try{const r=await apiClient.get(`/enrollment/applications/${a.id}`);if(v!==epoch)return;detail.value=r.data.data.application;requirements.value=r.data.data.requirements;notes.value=detail.value.review_notes||''}
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function act() {
 if(busy.value)return;const action=confirmation.value;confirmation.value='';const v=++epoch;busy.value=true;error.value=''
 try{await apiClient.post(`/enrollment/applications/${detail.value.id}/${action}`,{version:detail.value.version,notes:notes.value,confirmed:true});if(v!==epoch)return;notice.value='Application updated.';await inspect(detail.value);await load()}
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
const localDate=value=>{if(!value)return '';const d=new Date(value);return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,16)}
function edit(p) { form.value=p?{...p,opens_at:localDate(p.opens_at),closes_at:localDate(p.closes_at),document_requirements:JSON.parse(JSON.stringify(p.document_requirements))}:{academic_year_id:'',semester_id:'',opens_at:'',closes_at:'',enabled:false,document_requirements:{regular:[],irregular:[],transferee:[],returnee:[]}} }
async function savePeriod() {
 if(busy.value)return;const v=++epoch;busy.value=true;error.value=''
 try {const payload={...form.value,academic_year_id:Number(form.value.academic_year_id),semester_id:Number(form.value.semester_id),opens_at:new Date(form.value.opens_at).toISOString(),closes_at:new Date(form.value.closes_at).toISOString()};
 if(form.value.id)await apiClient.put(`/enrollment/periods/${form.value.id}`,payload);else await apiClient.post('/enrollment/periods',payload);if(v!==epoch)return;form.value=null;notice.value='Enrollment period saved.';await load()}
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function download(d) {
 const v=epoch;error.value=''
 try {const r=await apiClient.get(`/enrollment/applications/${detail.value.id}/documents/${d.document_id}`,{responseType:'blob'});if(v!==epoch)return;const url=URL.createObjectURL(r.data);const link=document.createElement('a');link.href=url;link.download=`${d.name}.${({'application/pdf':'pdf','image/png':'png','image/jpeg':'jpg'})[r.data.type] || 'bin'}`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}catch(e){if(v===epoch)error.value=safeError(e)}
}
watch(()=>route.name,()=>{data.value=null;detail.value=null;form.value=null;confirmation.value='';page.value=1;load()})
onMounted(load);onBeforeUnmount(()=>{epoch++})
</script>
<template>
<header class="page-header"><p class="page-kicker">Enrollment</p><h1 class="page-title">{{ periodsPage?'Enrollment Periods':'Applications' }}</h1><p class="page-description">{{ periodsPage?'Manage when Students can apply for an academic term.':'Review enrollment applications before academic finalization.' }}</p></header>
<nav class="en-actions" aria-label="Enrollment workspace"><RouterLink class="en-link" to="/enrollment/applications">Applications</RouterLink><RouterLink class="en-link" to="/enrollment/periods">Enrollment Periods</RouterLink></nav>
<p v-if="error" class="en-card" role="alert">{{ error }} <button :disabled="busy" @click="detail?inspect(detail):load()">Reload</button></p><p v-if="notice" role="status">{{ notice }}</p><p v-if="busy" role="status">Loading…</p>
<template v-if="data">
<template v-if="periodsPage">
<button :disabled="busy" @click="edit(null)">Create period</button>
<form v-if="form" class="en-card" @submit.prevent="savePeriod"><h2>{{ form.id?'Edit period':'Create enrollment period' }}</h2><div class="en-grid"><label>Academic year<select v-model="form.academic_year_id" :disabled="!!form.id || busy" required><option value="">Choose year</option><option v-for="y in data.academic_years" :key="y.id" :value="y.id">{{ y.school_year }}</option></select></label><label>Semester<select v-model="form.semester_id" :disabled="!!form.id || busy" required><option value="">Choose semester</option><option v-for="s in data.semesters" :key="s.id" :value="s.id">{{ s.semester_name }}</option></select></label><label>Opens at (your local time)<input type="datetime-local" v-model="form.opens_at" required :disabled="busy"></label><label>Closes at (your local time)<input type="datetime-local" v-model="form.closes_at" required :disabled="busy"></label></div>
<label class="en-check"><input type="checkbox" v-model="form.enabled" :disabled="busy"> Enable period</label><p>Enabled periods open and close according to backend time. Disable a period to close it immediately. To reopen it, enable it with a current opening and future closing time.</p>
<h3>Required documents by classification</h3><p>Require only school-approved evidence. Verified existing Student documents are reused automatically. Requirements cannot change once applications exist.</p><div class="en-grid"><fieldset v-for="c in classifications" :key="c.value"><legend>{{ c.label }}</legend><label class="en-check" v-for="d in data.document_types" :key="d.id"><input type="checkbox" :value="d.id" v-model="form.document_requirements[c.value]" :disabled="busy">{{ d.document_name }}</label><p v-if="!data.document_types.length">No active document types configured.</p></fieldset></div><div class="en-actions"><button :disabled="busy" type="submit">Save period</button><button :disabled="busy" type="button" class="en-secondary" @click="form=null">Cancel</button></div></form>
</template>
<form v-else class="en-card en-grid" @submit.prevent="page=1;load()"><label>Student name or number<input v-model="filters.search" maxlength="100" placeholder="Search Students"></label><label>Course<select v-model="filters.course_id"><option value="">All courses</option><option v-for="c in data.courses" :key="c.id" :value="c.id">{{ c.course_name }}</option></select></label><label>Classification<select v-model="filters.classification"><option value="">All classifications</option><option v-for="c in classifications" :key="c.value" :value="c.value">{{ c.label }}</option></select></label><label>Status<select v-model="filters.status"><option value="">All statuses</option><option v-for="s in ['draft','submitted','under_review','approved','rejected','cancelled']" :key="s" :value="s">{{ label(s) }}</option></select></label><label>Term<select v-model="filters.period_id"><option value="">All terms</option><option v-for="p in data.periods" :key="p.id" :value="p.id">{{ termLabel(p) }}</option></select></label><label>Sort<select v-model="filters.sort"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></label><button :disabled="busy" type="submit">Apply filters</button></form>
<div class="en-card en-table"><table><thead><tr v-if="periodsPage"><th>Term</th><th>Window</th><th>State</th><th>Action</th></tr><tr v-else><th>Student</th><th>Course / term</th><th>Classification</th><th>Status</th><th>Action</th></tr></thead><tbody><template v-for="row in rows?.data" :key="row.id"><tr v-if="periodsPage"><td>{{ termLabel(row) }}</td><td>{{ new Date(row.opens_at).toLocaleString() }} – {{ new Date(row.closes_at).toLocaleString() }}</td><td><span class="en-badge" :data-status="row.state">{{ label(row.state) }}</span></td><td><button :disabled="busy" @click="edit(row)">Edit / open / close</button></td></tr><tr v-else><td>{{ row.student?.user_profile?.first_name }} {{ row.student?.user_profile?.last_name }}<br>{{ row.student?.student_number }}</td><td>{{ row.course?.course_name }}<br>{{ termLabel(row.period) }}</td><td><span class="en-badge">{{ label(row.classification) }}</span></td><td><span class="en-badge" :data-status="row.status">{{ label(row.status) }}</span></td><td><button :disabled="busy" @click="inspect(row)">Inspect</button></td></tr></template><tr v-if="!rows?.data?.length"><td :colspan="periodsPage?4:5">No records found.</td></tr></tbody></table></div>
<div class="en-actions"><button :disabled="busy || page<=1" @click="page--;load()">Previous</button><span>Page {{ rows?.current_page }} of {{ rows?.last_page }}</span><button :disabled="busy || page>=rows?.last_page" @click="page++;load()">Next</button></div>
<div v-if="detail && !periodsPage" class="en-card"><h2>Application #{{ detail.id }}</h2><p>{{ detail.student?.user_profile?.first_name }} {{ detail.student?.user_profile?.last_name }} · {{ detail.student?.student_number }}</p><p>{{ detail.course?.course_name }} · {{ detail.curriculum?.curriculum_name }} · Year {{ detail.year_level }}</p><p>{{ termLabel(detail.period) }} · {{ label(detail.classification) }}</p><span class="en-badge" :data-status="detail.status">{{ label(detail.status) }}</span><p>{{ classifications.find(c=>c.value===detail.classification)?.description }}</p><h3>Documents</h3><p v-if="!requirements.length">No additional documents required.</p><div v-for="d in requirements" :key="d.id" class="en-actions"><span>{{ d.name }} — {{ d.attached?'Attached':d.reusable?'Verified existing record':'Missing' }}</span><button v-if="d.document_id" @click="download(d)">Download {{ d.name }}</button></div><label>Review notes<textarea v-model="notes" maxlength="3000" :disabled="busy || !['submitted','under_review'].includes(detail.status)"></textarea></label><p>Approval completes application review. It does not assign subjects, sections, or mark the Student academically enrolled.</p><div class="en-actions"><button v-if="detail.status==='submitted'" :disabled="busy" @click="confirmation='review'">Start Review</button><button v-if="detail.status==='under_review'" :disabled="busy" @click="confirmation='approve'">Approve</button><button v-if="['submitted','under_review'].includes(detail.status)" :disabled="busy || !notes.trim()" @click="confirmation='reject'">Reject</button><button class="en-secondary" :disabled="busy" @click="detail=null">Close detail</button></div></div>
</template>
<EnrollmentDialog v-if="confirmation" labelledby="en-staff-confirm" :busy="busy" @cancel="confirmation=''"><h2 id="en-staff-confirm">Confirm {{ label(confirmation) }}</h2><p>Application #{{ detail.id }} · {{ detail.student?.student_number }} · {{ detail.course?.course_name }}</p><p>{{ notes }}</p><div class="en-actions"><button @click="confirmation=''">Go back</button><button @click="act">Confirm</button></div></EnrollmentDialog>
</template>
