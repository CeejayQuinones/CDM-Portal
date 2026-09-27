<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import EnrollmentDialog from './EnrollmentDialog.vue'
import { apiClient } from '../../services/apiClient'
import { classifications, label, termLabel, safeError } from './enrollment'
const historyPage=ref(1), historyLastPage=ref(1)
const data=ref(null), applications=ref([]), application=ref(null), requirements=ref([]), busy=ref(false), error=ref(''), notice=ref(''), step=ref(0), classification=ref('regular'), confirmation=ref('')
let epoch=0
const messages={ student_record_required:'Your academic Student record is not available. Please contact the Registrar.', profile_reconciliation_required:'Your profile requires Registrar review.', academic_identity_incomplete:'Your academic identity needs completion.', academic_review_required:'Your academic status requires review before enrollment.', course_inactive:'Your program is inactive.', curriculum_invalid:'Your curriculum requires review.', admission_conversion_incomplete:'Admission conversion must be completed or reconciled.', enrollment_period_unavailable:'No enrollment period is available yet.', enrollment_period_closed:'The enrollment period is not open.', term_unavailable:'The academic term is unavailable.', term_enrollment_exists:'An academic enrollment already exists for this term.' }
const current= computed(()=>data.value?.current_application || applications.value.find(a=>a.period_id===data.value?.period?.id))
const selected= computed(()=>classifications.find(c=>c.value===classification.value))
async function load() {
 const v=++epoch; busy.value=true; error.value=''
 try { const [status,list]=await Promise.all([apiClient.get('/enrollment/status'),apiClient.get('/enrollment/applications/mine',{params:{page:historyPage.value}})]); if(v!==epoch)return; data.value=status.data.data;applications.value=list.data.data.data;historyLastPage.value=list.data.data.last_page; }
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function open(a) {
 const v=++epoch;busy.value=true;error.value=''
 try {const r=await apiClient.get(`/enrollment/applications/${a.id}`);if(v!==epoch)return;application.value=r.data.data.application;requirements.value=r.data.data.requirements;classification.value=application.value.classification;step.value=application.value.status==='draft'?0:4}
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function create() {
 if(busy.value)return;const v=++epoch;busy.value=true;error.value=''
 try { const r=await apiClient.post('/enrollment/applications',{period_id:data.value.period.id,classification:classification.value});if(v!==epoch)return;await open(r.data.data);notice.value='Draft created. Your existing academic identity is reused.' }
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function act(action) {
 if(busy.value)return;const v=++epoch;busy.value=true;error.value='';confirmation.value=''
 try {const r=await apiClient.post(`/enrollment/applications/${application.value.id}/${action}`,{version:application.value.version,classification:classification.value,confirmed:true});if(v!==epoch)return;application.value={...application.value,...r.data.data};notice.value=action==='save'?'Draft saved.':`Application ${label(application.value.status).toLowerCase()}.`;if(action!=='save')step.value=4;else {const detail=await apiClient.get(`/enrollment/applications/${application.value.id}`);if(v===epoch)requirements.value=detail.data.data.requirements} }
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function upload(type,event) {
 const file=event.target.files?.[0];if(!file||busy.value)return;const v=++epoch;busy.value=true;error.value=''
 const form=new FormData();form.append('file',file);form.append('version',application.value.version);form.append('document_type_id',type)
 try {await apiClient.post(`/enrollment/applications/${application.value.id}/documents`,form,{headers:{'Content-Type':'multipart/form-data'}});if(v!==epoch)return;await open(application.value);step.value=3}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function backToLanding(){application.value=null;step.value=0;await load()}
onMounted(load);onBeforeUnmount(()=>{epoch++})
</script>
<template>
<header class="page-header"><p class="page-kicker">Enrollment</p><h1 class="page-title">Your enrollment application</h1><p class="page-description">Confirm your academic information, choose your classification, and submit for review.</p></header>
<p v-if="error" class="en-card" role="alert">{{ error }} <button :disabled="busy" @click="application ? open(application) : load()">Reload</button></p>
<p v-if="notice" role="status">{{ notice }}</p><p v-if="busy" role="status">Loading…</p>
<template v-if="data">
<div class="en-card"><h2>{{ data.academic_ready ? 'Academic record ready' : 'Academic review needed' }}</h2><p v-if="data.reason">{{ messages[data.reason] || 'Please contact the Registrar.' }}</p><template v-if="data.period"><h3>{{ termLabel(data.period) }}</h3><span class="en-badge" :data-status="data.period.state">{{ label(data.period.state) }}</span><p>{{ new Date(data.period.opens_at).toLocaleString() }} – {{ new Date(data.period.closes_at).toLocaleString() }}</p></template></div>
<div v-if="data.student" class="en-card"><h2>{{ data.student.name }}</h2><dl class="en-grid"><div><dt>Student number</dt><dd>{{ data.student.student_number }}</dd></div><div><dt>Course</dt><dd>{{ data.student.course.course_name }}</dd></div><div><dt>Curriculum</dt><dd>{{ data.student.curriculum.curriculum_name }}</dd></div><div><dt>Year level</dt><dd>{{ data.student.year_level }}</dd></div></dl></div>
<template v-if="!application">
<div class="en-actions"><button v-if="current" :disabled="busy" @click="open(current)">View current application</button><button v-else-if="data.eligible" :disabled="busy" @click="create">Start enrollment application</button></div>
<div class="en-card"><h2>Application history</h2><p v-if="!applications.length">No enrollment application yet.</p><div v-for="a in applications" :key="a.id" class="en-actions"><span>{{ termLabel(a.period) }} · {{ label(a.classification) }}</span><span class="en-badge" :data-status="a.status">{{ label(a.status) }}</span><button :disabled="busy" @click="open(a)">View application</button></div><div v-if="historyLastPage>1" class="en-actions"><button :disabled="busy||historyPage<=1" @click="historyPage--;load()">Previous</button><span>Page {{ historyPage }} of {{ historyLastPage }}</span><button :disabled="busy||historyPage>=historyLastPage" @click="historyPage++;load()">Next</button></div></div>
</template>
<template v-else>
<div class="en-actions"><button class="en-secondary" :disabled="busy" @click="backToLanding">All applications</button><span class="en-badge" :data-status="application.status">{{ label(application.status) }}</span></div>
<template v-if="application.status==='draft'">
<ol class="en-progress" aria-label="Application progress"><li v-for="(title,i) in ['Academic information','Classification','Academic path','Review']" :key="title" :aria-current="step===i?'step':undefined">{{ i+1 }}. {{ title }}</li></ol>
<div class="en-card" v-if="step===0"><h2>Confirm your academic information</h2><p>Your Student number, course, curriculum and year level are shown above. Contact the Registrar if corrections are needed.</p></div>
<div class="en-card" v-if="step===1"><h2>Choose your classification</h2><div class="en-grid"><label v-for="c in classifications" :key="c.value" class="en-card"><span class="en-check"><input type="radio" v-model="classification" :value="c.value" :disabled="busy" name="classification"> {{ c.label }}</span><span>{{ c.description }}</span></label></div></div>
<div class="en-card" v-if="step===2"><h2>Academic path</h2><h3>{{ selected.label }}</h3><p>{{ selected.description }}</p><p>Approval confirms your application. Subject selection, section assignment, schedules and COR follow during academic finalization.</p></div>
<div class="en-card" v-if="step===3"><h2>Review your application</h2><p>{{ data.student?.name }} · {{ data.student?.student_number }}</p><p>{{ termLabel(application.period || data.period) }} · {{ label(classification) }}</p><p>{{ data.student?.course.course_name }} · {{ data.student?.curriculum.curriculum_name }}</p><p v-if="classification!==application.classification">Save your classification to refresh document requirements before submitting.</p><h3>Required documents</h3><p v-if="!requirements.length">No additional documents are required for this classification in this period.</p><div v-for="d in requirements" :key="d.id"><p>{{ d.name }} — {{ d.attached ? 'Attached' : d.reusable ? 'Verified existing document will be reused' : 'Upload required' }}</p><label v-if="!d.attached && !d.reusable">{{ d.name }} (PDF, JPEG or PNG; up to 10 MB)<input type="file" accept=".pdf,.jpg,.jpeg,.png" :disabled="busy || classification!==application.classification" @change="upload(d.id,$event)"></label></div></div>
<div class="en-actions"><button class="en-secondary" :disabled="busy||step===0" @click="step--">Back</button><button v-if="step<3" :disabled="busy" @click="step++">Continue</button><button :disabled="busy" @click="act('save')">Save draft</button><button v-if="step===3" :disabled="busy || classification!==application.classification || !data.eligible || requirements.some(d=>!d.attached&&!d.reusable)" @click="confirmation='submit'">Submit application</button><button class="en-secondary" :disabled="busy" @click="confirmation='cancel'">Cancel draft</button></div>
</template>
<div v-else class="en-card"><h2>{{ label(application.status) }}</h2><p>{{ label(application.classification) }} · {{ termLabel(application.period || data.period) }}</p><p v-if="application.status==='submitted'">Your application is awaiting staff review.</p><p v-if="application.status==='under_review'">Your application is being reviewed.</p><p v-if="application.status==='approved'">Your application is approved. Academic enrollment and subject assignment are still pending finalization.</p><p v-if="application.status==='rejected'">Your application was rejected. Contact the Registrar for guidance.</p><p v-if="application.review_notes">Registrar notes: {{ application.review_notes }}</p></div>
</template>
</template>
<EnrollmentDialog v-if="confirmation" labelledby="en-confirm" :busy="busy" @cancel="confirmation=''"><h2 id="en-confirm">{{ confirmation==='submit'?'Submit application?':'Cancel draft?' }}</h2><p>{{ data.student?.name }} · {{ data.student?.student_number }} · {{ label(classification) }}</p><p>{{ confirmation==='submit'?'Your application will be sent for review and can no longer be edited.':'This draft will be closed. Contact the Registrar before applying again for this term.' }}</p><div class="en-actions"><button @click="confirmation=''">Go back</button><button @click="act(confirmation)">Confirm {{ confirmation }}</button></div></EnrollmentDialog>
</template>
