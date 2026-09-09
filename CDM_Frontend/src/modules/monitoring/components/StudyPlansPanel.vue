<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { fetchStudyPlans, fetchStudentStudyPlan } from '../services/monitoringApi'
const props = defineProps({ studentId: { type: Number, default: null } })
const plans = ref([]); const error = ref(''); const loading = ref(true)
const selected = computed(() => plans.value.find((plan) => plan.student_id === props.studentId) || plans.value[0])
const load = async () => { loading.value = true; try { const response = await fetchStudyPlans(); plans.value = response.plans || response || [] } catch (err) { error.value = err.response?.data?.message || 'Unable to load study plans.' } finally { loading.value = false } }
const refresh = async () => { if (!selected.value) return; const plan = await fetchStudentStudyPlan(selected.value.student_id); plans.value = [plan, ...plans.value.filter((item) => item.student_id !== plan.student_id)] }
watch(() => props.studentId, () => error.value = '')
onMounted(load)
</script>
<template><section><header><h2>Study Plan</h2><button type="button" :disabled="!selected" @click="refresh">Refresh plan</button></header><p v-if="loading">Preparing study plan...</p><p v-else-if="error" class="error">{{ error }}</p><template v-else-if="selected"><p><strong>{{ selected.risk_level }} risk</strong></p><div v-for="session in selected.week" :key="session.day" class="session"><strong>{{ session.day }}: {{ session.subject_code }}</strong><span>{{ session.focus }}</span><small>{{ session.duration_minutes }} minutes</small></div></template><p v-else>No study plan available.</p></section></template>
<style scoped>section{border:1px solid #d7dfd6;border-radius:8px;padding:16px;background:#fff}header{display:flex;justify-content:space-between;gap:12px;align-items:center}h2{margin:0}.session{display:grid;gap:3px;padding:10px 0;border-top:1px solid #e5e9e5}.session span,small{color:#52605a}.error{color:#a8291f}button{border:1px solid #106a2e;background:#fff;color:#106a2e;border-radius:5px;padding:7px 10px;font-weight:700}</style>
