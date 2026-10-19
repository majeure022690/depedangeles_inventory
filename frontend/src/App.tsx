import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes, useParams } from 'react-router-dom'

import { ErrorBoundary } from '@/components/ErrorBoundary'
import { Spinner } from '@/components/ui/Spinner'
import { ManagementLayout } from '@/layouts/ManagementLayout'
import { SETTINGS_PERMISSIONS } from '@/lib/permissions'
import { NotFound } from '@/pages/NotFound'
import { AuditLog } from '@/pages/management/AuditLog'
import { ForgotPassword } from '@/pages/management/ForgotPassword'
import { Login } from '@/pages/management/Login'
import { ProfileSettings } from '@/pages/management/ProfileSettings'
import { ResetPassword } from '@/pages/management/ResetPassword'
import { Settings } from '@/pages/management/Settings'
import { TwoFactorSetup } from '@/pages/management/TwoFactorSetup'
import { TwoFactorVerify } from '@/pages/management/TwoFactorVerify'
import { EquipmentCreate } from '@/pages/management/equipment/EquipmentCreate'
import { EquipmentEdit } from '@/pages/management/equipment/EquipmentEdit'
import { EquipmentList } from '@/pages/management/equipment/EquipmentList'
import { ConnectivitySurveyEdit } from '@/pages/management/internet-connectivity-surveys/ConnectivitySurveyEdit'
import { ConnectivitySurveyList } from '@/pages/management/internet-connectivity-surveys/ConnectivitySurveyList'
import { IspAccountCreate } from '@/pages/management/isp-accounts/IspAccountCreate'
import { IspAccountEdit } from '@/pages/management/isp-accounts/IspAccountEdit'
import { IspAccountList } from '@/pages/management/isp-accounts/IspAccountList'
import { OfficeCreate } from '@/pages/management/offices/OfficeCreate'
import { OfficeEdit } from '@/pages/management/offices/OfficeEdit'
import { OfficeList } from '@/pages/management/offices/OfficeList'
import { PersonnelCreate } from '@/pages/management/personnel/PersonnelCreate'
import { PersonnelEdit } from '@/pages/management/personnel/PersonnelEdit'
import { PersonnelList } from '@/pages/management/personnel/PersonnelList'
import { ReferenceDataIndex } from '@/pages/management/reference-data/ReferenceDataIndex'
import { ReferenceDataTable } from '@/pages/management/reference-data/ReferenceDataTable'
import { RoleList } from '@/pages/management/roles/RoleList'
import { StakeholderProfileEdit } from '@/pages/management/stakeholder-profiles/StakeholderProfileEdit'
import { StakeholderProfileList } from '@/pages/management/stakeholder-profiles/StakeholderProfileList'
import { UserList } from '@/pages/management/users/UserList'
import { GuestOnly, RequireAuth, RequirePermission } from '@/router/Guards'

// recharts is the heaviest dependency the portal ships; keep it in its own
// chunk so every other route stays off that download.
const ManagementDashboard = lazy(() => import('@/pages/management/Dashboard').then((m) => ({ default: m.Dashboard })))

/**
 * Printed equipment QR labels encode `APP_URL/equipment/{id}/edit` (ADR §8).
 * When the frontend host is the one receiving the scan, forward it into the portal.
 */
function EquipmentQrAlias() {
  const { id } = useParams()
  return <Navigate to={`/portal/equipment/${id}/edit`} replace />
}

