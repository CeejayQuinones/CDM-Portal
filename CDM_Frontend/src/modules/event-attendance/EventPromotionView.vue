<script setup>
import { onMounted, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'
import './event.css'

const events = ref([])
const selected = ref(null)
const loading = ref(true)
const error = ref('')
const search = ref('')
const fmt = (value) => new Intl.DateTimeFormat(undefined, { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value))

async function load() {
  loading.value = true
  error.value = ''
  try { events.value = (await eventService.promotions({ search: search.value || undefined })).data || [] }
  catch (cause) { error.value = eventErrorMessage(cause) }
  finally { loading.value = false }
}
async function inspect(event) {
  error.value = ''
  try { selected.value = await eventService.promotion(event.id) }
  catch (cause) { error.value = eventErrorMessage(cause) }
}
onMounted(load)
</script>

<template>
  <main class="event-page event-promotion-page">
    <header class="event-hero"><div><p class="event-eyebrow">Campus life</p><h1>Events</h1><p>Discover upcoming activities and gatherings at Colegio de Montalban.</p></div></header>
    <p v-if="error" class="event-alert error" role="alert">{{ error }}</p>
    <form class="event-toolbar" aria-label="Search Events" @submit.prevent="load"><label><span>Search upcoming Events</span><input v-model.trim="search" type="search" placeholder="Title, description, or venue" /></label><button class="event-button primary" type="submit">Search</button></form>
    <div v-if="loading" class="event-state" aria-live="polite">Loading upcoming Events…</div>
    <div v-else-if="!events.length" class="event-state"><strong>No upcoming Events</strong><span>New campus activities will appear here when published.</span></div>
    <section v-else class="promotion-grid" aria-label="Upcoming Events">
      <article v-for="event in events" :key="event.id" class="promotion-card">
        <div class="promotion-poster" aria-hidden="true"><span>CDM</span></div>
        <div><span class="event-badge" :data-status="event.status">{{ event.status }}</span><h2>{{ event.title }}</h2><p>{{ event.description || 'More Event information will be announced soon.' }}</p><dl><div><dt>Date and time</dt><dd>{{ fmt(event.starts_at) }}</dd></div><div><dt>Venue</dt><dd>{{ event.venue }}</dd></div><div><dt>Organizer</dt><dd>{{ event.organizer }}</dd></div></dl><button class="event-button secondary" type="button" @click="inspect(event)">View Details</button></div>
      </article>
    </section>
    <div v-if="selected" class="event-modal-backdrop" @click.self="selected = null"><section class="event-modal promotion-detail" role="dialog" aria-modal="true" aria-labelledby="promotion-title" tabindex="-1" @keydown.esc="selected = null"><header><h2 id="promotion-title">{{ selected.title }}</h2><button type="button" aria-label="Close Event details" @click="selected = null">×</button></header><p>{{ selected.description || 'More Event information will be announced soon.' }}</p><dl><div><dt>Starts</dt><dd>{{ fmt(selected.starts_at) }}</dd></div><div><dt>Ends</dt><dd>{{ fmt(selected.ends_at) }}</dd></div><div><dt>Venue</dt><dd>{{ selected.venue }}</dd></div><div><dt>Audience</dt><dd>{{ selected.public_audience }}</dd></div><div><dt>Organizer</dt><dd>{{ selected.organizer }}</dd></div></dl></section></div>
  </main>
</template>
