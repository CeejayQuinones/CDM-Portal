<script setup>
import { onMounted, ref } from 'vue'
import { fetchAdviserAlerts } from '../services/monitoringApi'
const emit = defineEmits(['select-student'])
const alerts = ref([]); const error = ref(''); const loading = ref(true)
const load = async () => { loading.value = true; error.value = ''; try { alerts.value = (await fetchAdviserAlerts()).alerts || [] } catch (err) { error.value = err.response?.data?.message || 'Unable to load adviser alerts.' } finally { loading.value = false } }
onMounted(load)
</script>
<template><section><header><h2>Adviser Alerts</h2><button type="button" @click="load">Refresh</button></header><p v-if="loading">Loading alerts...</p><p v-else-if="error" class="error">{{ error }}</p><ul v-else><li v-for="alert in alerts" :key="alert.id"><strong>{{ alert.risk_level }} risk</strong><span>{{ alert.headline }}</span><button type="button" @click="emit('select-student', alert.student_id)">Review</button></li><li v-if="!alerts.length">No adviser alerts right now.</li></ul></section></template>
<style scoped>section{border:1px solid #d7dfd6;border-radius:8px;padding:16px;background:#fff}header,li{display:flex;gap:12px;align-items:center;justify-content:space-between}h2{margin:0}ul{padding:0;list-style:none}li{padding:12px 0;border-top:1px solid #e5e9e5}span{flex:1;color:#52605a}.error{color:#a8291f}button{border:1px solid #106a2e;background:#fff;color:#106a2e;border-radius:5px;padding:7px 10px;font-weight:700}</style>
