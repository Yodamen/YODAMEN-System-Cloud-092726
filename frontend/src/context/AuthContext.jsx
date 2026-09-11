/* eslint-disable react-refresh/only-export-components */

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react'
import { api, initializeCsrf } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [bootstrap, setBootstrap] = useState(null)
  const [loading, setLoading] = useState(true)

  const loadBootstrap = useCallback(async () => {
    const response = await api.get('/v1/bootstrap')
    setBootstrap(response.data)

    return response.data
  }, [])

  const refreshSession = useCallback(async () => {
    try {
      const response = await api.get('/v1/auth/me')

      setUser(response.data.user)
      await loadBootstrap()
    } catch (error) {
      if (error?.response?.status !== 401) {
        console.error('Unable to restore YODAMEN session.', error)
      }

      setUser(null)
      setBootstrap(null)
    } finally {
      setLoading(false)
    }
  }, [loadBootstrap])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      void refreshSession()
    }, 0)

    return () => {
      window.clearTimeout(timeoutId)
    }
  }, [refreshSession])

  const login = useCallback(
    async (username, accessCode) => {
      await initializeCsrf()

      const response = await api.post('/v1/auth/login', {
        username,
        access_code: accessCode,
      })

      setUser(response.data.user)
      await loadBootstrap()

      return response.data.user
    },
    [loadBootstrap],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/v1/auth/logout')
    } finally {
      setUser(null)
      setBootstrap(null)
    }
  }, [])

  const changeAccessCode = useCallback(async (accessCode, confirmation) => {
    const response = await api.put('/v1/auth/access-code', {
      access_code: accessCode,
      access_code_confirmation: confirmation,
    })

    setUser(response.data.user)

    return response.data.user
  }, [])

  const hasPermission = useCallback(
    (module, action = 'VIEW') => {
      if (!user) {
        return false
      }

      if (user.role?.is_super_admin) {
        return true
      }

      return user.permissions?.includes(`${module}.${action}`) ?? false
    },
    [user],
  )

  const value = useMemo(
    () => ({
      user,
      bootstrap,
      loading,
      login,
      logout,
      changeAccessCode,
      refreshSession,
      loadBootstrap,
      hasPermission,
    }),
    [
      user,
      bootstrap,
      loading,
      login,
      logout,
      changeAccessCode,
      refreshSession,
      loadBootstrap,
      hasPermission,
    ],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used inside AuthProvider.')
  }

  return context
}