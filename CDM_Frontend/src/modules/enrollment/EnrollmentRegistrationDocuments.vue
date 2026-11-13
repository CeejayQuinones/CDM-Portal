<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiClient } from '../../services/apiClient'
import { safeError, termLabel } from './enrollment'
import EnrollmentCor from './EnrollmentCor.vue'
import EnrollmentOvrfActions from './EnrollmentOvrfActions.vue'

const route = useRoute(), router = useRouter()
const terms = ref([]), record = ref(null), loading = ref(true), error = ref('')
const documentType = computed(() => route.query.document === 'ovrf' ? 'ovrf' : 'cor')
let epoch = 0

async function selectRecord(id) {
  await router.replace({ name: 'enrollment-cor', query: { ...route.query, record: String(id), document: documentType.value } })
}
async function selectDocument(type) {
  await router.replace({ name: 'enrollment-cor', query: { ...route.query, document: type } })
}
async function load() {
  const version = ++epoch
  loading.value = true; error.value = ''; record.value = null
  try {
    // Read every authorized page so older terms are available in one selector.
    const available = []
    let page = 1, lastPage = 1
    do {
      const response = await apiClient.get('/enrollment/academic/records', { params: { page } })
      if (version !== epoch) return
      const result = response.data.data
      available.push(...result.data.filter(item => ['enrolled', 'completed'].includes(item.status)))
      lastPage = result.last_page || 1; page++
    } while (page <= lastPage)
    terms.value = available
    const requested = route.query.record
    const selected = requested ? available.find(item => String(item.id) === String(requested)) : available[0]
    if (requested && !selected) throw new Error('The requested enrollment term is unavailable. Choose another term.')
    if (selected) {
      const response = await apiClient.get(`/enrollment/academic/records/${selected.id}`)
      if (version !== epoch) return
      record.value = response.data.data
    }
  } catch (exception) {
    if (version === epoch) error.value = exception.response ? safeError(exception) : exception.message
  } finally { if (version === epoch) loading.value = false }
}
watch(() => route.query.record, load, { immediate: true })
onBeforeUnmount(() => { epoch++ })
</script>

<template>
  <section class="registration-documents">
    <header class="registration-heading en-no-print">
      <div><p class="registration-eyebrow">Enrollment / Academic records</p><h1>Registration Documents</h1><p>Your COR and OVRF, together in one place. Choose a term, then open the document you need.</p></div>
      <span class="registration-copy-label">Student copy · View only</span>
    </header>

    <section class="registration-controls en-no-print" aria-label="Choose registration document">
      <label class="registration-term">Academic term<select :value="route.query.record || record?.id || ''" :disabled="loading || !terms.length" @change="selectRecord($event.target.value)"><option v-if="!record" value="" disabled>Select a finalized term</option><option v-for="term in terms" :key="term.id" :value="term.id">{{ termLabel(term) }} · {{ term.section?.section_name || 'Finalized enrollment' }}</option></select></label>
      <nav class="registration-tabs" aria-label="Registration document type">
        <button type="button" :aria-current="documentType === 'cor' ? 'page' : undefined" @click="selectDocument('cor')"><strong>COR</strong><span>Certificate of Registration</span></button>
        <button type="button" :aria-current="documentType === 'ovrf' ? 'page' : undefined" @click="selectDocument('ovrf')"><strong>OVRF</strong><span>Verification Registration Form</span></button>
      </nav>
    </section>

    <p v-if="loading" class="registration-state" role="status">Loading your registration documents…</p>
    <div v-else-if="error" class="registration-state" role="alert"><p>{{ error }}</p><button type="button" class="en-modern-outline" @click="load">Try again</button></div>
    <div v-else-if="!terms.length" class="registration-state"><h2>No registration documents yet</h2><p>Your COR and OVRF will appear here after Enrollment is finalized.</p></div>
    <template v-else-if="record">
      <p class="registration-guidance en-no-print">{{ documentType === 'cor' ? 'COR shows your registered subjects, units, section, and available class schedules.' : 'OVRF opens directly below. Student downloads and printed copies carry a viewing-only watermark.' }}</p>
      <EnrollmentCor v-if="documentType === 'cor'" :key="`cor-${record.id}`" :record="record" />
      <EnrollmentOvrfActions v-else :key="`ovrf-${record.id}`" :enrollment-id="record.id" :student-name="record.name" :academic-term="`${record.academic_year} · ${record.semester}`" embedded read-only-indicator />
    </template>
  </section>
</template>

<style scoped>
.registration-documents{--reg-surface:var(--website-card,var(--desktop-surface,#fff));--reg-border:var(--website-border,var(--desktop-border,#d5e3db));--reg-ink:var(--website-ink,var(--desktop-text,#163b2b));--reg-muted:var(--website-muted,var(--desktop-muted,#63786b));display:grid;gap:18px;min-width:0;color:var(--reg-ink)}
.registration-heading{display:flex;align-items:center;justify-content:space-between;gap:20px;padding-bottom:20px;border-bottom:1px solid var(--reg-border)}
.registration-heading h1{margin:5px 0 8px;font-size:clamp(1.55rem,3vw,2rem);line-height:1.2}.registration-heading p{margin:0;color:var(--reg-muted);line-height:1.6}.registration-heading .registration-eyebrow{color:var(--website-accent,var(--desktop-accent,#08734e));font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
.registration-copy-label{flex-shrink:0;padding:7px 12px;border:1px solid var(--reg-border);border-radius:999px;font-size:11px;font-weight:750;color:var(--reg-muted)}
.registration-controls{display:flex;align-items:end;justify-content:space-between;flex-wrap:wrap;gap:20px;padding:18px;background:var(--reg-surface);border:1px solid var(--reg-border);border-radius:14px}.registration-term{display:grid;gap:7px;flex:1 1 300px;max-width:480px;min-width:0;font-size:12px;font-weight:750}.registration-term select{width:100%;min-width:0;min-height:44px;padding:9px 12px;border:1px solid var(--reg-border);border-radius:8px;background:var(--reg-surface);color:var(--reg-ink);font:inherit}
.registration-tabs{display:flex;gap:6px;padding:5px;border:1px solid var(--reg-border);border-radius:11px;background:var(--website-hover,var(--desktop-surface-muted,#f2f7f4))}.registration-tabs button{display:grid;gap:3px;min-height:54px;padding:8px 16px;border:1px solid transparent;border-radius:8px;background:transparent;color:var(--reg-muted);text-align:left;cursor:pointer;font:inherit}.registration-tabs strong{font-size:14px}.registration-tabs span{font-size:10px}.registration-tabs [aria-current='page']{background:var(--website-success-bg,#e5f4ed);color:var(--website-success,#08623f);border-color:var(--website-success-border,#9bcab1)}
.registration-guidance{margin:0;font-size:12px;color:var(--reg-muted);line-height:1.5}.registration-state{display:grid;justify-items:center;gap:10px;padding:45px 20px;border:1px dashed var(--reg-border);border-radius:14px;background:var(--reg-surface);text-align:center}.registration-state p,.registration-state h2{margin:0}.registration-controls :focus-visible{outline:3px solid var(--website-focus,#d4a72c);outline-offset:3px}
@media(max-width:640px){.registration-heading{align-items:start;flex-direction:column;gap:12px}.registration-controls{padding:12px;gap:14px}.registration-term{max-width:none}.registration-tabs{width:100%}.registration-tabs button{flex:1;padding:8px}.registration-tabs span{font-size:9px}}
</style>
