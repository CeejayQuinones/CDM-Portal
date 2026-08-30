<script setup>
import { computed, onMounted, ref } from 'vue'
import { documentRequestService as api } from './documentRequestService'

const appointmentNeededRequests = ref([])
const appointments = ref([])
const availabilityByMonth = ref({})
const weekendSettings = ref({
  block_saturday: true,
  block_sunday: true,
})
const bookingRequest = ref(null)
const appointmentDate = ref('')
const appointmentTime = ref('')
const slots = ref([])
const loading = ref(false)
const message = ref('')
const error = ref('')
const currentDate = new Date()
const today = dateKey(currentDate.getFullYear(), currentDate.getMonth(), currentDate.getDate())
const calendarMonth = ref(new Date(currentDate.getFullYear(), currentDate.getMonth(), 1))
const weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const requestError = (err) =>
  err.response?.data?.errors?.appointment_date?.[0] ||
  err.response?.data?.message ||
  'The appointment could not be completed.'
const calendarMonthKey = computed(() =>
  `${calendarMonth.value.getFullYear()}-${String(calendarMonth.value.getMonth() + 1).padStart(2, '0')}`,
)
const blockedByDate = computed(() => {
  const blockedDates = availabilityByMonth.value[calendarMonthKey.value] || []

  return blockedDates.reduce((dates, blockedDate) => {
    const existing = dates.get(blockedDate.date)
    dates.set(blockedDate.date, {
      ...blockedDate,
      reason: existing ? `${existing.reason}; ${blockedDate.reason}` : blockedDate.reason,
    })

    return dates
  }, new Map())
})
const selectedUnavailable = computed(() => {
  if (!appointmentDate.value) return null

  const blockedDate = Object.values(availabilityByMonth.value)
    .flat()
    .find((item) => item.date === appointmentDate.value)

  if (blockedDate) return blockedDate

  const [year, month, day] = appointmentDate.value.split('-').map(Number)
  const weekend = blockedWeekend(new Date(year, month - 1, day))
  if (weekend) return weekend

  return null
})
const calendarLabel = computed(() =>
  new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(calendarMonth.value),
)
const isCurrentMonth = computed(
  () =>
    calendarMonth.value.getFullYear() === currentDate.getFullYear() &&
    calendarMonth.value.getMonth() === currentDate.getMonth(),
)
const calendarDays = computed(() => {
  const year = calendarMonth.value.getFullYear()
  const month = calendarMonth.value.getMonth()
  const leadingDays = new Date(year, month, 1).getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const days = Array.from({ length: leadingDays }, (_, index) => ({ key: `blank-${index}`, blank: true }))

  for (let day = 1; day <= daysInMonth; day += 1) {
    const date = dateKey(year, month, day)
    const blockedDate = blockedByDate.value.get(date) || null
    const weekend = blockedWeekend(new Date(year, month, day))
    const unavailable = blockedDate || weekend

    days.push({
      key: date,
      blank: false,
      date,
      day,
      unavailable,
      disabled: date < today || Boolean(unavailable),
    })
  }

  return days
})

function dateKey(year, month, day) {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function blockedWeekend(date) {
  if (date.getDay() === 6 && weekendSettings.value.block_saturday) {
    return { reason: 'Saturday', type: 'weekend' }
  }

  if (date.getDay() === 0 && weekendSettings.value.block_sunday) {
    return { reason: 'Sunday', type: 'weekend' }
  }

  return null
}

async function changeMonth(offset) {
  appointmentDate.value = ''
  appointmentTime.value = ''
  slots.value = []
  calendarMonth.value = new Date(
    calendarMonth.value.getFullYear(),
    calendarMonth.value.getMonth() + offset,
    1,
  )
  await loadAvailability()
}

async function openBooking(item) {
  bookingRequest.value = item
  appointmentDate.value = ''
  appointmentTime.value = ''
  slots.value = []
  calendarMonth.value = new Date(currentDate.getFullYear(), currentDate.getMonth(), 1)
  await loadAvailability()
}

function closeBooking() {
  bookingRequest.value = null
  appointmentDate.value = ''
  appointmentTime.value = ''
  slots.value = []
}

async function selectDate(day) {
  if (day.disabled) return
  appointmentDate.value = day.date
  await loadSlots()
}

async function loadAvailability() {
  const month = calendarMonthKey.value
  if (Object.hasOwn(availabilityByMonth.value, month)) return

  try {
    const availability = await api.appointmentAvailability(month)
    availabilityByMonth.value = {
      ...availabilityByMonth.value,
      [month]: availability.blocked_dates,
    }
    weekendSettings.value = {
      block_saturday: availability.settings?.block_saturday ?? true,
      block_sunday: availability.settings?.block_sunday ?? true,
    }
  } catch (err) {
    error.value = requestError(err)
  }
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    const overview = await api.appointmentOverview()
    appointmentNeededRequests.value = overview.requests_needing_appointment
    appointments.value = overview.appointments
  } catch (err) {
    error.value = requestError(err)
  } finally {
    loading.value = false
  }
}

