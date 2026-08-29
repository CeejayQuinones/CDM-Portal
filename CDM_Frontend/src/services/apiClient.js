import axios from 'axios'
import { resolveApiBaseUrl } from '../config/apiBaseUrl'

const AUTH_STORAGE_KEY = 'cdm_portal_auth'
const apiBaseUrl = resolveApiBaseUrl()

export const apiClient = axios.create({
  baseURL: apiBaseUrl,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 20000,
})

apiClient.interceptors.request.use((config) => {
  const savedAuth = localStorage.getItem(AUTH_STORAGE_KEY)

  if (savedAuth) {
    try {
      const { token } = JSON.parse(savedAuth)
      if (token) config.headers.Authorization = `Bearer ${token}`
    } catch {
      localStorage.removeItem(AUTH_STORAGE_KEY)
    }
  }

  return config
})

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && !error.config?.url?.includes('/login')) {
      localStorage.removeItem(AUTH_STORAGE_KEY)
      if (window.location.hash !== '#/login') window.location.hash = '#/login'
    }

    return Promise.reject(error)
  },
)

if (import.meta.env.DEV) {
  console.info('[CDM Portal] API base URL:', apiBaseUrl)
}
