import { readonly, ref } from 'vue'

const appointmentAvailabilityOpen = ref(false)

export const useAppointmentAvailabilityState = () => ({
  isOpen: readonly(appointmentAvailabilityOpen),
  open: () => {
    appointmentAvailabilityOpen.value = true
  },
  close: () => {
    appointmentAvailabilityOpen.value = false
  },
})
