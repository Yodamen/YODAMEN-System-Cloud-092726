import { createBrowserRouter, Navigate } from 'react-router-dom'
import ProtectedRoute from '../features/auth/ProtectedRoute'
import AppLayout from '../layouts/AppLayout'
import DashboardPage from '../pages/DashboardPage'
import LoginPage from '../pages/LoginPage'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <DashboardPage /> },
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])
