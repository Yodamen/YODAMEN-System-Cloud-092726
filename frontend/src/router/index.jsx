import { createBrowserRouter, Navigate } from 'react-router-dom'
import ProtectedRoute from '../features/auth/ProtectedRoute'
import ModuleRoute from '../features/auth/ModuleRoute'
import AppLayout from '../layouts/AppLayout'
import DashboardPage from '../pages/DashboardPage'
import LoginPage from '../pages/LoginPage'
import ReferenceListsPage from '../pages/admin/ReferenceListsPage'
import ServicesPricingPage from '../pages/admin/ServicesPricingPage'
import PersonnelPage from '../pages/hr/PersonnelPage'

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
          {
            path: '/admin/references',
            element: (
              <ModuleRoute module="references">
                <ReferenceListsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/services',
            element: (
              <ModuleRoute module="services-pricing">
                <ServicesPricingPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/personnel',
            element: (
              <ModuleRoute module="employees">
                <PersonnelPage />
              </ModuleRoute>
            ),
          },
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])
