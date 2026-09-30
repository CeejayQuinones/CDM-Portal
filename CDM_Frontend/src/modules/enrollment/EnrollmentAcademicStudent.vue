<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../../stores/authStore'
import { apiClient } from '../../services/apiClient'
import { label, termLabel, safeError } from './enrollment'
import EnrollmentLoad from './EnrollmentLoad.vue'
import EnrollmentCor from './EnrollmentCor.vue'
import EnrollmentSchedule from './EnrollmentSchedule.vue'
const route=useRoute(),auth=useAuthStore(),data=ref(null),record=ref(null),application=ref(null),busy=ref(false),error=ref(''),page=ref(1)
const subjects=computed(()=>route.name==='enrollment-subjects'),cor=computed(()=>route.name==='enrollment-cor')
let epoch=0
async function load(){const v=++epoch;busy.value=true;error.value='';record.value=null;application.value=null;try{const r=await apiClient.get(subjects.value?'/enrollment/applications/mine':'/enrollment/academic/records',{params:{page:page.value}});if(v!==epoch)return;data.value=r.data.data;if(!subjects.value && route.query.record)await openRecord(Number(route.query.record))}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
async function openRecord(id){const v=++epoch;busy.value=true;error.value='';try{const r=await apiClient.get(`/enrollment/academic/records/${id}`);if(v===epoch)record.value=r.data.data}catch(e){if(v===epoch)error.value=safeError(e)}finally{if(v===epoch)busy.value=false}}
watch(()=>[route.name,auth.currentUser?.id],()=>{data.value=null;page.value=1;load()},{immediate:true});onBeforeUnmount(()=>{epoch++})
</script>
<template><header class="page-header"><p class="page-kicker">Enrollment</p><h1 class="page-title">{{ subjects?'My Subjects':cor?'Certificate of Registration':'My Schedule' }}</h1></header><p v-if="error" role="alert">{{ error }} <button @click="load">Reload</button></p><p v-if="busy" role="status">Loading…</p><div v-if="data" class="en-card"><p v-if="!data.data.length">No {{ subjects?'applications':'academic enrollment records' }} available.</p><div v-for="r in data.data" :key="r.id" class="en-actions"><span>{{ termLabel(subjects?r.period:r) }} · {{ label(r.status) }}</span><button v-if="subjects && ['approved','enrolled'].includes(r.status)" :disabled="busy" @click="application=r.id">View subject load</button><button v-else-if="!subjects && ['enrolled','completed'].includes(r.status)" :disabled="busy" @click="openRecord(r.id)">{{ cor?'View COR':'View schedule' }}</button></div><div v-if="data.last_page>1" class="en-actions"><button :disabled="busy||page<=1" @click="page--;load()">Previous</button><span>Page {{ page }} of {{ data.last_page }}</span><button :disabled="busy||page>=data.last_page" @click="page++;load()">Next</button></div></div><EnrollmentLoad v-if="application" :application-id="application"/><EnrollmentCor v-if="record && cor" :record="record"/><div v-else-if="record" class="en-card"><h2>{{ record.section }} · {{ record.academic_year }} · {{ record.semester }}</h2><EnrollmentSchedule :schedules="record.subjects.filter(s=>s.schedule).map(s=>({...s.schedule,subject:s}))"/></div></template>