async function loadSlots() {
  appointmentTime.value = ''
  slots.value = []
  if (!appointmentDate.value || selectedUnavailable.value) return
  try {
    slots.value = await api.slots(appointmentDate.value)
  } catch (err) {
    error.value = requestError(err)
  }
}

async function book() {
  if (!bookingRequest.value) return
  error.value = ''
  message.value = ''
  if (selectedUnavailable.value) {
    error.value = `${selectedUnavailable.value.reason} is unavailable for appointments.`
    appointmentTime.value = ''
    slots.value = []

    return
  }
  loading.value = true
  try {
    await api.book(bookingRequest.value.id, {
      appointment_date: appointmentDate.value,
      appointment_time: appointmentTime.value,
    })
    closeBooking()
    message.value = 'Appointment booked. Registrar staff will confirm it.'
    await refresh()
  } catch (err) {
    error.value = requestError(err)
    await loadSlots()
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await loadAvailability()
  await refresh()
})
</script>

<template>
  <section class="page-header">
    <p class="page-kicker">Student Services</p>
    <h1 class="page-title">Appointments</h1>
    <p class="page-description">Book and track appointments for document requests that require one.</p>
  </section>

  <p v-if="message" class="notice success">{{ message }}</p>
  <p v-if="error" class="notice error">{{ error }}</p>

  <section class="dr-panel">
    <h2>Requests needing an appointment</h2>
    <p v-if="loading && !appointmentNeededRequests.length" class="empty">Loading appointments…</p>
    <p v-else-if="!appointmentNeededRequests.length" class="empty">No requests currently need an appointment.</p>
    <div v-for="item in appointmentNeededRequests" :key="item.id" class="request-line">
      <span>
        <strong>{{ item.document_type.document_name }}</strong>
        <small>Request #{{ item.id }} · {{ item.status.replaceAll('_', ' ') }}</small>
      </span>
      <button type="button" @click="openBooking(item)">Book appointment</button>
    </div>
  </section>

  <section v-if="bookingRequest" class="dr-panel">
    <h2>Book: {{ bookingRequest.document_type.document_name }}</h2>
    <form class="form-grid" @submit.prevent="book">
      <fieldset class="appointment-calendar wide">
        <legend>Date</legend>
        <div class="calendar-toolbar">
          <button type="button" class="calendar-nav" :disabled="isCurrentMonth" aria-label="Previous month" @click="changeMonth(-1)">
            &lsaquo;
          </button>
          <strong>{{ calendarLabel }}</strong>
          <button type="button" class="calendar-nav" aria-label="Next month" @click="changeMonth(1)">&rsaquo;</button>
        </div>
        <div class="calendar-grid" role="grid" :aria-label="calendarLabel">
          <span v-for="weekday in weekdayLabels" :key="weekday" class="calendar-weekday" role="columnheader">
            {{ weekday }}
          </span>
          <template v-for="day in calendarDays" :key="day.key">
            <span v-if="day.blank" class="calendar-blank" aria-hidden="true"></span>
            <button
              v-else
              type="button"
              class="calendar-day"
              :class="{ selected: appointmentDate === day.date, unavailable: day.unavailable }"
              :disabled="day.disabled"
              :title="day.unavailable ? `${day.unavailable.reason} — Unavailable` : day.date"
              :aria-label="day.unavailable ? `${day.date}: ${day.unavailable.reason}, unavailable` : day.date"
              @click="selectDate(day)"
            >
              <span>{{ day.day }}</span>
              <small v-if="day.unavailable">{{ day.unavailable.reason }}</small>
            </button>
          </template>
        </div>
        <p v-if="appointmentDate" class="calendar-selection">Selected date: {{ appointmentDate }}</p>
      </fieldset>
      <label>
        Available time
        <select v-model="appointmentTime" required :disabled="!appointmentDate || Boolean(selectedUnavailable)">
          <option value="" disabled>Select a time</option>
          <option v-for="slot in slots.filter((slot) => slot.available)" :key="slot.time" :value="slot.time">
            {{ slot.time }}
          </option>
        </select>
      </label>
      <button :disabled="loading || !appointmentDate || !appointmentTime || Boolean(selectedUnavailable)">
        Confirm appointment
      </button>
      <button type="button" class="secondary" @click="closeBooking">Cancel</button>
    </form>
  </section>

  <section class="dr-panel">
    <h2>Your appointments</h2>
    <p v-if="!appointments.length" class="empty">No appointments booked yet.</p>
    <div v-for="appointment in appointments" :key="appointment.id" class="appointment">
      <span>
        <strong>{{ appointment.document_request.document_type.document_name }}</strong>
        · Request #{{ appointment.document_request_id }} · {{ appointment.appointment_date }} at
        {{ String(appointment.appointment_time).slice(0, 5) }}
      </span>
      <span class="badge" :class="appointment.status">{{ appointment.status.replaceAll('_', ' ') }}</span>
    </div>
  </section>
</template>

<style scoped src="./documentRequest.css"></style>
