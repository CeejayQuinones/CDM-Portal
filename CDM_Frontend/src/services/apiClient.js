import axios from 'axios'

const AUTH_STORAGE_KEY = 'cdm_portal_auth'

export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
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
