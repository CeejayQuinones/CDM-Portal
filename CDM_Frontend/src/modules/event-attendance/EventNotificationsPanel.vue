<script setup>
import { onMounted, ref } from 'vue'
import { eventErrorMessage, eventService } from './eventService'
const items = ref([]), unread = ref(0), loading = ref(true), error = ref('')
const fmt = (value) => new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
async function load() { loading.value = true; try { const data = await eventService.notifications(); items.value = data.data || []; unread.value = data.unread_count } catch (cause) { error.value = eventErrorMessage(cause) } finally { loading.value = false } }
async function markRead(item) { if (item.read_at) return; await eventService.readNotification(item.id); item.read_at = new Date().toISOString(); unread.value = Math.max(0, unread.value - 1) }
onMounted(load)
</script>

<template><section class="event-notifications" aria-labelledby="event-notifications-title"><header><div><p class="event-eyebrow">Event updates</p><h2 id="event-notifications-title">Notifications</h2></div><span>{{ unread }} unread</span></header><div v-if="loading" class="event-state">Loading notifications…</div><p v-else-if="error" class="event-alert error" role="alert">{{ error }}</p><div v-else-if="items.length" class="notification-list"><button v-for="item in items" :key="item.id" type="button" :class="{ unread: !item.read_at }" @click="markRead(item)"><span><strong>{{ item.message }}</strong><small>{{ fmt(item.created_at) }}</small></span><i v-if="!item.read_at" aria-label="Unread"></i></button></div><div v-else class="event-state"><strong>No Event notifications</strong><span>Updates and moderator decisions will appear here.</span></div></section></template>
