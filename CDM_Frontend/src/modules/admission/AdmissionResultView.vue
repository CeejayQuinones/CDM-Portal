<script setup>
import { useAuthStore } from '../../stores/authStore'
import { onMounted, ref } from 'vue'
import { admissionApi, admissionError } from './services/workflowService'
const auth = useAuthStore()
const data = ref(null), loading = ref(true), error = ref('')
async function load() { loading.value=true; error.value=''; try { data.value=await admissionApi('get','result') } catch(e) { error.value=admissionError(e) } finally { loading.value=false } }
onMounted(load)
</script>
<template>
<section class="admission-workflow admission-applicant"><header class="page-header"><p class="page-kicker"><router-link to="/admission">Admission home</router-link></p><h1 class="page-title">Result</h1><p class="page-description">Your published entrance exam result and next step.</p></header>
<p v-if="loading" class="placeholder-panel panel status-block" role="status">Loading result...</p><p v-if="error" class="placeholder-panel panel status-block" role="alert">{{ error }}</p>
<div v-if="data?.published && !loading" class="placeholder-panel panel result-card reveal" :class="data.result.outcome === 'PASSED' ? 'result-passed' : 'result-not-passed'"><span class="badge">Published result</span><h2 class="result-outcome">{{ data.result.outcome === 'PASSED' ? 'Passed' : 'Not Passed' }}</h2>
<dl class="summary-grid"><div><dt>Official score</dt><dd>{{ data.result.score === null ? 'Not disclosed' : data.result.score + ' / 100' }}</dd></div><div><dt>Attempt</dt><dd>{{ data.result.attempt_number }} of {{ data.result.max_attempts || 2 }}</dd></div><div><dt>Published</dt><dd>{{ new Date(data.result.published_at).toLocaleString() }}</dd></div></dl>
<p v-if="(data.result.retake_eligible && auth.currentRole === 'Guest')">You are eligible for one retake. Review the instructions when you are ready.</p><p v-else-if="data.result.outcome === 'FAILED'">Your two exam attempts are exhausted. Contact the Registrar for guidance.</p><p v-else>Contact the Registrar for the next admission steps.</p>
<router-link class="link-button primary" :to="(data.result.retake_eligible && auth.currentRole === 'Guest') ? '/admission/exam' : '/admission/recommendation'">{{ (data.result.retake_eligible && auth.currentRole === 'Guest') ? 'Take Retake' : 'View Recommendation' }}</router-link>
</div><div v-else-if="data && !loading" class="placeholder-panel panel"><h2>Result not published yet</h2><p>Your result will appear here after your exam is reviewed and published by the Registrar.</p><router-link to="/admission">Check your next step on Admission home</router-link></div>
<button :disabled="loading" @click="load">Refresh result</button></section>
</template>
<style src="./admission.css"></style>
