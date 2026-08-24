<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import StudentDocumentRequestView from './StudentDocumentRequestView.vue'
import RegistrarDocumentRequestView from './RegistrarDocumentRequestView.vue'

const authStore = useAuthStore()
const isStudent = computed(() => authStore.currentRole === ROLES.STUDENT)
const isRegistrarStaff = computed(() => authStore.currentRole === ROLES.REGISTRAR_STAFF)
</script>

<template>
  <StudentDocumentRequestView v-if="isStudent" />
  <RegistrarDocumentRequestView v-else-if="isRegistrarStaff" />
  <section v-else class="placeholder-panel"><h2>Unauthorized</h2><p>This feature is available to Students and Registrar Staff only.</p></section>
</template>
