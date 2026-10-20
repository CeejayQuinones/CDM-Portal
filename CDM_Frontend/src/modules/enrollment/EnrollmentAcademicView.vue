<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import EnrollmentAcademicStudent from './EnrollmentAcademicStudent.vue'
import EnrollmentAcademicStaff from './EnrollmentAcademicStaff.vue'
import EnrollmentProfessor from './EnrollmentProfessor.vue'
import EnrollmentNotices from './EnrollmentNotices.vue'
import './enrollment.css'
const auth=useAuthStore(),student=computed(()=>auth.currentRole===ROLES.STUDENT),professor=computed(()=>auth.currentRole===ROLES.PROFESSOR)
</script>
<template><section class="enrollment-workspace"><template v-if="student"><EnrollmentNotices :key="`notices-${auth.currentUser?.id}`"/><EnrollmentAcademicStudent :key="auth.currentUser?.id"/></template><EnrollmentProfessor v-else-if="professor" :key="auth.currentUser?.id"/><EnrollmentAcademicStaff v-else :key="`${auth.currentRole}-${auth.currentUser?.id}`"/></section></template>
