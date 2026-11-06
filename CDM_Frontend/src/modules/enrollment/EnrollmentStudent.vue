<script setup>
import { computed, onMounted, onBeforeUnmount, reactive, ref } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import EnrollmentNotices from './EnrollmentNotices.vue'
import AcademicPageHeader from '../../components/AcademicPageHeader.vue'
import EnrollmentDialog from './EnrollmentDialog.vue'
import { apiClient } from '../../services/apiClient'
import { clientPlatform } from '../../config/clientPlatform'
import { classifications, label, termLabel, safeError } from './enrollment'
import WebsiteEmptyState from '../../components/website/WebsiteEmptyState.vue'
import WebsiteLoadingState from '../../components/website/WebsiteLoadingState.vue'
import WebsiteModuleHeader from '../../components/website/WebsiteModuleHeader.vue'
import WebsiteStatusBadge from '../../components/website/WebsiteStatusBadge.vue'
import WebsiteStepper from '../../components/website/WebsiteStepper.vue'
import WebsiteAcademicJourneyNav from '../../components/website/WebsiteAcademicJourneyNav.vue'
import WebsiteAcademicRecordCard from '../../components/website/WebsiteAcademicRecordCard.vue'
const isWebsitePresentation=clientPlatform==='web'
const enrollmentSteps=isWebsitePresentation?['Student info','Enrollment','Course']:['Academic information','Classification','Academic path','Review']
const finalDraftStep=isWebsitePresentation?2:3
const historyPage=ref(1), historyLastPage=ref(1)
const data=ref(null), applications=ref([]), application=ref(null), requirements=ref([]), busy=ref(false), error=ref(''), notice=ref(''), step=ref(0), classification=ref('regular'), confirmation=ref('')
const detailsForm=ref(null), savedDetails=ref(''), previewUrls=ref({}), previewStates=ref({}), uploadStates=ref({}), previewDocument=ref(null), fileInputs=ref({})
const personalDetails=reactive({first_name:'',middle_name:'',last_name:'',birth_date:'',gender:'',contact_number:'',email:'',address:''})
let epoch=0
const messages={ student_identity_pending:'Admission is complete. Your Student identity and official student number will be created during Enrollment finalization in Phase 2.', student_record_required:'Your academic Student record is not available. Please contact the Registrar.', profile_reconciliation_required:'Your profile requires Registrar review.', academic_identity_incomplete:'Your academic identity needs completion.', academic_review_required:'Your academic status requires review before enrollment.', course_inactive:'Your program is inactive.', curriculum_invalid:'Your curriculum requires review.', admission_conversion_incomplete:'Admission conversion must be completed or reconciled.', enrollment_period_unavailable:'No enrollment period is available yet.', enrollment_period_closed:'The enrollment period is not open.', term_unavailable:'The academic term is unavailable.', term_enrollment_exists:'An academic enrollment already exists for this term.' }
const current= computed(()=>data.value?.current_application || applications.value.find(a=>a.status!=='cancelled' && a.period_id===data.value?.period?.id))
const serializedDetails=()=>JSON.stringify(personalDetails)
const hasUnsavedClassification=computed(()=>application.value?.status==='draft' && classification.value!==application.value.classification)
const hasUnsavedDetails=computed(()=>application.value?.status==='draft' && savedDetails.value && serializedDetails()!==savedDetails.value)
const hasUnsavedDraft=computed(()=>hasUnsavedClassification.value||hasUnsavedDetails.value)
const selected= computed(()=>classifications.find(c=>c.value===classification.value))
const applicantName=computed(()=>data.value?.student?.name || data.value?.admission?.name || 'Applicant')
const applicantIdentity=computed(()=>data.value?.student?.student_number || data.value?.admission?.applicant_number || 'Student number pending')
const applicantCourse=computed(()=>data.value?.student?.course || data.value?.admission?.course)
const applicantCurriculum=computed(()=>data.value?.student?.curriculum || data.value?.admission?.curriculum)
const age=computed(()=>{const value=personalDetails.birth_date;if(!value)return null;const birth=new Date(`${value}T00:00:00`);if(Number.isNaN(birth.getTime()))return null;const today=new Date();let years=today.getFullYear()-birth.getFullYear();if(today.getMonth()<birth.getMonth()||(today.getMonth()===birth.getMonth()&&today.getDate()<birth.getDate()))years--;return years>=0?years:null})
function revokePreviews(){Object.values(previewUrls.value).forEach(url=>globalThis.URL?.revokeObjectURL?.(url));previewUrls.value={}}
async function loadPreview(document){
 if(!document?.attached||!document.preview_url)return
 const applicationId=application.value?.id;previewStates.value={...previewStates.value,[document.id]:{loading:true,error:''}}
 const previous=previewUrls.value[document.id]
 try{const response=await apiClient.get(document.preview_url,{responseType:'blob'});if(application.value?.id!==applicationId)return;const BlobType=globalThis.Blob;if(!BlobType)throw new Error('This device does not support private previews.');const blob=response.data instanceof BlobType?response.data:new BlobType([response.data],{type:document.mime_type||''});if(!blob.size)throw new Error('The saved document is empty.');if(document.preview_kind==='image'&&!String(blob.type||document.mime_type).startsWith('image/'))throw new Error('The saved file is not a supported image.');const url=globalThis.URL?.createObjectURL?.(blob);if(!url)throw new Error('This device could not create a private preview.');if(previous)globalThis.URL?.revokeObjectURL?.(previous);previewUrls.value={...previewUrls.value,[document.id]:url};previewStates.value={...previewStates.value,[document.id]:{loading:false,error:''}}
 }catch(e){if(application.value?.id!==applicationId)return;const message=e?.response?safeError(e):(e?.message||'Preview unavailable.');previewStates.value={...previewStates.value,[document.id]:{loading:false,error:message}}}
}
async function loadPreviews(items){revokePreviews();previewStates.value={};await Promise.all(items.filter(d=>d.attached&&d.preview_url&&['image','pdf'].includes(d.preview_kind)).map(loadPreview))}
function handlePreviewImageError(document){const url=previewUrls.value[document.id];if(url)globalThis.URL?.revokeObjectURL?.(url);const next={...previewUrls.value};delete next[document.id];previewUrls.value=next;previewStates.value={...previewStates.value,[document.id]:{loading:false,error:'The saved image could not be displayed. Retry the private preview.'}}}
function setFileInput(id,element){if(element)fileInputs.value[id]=element;else delete fileInputs.value[id]}
function activateDocument(document){if(document.reusable||busy.value||classification.value!==application.value?.classification)return;fileInputs.value[document.id]?.click?.()}
function hydrateDetails(value){const source=value||{};Object.keys(personalDetails).forEach(key=>{personalDetails[key]=source[key]??''});savedDetails.value=serializedDetails()}
async function load() {
 const v=++epoch; busy.value=true; error.value=''
 try { const [status,list]=await Promise.all([apiClient.get('/enrollment/status'),apiClient.get('/enrollment/applications/mine',{params:{page:historyPage.value}})]); if(v!==epoch)return; data.value=status.data.data;applications.value=list.data.data.data;historyLastPage.value=list.data.data.last_page; }
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function open(a) {
 const v=++epoch;busy.value=true;error.value=''
 try {const r=await apiClient.get(`/enrollment/applications/${a.id}`);if(v!==epoch)return;application.value=r.data.data.application;requirements.value=r.data.data.requirements;classification.value=application.value.classification;hydrateDetails(application.value.personal_details);await loadPreviews(requirements.value);step.value=application.value.status==='draft'?0:finalDraftStep+1}
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function create() {
 if(busy.value)return;const v=++epoch;busy.value=true;error.value=''
 try { const r=await apiClient.post('/enrollment/applications',{period_id:data.value.period.id,classification:classification.value});if(v!==epoch)return;const [status,list]=await Promise.all([apiClient.get('/enrollment/status'),apiClient.get('/enrollment/applications/mine',{params:{page:historyPage.value}})]);if(v!==epoch)return;data.value=status.data.data;applications.value=list.data.data.data;historyLastPage.value=list.data.data.last_page;await open(r.data.data);notice.value=data.value.admitted_guest?'Draft created. Your Admission identity is linked.':'Draft created. Your existing academic identity is reused.' }
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function act(action) {
 if(action==='save'&&detailsForm.value&&typeof detailsForm.value.reportValidity==='function'&&!detailsForm.value.reportValidity())return false
 if(busy.value)return false;const v=++epoch;busy.value=true;error.value='';confirmation.value=''
 try {const r=await apiClient.post(`/enrollment/applications/${application.value.id}/${action}`,{version:application.value.version,classification:classification.value,personal_details:{...personalDetails},confirmed:true});if(v!==epoch)return false;application.value={...application.value,...r.data.data};if(action==='save')hydrateDetails(application.value.personal_details);notice.value=action==='save'?'Draft saved.':`Application ${label(application.value.status).toLowerCase()}.`;if(action==='cancel'){revokePreviews();application.value=null;step.value=0;await load()}else if(action!=='save')step.value=finalDraftStep+1;else {const detail=await apiClient.get(`/enrollment/applications/${application.value.id}`);if(v===epoch){requirements.value=detail.data.data.requirements;await loadPreviews(requirements.value)}}return true }
 catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}
}
async function upload(type,event) {
 const file=event.target.files?.[0];if(!file||busy.value)return;const v=++epoch;busy.value=true;error.value=''
 const oldState=uploadStates.value[type]||{};const localUrl=file.type.startsWith('image/')&&globalThis.URL?.createObjectURL?globalThis.URL.createObjectURL(file):null;uploadStates.value={...uploadStates.value,[type]:{uploading:true,progress:0,localUrl,error:''}}
 const form=new FormData();form.append('file',file);form.append('version',application.value.version);form.append('document_type_id',type)
 try {await apiClient.post(`/enrollment/applications/${application.value.id}/documents`,form,{headers:{'Content-Type':'multipart/form-data'},onUploadProgress:p=>{const total=p.total||file.size;uploadStates.value={...uploadStates.value,[type]:{...uploadStates.value[type],progress:total?Math.round(p.loaded/total*100):0}}}});if(v!==epoch)return;await open(application.value);if(localUrl)globalThis.URL?.revokeObjectURL?.(localUrl);uploadStates.value={...uploadStates.value,[type]:{}};step.value=0;notice.value='Document uploaded successfully.'}catch(e){if(localUrl)globalThis.URL?.revokeObjectURL?.(localUrl);uploadStates.value={...uploadStates.value,[type]:{...oldState,error:safeError(e)}};if(v===epoch)error.value=safeError(e)}finally{event.target.value='';if(v===epoch){busy.value=false;uploadStates.value={...uploadStates.value,[type]:{...uploadStates.value[type],uploading:false}}}}
}
async function continueStep(){if(step.value===0){const saved=await act('save');if(saved)step.value++}else step.value++}
function showPreview(document){if(previewUrls.value[document.id])previewDocument.value=document}
function confirmDraftLeave(){return !hasUnsavedDraft.value || globalThis.window?.confirm?.('Leave without saving your enrollment draft changes?')!==false}
function handleBeforeUnload(event){if(!hasUnsavedDraft.value)return;event.preventDefault();event.returnValue=''}
async function backToLanding(){if(!confirmDraftLeave())return;application.value=null;step.value=0;await load()}
onBeforeRouteLeave(()=>confirmDraftLeave())
onMounted(()=>{globalThis.window?.addEventListener?.('beforeunload',handleBeforeUnload);load()});onBeforeUnmount(()=>{epoch++;revokePreviews();Object.values(uploadStates.value).forEach(state=>state.localUrl&&globalThis.URL?.revokeObjectURL?.(state.localUrl));globalThis.window?.removeEventListener?.('beforeunload',handleBeforeUnload)})
</script>
<template>
<EnrollmentNotices/>
<WebsiteModuleHeader v-if="isWebsitePresentation" class="enrollment-reference-header" eyebrow="Qualification phase" title="Student Enrollment" description="Review your verified academic identity, choose the correct enrollment classification, and submit the configured requirements for the active term." />
<WebsiteAcademicJourneyNav v-if="isWebsitePresentation" />
<AcademicPageHeader v-else eyebrow="Enrollment" title="Your enrollment application" description="Confirm your academic information, choose your classification, and submit for review." />
<p v-if="error" class="en-card" role="alert">{{ error }} <button :disabled="busy" @click="application ? open(application) : load()">Reload</button></p>
<p v-if="notice" role="status">{{ notice }}</p><WebsiteLoadingState v-if="isWebsitePresentation && busy && !data" label="Loading enrollment workspace"/><p v-else-if="busy" role="status">Loading…</p>
<template v-if="data">
<div class="en-card enrollment-status-card enrollment-reference-status"><div><small>Enrollment eligibility</small><h2>{{ data.admitted_guest ? 'Eligible for Enrollment' : data.academic_ready ? 'Academic record ready' : 'Academic review needed' }}</h2><p v-if="data.reason">{{ messages[data.reason] || 'Please contact the Registrar.' }}</p></div><template v-if="data.period"><div class="enrollment-reference-term"><small>Active academic term</small><h3>{{ termLabel(data.period) }}</h3><WebsiteStatusBadge v-if="isWebsitePresentation" :status="data.period.state"/><span v-else class="en-badge" :data-status="data.period.state">{{ label(data.period.state) }}</span><p>{{ new Date(data.period.opens_at).toLocaleString() }} – {{ new Date(data.period.closes_at).toLocaleString() }}</p></div></template></div>
<div v-if="data.admitted_guest" class="en-card"><h2>{{ data.admission.name }}</h2><dl class="en-grid"><div><dt>Applicant number</dt><dd>{{ data.admission.applicant_number }}</dd></div><div><dt>Admitted program</dt><dd>{{ data.admission.course.course_name }}</dd></div><div><dt>Curriculum</dt><dd>{{ data.admission.curriculum.curriculum_name }}</dd></div><div><dt>Current role</dt><dd>Guest</dd></div><div><dt>Student identity</dt><dd>Not created yet</dd></div></dl></div>
<div v-if="data.student" class="en-card"><h2>{{ data.student.name }}</h2><dl class="en-grid"><div><dt>Student number</dt><dd>{{ data.student.student_number }}</dd></div><div><dt>Course</dt><dd>{{ data.student.course.course_name }}</dd></div><div><dt>Curriculum</dt><dd>{{ data.student.curriculum.curriculum_name }}</dd></div><div><dt>Year level</dt><dd>{{ data.student.year_level }}</dd></div></dl></div>
<template v-if="!application">
<div class="en-actions enrollment-next-action"><div v-if="isWebsitePresentation" class="enrollment-next-copy"><small>Next action</small><strong>{{ current ? current.status === 'draft' ? 'Continue your draft application' : 'Review your current application status' : data.eligible && data.applications_enabled ? 'Start a new enrollment application' : 'A new application can be started when an authorized enrollment period opens' }}</strong><span v-if="!current && (!data.eligible || !data.applications_enabled)">Your application history remains available below.</span></div><button v-if="current" :disabled="busy" @click="open(current)">View current application</button><button v-else-if="data.eligible && data.applications_enabled" :disabled="busy" @click="create">Start New Enrollment Application</button></div>
<div class="en-card enrollment-history"><h2>Application history</h2><WebsiteEmptyState v-if="isWebsitePresentation && !applications.length" title="No enrollment applications" message="When an eligible enrollment period opens, your application will appear here."/><p v-else-if="!applications.length">No enrollment application yet.</p><template v-for="a in applications" :key="a.id"><WebsiteAcademicRecordCard v-if="isWebsitePresentation" eyebrow="Enrollment application" :title="termLabel(a.period)" :detail="label(a.classification)" :status="a.status" action-label="View application" :disabled="busy" @open="open(a)"/><div v-else class="en-actions enrollment-history-row"><span>{{ termLabel(a.period) }} · {{ label(a.classification) }}</span><span class="en-badge" :data-status="a.status">{{ label(a.status) }}</span><button :disabled="busy" @click="open(a)">View application</button></div></template><div v-if="historyLastPage>1" class="en-actions"><button :disabled="busy||historyPage<=1" @click="historyPage--;load()">Previous</button><span>Page {{ historyPage }} of {{ historyLastPage }}</span><button :disabled="busy||historyPage>=historyLastPage" @click="historyPage++;load()">Next</button></div></div>
</template>
<template v-else>
<div class="en-actions"><button class="en-secondary" :disabled="busy" @click="backToLanding">All applications</button><WebsiteStatusBadge v-if="isWebsitePresentation" :status="application.status"/><span v-else class="en-badge" :data-status="application.status">{{ label(application.status) }}</span></div>
<template v-if="application.status==='draft'">
<header v-if="isWebsitePresentation" class="enrollment-reference-draft-heading"><div><small>Qualification phase</small><h2>Enrollment application</h2><p>Complete each verified section before sending the application to the Registrar.</p></div><strong>Step {{ step+1 }} of {{ enrollmentSteps.length }}</strong></header>
<WebsiteStepper v-if="isWebsitePresentation" class="enrollment-reference-stepper" :steps="enrollmentSteps" :current-index="step" style="--step-count:3"/>
<ol v-else class="en-progress" aria-label="Application progress"><li v-for="(title,i) in enrollmentSteps" :key="title" :aria-current="step===i?'step':undefined">{{ i+1 }}. {{ title }}</li></ol>
<div class="en-card enrollment-reference-stage enrollment-details-form" v-if="step===0">
  <div class="enrollment-reference-stage-title"><span aria-hidden="true">01</span><div><small>Student info</small><h2>Personal details</h2></div></div>
  <p>These details belong only to this Enrollment draft. Saving them does not overwrite your account profile or Admission record.</p>
  <form ref="detailsForm" class="enrollment-personal-form" @submit.prevent="act('save')">
    <label>First name <span aria-hidden="true">*</span><input v-model.trim="personalDetails.first_name" required maxlength="100" autocomplete="given-name"></label>
    <label>Middle name <small>Optional</small><input v-model.trim="personalDetails.middle_name" maxlength="100" autocomplete="additional-name"></label>
    <label>Last name <span aria-hidden="true">*</span><input v-model.trim="personalDetails.last_name" required maxlength="100" autocomplete="family-name"></label>
    <label>Birth date <span aria-hidden="true">*</span><input v-model="personalDetails.birth_date" required type="date" autocomplete="bday"></label>
    <label>Age <small>Derived</small><output>{{ age ?? 'Enter a valid birth date' }}</output></label>
    <label>Gender <span aria-hidden="true">*</span><select v-model="personalDetails.gender" required><option disabled value="">Select gender</option><option>Male</option><option>Female</option><option>Prefer not to say</option></select></label>
    <label>Contact number <span aria-hidden="true">*</span><input v-model.trim="personalDetails.contact_number" required maxlength="20" autocomplete="tel"></label>
    <label>Email <span aria-hidden="true">*</span><input v-model.trim="personalDetails.email" required type="email" maxlength="255" autocomplete="email"></label>
    <label class="enrollment-personal-form-wide">Full address <span aria-hidden="true">*</span><textarea v-model.trim="personalDetails.address" required maxlength="1000" rows="3" autocomplete="street-address"></textarea><small>The current account contract stores one complete address; it does not support separate province, city, or barangay fields.</small></label>
  </form>
  <aside class="enrollment-reference-note"><strong>Academic identity remains authoritative</strong><p>{{ applicantName }} · {{ applicantIdentity }} · {{ termLabel(application.period || data.period) }} · {{ data.student?.year_level ? `Year level ${data.student.year_level}` : 'First year after finalization' }}</p></aside>
  <div class="enrollment-reference-requirements-heading"><div><small>Document requirements</small><h3>Enrollment uploads</h3></div><span>PDF, JPEG or PNG · up to 10 MB</span></div>
  <p>Uploads are stored privately and can be viewed only through your authenticated Enrollment application.</p>
  <p v-if="!requirements.length" class="enrollment-reference-empty">No documents are configured for this classification in this period.</p>
  <div v-else class="enrollment-reference-documents enrollment-document-gallery">
    <article v-for="d in requirements" :key="d.id" class="enrollment-reference-document enrollment-document-card" :data-ready="d.attached||d.reusable">
      <div><strong>{{ d.name }}</strong><em>{{ d.required ? 'Required' : 'Optional' }}</em></div>
      <div class="enrollment-document-upload-shell">
        <button type="button" class="enrollment-document-preview" :class="{empty:!d.attached&&!d.reusable,failed:previewStates[d.id]?.error}" :disabled="d.reusable || busy || classification!==application.classification" :aria-label="d.attached ? `Replace ${d.name}` : `Add ${d.name}`" @click="activateDocument(d)">
          <img v-if="uploadStates[d.id]?.localUrl || (d.preview_kind==='image'&&previewUrls[d.id])" :src="uploadStates[d.id]?.localUrl || previewUrls[d.id]" :alt="`Preview of ${d.name}`" @error="handlePreviewImageError(d)">
          <span v-else-if="previewStates[d.id]?.loading" class="enrollment-document-prompt"><span aria-hidden="true">↻</span><strong>Loading preview…</strong></span>
          <span v-else-if="previewStates[d.id]?.error" class="enrollment-document-prompt"><span aria-hidden="true">!</span><strong>Preview unavailable</strong></span>
          <span v-else-if="d.preview_kind==='pdf'&&d.attached" class="enrollment-document-prompt"><span aria-hidden="true">PDF</span><strong>View saved document</strong></span>
          <span v-else-if="d.reusable" class="enrollment-document-prompt"><span aria-hidden="true">✓</span><strong>Verified document</strong></span>
          <span v-else class="enrollment-document-prompt"><span aria-hidden="true">↑</span><strong>Add Document</strong></span>
          <span v-if="!d.reusable" class="enrollment-document-replace"><span aria-hidden="true">↑</span>{{ d.attached ? (d.preview_kind==='image' ? 'Replace Image' : 'Replace Document') : 'Add Document' }}</span>
          <span v-if="d.attached&&!d.reusable" class="enrollment-document-mobile-edit" aria-hidden="true">✎</span>
        </button>
        <input :ref="element=>setFileInput(d.id,element)" class="enrollment-document-file-input" type="file" accept=".pdf,.jpg,.jpeg,.png" :disabled="busy || classification!==application.classification" :aria-label="d.attached ? `Replace ${d.name}` : `Add ${d.name}`" @change="upload(d.id,$event)">
        <button v-if="d.attached&&previewUrls[d.id]" type="button" class="enrollment-document-view" :aria-label="d.preview_kind==='pdf' ? `View ${d.name} document` : `View full ${d.name} image`" title="View saved document" @click.stop="showPreview(d)">⌕</button>
        <button v-else-if="d.attached&&previewStates[d.id]?.error" type="button" class="enrollment-document-retry" :disabled="previewStates[d.id]?.loading" :aria-label="`Retry ${d.name} preview`" @click.stop="loadPreview(d)">Retry preview</button>
      </div>
      <p>{{ d.filename || (d.reusable ? 'Verified existing document will be reused' : d.required ? 'Upload required before submission' : 'No file supplied') }}</p>
      <span v-if="d.attached&&!previewStates[d.id]?.error" class="enrollment-reference-document-ready">Uploaded / Ready</span>
      <span v-else-if="d.attached" class="enrollment-document-error" role="status">Saved document · Preview unavailable</span>
      <progress v-if="uploadStates[d.id]?.uploading" :value="uploadStates[d.id].progress" max="100">{{ uploadStates[d.id].progress }}%</progress>
      <p v-if="uploadStates[d.id]?.error" class="enrollment-document-error" role="alert">{{ uploadStates[d.id].error }}</p>
      <span v-if="d.reusable" class="enrollment-reference-document-ready">Verified and ready</span>
    </article>
  </div>
</div>
<div class="en-card enrollment-reference-stage" v-if="step===1"><div class="enrollment-reference-stage-title centered"><span aria-hidden="true">02</span><div><small>Enrollment</small><h2>Choose your classification</h2></div></div><div class="en-grid enrollment-reference-classifications"><label v-for="(c,index) in classifications" :key="c.value" class="enrollment-reference-choice" :class="{selected:classification===c.value}"><input type="radio" v-model="classification" :value="c.value" :disabled="busy" name="classification"><span class="enrollment-reference-choice-mark">{{ index+1 }}</span><strong>{{ c.label }}</strong><span>{{ c.description }}</span><em v-if="classification===c.value">Selected</em></label></div><aside class="enrollment-reference-note"><strong>Important note</strong><p>Select the classification that matches your current academic standing. Save the draft after changing it so the Registrar-configured document requirements can refresh.</p></aside></div>
<div class="en-card enrollment-reference-stage" v-if="isWebsitePresentation && step===2"><div class="enrollment-reference-stage-title"><span aria-hidden="true">03</span><div><small>Course</small><h2>Academic path and review</h2></div></div><div class="enrollment-reference-program-choice" role="group" aria-label="Verified primary program"><div class="enrollment-reference-program selected"><span>Primary program</span><strong>{{ applicantCourse?.course_name || 'Program pending verification' }}</strong><small>{{ applicantCourse?.course_code || 'No program code available' }}</small><em>Verified selection</em></div><div class="enrollment-reference-program unavailable"><span>Secondary program</span><strong>Not available in Enrollment</strong><small>CDM Portal has no approved secondary-choice enrollment contract.</small></div></div><div class="enrollment-reference-path"><div><small>Curriculum</small><strong>{{ applicantCurriculum?.curriculum_name || 'Curriculum pending' }}</strong><span>{{ applicantCurriculum?.curriculum_code || 'No curriculum code available' }}</span></div><div><small>Year level</small><strong>{{ data.student?.year_level || application.year_level || 1 }}</strong><span>Authoritative academic record</span></div><div><small>Enrollment type</small><strong>{{ selected.label }}</strong><span>{{ selected.description }}</span></div></div><aside class="enrollment-reference-note"><strong>Your program is already verified</strong><p>The collaborators’ first-year flow allows primary and secondary program choices. CDM Portal preserves the program accepted through Admission or assigned in Student Records; it does not expose an unsupported choice or capacity value.</p></aside><div class="enrollment-reference-review-heading"><small>Final review</small><h3>Confirm before submission</h3></div><p class="enrollment-reference-review-lead">{{ applicantName }} · {{ applicantIdentity }}</p><div class="enrollment-reference-review-grid"><div><dt>Academic term</dt><dd>{{ termLabel(application.period || data.period) }}</dd></div><div><dt>Program</dt><dd>{{ applicantCourse?.course_name || 'Pending verified program' }}</dd><small>{{ applicantCurriculum?.curriculum_name || 'Pending verified curriculum' }}</small></div><div><dt>Enrollment type</dt><dd>{{ label(classification) }}</dd></div><div><dt>Documents</dt><dd>{{ requirements.filter(d=>d.attached||d.reusable).length }} of {{ requirements.length }} ready</dd></div></div><p v-if="classification!==application.classification" class="enrollment-reference-warning" role="alert">Save your classification, then return to Student info to review the refreshed document requirements before submitting.</p><p v-else-if="requirements.some(d=>d.required&&!d.attached&&!d.reusable)" class="enrollment-reference-warning" role="alert">Return to Student info and provide every required configured document before submitting.</p></div>
<div class="en-card" v-if="!isWebsitePresentation && step===2"><h2>Academic path</h2><p>{{ applicantCourse?.course_name }} · {{ applicantCurriculum?.curriculum_name }} · {{ selected.label }}</p></div>
<div class="en-card" v-if="!isWebsitePresentation && step===3"><h2>Review your application</h2><p>{{ applicantName }} · {{ applicantIdentity }} · {{ termLabel(application.period || data.period) }} · {{ label(classification) }}</p><p v-if="classification!==application.classification" role="alert">Save your classification to refresh document requirements before submitting.</p><div v-for="d in requirements" :key="d.id"><strong>{{ d.name }}</strong> — {{ d.attached?'Attached':d.reusable?'Verified existing document':d.required?'Required':'Optional' }}<label v-if="!d.attached&&!d.reusable">Choose document<input type="file" accept=".pdf,.jpg,.jpeg,.png" :disabled="busy||classification!==application.classification" @change="upload(d.id,$event)"></label></div></div>
<div class="en-actions enrollment-reference-footer"><button class="en-secondary" :disabled="busy||step===0" @click="step--">Previous Step</button><button v-if="step<finalDraftStep" :disabled="busy" @click="continueStep">Continue</button><button :disabled="busy" @click="act('save')">Save draft</button><button v-if="step===finalDraftStep" :disabled="busy || hasUnsavedDraft || !data.eligible || requirements.some(d=>d.required&&!d.attached&&!d.reusable)" @click="confirmation='submit'">Submit application</button><button class="en-secondary" :disabled="busy" @click="confirmation='cancel'">Cancel Application</button></div>
</template>
<div v-else class="en-card"><h2>{{ label(application.status) }}</h2><p>{{ label(application.classification) }} · {{ termLabel(application.period || data.period) }}</p><p v-if="application.status==='submitted'">Your application is awaiting staff review.</p><p v-if="application.status==='under_review'">Your application is being reviewed.</p><p v-if="application.status==='approved'">Your application is approved. Academic enrollment and subject assignment are still pending finalization. Open Subjects to review your load.</p><p v-if="application.status==='rejected'">Your application was rejected. Contact the Registrar for guidance.</p><p v-if="application.review_notes">Registrar notes: {{ application.review_notes }}</p></div>
</template>
</template>
<EnrollmentDialog v-if="previewDocument" labelledby="en-document-preview" :busy="false" @cancel="previewDocument=null"><h2 id="en-document-preview">{{ previewDocument.name }}</h2><img v-if="previewDocument.preview_kind==='image'" class="enrollment-full-preview" :src="previewUrls[previewDocument.id]" :alt="`Full preview of ${previewDocument.name}`"><iframe v-else-if="previewDocument.preview_kind==='pdf'" class="enrollment-pdf-preview" :src="previewUrls[previewDocument.id]" :title="previewDocument.name"></iframe><div class="en-actions"><button type="button" @click="previewDocument=null">Close</button></div></EnrollmentDialog>
<EnrollmentDialog v-if="confirmation" labelledby="en-confirm" :busy="busy" @cancel="confirmation=''"><h2 id="en-confirm">{{ confirmation==='submit'?'Submit application?':'Cancel application?' }}</h2><p>{{ applicantName }} · {{ applicantIdentity }} · {{ label(classification) }}</p><p>{{ confirmation==='submit'?'Your application will be sent for review and can no longer be edited.':'Cancellation ends this unsubmitted draft, which will remain in your history. If the term is still open and you remain eligible, you can start a new application.' }}</p><div class="en-actions"><button @click="confirmation=''">Go back</button><button @click="act(confirmation)">{{ confirmation==='submit'?'Confirm submit':'Confirm cancellation' }}</button></div></EnrollmentDialog>
</template>
