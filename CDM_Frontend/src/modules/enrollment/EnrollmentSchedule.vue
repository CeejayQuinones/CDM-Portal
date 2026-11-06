<script setup>
import { computed, ref } from 'vue'
import { clientPlatform } from '../../config/clientPlatform'
import { hasSavedSchedule } from './enrollment'
import WebsiteActionBar from '../../components/website/WebsiteActionBar.vue'
import WebsiteEmptyState from '../../components/website/WebsiteEmptyState.vue'

const isWebsitePresentation = clientPlatform === 'web'
const isDesktopPresentation = clientPlatform === 'desktop'
const props = defineProps({ schedules: { type: Array, default: () => [] } })
const view = ref(isDesktopPresentation ? 'timetable' : 'cards')
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const scheduled = computed(() => props.schedules.flatMap(schedule => {
  const meetings = schedule.meetings?.length ? schedule.meetings : hasSavedSchedule(schedule) ? [{ day: schedule.day, start_time: schedule.start_time, end_time: schedule.end_time, room: schedule.room }] : []
  return meetings.map(meeting => ({ ...schedule, ...meeting, meeting_key: `${schedule.id}:${meeting.day}` }))
}).sort((left, right) => days.indexOf(left.day) - days.indexOf(right.day) || String(left.start_time).localeCompare(String(right.start_time))))
const unscheduled = computed(() => props.schedules.filter(schedule => !hasSavedSchedule(schedule)).sort((left, right) => String(left.subject?.subject_code || '').localeCompare(String(right.subject?.subject_code || ''))))
const ordered = computed(() => [...scheduled.value, ...unscheduled.value])
const visibleDays = computed(() => days.filter(day => day !== 'Sunday' || scheduled.value.some(schedule => schedule.day === day)))
const toMinutes = value => { const [hours = 0, minutes = 0] = String(value || '').split(':').map(Number); return hours * 60 + minutes }
const rangeStart = computed(() => scheduled.value.length ? Math.max(0, Math.floor(Math.min(...scheduled.value.map(schedule => toMinutes(schedule.start_time))) / 60) * 60) : 7 * 60)
const rangeEnd = computed(() => scheduled.value.length ? Math.min(24 * 60, Math.ceil(Math.max(...scheduled.value.map(schedule => toMinutes(schedule.end_time))) / 60) * 60) : 18 * 60)
const timeLabels = computed(() => { const labels = []; for (let minute = rangeStart.value; minute <= rangeEnd.value; minute += 60) labels.push(minute); return labels })
const timetableHeight = computed(() => Math.max(440, ((rangeEnd.value - rangeStart.value) / 60) * 52))
const timeLabel = minute => `${String(Math.floor(minute / 60)).padStart(2, '0')}:00`
const professorName = schedule => [schedule.professor?.user?.profile?.first_name, schedule.professor?.user?.profile?.last_name].filter(Boolean).join(' ') || 'Not assigned'
const scheduleKey = schedule => schedule.meeting_key || `${schedule.id}:${schedule.day}:${schedule.start_time}:${schedule.end_time}`
const overlapKeys = computed(() => {
  const overlaps = new Set()
  for (const day of visibleDays.value) {
    const meetings = scheduled.value.filter(schedule => schedule.day === day)
    meetings.forEach((left, index) => meetings.slice(index + 1).forEach(right => {
      if (toMinutes(left.start_time) < toMinutes(right.end_time) && toMinutes(right.start_time) < toMinutes(left.end_time)) {
        overlaps.add(scheduleKey(left)); overlaps.add(scheduleKey(right))
      }
    }))
  }
  return overlaps
})
const blockStyle = schedule => {
  const pixelsPerMinute = 52 / 60
  const top = Math.max(0, (toMinutes(schedule.start_time) - rangeStart.value) * pixelsPerMinute)
  const height = Math.max(40, (toMinutes(schedule.end_time) - toMinutes(schedule.start_time)) * pixelsPerMinute)
  return { top: `${top}px`, height: `${height}px` }
}
const print = () => window.print()
</script>

