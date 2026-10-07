import axios from 'axios'
import { isOfflineDemo } from '../config/demoMode'
import { createOfflineApiClient } from './offline/offlineApi'
import { performanceMonitor } from './performance/performanceMonitor'
import { resolveApiAssetUrl } from '../utils/apiAssetUrl'
import { clientPlatform } from '../config/clientPlatform'

const AUTH_STORAGE_KEY = 'cdm_portal_auth'
const configuredTimeout = Number(import.meta.env.VITE_API_TIMEOUT_MS || 15000)
const API_TIMEOUT_MS = Number.isFinite(configuredTimeout) && configuredTimeout > 0 ? configuredTimeout : 15000

const onlineApiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  timeout: API_TIMEOUT_MS,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-CDM-Client': clientPlatform,
  },
})

onlineApiClient.interceptors.request.use((config) => {
  config.cdmPerformanceRequest = performanceMonitor.beginApiRequest(
    config.method,
    config.url,
    config.baseURL,
    config.params,
  )
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

onlineApiClient.interceptors.response.use(
  (response) => {
    performanceMonitor.endApiRequest(response.config.cdmPerformanceRequest, response.status, response.data)
    return response
  },
  (error) => {
    performanceMonitor.endApiRequest(
      error.config?.cdmPerformanceRequest,
      error.response?.status,
      error.response?.data,
    )
    if (error.response?.status === 401 && !error.config?.url?.includes('/login')) {
      localStorage.removeItem(AUTH_STORAGE_KEY)
      if (window.location.hash !== '#/login') window.location.hash = '#/login'
    }

    return Promise.reject(error)
  },
)

export const apiClient = isOfflineDemo ? createOfflineApiClient() : onlineApiClient

export const apiAssetUrl = (path) => resolveApiAssetUrl(path, apiClient.defaults?.baseURL, window.location.href)
