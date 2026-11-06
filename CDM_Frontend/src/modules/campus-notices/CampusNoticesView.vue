<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { archiveCampusNotice, getCampusNotices, publishCampusNotice, saveCampusNotice } from '../../services/campusNoticeService'
import CdmBadge from '../../design-system/CdmBadge.vue'
import CdmButton from '../../design-system/CdmButton.vue'
import CdmCard from '../../design-system/CdmCard.vue'
import CdmEmptyState from '../../design-system/CdmEmptyState.vue'
import CdmInput from '../../design-system/CdmInput.vue'
import CdmLoadingState from '../../design-system/CdmLoadingState.vue'
import CdmPageHeader from '../../design-system/CdmPageHeader.vue'
import CdmSelect from '../../design-system/CdmSelect.vue'
import CdmTextarea from '../../design-system/CdmTextarea.vue'
import CampusNoticeIcon from './CampusNoticeIcon.vue'

const categories = ['general', 'enrollment', 'admission', 'registrar_reminder', 'class_suspension', 'school_policy', 'academic_reminder']
const priorities = ['urgent', 'high', 'normal', 'low']
const weekdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
const notices = ref([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editingId = ref(null)
const filter = reactive({ status: '', category: '' })
const blank = () => ({
  title: '', message: '', category: 'general', priority: 'normal', audiences: ['Student'],
  publication_start_at: '', publication_end_at: '', effective_start_at: '', effective_end_at: '',
  is_recurring: false, recurrence_weekdays: [], requires_acknowledgement: false,
  official_source: '', affected_scope: '', target_route: '',
})
const form = reactive(blank())
const suspension = computed(() => form.category === 'class_suspension')
const composerStatus = computed(() => editingId.value ? 'Editing draft' : (form.title || form.message ? 'Draft in progress' : 'New draft'))
const publishLabel = computed(() => form.publication_start_at && new Date(form.publication_start_at) > new Date() ? 'Schedule notice' : 'Publish notice')
const label = value => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())

function resetForm() {
  Object.assign(form, blank())
  editingId.value = null
  error.value = ''
}

function payload() {
  const value = { ...form }
  for (const field of ['publication_start_at', 'publication_end_at', 'effective_start_at', 'effective_end_at']) {
    value[field] = value[field] ? new Date(value[field]).toISOString() : null
  }
  value.recurrence_weekdays = value.is_recurring ? value.recurrence_weekdays : null
  value.official_source ||= null
  value.affected_scope ||= null
  value.target_route ||= null
  return value
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await getCampusNotices(filter)
    notices.value = data.notices?.data || []
  } catch (requestError) {
    error.value = requestError.response?.data?.message || 'Campus notices could not be loaded.'
  } finally { loading.value = false }
}

async function persist(publishAfterSave = false) {
  saving.value = true
  error.value = ''
  try {
    const saved = await saveCampusNotice(payload(), editingId.value)
    if (publishAfterSave) await publishCampusNotice(saved.id)
    resetForm()
    await load()
  } catch (requestError) {
    const errors = requestError.response?.data?.errors
    error.value = errors ? Object.values(errors).flat().join(' ') : (requestError.response?.data?.message || 'The notice could not be saved.')
  } finally { saving.value = false }
}

async function save() { await persist(false) }
async function publishCurrent(event) {
  if (!event.currentTarget.form?.reportValidity()) return
  await persist(true)
}

