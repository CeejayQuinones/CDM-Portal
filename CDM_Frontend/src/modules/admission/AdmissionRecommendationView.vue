<script setup>
import { computed, onMounted, ref } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { admissionApi, admissionError, topics } from './services/workflowService'
const auth=useAuthStore()
const data=ref(null), loading=ref(false), error=ref(''), interests=ref(Object.fromEntries(topics.map(t=>[t,3])))
const recommendation=computed(()=>data.value?.recommendation)
async function load(generate=false) {
  if(loading.value) return
  loading.value=true; error.value=''
  try { data.value=await admissionApi(generate?'post':'get','recommendation',generate?{interests:interests.value}:undefined); if(Object.keys(data.value.interests).length) interests.value=data.value.interests }
  catch(e) { error.value=e?.response?.status === 403 ? 'Program guidance is not available yet. Check your published result and eligibility on Admission home.' : admissionError(e) }
  finally { loading.value=false }
}
onMounted(()=>load())
</script>
<template><section class="admission-workflow"><p><router-link to="/admission">Admission home</router-link></p><h1>Recommendation</h1><p>Program guidance uses your saved exam category evidence and optional stated interests. It is not admission acceptance.</p><p v-if="loading" role="status">Loading program guidance...</p><p v-if="error" role="alert">{{ error }}</p><button :disabled="loading" @click="load()">Reload guidance</button><template v-if="recommendation"><p v-if="!recommendation.ranked_programs?.length" class="panel">There is not enough assessment evidence to suggest a program yet. Please contact a school adviser.</p><details v-if="auth.currentRole === 'Guest'" class="panel"><summary>Personalize guidance with your interests</summary><form v-if="auth.currentRole === 'Guest'" class="panel" @submit.prevent="load(true)"><h2>Your interests</h2><p>Rate each topic from 1 (low interest) to 5 (high interest).</p><div class="grid"><label v-for="topic in topics" :key="topic">{{ topic }}<select v-model.number="interests[topic]"><option v-for="n in 5" :key="n" :value="n">{{ n }}</option></select></label></div><button class="primary" :disabled="loading">Update guidance</button></form></details><p v-if="recommendation.ai_status === 'unavailable'">Personalized explanations are temporarily unavailable. You can still explore the programs below.</p><p v-if="recommendation.tied_top_codes?.length > 1">Top programs are tied: {{ recommendation.tied_top_codes.join(', ') }}. No single winner is assigned.</p><article v-for="(program,index) in recommendation.ranked_programs" :key="program.course_id" class="panel" :class="{ 'top-program': index === 0 }"><p class="badge">{{ index === 0 ? (recommendation.tied_top_codes?.length > 1 ? 'One of your top matches' : 'Top recommended program') : 'Also consider' }}</p><h2>{{ program.course_name }} ({{ program.course_code }})</h2><p>{{ recommendation.ai_explanations?.[program.course_code]?.explanation || 'This program aligns with your assessment and stated interests. Discuss your options with a school adviser.' }}</p><p v-if="recommendation.ai_explanations?.[program.course_code]?.next_step">{{ recommendation.ai_explanations[program.course_code].next_step }}</p></article><details v-if="recommendation.evidence && Object.keys(recommendation.evidence).length" class="panel"><summary>Assessment evidence</summary><p v-for="(evidence,topic) in recommendation.evidence" :key="topic">{{ topic }}: {{ evidence.correct }} / {{ evidence.total }}</p></details></template></section></template>
<style src="./admission.css"></style>
