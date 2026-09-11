import axios from 'axios'

const apiBaseUrl = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')
const backendBaseUrl = apiBaseUrl.replace(/\/api$/, '')

export const api = axios.create({
  baseURL: apiBaseUrl,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

export async function initializeCsrf() {
  await axios.get(`${backendBaseUrl}/sanctum/csrf-cookie`, {
    withCredentials: true,
    headers: {
      Accept: 'application/json',
    },
  })
}

export function getApiErrorMessage(error, fallback = 'Something went wrong.') {
  const validation = error?.response?.data?.errors

  if (validation && typeof validation === 'object') {
    const first = Object.values(validation).flat().find(Boolean)
    if (first) return first
  }

  return error?.response?.data?.message || fallback
}
