<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiClient } from '../../services/apiClient'

const route = useRoute()
const router = useRouter()
const student = ref(null)
const documents = ref([])
const loading = ref(false)
const error = ref('')
const title = (value) => value ? String(value).replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Not available'
const date = (value) => value ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'long' }).format(new Date(`${value}T00:00:00`)) : 'Not available'

async function loadDocuments() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await apiClient.get(`/students/${route.params.id}/documents`)
    student.value = data.data.student
    documents.value = data.data.documents
  } catch (requestError) {
    error.value = requestError.response?.status === 404 ? 'This student record could not be found.' : requestError.response?.data?.message || 'Unable to load student documents.'
  } finally {
    loading.value = false
  }
}
watch(() => route.params.id, loadDocuments)
onMounted(loadDocuments)
</script>

<template>
  <section class="page-header"><p class="page-kicker">Registrar / Student Management</p><h1 class="page-title">Student Documents</h1><p class="page-description">Read-only view of documents submitted for this student.</p></section>
  <section v-if="loading" class="panel state">Loading student documents…</section><section v-else-if="error" class="panel state error" role="alert">{{ error }}</section>
  <template v-else-if="student">
    <section class="summary"><div><p>Student Number</p><strong>{{ student.student_number }}</strong></div><div><p>Name</p><strong>{{ student.full_name }}</strong></div><div><p>Course</p><strong>{{ student.course?.code }} — {{ student.course?.name }}</strong></div><div><p>Year</p><strong>Year {{ student.year_level }}</strong></div><div><p>Status</p><span class="badge">{{ title(student.student_status) }}</span></div></section>
    <section v-if="!documents.length" class="panel state"><h2>No submitted documents</h2><p>There are no student documents available in the registrar record yet.</p></section>
    <section v-else class="documents"><article v-for="document in documents" :key="document.id" class="document-card"><div class="doc-head"><h2>{{ document.name }}</h2><span class="badge">{{ title(document.status) }}</span></div><dl><div><dt>Submitted Date</dt><dd>{{ date(document.submitted_date) }}</dd></div><div><dt>Verified Date</dt><dd>{{ date(document.verified_date) }}</dd></div><div><dt>Verified By</dt><dd>{{ document.verified_by || 'Not available' }}</dd></div><div class="remarks"><dt>Remarks</dt><dd>{{ document.remarks || 'No remarks' }}</dd></div></dl><div class="document-actions"><button disabled>View</button><button disabled>Download</button><button disabled>Upload</button><button disabled>Delete</button></div></article></section>
    <footer class="back-bar"><button type="button" @click="router.push({ name: 'student-details', params: { id: student.id } })">← Back to Student Profile</button></footer>
  </template>
</template>

<style scoped>
.panel,.summary,.document-card { background:var(--color-surface); border:1px solid var(--color-border); border-radius:10px; box-shadow:var(--shadow-soft); margin-bottom:20px; padding:22px; }.summary { display:grid; gap:14px; grid-template-columns:repeat(5,minmax(0,1fr)); }.summary p,dt { color:var(--color-muted); font-size:.75rem; font-weight:700; margin:0 0 5px; text-transform:uppercase; }.summary strong { font-size:.92rem; overflow-wrap:anywhere; }.badge { background:var(--color-green-tint); border-radius:999px; color:var(--color-dartmouth-green); display:inline-block; font-size:.78rem; font-weight:700; padding:5px 9px; }.documents { display:grid; gap:18px; }.document-card { margin:0; }.doc-head { align-items:center; display:flex; gap:12px; justify-content:space-between; }.doc-head h2 { font-size:1.1rem; margin:0; }.document-card dl { display:grid; gap:12px; grid-template-columns:repeat(3,minmax(0,1fr)); }.document-card dl div { background:var(--color-anti-flash-white); border-radius:6px; padding:10px; }.document-card dd { margin:0; overflow-wrap:anywhere; }.remarks { grid-column:span 2; }.document-actions,.back-bar { display:flex; flex-wrap:wrap; gap:8px; margin-top:16px; }.document-actions button,.back-bar button { border:0; border-radius:6px; min-height:38px; padding:8px 12px; }.document-actions button { background:var(--color-anti-flash-white); color:var(--color-muted); cursor:not-allowed; }.back-bar button { background:var(--color-dartmouth-green); color:#fff; cursor:pointer; }.state { color:var(--color-muted); text-align:center; }.state h2 { color:var(--color-eerie-black); margin-top:0; }.error { background:#fce8e8; color:#9c2222; }@media(max-width:850px){.summary{grid-template-columns:repeat(2,minmax(0,1fr));}.document-card dl{grid-template-columns:repeat(2,minmax(0,1fr));}}@media(max-width:500px){.summary,.document-card dl{grid-template-columns:1fr;}.panel,.summary,.document-card{padding:16px;}.remarks{grid-column:auto;}.document-actions button,.back-bar button{flex:1;}}
</style>
