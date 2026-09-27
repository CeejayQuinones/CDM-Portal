<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../../stores/authStore'
import { ROLES } from '../../config/accessControl'
import EnrollmentStudent from './EnrollmentStudent.vue'
import EnrollmentStaff from './EnrollmentStaff.vue'
import './enrollment.css'
const auth = useAuthStore()
const isStudent = computed(() => auth.currentRole === ROLES.STUDENT)
</script>
<template><section class="enrollment-workspace"><EnrollmentStudent v-if="isStudent" :key="auth.currentUser?.id"/><EnrollmentStaff v-else :key="`${auth.currentRole}-${auth.currentUser?.id}`"/></section></template>
