import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiClient } from '../services/apiClient'
import { ROLES } from '../config/accessControl'
import { isStudentMobileApp, STUDENT_MOBILE_ONLY_MESSAGE } from '../utils/platform'

const AUTH_STORAGE_KEY = 'cdm_portal_auth'

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

export const useAuthStore = defineStore('auth', () => {
  const storedAuth = readStoredAuth()
  const token = ref(storedAuth.token || null)
  const currentUser = ref(storedAuth.currentUser || null)
  const currentRole = ref(storedAuth.currentRole || null)
  const firstLogin = ref(Boolean(storedAuth.firstLogin))
  const isInitialized = ref(false)

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

  const clearAuth = () => {
    token.value = null
    currentUser.value = null
    currentRole.value = null
    firstLogin.value = false
    localStorage.removeItem(AUTH_STORAGE_KEY)
  }

  const login = async (credentials) => {
    const { data } = await apiClient.post('/login', credentials)
    const user = data.data.user
    const roleName = user?.role?.role_name || user?.role || null

    if (isStudentMobileApp() && roleName !== ROLES.STUDENT) {
      try {
        await apiClient.post('/logout', null, {
          headers: { Authorization: `Bearer ${data.data.token}` },
        })
      } catch {
        // Best-effort revoke; local session will not be kept either way.
      }
      const error = new Error(STUDENT_MOBILE_ONLY_MESSAGE)
      error.code = 'STUDENT_MOBILE_ONLY'
      throw error
    }

    token.value = data.data.token
    setUser(user)
    return user
  }

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
      if (error.response?.status === 401) clearAuth()
      throw error
    }
  }

  const initialize = async () => {
    if (isInitialized.value) return isAuthenticated.value
    isInitialized.value = true

    if (!token.value) return false
    try {
      await fetchCurrentUser()
      if (isStudentMobileApp() && currentRole.value && currentRole.value !== ROLES.STUDENT) {
        clearAuth()
        return false
      }
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
      setUser(data.data)
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
    login,
    register,
    logout,
    fetchCurrentUser,
    changePassword,
    initialize,
    clearAuth,
  }
})
