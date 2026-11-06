<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { apiClient } from '../../services/apiClient'
import { ROLES } from '../../config/accessControl'
import { clientPlatform } from '../../config/clientPlatform'
import { label, termLabel, safeError } from './enrollment'
import EnrollmentDialog from './EnrollmentDialog.vue'
import WebsiteDataTable from '../../components/website/WebsiteDataTable.vue'
import WebsiteEmptyState from '../../components/website/WebsiteEmptyState.vue'

const props = defineProps({ applicationId: { type: Number, required: true } })
const emit = defineEmits(['changed'])
const auth = useAuthStore()
const staff = computed(() => [ROLES.ADMIN, ROLES.REGISTRAR_STAFF].includes(auth.currentRole))
const isWebsitePresentation = clientPlatform === 'web'
const isDesktopStaff = computed(() => clientPlatform === 'desktop' && staff.value)

const data = ref(null)
const selected = ref([])
const section = ref('')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const confirm = ref(false)
const modalError = ref('')
const errorSummary = ref(null)
const sectionPage = ref(1)
let epoch = 0

const canSelect = computed(() => data.value?.application.status === 'approved'
  && data.value.application.classification !== 'regular'
  && (staff.value || data.value.application.classification === 'irregular'))
const selectedSubjects = computed(() => data.value?.load.candidates.filter(subject => selected.value.includes(subject.id)) || [])
const units = computed(() => selectedSubjects.value.reduce((sum, subject) => sum + Number(subject.units), 0))
const applicantProfile = computed(() => data.value?.application.student?.user_profile || data.value?.application.owner?.profile)
const applicantName = computed(() => [applicantProfile.value?.first_name, applicantProfile.value?.last_name].filter(Boolean).join(' ') || 'Applicant')
const applicantInitials = computed(() => [applicantProfile.value?.first_name, applicantProfile.value?.last_name].filter(Boolean).map(value => value[0]).join('').slice(0, 2).toUpperCase() || 'AP')
const applicantIdentity = computed(() => data.value?.application.student?.student_number || data.value?.application.admission_applicant?.applicant_number || `Application #${data.value?.application.id || props.applicationId}`)
const applicantType = computed(() => data.value?.application.student ? 'Continuing Student' : 'New Student')
const programName = computed(() => data.value?.application.course?.course_code || data.value?.application.course?.course_name || 'Program pending')
const yearLevel = computed(() => data.value?.application.year_level ? `${ordinal(data.value.application.year_level)} Year` : 'Not available')
const academicTerm = computed(() => data.value ? termLabel(data.value.application.period) : '')
const assignedSection = computed(() => data.value?.application.section)
const selectedSection = computed(() => data.value?.sections?.data?.find(item => String(item.id) === String(section.value)))
const sectionChanged = computed(() => String(section.value || '') !== String(data.value?.application.section_id || ''))
const finalized = computed(() => data.value?.application.status === 'enrolled')
const canFinalize = computed(() => !busy.value && data.value?.application.status === 'approved'
  && Boolean(data.value.application.section_id) && Boolean(data.value.application.load_reviewed_at) && selected.value.length > 0)

function ordinal(value) {
  const number = Number(value)
  const suffix = number % 100 >= 11 && number % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' })[number % 10] || 'th'
  return `${number}${suffix}`
}

function clearCorrectedErrors() {
  error.value = ''
  modalError.value = ''
}

async function load() {
  const version = ++epoch
  busy.value = true
  error.value = ''
  try {
    const response = await apiClient.get(`/enrollment/academic/applications/${props.applicationId}`, { params: { page: sectionPage.value } })
    if (version !== epoch) return
    data.value = response.data.data
    selected.value = data.value.load.selected_subject_ids.length
      ? data.value.load.selected_subject_ids
      : data.value.application.classification === 'regular' ? data.value.load.standard_subject_ids : []
    section.value = data.value.application.section_id || ''
  } catch (reason) {
    if (version === epoch) error.value = safeError(reason)
  } finally {
    if (version === epoch) busy.value = false
  }
}

