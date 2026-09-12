import { createBrowserRouter, Navigate } from 'react-router-dom'
import ProtectedRoute from '../features/auth/ProtectedRoute'
import ModuleRoute from '../features/auth/ModuleRoute'
import AppLayout from '../layouts/AppLayout'
import DashboardPage from '../pages/DashboardPage'
import LoginPage from '../pages/LoginPage'
import ReferenceListsPage from '../pages/admin/ReferenceListsPage'
import ServicesPricingPage from '../pages/admin/ServicesPricingPage'
import PersonnelPage from '../pages/hr/PersonnelPage'
import CustomersPage from '../pages/operations/CustomersPage'
import BookingsPage from '../pages/operations/BookingsPage'
import QueuePage from '../pages/operations/QueuePage'

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
            path: '/operations/customers',
            element: (
              <ModuleRoute module="customers">
                <CustomersPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/operations/bookings',
            element: (
              <ModuleRoute module="bookings">
                <BookingsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/operations/queue',
            element: (
              <ModuleRoute module="queue">
                <QueuePage />
              </ModuleRoute>
            ),
          },
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