<template>
  <section class="en-schedule" :class="{ 'en-desktop-schedule': isDesktopPresentation }">
    <component :is="isWebsitePresentation ? WebsiteActionBar : 'div'" class="en-actions en-no-print schedule-view-switcher" label="Schedule views">
      <button v-for="option in ['cards','timetable','list']" :key="option" type="button" :class="{ active: view===option }" :aria-pressed="view===option" @click="view=option">{{ option.charAt(0).toUpperCase()+option.slice(1) }}</button>
      <button type="button" class="en-secondary" @click="print">Print schedule</button>
    </component>
    <p v-if="isDesktopPresentation && view==='timetable' && schedules.length" class="en-view-hint">Weekly overview · Switch to Cards or List to manage individual entries.</p>
    <WebsiteEmptyState v-if="isWebsitePresentation&&!schedules.length" title="No scheduled classes" message="Class times and rooms will appear after an authorized schedule is assigned."/>
    <div v-else-if="!schedules.length" class="en-modern-empty"><h2>No scheduled classes</h2><p>Authorized subject assignments and class times will appear here.</p></div>

    <div v-if="view==='cards'" class="en-grid schedule-card-grid">
      <article v-for="schedule in ordered" :key="schedule.id" class="en-card schedule-class-card" :class="{ 'ui4-schedule-card': isWebsitePresentation, 'en-desktop-class-card': isDesktopPresentation }">
        <template v-if="isWebsitePresentation">
          <div class="ui4-schedule-heading"><span>{{ schedule.subject?.subject_code || 'Subject' }}</span><strong>{{ hasSavedSchedule(schedule) ? schedule.day : 'Schedule pending' }}</strong></div>
          <h3>{{ schedule.subject?.subject_name || 'Subject details unavailable' }}</h3>
          <dl class="ui4-schedule-details"><div><dt>Time</dt><dd>{{ hasSavedSchedule(schedule) ? `${schedule.start_time.slice(0,5)}–${schedule.end_time.slice(0,5)}` : 'Time pending' }}</dd></div><div><dt>Room</dt><dd>{{ hasSavedSchedule(schedule) ? (schedule.room || 'Not assigned') : 'Not assigned' }}</dd></div><div><dt>Professor</dt><dd>{{ professorName(schedule) }}</dd></div></dl>
        </template>
        <template v-else-if="isDesktopPresentation">
          <header><span>{{ schedule.subject?.subject_code || 'Subject' }}</span><strong>{{ hasSavedSchedule(schedule) ? schedule.day : 'Schedule pending' }}</strong></header><div><h3>{{ schedule.subject?.subject_name || 'Subject details unavailable' }}</h3><dl><div><dt>Time</dt><dd>{{ hasSavedSchedule(schedule) ? `${schedule.start_time.slice(0,5)}–${schedule.end_time.slice(0,5)}` : 'To be announced' }}</dd></div><div><dt>Room</dt><dd>{{ hasSavedSchedule(schedule) ? schedule.room : 'To be announced' }}</dd></div><div><dt>Professor</dt><dd>{{ professorName(schedule) }}</dd></div></dl></div>
        </template>
        <template v-else><h3>{{ schedule.subject?.subject_code }}</h3><p>{{ schedule.subject?.subject_name }}</p><p>{{ hasSavedSchedule(schedule)?`${schedule.day} · ${schedule.start_time.slice(0,5)}–${schedule.end_time.slice(0,5)}`:'Schedule pending' }}</p><p>{{ hasSavedSchedule(schedule)?schedule.room:'Room: To be announced' }} · {{ professorName(schedule) }}</p></template>
        <slot name="actions" :schedule="schedule"/>
      </article>
    </div>

    <template v-else-if="view==='timetable'">
      <div v-if="isDesktopPresentation && scheduled.length" class="en-weekly-timetable" role="region" aria-label="Weekly section timetable" tabindex="0">
        <div class="en-weekly-head" :style="{ gridTemplateColumns: `64px repeat(${visibleDays.length}, minmax(120px, 1fr))` }"><span>Time</span><strong v-for="day in visibleDays" :key="day">{{ day }}</strong></div>
        <div class="en-weekly-body" :style="{ gridTemplateColumns: `64px repeat(${visibleDays.length}, minmax(120px, 1fr))`, height: `${timetableHeight}px` }">
          <div class="en-time-axis"><span v-for="minute in timeLabels" :key="minute" :style="{ top: `${(minute-rangeStart)*(52/60)}px` }">{{ timeLabel(minute) }}</span></div>
          <div v-for="day in visibleDays" :key="day" class="en-day-column"><article v-for="schedule in scheduled.filter(item=>item.day===day)" :key="schedule.id" class="en-timetable-block" :class="{ 'has-overlap': overlapKeys.has(scheduleKey(schedule)) }" :style="blockStyle(schedule)"><strong>{{ schedule.subject?.subject_code }}</strong><span>{{ schedule.start_time.slice(0,5) }}–{{ schedule.end_time.slice(0,5) }}</span><small>{{ schedule.room }} · {{ professorName(schedule) }}</small><slot name="actions" :schedule="schedule"/></article></div>
        </div>
      </div>
      <div v-else class="en-timetable"><section v-for="day in days" :key="day"><h3>{{ day }}</h3><article v-for="schedule in scheduled.filter(item=>item.day===day)" :key="schedule.id" class="en-time-class"><strong>{{ schedule.start_time.slice(0,5) }}–{{ schedule.end_time.slice(0,5) }}</strong><p>{{ schedule.subject?.subject_code }} · {{ schedule.room }}</p><slot name="actions" :schedule="schedule"/></article></section></div>
      <section v-if="unscheduled.length" class="en-unscheduled-list"><header><h3>Schedule pending</h3><span>{{ unscheduled.length }}</span></header><article v-for="schedule in unscheduled" :key="schedule.id"><div><strong>{{ schedule.subject?.subject_code }}</strong><span>{{ schedule.subject?.subject_name }}</span></div><small>Day, time, and room are to be announced.</small><slot name="actions" :schedule="schedule"/></article></section>
    </template>

    <div v-else class="en-table en-schedule-list"><table><thead><tr><th>Subject</th><th>Day / time</th><th>Room / Professor</th><th class="en-no-print">Actions</th></tr></thead><tbody><tr v-for="schedule in ordered" :key="schedule.id"><td><strong>{{ schedule.subject?.subject_code }}</strong><br>{{ schedule.subject?.subject_name }}</td><td>{{ hasSavedSchedule(schedule)?`${schedule.day} ${schedule.start_time.slice(0,5)}–${schedule.end_time.slice(0,5)}`:'Schedule pending' }}</td><td>{{ hasSavedSchedule(schedule)?schedule.room:'To be announced' }} · {{ professorName(schedule) }}</td><td class="en-no-print"><slot name="actions" :schedule="schedule"/></td></tr></tbody></table></div>
  </section>
</template>

<style scoped>
.ui4-schedule-card { overflow: hidden; padding: 0; border: 1px solid var(--website-border); border-radius: 10px; background: var(--website-card); box-shadow: 0 1px 3px rgb(15 23 42 / 5%); }
.ui4-schedule-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-bottom: 1px solid var(--website-border); background: var(--website-hover); color: var(--website-success); font-size: 12px; font-weight: 800; }
.ui4-schedule-heading strong { color: var(--website-ink); font-size: 12px; }
.ui4-schedule-card h3 { margin: 0; padding: 16px 16px 8px; color: var(--website-ink); font-size: 16px; }
.ui4-schedule-details { display: grid; gap: 9px; margin: 0; padding: 4px 16px 18px; }
.ui4-schedule-details div { display: flex; gap: 12px; justify-content: space-between; min-width: 0; }
.ui4-schedule-details dt { color: var(--website-muted); font-size: 12px; }
.ui4-schedule-details dd { margin: 0; color: var(--website-ink); font-size: 12px; font-weight: 700; text-align: right; overflow-wrap: anywhere; }
@media (max-width: 640px) { .ui4-schedule-heading { padding: 12px 14px; } }
</style>