function actionPayload(action) {
  const payload = { version: data.value.application.version }
  if (action === 'subjects') payload.subject_ids = selected.value
  if (action === 'assign') payload.section_id = Number(section.value)
  if (action === 'finalize') payload.confirmed = true
  return payload
}

async function act(action) {
  if (busy.value) return
  const finalizing = action === 'finalize'
  const version = ++epoch
  busy.value = true
  notice.value = ''
  if (finalizing) modalError.value = ''
  else error.value = ''
  try {
    await apiClient.post(`/enrollment/academic/applications/${props.applicationId}/${action}`, actionPayload(action))
    if (version !== epoch) return
    if (finalizing) confirm.value = false
    notice.value = finalizing
      ? 'Academic enrollment completed. The official Student identity and COR are now available.'
      : action === 'assign' ? 'Section assigned.' : 'Subject load saved.'
    await load()
    emit('changed')
  } catch (reason) {
    if (version !== epoch) return
    const message = safeError(reason)
    if (finalizing) {
      modalError.value = message
      await nextTick()
      errorSummary.value?.focus?.()
    } else {
      error.value = message
      await nextTick()
      errorSummary.value?.focus?.()
    }
  } finally {
    if (version === epoch) busy.value = false
  }
}

function openConfirmation() {
  modalError.value = ''
  confirm.value = true
}

function closeConfirmation() {
  if (busy.value) return
  modalError.value = ''
  confirm.value = false
}

watch(() => [props.applicationId, auth.currentUser?.id, auth.currentRole], () => {
  data.value = null
  confirm.value = false
  modalError.value = ''
  load()
}, { immediate: true })
watch(selected, clearCorrectedErrors, { deep: true })
watch(section, clearCorrectedErrors)
onBeforeUnmount(() => { epoch++ })
</script>

