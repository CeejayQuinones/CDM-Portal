import { readonly, ref } from 'vue'
import { apiClient } from '../services/apiClient'

const isOpen = ref(false)
const verifying = ref(false)
const verificationError = ref('')
let pendingRequest = null

const isStepUpRequired = (error) =>
  error.response?.status === 428 && error.response?.data?.code === 'STEP_UP_REQUIRED'

export const isStepUpCancelled = (error) => error?.code === 'STEP_UP_CANCELLED'

export function useStepUpAuth() {
  const runWithStepUp = async (request) => {
    try {
      return await request()
    } catch (error) {
      if (!isStepUpRequired(error)) throw error

      return new Promise((resolve, reject) => {
        if (pendingRequest) {
          reject(new Error('Another sensitive request is already awaiting identity verification.'))
          return
        }

        pendingRequest = { request, resolve, reject }
        verificationError.value = ''
        isOpen.value = true
      })
    }
  }

  const verify = async (password) => {
    verifying.value = true
    verificationError.value = ''

    try {
      await apiClient.post('/step-up/verify', { password })

      const requestToRetry = pendingRequest
      pendingRequest = null
      isOpen.value = false

      if (requestToRetry) {
        requestToRetry.request().then(requestToRetry.resolve).catch(requestToRetry.reject)
      }

      return true
    } catch (error) {
      verificationError.value =
        error.response?.data?.errors?.password?.[0] ||
        error.response?.data?.message ||
        'Unable to verify your identity. Please try again.'
      return false
    } finally {
      verifying.value = false
    }
  }

  const cancel = () => {
    const requestToCancel = pendingRequest
    pendingRequest = null
    isOpen.value = false
    verificationError.value = ''

    if (requestToCancel) {
      const error = new Error('Identity verification was cancelled.')
      error.code = 'STEP_UP_CANCELLED'
      requestToCancel.reject(error)
    }
  }

  return {
    isOpen: readonly(isOpen),
    verifying: readonly(verifying),
    verificationError: readonly(verificationError),
    runWithStepUp,
    verify,
    cancel,
  }
}
