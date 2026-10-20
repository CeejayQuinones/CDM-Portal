<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import EnrollmentProfessor from './EnrollmentProfessor.vue'
import EnrollmentStudent from './EnrollmentStudent.vue'
import EnrollmentStaff from './EnrollmentStaff.vue'
import './enrollment.css'
const auth = useAuthStore()
const isStudent = computed(() => [ROLES.GUEST, ROLES.STUDENT].includes(auth.currentRole))
</script>
<template><section class="enrollment-workspace"><EnrollmentStudent v-if="isStudent" :key="auth.currentUser?.id"/><EnrollmentProfessor v-else-if="auth.currentRole===ROLES.PROFESSOR" :key="auth.currentUser?.id"/><EnrollmentStaff v-else :key="`${auth.currentRole}-${auth.currentUser?.id}`"/></section></template>