<template>
  <section v-if="isDesktopStaff" class="ear-workspace" aria-labelledby="ear-title">
    <div v-if="error" ref="errorSummary" class="ear-feedback ear-feedback--error" role="alert" tabindex="-1">
      <span>{{ error }}</span>
      <button type="button" :disabled="busy" @click="load">Retry</button>
    </div>
    <p v-if="notice" class="ear-feedback ear-feedback--success" role="status">{{ notice }}</p>
    <p v-if="busy && !data" class="ear-loading" role="status">Loading academic enrollment…</p>

    <template v-if="data">
      <ol class="ear-progress" aria-label="Academic enrollment progress">
        <li class="is-complete"><span aria-hidden="true">✓</span><div><strong>Application</strong><small>Verified</small></div></li>
        <li :class="{ 'is-complete': finalized, 'is-current': !finalized }"><span aria-hidden="true">{{ finalized ? '✓' : '2' }}</span><div><strong>Academic Enrollment</strong><small>{{ finalized ? 'Finalized' : 'Assign subjects and section' }}</small></div></li>
        <li :class="{ 'is-complete': finalized }"><span aria-hidden="true">{{ finalized ? '✓' : '3' }}</span><div><strong>Complete</strong><small>Student account and ID</small></div></li>
      </ol>

      <div class="ear-layout">
        <main class="ear-main">
          <header class="ear-heading">
            <div class="ear-heading-icon" aria-hidden="true">▥</div>
            <div><p class="ear-kicker">Academic enrollment</p><h2 id="ear-title">Subjects and Academic Enrollment</h2><p>Review the approved curriculum load, assign a section, and finalize the Student record.</p></div>
            <span class="ear-guidance-chip">Schedule not required</span>
          </header>

          <div class="ear-schedule-note" role="note">
            <span aria-hidden="true">i</span>
            <p><strong>Enrollment can be finalized now.</strong> Class days, times, rooms, and Professors may be assigned later in Scheduling.</p>
          </div>

          <dl class="ear-summary">
            <div><dt>Program</dt><dd>{{ programName }}</dd></div>
            <div><dt>Year level</dt><dd>{{ yearLevel }}</dd></div>
            <div><dt>Academic term</dt><dd>{{ academicTerm }}</dd></div>
            <div><dt>Load type</dt><dd>{{ label(data.application.classification) }}</dd></div>
          </dl>

          <section class="ear-subjects" aria-labelledby="ear-subjects-title">
            <div class="ear-section-heading"><div><h3 id="ear-subjects-title">Assigned Subjects <span>({{ selectedSubjects.length }})</span></h3><p>Only approved curriculum subjects are included in finalization.</p></div></div>
            <div class="ear-table-wrap">
              <table>
                <thead><tr><th scope="col">Include</th><th scope="col">Code and subject</th><th scope="col">Units</th><th scope="col">Schedule</th><th scope="col">Status</th></tr></thead>
                <tbody>
                  <tr v-for="subject in data.load.candidates" :key="subject.id" :class="{ 'is-included': selected.includes(subject.id) }">
                    <td><input v-model="selected" type="checkbox" :value="subject.id" :aria-label="`Include ${subject.subject_code}`" :disabled="busy || !canSelect || subject.completed || !subject.prerequisite_met"></td>
                    <td><strong>{{ subject.subject_code }}</strong><span>{{ subject.subject_name }}</span></td>
                    <td>{{ Number(subject.units).toFixed(1) }}</td>
                    <td><span class="ear-schedule-copy">{{ subject.schedule_status === 'scheduled' ? 'Scheduled' : 'To be announced' }}</span></td>
                    <td><span v-if="subject.completed" class="ear-badge ear-badge--muted">Completed</span><span v-else-if="!subject.prerequisite_met" class="ear-badge ear-badge--danger">Prerequisite pending</span><span v-else-if="subject.schedule_status === 'scheduled'" class="ear-badge ear-badge--ready">Schedule ready</span><span v-else class="ear-badge ear-badge--pending">Schedule pending</span></td>
                  </tr>
                  <tr v-if="!data.load.candidates.length"><td colspan="5" class="ear-empty">No curriculum subjects are configured for this academic term.</td></tr>
                </tbody>
              </table>
            </div>
            <div class="ear-total"><strong>Total Units: {{ units.toFixed(1) }}</strong><span>{{ data.application.load_reviewed_at ? 'Staff-reviewed curriculum load' : 'Staff review required' }}</span></div>
            <div v-if="data.application.status === 'approved' && canSelect" class="ear-inline-action"><button type="button" :disabled="busy || !selected.length" @click="act('subjects')">Save subject load</button></div>
          </section>

          <section v-if="data.application.status === 'approved'" class="ear-section-assignment" aria-labelledby="ear-section-title">
            <div><h3 id="ear-section-title">Section Assignment</h3><p>Select the section where the Student will be enrolled.</p></div>
            <div class="ear-section-control">
              <label><span>Authorized section</span><select v-model="section" :disabled="busy"><option value="">Choose section</option><option v-for="item in data.sections.data" :key="item.id" :value="item.id">{{ item.section_name }} — {{ item.enrolled_count + item.reserved_count }} / {{ item.capacity }} seats</option></select></label>
              <button v-if="sectionChanged" type="button" :disabled="busy || !section" @click="act('assign')">Assign section</button>
              <span v-else-if="assignedSection" class="ear-assigned">Assigned</span>
            </div>
            <div v-if="selectedSection" class="ear-capacity" aria-live="polite">{{ selectedSection.capacity - selectedSection.enrolled_count - selectedSection.reserved_count }} seats currently available.</div>
            <div v-if="data.sections.last_page > 1" class="ear-pagination"><button type="button" :disabled="busy || sectionPage <= 1" @click="sectionPage--; load()">Previous sections</button><span>Page {{ data.sections.current_page }} of {{ data.sections.last_page }}</span><button type="button" :disabled="busy || sectionPage >= data.sections.last_page" @click="sectionPage++; load()">Next sections</button></div>
          </section>

          <footer class="ear-actions">
            <p v-if="!finalized">Finalization rechecks curriculum, prerequisites, capacity, academic term, identity, eligibility, and duplicate enrollment.</p>
            <p v-else>Academic enrollment is complete. Any pending class schedule will appear after Scheduling assigns it.</p>
            <button v-if="!finalized" type="button" class="ear-finalize" :disabled="!canFinalize" @click="openConfirmation"><span aria-hidden="true">✓</span> Finalize Academic Enrollment</button>
          </footer>
        </main>

        <aside class="ear-aside" aria-label="Applicant summary">
          <section class="ear-applicant-card">
            <header><span class="ear-avatar">{{ applicantInitials }}</span><div><p>Student Application</p><h3>{{ applicantName }}</h3><small>{{ applicantIdentity }}</small></div></header>
            <span class="ear-verified">Application verified</span>
            <dl><div><dt>Applicant type</dt><dd>{{ applicantType }}</dd></div><div><dt>Program</dt><dd>{{ programName }}</dd></div><div><dt>Academic term</dt><dd>{{ academicTerm }}</dd></div><div><dt>Classification</dt><dd>{{ label(data.application.classification) }}</dd></div><div><dt>Section</dt><dd>{{ assignedSection?.section_name || 'Not assigned' }}</dd></div></dl>
          </section>
          <section class="ear-next-card"><h3>What Happens Next?</h3><ol><li class="is-current"><strong>Finalize enrollment</strong><span>Create the official Student identity, number, enrollment, and subject records.</span></li><li><strong>Assign schedules when ready</strong><span>Add days, times, rooms, and Professors later without reenrolling the Student.</span></li><li><strong>Student views updates</strong><span>COR and My Schedule clearly show pending times until Scheduling is complete.</span></li></ol></section>
        </aside>
      </div>
    </template>
  </section>

  <section v-else class="en-card subject-load-workspace">
    <h2>Subjects and academic enrollment</h2>
    <p v-if="error" ref="errorSummary" role="alert" tabindex="-1">{{ error }} <button :disabled="busy" @click="load">Reload load</button></p>
    <p v-if="busy" role="status">Loading…</p>
    <p v-if="notice" role="status">{{ notice }}</p>
    <template v-if="data">
      <p>{{ label(data.application.classification) }} · {{ termLabel(data.application.period) }}</p>
      <p v-if="data.application.classification === 'regular'">Your standard load comes directly from your curriculum, year level and semester.</p>
      <p v-else>Select an active curriculum load. Recorded completed subjects and prerequisites are checked again before enrollment.</p>
      <component :is="isWebsitePresentation ? WebsiteDataTable : 'div'" label="Enrolled subject load" :class="{ 'en-table': !isWebsitePresentation }"><table><thead><tr><th>Include</th><th>Subject</th><th>Units</th><th>Eligibility</th></tr></thead><tbody><tr v-for="subject in data.load.candidates" :key="subject.id"><td><input v-model="selected" type="checkbox" :aria-label="`Select ${subject.subject_code}`" :value="subject.id" :disabled="busy || !canSelect || subject.completed || !subject.prerequisite_met"></td><td><strong>{{ subject.subject_code }}</strong><br>{{ subject.subject_name }}</td><td>{{ subject.units }}</td><td>{{ subject.completed ? 'Already completed' : !subject.prerequisite_met ? 'Prerequisite not completed' : 'Eligible' }}</td></tr></tbody></table></component>
      <WebsiteEmptyState v-if="isWebsitePresentation && !data.load.candidates.length" title="No subjects configured" message="No curriculum subjects are available for this academic term."/>
      <p v-else-if="!data.load.candidates.length">No subjects are configured for this curriculum and semester.</p>
      <p class="subject-load-total"><strong>Total units: {{ units }}</strong></p>
      <p>{{ data.application.load_reviewed_at ? 'Staff-reviewed load' : 'Staff review is required before finalization.' }}</p>
      <div class="en-actions"><button v-if="data.application.status === 'approved' && (staff || canSelect)" :disabled="busy || !selected.length" @click="act('subjects')">{{ staff ? 'Prepare / approve subject load' : 'Save selected subjects' }}</button></div>
      <template v-if="staff && data.application.status === 'approved'"><label>Assign section<select v-model="section" :disabled="busy"><option value="">Choose section</option><option v-for="item in data.sections.data" :key="item.id" :value="item.id">{{ item.section_name }} — {{ item.enrolled_count + item.reserved_count }}/{{ item.capacity }} seats</option></select></label><div v-if="data.sections.last_page > 1" class="en-actions"><button :disabled="busy || sectionPage <= 1" @click="sectionPage--; load()">Previous sections</button><button :disabled="busy || sectionPage >= data.sections.last_page" @click="sectionPage++; load()">More sections</button></div><div class="en-actions"><button :disabled="busy || !section" @click="act('assign')">Assign section</button><button :disabled="!canFinalize" @click="openConfirmation">Finalize academic enrollment</button></div></template>
      <p v-if="data.application.section">Assigned section: {{ data.application.section.section_name }}</p>
      <p v-if="data.application.enrollment_id">Academically enrolled. <RouterLink v-if="!staff" :to="`/enrollment/cor?record=${data.application.enrollment_id}`">View COR</RouterLink></p>
    </template>
  </section>

  <EnrollmentDialog v-if="confirm && data" labelledby="en-finalize-title" :busy="busy" @cancel="closeConfirmation">
    <div class="ear-confirm-dialog">
      <header><span aria-hidden="true">✓</span><div><p>Final review</p><h2 id="en-finalize-title">Confirm Academic Enrollment</h2></div></header>
      <p v-if="modalError" ref="errorSummary" class="ear-confirm-error" role="alert" tabindex="-1">{{ modalError }}</p>
      <div class="ear-confirm-student"><span class="ear-avatar">{{ applicantInitials }}</span><div><strong>{{ applicantName }}</strong><span>{{ applicantIdentity }}</span></div></div>
      <dl><div><dt>Section</dt><dd>{{ assignedSection?.section_name || 'Not assigned' }}</dd></div><div><dt>Academic term</dt><dd>{{ academicTerm }}</dd></div><div><dt>Subjects</dt><dd>{{ selectedSubjects.length }}</dd></div><div><dt>Total units</dt><dd>{{ units.toFixed(1) }}</dd></div></dl>
      <div class="ear-confirm-notice"><strong>Official Student identity</strong><p v-if="!data.application.student">Confirming creates the official Student identity and next authorized Student number for this account.</p><p v-else>Confirming creates the official enrollment and subject records for this Student and term.</p></div>
      <p class="ear-confirm-schedule"><strong>Schedules may remain pending.</strong> Missing class days, times, rooms, or Professors do not block enrollment and can be assigned later.</p>
      <footer><button type="button" :disabled="busy" @click="closeConfirmation">Cancel</button><button type="button" class="ear-finalize" :disabled="busy" @click="act('finalize')">{{ busy ? 'Finalizing…' : 'Confirm Final Enrollment' }}</button></footer>
    </div>
  </EnrollmentDialog>
