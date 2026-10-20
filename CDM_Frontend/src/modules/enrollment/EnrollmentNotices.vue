<script setup>
import { ref,onMounted,onBeforeUnmount } from 'vue'
import { apiClient } from '../../services/apiClient'
const notices=ref([]),error=ref('');let alive=true
async function load(){try{const r=await apiClient.get('/enrollment/academic/notifications');if(alive)notices.value=r.data.data.data}catch{if(alive)error.value='Enrollment updates are temporarily unavailable.'}}
async function read(n){try{await apiClient.patch(`/enrollment/academic/notifications/${n.id}/read`);if(alive)n.read_at=new Date().toISOString()}catch{if(alive)error.value='Could not mark this update as read.'}}
onMounted(load);onBeforeUnmount(()=>{alive=false})
</script>
<template><aside v-if="notices.length||error" class="en-card en-no-print"><h2>Enrollment updates</h2><p v-if="error" role="status">{{ error }}</p><div v-for="n in notices" :key="n.id" class="en-actions"><span>{{ n.data.message }}</span><button v-if="!n.read_at" @click="read(n)">Mark read</button></div></aside></template>