export function App() {
  return (
    <ErrorBoundary>
      <Routes>
        <Route index element={<Navigate to="/portal/dashboard" replace />} />
        <Route path="/equipment/:id/edit" element={<EquipmentQrAlias />} />

        <Route
          path="/portal/login"
          element={
            <GuestOnly>
              <Login />
            </GuestOnly>
          }
        />

        <Route
          path="/portal/forgot-password"
          element={
            <GuestOnly>
              <ForgotPassword />
            </GuestOnly>
          }
        />

        <Route
          path="/portal/reset-password"
          element={
            <GuestOnly>
              <ResetPassword />
            </GuestOnly>
          }
        />

        <Route
          path="/portal/2fa/setup"
          element={
            <GuestOnly>
              <TwoFactorSetup />
            </GuestOnly>
          }
        />

        <Route
          path="/portal/2fa/verify"
          element={
            <GuestOnly>
              <TwoFactorVerify />
            </GuestOnly>
          }
        />

        <Route path="/portal" element={<RequireAuth />}>
          <Route element={<ManagementLayout />}>
            <Route index element={<Navigate to="dashboard" replace />} />
            <Route
              path="dashboard"
              element={
                <Suspense fallback={<Spinner full />}>
                  <ManagementDashboard />
                </Suspense>
              }
            />
            <Route path="profile" element={<ProfileSettings />} />

            <Route element={<RequirePermission permission="personnel.view" />}>
              <Route path="personnel" element={<PersonnelList />} />
              <Route path="personnel/:id/edit" element={<PersonnelEdit />} />
              <Route element={<RequirePermission permission="personnel.create" />}>
                <Route path="personnel/new" element={<PersonnelCreate />} />
              </Route>
            </Route>

            <Route element={<RequirePermission permission="equipment.view" />}>
              <Route path="equipment" element={<EquipmentList />} />
              <Route path="equipment/:id/edit" element={<EquipmentEdit />} />
              <Route element={<RequirePermission permission="equipment.create" />}>
                <Route path="equipment/new" element={<EquipmentCreate />} />
              </Route>
            </Route>

            <Route element={<RequirePermission permission="isp_accounts.view" />}>
              <Route path="isp-accounts" element={<IspAccountList />} />
              <Route path="isp-accounts/:id/edit" element={<IspAccountEdit />} />
              <Route element={<RequirePermission permission="isp_accounts.create" />}>
                <Route path="isp-accounts/new" element={<IspAccountCreate />} />
              </Route>
            </Route>

            <Route element={<RequirePermission permission="stakeholder_profile.view_all" />}>
              <Route path="stakeholder-profiles" element={<StakeholderProfileList />} />
            </Route>
            <Route element={<RequirePermission permission={['stakeholder_profile.view', 'stakeholder_profile.view_all']} />}>
              <Route path="stakeholder-profiles/:officeId" element={<StakeholderProfileEdit />} />
            </Route>

            <Route element={<RequirePermission permission="internet_connectivity.view_all" />}>
              <Route path="internet-connectivity-surveys" element={<ConnectivitySurveyList />} />
            </Route>
            <Route element={<RequirePermission permission={['internet_connectivity.view', 'internet_connectivity.view_all']} />}>
              <Route path="internet-connectivity-surveys/:officeId" element={<ConnectivitySurveyEdit />} />
            </Route>

            <Route element={<RequirePermission permission="office.view" />}>
              <Route path="offices" element={<OfficeList />} />
              <Route path="offices/:id/edit" element={<OfficeEdit />} />
              <Route element={<RequirePermission permission="office.create" />}>
                <Route path="offices/new" element={<OfficeCreate />} />
              </Route>
            </Route>

            <Route element={<RequirePermission permission="reference-data.manage" />}>
              <Route path="reference-data" element={<ReferenceDataIndex />} />
              <Route path="reference-data/:table" element={<ReferenceDataTable />} />
            </Route>

            <Route element={<RequirePermission permission="users.manage" />}>
              <Route path="users" element={<UserList />} />
            </Route>

            <Route element={<RequirePermission permission="roles.manage" />}>
              <Route path="roles" element={<RoleList />} />
            </Route>

            <Route element={<RequirePermission permission="audit_log.view" />}>
              <Route path="audit-log" element={<AuditLog />} />
            </Route>

            <Route element={<RequirePermission permission={SETTINGS_PERMISSIONS} />}>
              <Route path="settings" element={<Settings />} />
            </Route>
          </Route>
        </Route>

        <Route path="*" element={<NotFound />} />
      </Routes>
    </ErrorBoundary>
  )
}
