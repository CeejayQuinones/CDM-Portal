import { computed, onScopeDispose, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiClient } from '../services/apiClient'
import { clearGradeRecoveryForAccount } from '../modules/grading/gradeDraftRecovery'

const AUTH_STORAGE_KEY = 'cdm_portal_auth'
const LOGIN_CHALLENGE_STORAGE_KEY = 'cdm_portal_login_challenge'

const readStoredAuth = () => {
  try {
    return JSON.parse(localStorage.getItem(AUTH_STORAGE_KEY)) || {}
  } catch {
    localStorage.removeItem(AUTH_STORAGE_KEY)
    return {}
  }
}

const getErrorMessage = (error) =>
  error.response?.data?.message || error.response?.data?.errors?.username?.[0] || 'Unable to complete the request.'

const readPendingChallenge = () => {
  try {
    const challenge = JSON.parse(localStorage.getItem(LOGIN_CHALLENGE_STORAGE_KEY))
    if (!challenge?.challenge_id || new Date(challenge.expires_at).getTime() <= Date.now()) {
      localStorage.removeItem(LOGIN_CHALLENGE_STORAGE_KEY)
      return null
    }
    return challenge
  } catch {
    localStorage.removeItem(LOGIN_CHALLENGE_STORAGE_KEY)
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const storedAuth = readStoredAuth()
  const token = ref(storedAuth.token || null)
  const currentUser = ref(storedAuth.currentUser || null)
  const currentRole = ref(storedAuth.currentRole || null)
  const firstLogin = ref(Boolean(storedAuth.firstLogin))
  const isInitialized = ref(false)
  const pendingLoginChallenge = ref(readPendingChallenge())

  const isAuthenticated = computed(() => Boolean(token.value && currentUser.value))

  const persist = () => {
    if (!token.value) {
      localStorage.removeItem(AUTH_STORAGE_KEY)
      return
    }

    localStorage.setItem(
      AUTH_STORAGE_KEY,
      JSON.stringify({
        token: token.value,
        currentUser: currentUser.value,
        currentRole: currentRole.value,
        firstLogin: firstLogin.value,
      }),
    )
  }

  const setUser = (user) => {
    currentUser.value = user
    currentRole.value = user?.role?.role_name || user?.role || null
    firstLogin.value = Boolean(user?.is_first_login)
    persist()
  }

  const setPendingLoginChallenge = (challenge) => {
    pendingLoginChallenge.value = challenge
    if (challenge) localStorage.setItem(LOGIN_CHALLENGE_STORAGE_KEY, JSON.stringify(challenge))
    else localStorage.removeItem(LOGIN_CHALLENGE_STORAGE_KEY)
  }

  const clearAuth = () => {
    const accountId = currentUser.value?.id || currentUser.value?.username
    if (accountId && typeof sessionStorage !== 'undefined') clearGradeRecoveryForAccount(sessionStorage, accountId)
    token.value = null
    currentUser.value = null
    currentRole.value = null
    firstLogin.value = false
    localStorage.removeItem(AUTH_STORAGE_KEY)
  }

  const handleInvalidatedSession = () => clearAuth()
  if (typeof window !== 'undefined') {
    window.addEventListener('cdm:auth-invalidated', handleInvalidatedSession)
    onScopeDispose(() => window.removeEventListener('cdm:auth-invalidated', handleInvalidatedSession))
  }

  const login = async (credentials) => {
    setPendingLoginChallenge(null)
    const { data } = await apiClient.post('/login', credentials)
    if (data.data.requires_otp) {
      setPendingLoginChallenge(data.data)
      return { requiresOtp: true, challenge: data.data }
    }
    token.value = data.data.token
    setUser(data.data.user)
    return data.data.user
  }

  const exchangeGoogleCode = async (code, turnstileToken) => {
    setPendingLoginChallenge(null)
    const { data } = await apiClient.post('/auth/google/exchange', { code, turnstile_token: turnstileToken })
    if (data.data.requires_otp) {
      setPendingLoginChallenge(data.data)
      return { requiresOtp: true, challenge: data.data }
    }
    token.value = data.data.token
    setUser(data.data.user)
    return data.data.user
  }

  const verifyLoginOtp = async (code) => {
    if (!pendingLoginChallenge.value?.challenge_id) throw new Error('The login verification session has expired.')
    const { data } = await apiClient.post('/auth/login/otp/verify', {
      challenge_id: pendingLoginChallenge.value.challenge_id,
      code,
    })
    token.value = data.data.token
    setPendingLoginChallenge(null)
    setUser(data.data.user)
    return data.data.user
  }

  const resendLoginOtp = async () => {
    if (!pendingLoginChallenge.value?.challenge_id) throw new Error('The login verification session has expired.')
    const { data } = await apiClient.post('/auth/login/otp/resend', {
      challenge_id: pendingLoginChallenge.value.challenge_id,
    })
    setPendingLoginChallenge(data.data)
    return data.data
  }

  const cancelLoginOtp = () => setPendingLoginChallenge(null)

  const register = async (payload) => {
    const { data } = await apiClient.post('/register', payload)
    return data.data.user
  }

  const fetchCurrentUser = async () => {
    if (!token.value) return null

    try {
      const { data } = await apiClient.get('/me')
      setUser(data.data)
      return data.data
    } catch (error) {
      if ([401, 403].includes(error.response?.status)) clearAuth()
      throw error
    }
  }

  const initialize = async () => {
    if (isInitialized.value) return isAuthenticated.value
    isInitialized.value = true

    if (!token.value) return false
    try {
      await fetchCurrentUser()
    } catch {
      // The response interceptor and clearAuth handle expired or invalid tokens.
    }
    return isAuthenticated.value
  }

  const logout = async () => {
    try {
      if (token.value) await apiClient.post('/logout')
    } finally {
      clearAuth()
    }
  }

  const changePassword = async (payload) => {
    try {
      const { data } = await apiClient.post('/change-password', payload)
      clearAuth()
      return data.data
    } catch (error) {
      throw new Error(getErrorMessage(error))
    }
  }

  return {
    token,
    currentUser,
    currentRole,
    firstLogin,
    isAuthenticated,
    pendingLoginChallenge,
    login,
    exchangeGoogleCode,
    verifyLoginOtp,
    resendLoginOtp,
    cancelLoginOtp,
    register,
    logout,
    fetchCurrentUser,
    changePassword,
    initialize,
    clearAuth,
  }
})