function edit(notice) {
  editingId.value = notice.id
  Object.assign(form, blank(), notice)
  for (const field of ['publication_start_at', 'publication_end_at', 'effective_start_at', 'effective_end_at']) {
    form[field] = notice[field] ? String(notice[field]).slice(0, 16) : ''
  }
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

async function changeState(action, notice) {
  error.value = ''
  try {
    if (action === 'publish') await publishCampusNotice(notice.id)
    else await archiveCampusNotice(notice.id)
    await load()
  } catch (requestError) {
    error.value = requestError.response?.data?.message || `The notice could not be ${action}ed.`
  }
}

onMounted(load)
</script>

<template>
  <section class="notice-page desktop-notice-command">
    <CdmPageHeader eyebrow="Desktop staff workspace" title="Campus Notices" description="Compose, verify, and publish official updates.">
      <template #actions>
        <CdmBadge status="draft">{{ composerStatus }}</CdmBadge>
        <CdmButton type="button" size="icon" variant="outline" aria-label="Start a new notice" title="New notice" @click="resetForm"><CampusNoticeIcon name="edit" /></CdmButton>
        <CdmButton type="button" size="icon" variant="outline" aria-label="Refresh notice history" title="Refresh" :disabled="loading" @click="load"><CampusNoticeIcon name="refresh" /></CdmButton>
      </template>
    </CdmPageHeader>

    <div v-if="error" class="notice-alert" role="alert"><CampusNoticeIcon name="info"/><span>{{ error }}</span></div>

    <CdmCard class="notice-command-card" :padded="false">
      <template #header>
        <div class="command-title"><span class="command-icon"><CampusNoticeIcon name="edit" :size="20"/></span><div><h2>{{ editingId ? 'Edit Notice' : 'Compose Notice' }}</h2><p>Drafts stay private until published.</p></div></div>
        <span class="command-state"><i></i>{{ composerStatus }}</span>
      </template>

      <form class="notice-form" @submit.prevent="save">
        <div class="notice-composer-layout">
          <div class="composer-fields">
            <section class="composer-section" aria-labelledby="notice-basics-title">
              <header><CampusNoticeIcon name="tag"/><h3 id="notice-basics-title">Notice basics</h3></header>
              <div class="basics-grid">
                <CdmInput v-model.trim="form.title" label="Title" maxlength="120" required placeholder="Concise notice title" />
                <CdmSelect v-model="form.category" label="Category"><option v-for="item in categories" :key="item" :value="item">{{ label(item) }}</option></CdmSelect>
                <CdmSelect v-model="form.priority" label="Priority"><option v-for="item in priorities" :key="item" :value="item">{{ label(item) }}</option></CdmSelect>
              </div>
            </section>

            <section class="composer-section content-section" aria-labelledby="notice-content-title">
              <header><CampusNoticeIcon name="edit"/><h3 id="notice-content-title">Message</h3><span>{{ form.message.length }}/1000</span></header>
              <CdmTextarea v-model.trim="form.message" label="Notice content" maxlength="1000" rows="6" required placeholder="Write the official notice in plain text…" />
            </section>

            <section class="composer-section" aria-labelledby="notice-audience-title">
              <header><CampusNoticeIcon name="users"/><h3 id="notice-audience-title">Audience</h3></header>
              <div class="audience-chips" role="group" aria-label="Notice audience">
                <label :class="{ selected: form.audiences.includes('Student') }"><input v-model="form.audiences" type="checkbox" value="Student"><CampusNoticeIcon name="student"/><span>Students</span><b aria-hidden="true">✓</b></label>
                <label :class="{ selected: form.audiences.includes('Guest') }"><input v-model="form.audiences" type="checkbox" value="Guest"><CampusNoticeIcon name="users"/><span>Guests</span><b aria-hidden="true">✓</b></label>
              </div>
            </section>

            <section class="composer-section" aria-labelledby="notice-schedule-title">
              <header><CampusNoticeIcon name="calendar"/><h3 id="notice-schedule-title">Schedule &amp; visibility</h3></header>
              <div class="schedule-grid">
                <CdmInput v-model="form.publication_start_at" label="Publish from" type="datetime-local"/>
                <CdmInput v-model="form.publication_end_at" label="Publish until" type="datetime-local"/>
                <CdmInput v-model="form.effective_start_at" label="Effective from" type="datetime-local" :required="suspension"/>
                <CdmInput v-model="form.effective_end_at" label="Effective until" type="datetime-local" :required="suspension"/>
              </div>
              <div class="schedule-options">
                <label class="switch-row"><input v-model="form.is_recurring" type="checkbox"><span class="switch" aria-hidden="true"></span><CampusNoticeIcon name="repeat"/><span><strong>Weekly repeat</strong><small>Asia/Manila</small></span></label>
                <label class="switch-row"><input v-model="form.requires_acknowledgement" type="checkbox"><span class="switch" aria-hidden="true"></span><CampusNoticeIcon name="bell"/><span><strong>Acknowledgement</strong><small>Require confirmation</small></span></label>
              </div>
              <fieldset v-if="form.is_recurring" class="weekday-picker"><legend>Reminder weekdays</legend><label v-for="day in weekdays" :key="day" :class="{ selected: form.recurrence_weekdays.includes(day) }"><input v-model="form.recurrence_weekdays" type="checkbox" :value="day"><span>{{ day.slice(0, 3) }}</span></label></fieldset>
            </section>

            <section class="composer-section source-section" aria-labelledby="notice-source-title">
              <header><CampusNoticeIcon name="link"/><h3 id="notice-source-title">Source &amp; routing</h3></header>
              <div class="source-grid">
                <CdmInput v-model.trim="form.official_source" label="Official source / reference" maxlength="255" :required="suspension" placeholder="Memorandum, policy, or verified link"/>
                <CdmInput v-model.trim="form.affected_scope" label="Affected scope" maxlength="255" :required="suspension" placeholder="Required for class suspension"/>
                <CdmInput v-model.trim="form.target_route" label="Safe Portal route" maxlength="255" placeholder="/enrollment/status"/>
              </div>
              <p v-if="suspension" class="safeguard"><CampusNoticeIcon name="info"/><span>Class suspensions require a verified source, affected scope, and effective period. Weather forecasts cannot publish suspensions.</span></p>
            </section>
          </div>

          <aside class="notice-preview" aria-label="Live notice preview">
            <header><CampusNoticeIcon name="eye"/><h3>Live preview</h3><span>Auto-updates</span></header>
            <article class="preview-bubble" :data-priority="form.priority">
              <div class="preview-topline"><span><CampusNoticeIcon name="bell" :size="15"/>Verified update</span><em>{{ label(form.priority) }}</em></div>
              <div class="preview-body"><CampusNoticeIcon name="flag" :size="24"/><div><strong>{{ form.title || 'Notice title' }}</strong><p>{{ form.message || 'Your official notice will appear here.' }}</p></div></div>
              <footer><div><span v-for="audience in form.audiences" :key="audience"><CampusNoticeIcon :name="audience === 'Student' ? 'student' : 'users'" :size="14"/>{{ audience }}s</span></div><small><CampusNoticeIcon name="link" :size="13"/>{{ form.official_source || 'CDM authorized notice' }}</small></footer>
            </article>
            <dl class="preview-facts"><div><dt>Visibility</dt><dd>{{ form.publication_start_at ? 'Scheduled' : 'On publish' }}</dd></div><div><dt>Recurrence</dt><dd>{{ form.is_recurring ? `${form.recurrence_weekdays.length} day(s)` : 'Once' }}</dd></div></dl>
          </aside>
        </div>

        <footer class="composer-actions">
          <button type="button" class="action-button quiet" :disabled="saving" @click="resetForm"><CampusNoticeIcon name="trash"/><span>Clear</span></button>
          <button type="submit" class="action-button secondary" :disabled="saving || !form.audiences.length"><CampusNoticeIcon name="save"/><span>{{ saving ? 'Saving…' : 'Save draft' }}</span></button>
          <button type="button" class="action-button primary" :disabled="saving || !form.audiences.length" @click="publishCurrent"><CampusNoticeIcon name="send"/><span>{{ saving ? 'Working…' : publishLabel }}</span></button>
        </footer>
      </form>

      <section class="notice-history" aria-labelledby="notice-history-title">
        <header class="history-header"><div><CampusNoticeIcon name="bell"/><div><h2 id="notice-history-title">Notice history</h2><p>Manage saved and published notices.</p></div></div><span>{{ notices.length }} shown</span></header>
        <div class="list-tools"><CdmSelect v-model="filter.status" label="Status" @change="load"><option value="">All statuses</option><option value="draft">Draft</option><option value="published">Published</option><option value="archived">Archived</option></CdmSelect><CdmSelect v-model="filter.category" label="Category" @change="load"><option value="">All categories</option><option v-for="item in categories" :key="item" :value="item">{{ label(item) }}</option></CdmSelect><CdmButton type="button" size="icon" variant="outline" aria-label="Refresh notice history" title="Refresh" :disabled="loading" @click="load"><CampusNoticeIcon name="refresh"/></CdmButton></div>
        <CdmLoadingState v-if="loading" label="Loading notices…"/>
        <CdmEmptyState v-else-if="!notices.length" title="No matching notices" message="Try a different status or category."/>
        <div v-else class="notice-list">
          <article v-for="item in notices" :key="item.id" class="notice-row"><span class="row-icon"><CampusNoticeIcon name="bell"/></span><div><div class="row-title"><CdmBadge :status="item.status"/><h3>{{ item.title }}</h3></div><p>{{ item.message }}</p><small>{{ label(item.category) }} · {{ label(item.priority) }} · {{ item.audiences.join(', ') }} · {{ item.creator_name }}</small></div><div class="row-actions"><CdmButton v-if="item.status === 'draft'" type="button" size="icon" variant="outline" aria-label="Edit notice" title="Edit" @click="edit(item)"><CampusNoticeIcon name="edit"/></CdmButton><CdmButton v-if="item.status === 'draft'" type="button" size="icon" aria-label="Publish notice" title="Publish" @click="changeState('publish', item)"><CampusNoticeIcon name="send"/></CdmButton><CdmButton v-if="item.status !== 'archived'" type="button" size="icon" variant="ghost" aria-label="Archive notice" title="Archive" @click="changeState('archive', item)"><CampusNoticeIcon name="trash"/></CdmButton></div></article>
        </div>
      </section>
    </CdmCard>
  </section>
</template>

<style scoped>
.notice-page{color:var(--cdm-text);display:grid;gap:16px;margin:0 auto;max-width:1380px;font-family:var(--cdm-font-sans)}
.notice-page :deep(.cdm-page-header){align-items:center;margin-bottom:2px}.notice-page :deep(.cdm-page-header h1){font-size:26px}.notice-page :deep(.cdm-page-header__actions){align-items:center}
.notice-alert,.safeguard{align-items:flex-start;background:var(--cdm-warning-soft);border:1px solid color-mix(in srgb,var(--cdm-warning) 30%,var(--cdm-border));border-radius:10px;color:var(--cdm-warning);display:flex;font-size:12px;gap:8px;padding:10px 12px}
.notice-command-card{border-radius:18px;box-shadow:0 16px 38px rgb(15 49 36 / 9%)}.notice-command-card :deep(.cdm-card__header){align-items:center;background:linear-gradient(90deg,color-mix(in srgb,var(--cdm-primary) 7%,var(--cdm-surface)),var(--cdm-surface));display:flex;justify-content:space-between;padding:14px 18px}.command-title{align-items:center;display:flex;gap:11px}.command-title h2,.history-header h2{font-size:16px;margin:0}.command-title p,.history-header p{color:var(--cdm-text-muted);font-size:11px;margin:2px 0 0}.command-icon,.row-icon{align-items:center;background:var(--cdm-primary-soft);border-radius:10px;color:var(--cdm-primary);display:inline-flex;height:36px;justify-content:center;width:36px}.command-state{align-items:center;background:var(--cdm-surface);border:1px solid var(--cdm-border);border-radius:999px;color:var(--cdm-text-secondary);display:flex;font-size:11px;font-weight:750;gap:6px;padding:6px 10px}.command-state i{background:var(--cdm-success);border-radius:50%;height:7px;width:7px}
.notice-form{display:grid}.notice-composer-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(300px,.72fr)}.composer-fields{display:grid;padding:4px 22px 18px}.composer-section{border-bottom:1px solid var(--cdm-border);display:grid;gap:11px;padding:16px 0}.composer-section:last-child{border-bottom:0}.composer-section>header{align-items:center;color:var(--cdm-primary);display:flex;gap:7px}.composer-section>header h3,.notice-preview>header h3{color:var(--cdm-text);font-size:13px;margin:0}.composer-section>header span{color:var(--cdm-text-muted);font-size:10px;margin-left:auto}.basics-grid{display:grid;gap:10px;grid-template-columns:minmax(240px,2fr) minmax(150px,1fr) minmax(130px,.8fr)}.content-section :deep(textarea){background:color-mix(in srgb,var(--cdm-surface-muted) 44%,var(--cdm-surface));line-height:1.55;min-height:126px}.content-section :deep(.cdm-field>span){clip:rect(0 0 0 0);clip-path:inset(50%);height:1px;overflow:hidden;position:absolute;width:1px}
.audience-chips{display:flex;gap:9px}.audience-chips label,.weekday-picker label{align-items:center;background:var(--cdm-surface);border:1px solid var(--cdm-border-strong);border-radius:10px;color:var(--cdm-text-secondary);cursor:pointer;display:flex;font-size:12px;font-weight:750;gap:7px;min-height:38px;padding:7px 12px}.audience-chips label.selected,.weekday-picker label.selected{background:var(--cdm-primary-soft);border-color:color-mix(in srgb,var(--cdm-primary) 45%,var(--cdm-border));color:var(--cdm-primary)}.audience-chips input,.weekday-picker input{opacity:0;pointer-events:none;position:absolute}.audience-chips b{display:none}.audience-chips label.selected b{display:inline;margin-left:3px}.audience-chips label:focus-within,.weekday-picker label:focus-within{outline:2px solid var(--cdm-focus);outline-offset:2px}
.schedule-grid,.source-grid{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr))}.schedule-options{display:grid;gap:10px;grid-template-columns:repeat(2,minmax(0,1fr))}.switch-row{align-items:center;background:var(--cdm-surface-muted);border:1px solid var(--cdm-border);border-radius:10px;color:var(--cdm-primary);display:flex;gap:9px;padding:9px 11px}.switch-row>input{opacity:0;position:absolute}.switch{background:var(--cdm-border-strong);border-radius:999px;height:20px;position:relative;transition:background-color 150ms;width:36px}.switch::after{background:#fff;border-radius:50%;content:'';height:14px;left:3px;position:absolute;top:3px;transition:transform 150ms;width:14px}.switch-row>input:checked+.switch{background:var(--cdm-primary)}.switch-row>input:checked+.switch::after{transform:translateX(16px)}.switch-row>input:focus-visible+.switch{outline:2px solid var(--cdm-focus);outline-offset:2px}.switch-row>span:last-child{display:grid}.switch-row strong{color:var(--cdm-text);font-size:11px}.switch-row small{color:var(--cdm-text-muted);font-size:10px}.weekday-picker{border:0;display:flex;gap:6px;margin:0;padding:0}.weekday-picker legend{color:var(--cdm-text-muted);font-size:10px;font-weight:750;margin-bottom:6px}.weekday-picker label{border-radius:8px;justify-content:center;min-height:32px;min-width:42px;padding:5px 8px;text-transform:capitalize}.source-grid>*:last-child{grid-column:1/-1}
.notice-preview{background:linear-gradient(155deg,color-mix(in srgb,var(--cdm-primary) 8%,var(--cdm-surface-muted)),var(--cdm-surface-muted));border-left:1px solid var(--cdm-border);display:flex;flex-direction:column;gap:16px;padding:18px}.notice-preview>header{align-items:center;color:var(--cdm-primary);display:flex;gap:7px}.notice-preview>header span{color:var(--cdm-text-muted);font-size:10px;margin-left:auto}.preview-bubble{background:linear-gradient(145deg,var(--cdm-surface),color-mix(in srgb,var(--cdm-primary) 7%,var(--cdm-surface)));border:1px solid color-mix(in srgb,var(--cdm-primary) 38%,var(--cdm-border));border-radius:16px;box-shadow:0 12px 28px rgb(15 49 36 / 9%);display:grid;gap:17px;padding:18px}.preview-bubble[data-priority=urgent]{border-color:var(--cdm-danger)}.preview-bubble[data-priority=high]{border-color:var(--cdm-warning)}.preview-topline,.preview-topline>span,.preview-body,.preview-bubble footer,.preview-bubble footer div,.preview-bubble footer span,.preview-bubble footer small{align-items:center;display:flex}.preview-topline{justify-content:space-between}.preview-topline>span{background:var(--cdm-success-soft);border-radius:999px;color:var(--cdm-success);font-size:9px;font-weight:850;gap:5px;padding:5px 7px;text-transform:uppercase}.preview-topline em{border:1px solid var(--cdm-border);border-radius:999px;color:var(--cdm-text-secondary);font-size:10px;font-style:normal;padding:4px 8px}.preview-body{align-items:flex-start;color:var(--cdm-primary);gap:10px}.preview-body>div{min-width:0}.preview-body strong{color:var(--cdm-text);font-size:17px}.preview-body p{color:var(--cdm-text-secondary);font-size:12px;line-height:1.55;margin:7px 0 0;overflow-wrap:anywhere;white-space:pre-wrap}.preview-bubble footer{border-top:1px solid var(--cdm-border);flex-wrap:wrap;gap:8px;justify-content:space-between;padding-top:12px}.preview-bubble footer div{flex-wrap:wrap;gap:5px}.preview-bubble footer span{background:var(--cdm-primary-soft);border-radius:999px;color:var(--cdm-primary);font-size:9px;gap:4px;padding:4px 7px}.preview-bubble footer small{color:var(--cdm-text-muted);font-size:9px;gap:4px}.preview-facts{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr));margin:0}.preview-facts div{background:color-mix(in srgb,var(--cdm-surface) 78%,transparent);border:1px solid var(--cdm-border);border-radius:10px;padding:9px}.preview-facts dt{color:var(--cdm-text-muted);font-size:9px;text-transform:uppercase}.preview-facts dd{color:var(--cdm-text);font-size:11px;font-weight:750;margin:3px 0 0}
.composer-actions{align-items:center;background:var(--cdm-surface-muted);border-top:1px solid var(--cdm-border);display:flex;gap:9px;justify-content:flex-end;padding:12px 18px}.action-button{align-items:center;border:1px solid transparent;border-radius:9px;cursor:pointer;display:inline-flex;font:inherit;font-size:12px;font-weight:750;gap:7px;justify-content:center;min-height:38px;padding:8px 14px}.action-button.quiet{background:transparent;border-color:var(--cdm-border-strong);color:var(--cdm-text-secondary)}.action-button.secondary{background:var(--cdm-surface);border-color:var(--cdm-border-strong);color:var(--cdm-primary)}.action-button.primary{background:var(--cdm-primary);color:var(--cdm-on-primary);min-width:145px}.action-button:hover:not(:disabled){filter:brightness(.97)}.action-button:focus-visible{outline:2px solid var(--cdm-focus);outline-offset:2px}.action-button:disabled{cursor:not-allowed;opacity:.55}
.notice-history{border-top:1px solid var(--cdm-border);padding:18px 20px 20px}.history-header,.history-header>div,.list-tools,.row-title,.row-actions{align-items:center;display:flex}.history-header{justify-content:space-between}.history-header>div{gap:9px}.history-header>div>svg{color:var(--cdm-primary)}.history-header>span{background:var(--cdm-surface-muted);border-radius:999px;color:var(--cdm-text-muted);font-size:10px;padding:5px 8px}.list-tools{gap:8px;margin:12px 0}.list-tools>:deep(.cdm-field){min-width:155px}.notice-list{border:1px solid var(--cdm-border);border-radius:12px;overflow:hidden}.notice-row{align-items:center;display:grid;gap:12px;grid-template-columns:auto minmax(0,1fr) auto;padding:12px 14px}.notice-row+.notice-row{border-top:1px solid var(--cdm-border)}.notice-row .row-icon{height:32px;width:32px}.row-title{gap:8px}.notice-row h3{font-size:13px;margin:0}.notice-row p{color:var(--cdm-text-secondary);display:-webkit-box;font-size:11px;line-height:1.45;margin:5px 0;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:2}.notice-row small{color:var(--cdm-text-muted);font-size:10px}.row-actions{gap:6px}
@media(max-width:1050px){.notice-composer-layout{grid-template-columns:1fr}.notice-preview{border-left:0;border-top:1px solid var(--cdm-border)}}
@media(max-width:760px){.basics-grid,.schedule-grid,.schedule-options,.source-grid{grid-template-columns:1fr}.source-grid>*:last-child{grid-column:auto}.composer-fields{padding-inline:16px}.notice-row{align-items:start;grid-template-columns:auto 1fr}.row-actions{grid-column:2}.composer-actions{flex-wrap:wrap}.action-button{flex:1 1 auto}}
@media(prefers-reduced-motion:reduce){.switch,.switch::after{transition:none}}
</style>