</template>

<style scoped>
.ear-workspace{color:var(--desk-text,#13231d);display:grid;gap:14px;margin-top:18px}.ear-progress{align-items:start;background:var(--desk-surface,#fff);border:1px solid var(--desk-line,#dce7e1);border-radius:14px;display:grid;grid-template-columns:repeat(3,1fr);list-style:none;margin:0;padding:18px 22px}.ear-progress li{align-items:center;display:flex;gap:10px;position:relative}.ear-progress li:not(:last-child)::after{background:var(--desk-line,#d4dfda);content:"";height:2px;left:45px;position:absolute;right:10px;top:16px}.ear-progress li>span{align-items:center;background:var(--desk-soft,#edf3f0);border:2px solid var(--desk-line,#d4dfda);border-radius:50%;display:flex;font-size:13px;font-weight:800;height:32px;justify-content:center;min-width:32px;position:relative;z-index:1}.ear-progress li>div{display:grid;gap:1px;position:relative;z-index:1}.ear-progress strong{font-size:13px}.ear-progress small{color:var(--desk-muted,#66766f);font-size:11px}.ear-progress .is-complete>span,.ear-progress .is-current>span{background:#087443;border-color:#087443;color:#fff}.ear-progress .is-complete:not(:last-child)::after{background:#16885a}.ear-progress .is-current strong{color:#087443}.ear-layout{align-items:start;display:grid;gap:14px;grid-template-columns:minmax(0,1fr) minmax(235px,28%)}.ear-main,.ear-applicant-card,.ear-next-card{background:var(--desk-surface,#fff);border:1px solid var(--desk-line,#dce7e1);border-radius:14px}.ear-main{min-width:0;padding:20px}.ear-heading{align-items:center;display:grid;gap:12px;grid-template-columns:auto minmax(0,1fr) auto}.ear-heading-icon{align-items:center;background:linear-gradient(145deg,#087443,#005a35);border-radius:10px;color:#fff;display:flex;font-size:22px;height:46px;justify-content:center;width:46px}.ear-kicker{color:#087443;font-size:11px;font-weight:800;letter-spacing:.08em;margin:0;text-transform:uppercase}.ear-heading h2{font-size:20px;line-height:1.2;margin:2px 0 3px}.ear-heading p:not(.ear-kicker),.ear-section-heading p,.ear-section-assignment p,.ear-actions p{color:var(--desk-muted,#64736d);font-size:12px;line-height:1.45;margin:0}.ear-guidance-chip,.ear-verified{background:#e0f5e7;border-radius:999px;color:#17623f;font-size:11px;font-weight:800;padding:7px 10px;white-space:nowrap}.ear-schedule-note{align-items:start;background:#eaf4ff;border:1px solid #d3e5f8;border-radius:10px;color:#1c4f78;display:flex;gap:10px;margin:16px 0;padding:11px 13px}.ear-schedule-note>span{align-items:center;border:2px solid currentColor;border-radius:50%;display:flex;font-size:11px;font-weight:800;height:20px;justify-content:center;min-width:20px}.ear-schedule-note p{font-size:12px;line-height:1.45;margin:0}.ear-summary{display:grid;gap:8px;grid-template-columns:.9fr .75fr 1.45fr .8fr;margin:0 0 18px}.ear-summary div{background:var(--desk-soft,#f5f8f6);border:1px solid var(--desk-line,#dce7e1);border-radius:10px;min-width:0;padding:10px 12px}.ear-summary dt,.ear-applicant-card dt,.ear-confirm-dialog dt{color:var(--desk-muted,#64736d);font-size:10px;text-transform:uppercase}.ear-summary dd,.ear-applicant-card dd,.ear-confirm-dialog dd{font-size:12px;font-weight:800;margin:3px 0 0;overflow-wrap:anywhere}.ear-subjects{border-top:1px solid var(--desk-line,#dce7e1);padding-top:16px}.ear-section-heading{align-items:end;display:flex;justify-content:space-between;margin-bottom:9px}.ear-section-heading h3,.ear-section-assignment h3{font-size:15px;margin:0 0 2px}.ear-section-heading h3 span{color:var(--desk-muted,#64736d);font-weight:600}.ear-table-wrap{border:1px solid var(--desk-line,#dce7e1);border-radius:10px;overflow:hidden}.ear-table-wrap table{border-collapse:collapse;table-layout:fixed;width:100%}.ear-table-wrap th{background:#e9f5ee;color:#24543d;font-size:10px;letter-spacing:.02em;padding:9px 8px;text-align:left;text-transform:uppercase}.ear-table-wrap th:first-child{width:60px}.ear-table-wrap th:nth-child(3){width:62px}.ear-table-wrap th:nth-child(4){width:130px}.ear-table-wrap th:nth-child(5){width:132px}.ear-table-wrap td{border-top:1px solid var(--desk-line,#e1e9e5);font-size:12px;line-height:1.35;padding:9px 8px;vertical-align:middle}.ear-table-wrap tbody tr.is-included{box-shadow:inset 3px 0 #15935d}.ear-table-wrap td:nth-child(2) strong,.ear-table-wrap td:nth-child(2) span{display:block}.ear-table-wrap td:nth-child(2) span{color:var(--desk-muted,#64736d);margin-top:2px;overflow-wrap:anywhere}.ear-table-wrap input{accent-color:#087443;height:17px;width:17px}.ear-schedule-copy{color:var(--desk-muted,#64736d)}.ear-badge{border-radius:999px;display:inline-block;font-size:10px;font-weight:800;padding:5px 8px}.ear-badge--pending{background:#fff1c9;color:#805809}.ear-badge--ready{background:#ddf4e4;color:#17623f}.ear-badge--danger{background:#fde5e5;color:#a32626}.ear-badge--muted{background:var(--desk-soft,#edf2ef);color:var(--desk-muted,#64736d)}.ear-empty{color:var(--desk-muted,#64736d);padding:24px!important;text-align:center}.ear-total{align-items:center;background:#e7f7eb;border-radius:0 0 10px 10px;color:#17623f;display:flex;font-size:12px;justify-content:space-between;margin-top:7px;padding:10px 12px}.ear-inline-action{display:flex;justify-content:flex-end;margin-top:8px}.ear-inline-action button,.ear-section-control button,.ear-pagination button{background:var(--desk-surface,#fff);border:1px solid #16885a;border-radius:8px;color:#087443;font-size:12px;font-weight:800;height:36px;padding:0 13px}.ear-section-assignment{border-top:1px solid var(--desk-line,#dce7e1);margin-top:16px;padding-top:16px}.ear-section-control{align-items:end;display:grid;gap:8px;grid-template-columns:minmax(220px,1fr) auto;margin-top:10px}.ear-section-control label{display:grid;gap:5px}.ear-section-control label>span{font-size:11px;font-weight:700}.ear-section-control select{background:var(--desk-surface,#fff);border:1px solid var(--desk-line-strong,#bfcfc7);border-radius:8px;color:inherit;font:inherit;height:40px;padding:0 11px;width:100%}.ear-assigned{background:#e0f5e7;border-radius:999px;color:#17623f;font-size:11px;font-weight:800;padding:8px 12px}.ear-capacity{color:var(--desk-muted,#64736d);font-size:11px;margin-top:6px}.ear-pagination{align-items:center;display:flex;gap:8px;justify-content:flex-end;margin-top:8px}.ear-pagination span{color:var(--desk-muted,#64736d);font-size:11px}.ear-actions{align-items:center;border-top:1px solid var(--desk-line,#dce7e1);display:flex;gap:16px;justify-content:space-between;margin-top:18px;padding-top:16px}.ear-actions p{max-width:58ch}.ear-finalize{align-items:center;background:linear-gradient(135deg,#087443,#00663b);border:0;border-radius:9px;color:#fff;display:inline-flex;font-size:13px;font-weight:800;gap:7px;justify-content:center;min-height:40px;padding:0 17px}.ear-finalize:disabled{cursor:not-allowed;opacity:.5}.ear-aside{display:grid;gap:12px}.ear-applicant-card,.ear-next-card{overflow:hidden;padding:16px}.ear-applicant-card header{align-items:center;display:flex;gap:10px}.ear-applicant-card header p{color:#087443;font-size:10px;font-weight:800;letter-spacing:.06em;margin:0;text-transform:uppercase}.ear-applicant-card h3{font-size:16px;margin:2px 0}.ear-applicant-card header small{color:var(--desk-muted,#64736d);font-size:10px;overflow-wrap:anywhere}.ear-avatar{align-items:center;background:#dff3e6;border-radius:50%;color:#087443;display:flex;font-size:13px;font-weight:900;height:40px;justify-content:center;min-width:40px}.ear-verified{display:inline-block;margin:12px 0}.ear-applicant-card dl{border-top:1px solid var(--desk-line,#dce7e1);margin:0;padding-top:7px}.ear-applicant-card dl div{align-items:start;border-bottom:1px solid var(--desk-line,#e2e9e5);display:grid;gap:8px;grid-template-columns:90px minmax(0,1fr);padding:9px 0}.ear-applicant-card dd{text-align:right}.ear-next-card h3{font-size:15px;margin:0 0 12px}.ear-next-card ol{list-style:none;margin:0;padding:0}.ear-next-card li{border-left:2px solid var(--desk-line,#d8e3dd);display:grid;gap:3px;margin-left:8px;padding:0 0 16px 17px;position:relative}.ear-next-card li::before{background:var(--desk-surface,#fff);border:2px solid #aac0b5;border-radius:50%;content:"";height:13px;left:-8px;position:absolute;top:2px;width:13px}.ear-next-card li.is-current::before{background:#16885a;border-color:#16885a}.ear-next-card li:last-child{padding-bottom:0}.ear-next-card strong{font-size:12px}.ear-next-card span{color:var(--desk-muted,#64736d);font-size:11px;line-height:1.4}.ear-feedback{align-items:center;border-radius:9px;display:flex;font-size:12px;justify-content:space-between;padding:10px 12px}.ear-feedback--error,.ear-confirm-error{background:#fde8e8;color:#9d2525}.ear-feedback--success{background:#e1f5e8;color:#17623f}.ear-feedback button{background:transparent;border:1px solid currentColor;border-radius:7px;color:inherit;padding:5px 9px}.ear-loading{color:var(--desk-muted,#64736d);font-size:12px}.ear-confirm-dialog{display:grid;gap:15px;min-width:min(520px,80vw)}.ear-confirm-dialog header{align-items:center;display:flex;gap:10px}.ear-confirm-dialog header>span{align-items:center;background:#dff3e6;border-radius:10px;color:#087443;display:flex;font-weight:900;height:40px;justify-content:center;width:40px}.ear-confirm-dialog header p{color:#087443;font-size:10px;font-weight:800;letter-spacing:.08em;margin:0;text-transform:uppercase}.ear-confirm-dialog h2{font-size:19px;margin:2px 0 0}.ear-confirm-error{border-radius:8px;font-size:12px;margin:0;padding:10px}.ear-confirm-student{align-items:center;background:var(--desk-soft,#f3f7f5);border-radius:10px;display:flex;gap:10px;padding:11px}.ear-confirm-student div{display:grid;gap:2px}.ear-confirm-student span{color:var(--desk-muted,#64736d);font-size:11px}.ear-confirm-dialog dl{display:grid;gap:8px;grid-template-columns:repeat(2,1fr);margin:0}.ear-confirm-dialog dl div{border:1px solid var(--desk-line,#dce7e1);border-radius:8px;padding:9px}.ear-confirm-notice,.ear-confirm-schedule{border-radius:9px;font-size:12px;line-height:1.45;margin:0;padding:11px 12px}.ear-confirm-notice{background:#eaf4ff;color:#1c4f78}.ear-confirm-notice p{margin:3px 0 0}.ear-confirm-schedule{background:#fff4d7;color:#72510c}.ear-confirm-dialog footer{display:flex;gap:9px;justify-content:flex-end}.ear-confirm-dialog footer>button:not(.ear-finalize){background:var(--desk-surface,#fff);border:1px solid var(--desk-line-strong,#bfcfc7);border-radius:9px;color:inherit;font-weight:700;min-height:40px;padding:0 16px}.ear-workspace button:focus-visible,.ear-workspace select:focus-visible,.ear-workspace input:focus-visible,.ear-confirm-dialog button:focus-visible{outline:3px solid color-mix(in srgb,#18a66a 35%,transparent);outline-offset:2px}.ear-workspace button{cursor:pointer}.ear-workspace button:disabled{cursor:not-allowed}.subject-load-workspace [tabindex="-1"]:focus,.ear-feedback:focus,.ear-confirm-error:focus{outline:2px solid #b3261e;outline-offset:2px}
@media(max-width:980px){.ear-layout{grid-template-columns:1fr}.ear-aside{grid-template-columns:repeat(2,minmax(0,1fr))}.ear-summary{grid-template-columns:repeat(2,1fr)}}
@media(max-width:720px){.ear-progress{padding:14px}.ear-progress li:not(:last-child)::after{display:none}.ear-progress li{align-items:flex-start}.ear-progress li>div small{display:none}.ear-heading{grid-template-columns:auto 1fr}.ear-guidance-chip{grid-column:1/-1;justify-self:start}.ear-main{padding:14px}.ear-aside{grid-template-columns:1fr}.ear-table-wrap th:nth-child(4),.ear-table-wrap td:nth-child(4){display:none}.ear-table-wrap th:nth-child(5){width:118px}.ear-section-control{grid-template-columns:1fr}.ear-actions{align-items:stretch;flex-direction:column}.ear-finalize{width:100%}}
@media(prefers-reduced-motion:reduce){.ear-workspace *{scroll-behavior:auto!important;transition-duration:.01ms!important}}
</style>
