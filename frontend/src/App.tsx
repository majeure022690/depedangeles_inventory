import { lazy, Suspense, type ComponentType } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'

import { ErrorBoundary } from '@/components/ErrorBoundary'
import { Spinner } from '@/components/ui/Spinner'
import { ManagementLayout } from '@/layouts/ManagementLayout'
import { SETTINGS_PERMISSIONS } from '@/lib/permissions'
import { NotFound } from '@/pages/NotFound'
import { GuestOnly, RequireAuth, RequireDataScope, RequirePermission } from '@/router/Guards'

// Every page is its own chunk (recharts alone, on the dashboard, is the
// heaviest dependency the app ships). ManagementLayout suspends inside its
// shell, so the sidebar stays put while a page chunk loads.
const page = <K extends string, T extends Record<K, ComponentType>>(load: () => Promise<T>, name: K) =>
  lazy(() => load().then((module) => ({ default: module[name] })))

const Login = page(() => import('@/pages/management/Login'), 'Login')
const TwoFactorSetup = page(() => import('@/pages/management/TwoFactorSetup'), 'TwoFactorSetup')
const TwoFactorVerify = page(() => import('@/pages/management/TwoFactorVerify'), 'TwoFactorVerify')
const ManagementDashboard = page(() => import('@/pages/management/Dashboard'), 'Dashboard')
const ProfileSettings = page(() => import('@/pages/management/ProfileSettings'), 'ProfileSettings')
const Settings = page(() => import('@/pages/management/Settings'), 'Settings')
const AuditLog = page(() => import('@/pages/management/AuditLog'), 'AuditLog')
const EquipmentCreate = page(() => import('@/pages/management/equipment/EquipmentCreate'), 'EquipmentCreate')
const EquipmentEdit = page(() => import('@/pages/management/equipment/EquipmentEdit'), 'EquipmentEdit')
const EquipmentList = page(() => import('@/pages/management/equipment/EquipmentList'), 'EquipmentList')
const ConnectivitySurveyEdit = page(() => import('@/pages/management/internet-connectivity-surveys/ConnectivitySurveyEdit'), 'ConnectivitySurveyEdit')
const ConnectivitySurveyList = page(() => import('@/pages/management/internet-connectivity-surveys/ConnectivitySurveyList'), 'ConnectivitySurveyList')
const IspAccountCreate = page(() => import('@/pages/management/isp-accounts/IspAccountCreate'), 'IspAccountCreate')
const IspAccountEdit = page(() => import('@/pages/management/isp-accounts/IspAccountEdit'), 'IspAccountEdit')
const IspAccountList = page(() => import('@/pages/management/isp-accounts/IspAccountList'), 'IspAccountList')
const OfficeCreate = page(() => import('@/pages/management/offices/OfficeCreate'), 'OfficeCreate')
const OfficeEdit = page(() => import('@/pages/management/offices/OfficeEdit'), 'OfficeEdit')
const OfficeList = page(() => import('@/pages/management/offices/OfficeList'), 'OfficeList')
const PersonnelCreate = page(() => import('@/pages/management/personnel/PersonnelCreate'), 'PersonnelCreate')
const PersonnelEdit = page(() => import('@/pages/management/personnel/PersonnelEdit'), 'PersonnelEdit')
const PersonnelList = page(() => import('@/pages/management/personnel/PersonnelList'), 'PersonnelList')
const ReferenceDataIndex = page(() => import('@/pages/management/reference-data/ReferenceDataIndex'), 'ReferenceDataIndex')
const ReferenceDataTable = page(() => import('@/pages/management/reference-data/ReferenceDataTable'), 'ReferenceDataTable')
const RoleList = page(() => import('@/pages/management/roles/RoleList'), 'RoleList')
const StakeholderProfileEdit = page(() => import('@/pages/management/stakeholder-profiles/StakeholderProfileEdit'), 'StakeholderProfileEdit')
const StakeholderProfileList = page(() => import('@/pages/management/stakeholder-profiles/StakeholderProfileList'), 'StakeholderProfileList')
const UserList = page(() => import('@/pages/management/users/UserList'), 'UserList')


export function App() {
  return (
    <ErrorBoundary>
      <Suspense fallback={<Spinner full />}>
        <Routes>
          <Route index element={<Navigate to="/dashboard" replace />} />

          <Route
            path="/login"
            element={
              <GuestOnly>
                <Login />
              </GuestOnly>
            }
          />

          <Route path="/forgot-password" element={<Navigate to="/login" replace />} />
          <Route path="/reset-password" element={<Navigate to="/login" replace />} />

          <Route
            path="/2fa/setup"
            element={
              <GuestOnly>
                <TwoFactorSetup />
              </GuestOnly>
            }
          />

          <Route
            path="/2fa/verify"
            element={
              <GuestOnly>
                <TwoFactorVerify />
              </GuestOnly>
            }
          />

          <Route element={<RequireAuth />}>
            <Route element={<ManagementLayout />}>
              <Route path="dashboard" element={<ManagementDashboard />} />
              <Route path="profile" element={<ProfileSettings />} />

              <Route element={<RequirePermission permission="personnel.view" />}>
                <Route path="personnel" element={<PersonnelList />} />
                <Route path="personnel/:id/edit" element={<PersonnelEdit />} />
                <Route element={<RequirePermission permission="personnel.create" />}>
                  <Route element={<RequireDataScope scope={['school', 'all']} />}>
                    <Route path="personnel/new" element={<PersonnelCreate />} />
                  </Route>
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

              <Route element={<RequirePermission permission="stakeholder_profile.view" />}>
                <Route element={<RequireDataScope scope="all" />}>
                  <Route path="stakeholder-profiles" element={<StakeholderProfileList />} />
                </Route>
                <Route path="stakeholder-profiles/:officeId" element={<StakeholderProfileEdit />} />
              </Route>

              <Route element={<RequirePermission permission="internet_connectivity.view" />}>
                <Route element={<RequireDataScope scope="all" />}>
                  <Route path="internet-connectivity-surveys" element={<ConnectivitySurveyList />} />
                </Route>
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

              <Route element={<RequirePermission permission="users.view" />}>
                <Route path="users" element={<UserList />} />
              </Route>

              <Route element={<RequirePermission permission="roles.view" />}>
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
      </Suspense>
    </ErrorBoundary>
  )
}
