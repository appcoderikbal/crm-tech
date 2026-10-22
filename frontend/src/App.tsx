import { lazy, Suspense } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { AppShell } from '@/components/layout/AppShell';
import { LoadingPanel, ToastProvider } from '@/components/ui';
import { AuthLoading, LoginPage } from '@/features/auth/LoginPage';
import { AuthProvider, useAuth } from '@/features/auth/AuthContext';
import { ContactDetailPage } from '@/features/contacts/ContactDetailPage';
import { ContactsPage } from '@/features/contacts/ContactsPage';
import { ImportPage } from '@/features/contacts/ImportPage';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { ListDetailPage } from '@/features/lists/ListDetailPage';
import { ListsPage } from '@/features/lists/ListsPage';
import { AuditLogPage } from '@/features/settings/AuditLogPage';
import { ProfilePage } from '@/features/settings/ProfilePage';
import { SettingsPage } from '@/features/settings/SettingsPage';
import { SuppressionsPage } from '@/features/suppressions/SuppressionsPage';

// The rich-text editor and the charting library are the two heavy
// dependencies. Loading them per route keeps the initial bundle small, which
// matters on shared hosting without a CDN in front of it.
const CampaignsPage = lazy(() =>
  import('@/features/campaigns/CampaignsPage').then((module) => ({ default: module.CampaignsPage })),
);
const CampaignEditorPage = lazy(() =>
  import('@/features/campaigns/CampaignEditorPage').then((module) => ({ default: module.CampaignEditorPage })),
);
const CampaignReportPage = lazy(() =>
  import('@/features/campaigns/CampaignReportPage').then((module) => ({ default: module.CampaignReportPage })),
);
const ReportsPage = lazy(() =>
  import('@/features/reports/ReportsPage').then((module) => ({ default: module.ReportsPage })),
);

function AuthenticatedApp() {
  const { user, loading } = useAuth();

  if (loading) return <AuthLoading />;
  if (!user) return <LoginPage />;

  return (
    <Suspense fallback={<LoadingPanel />}>
      <Routes>
        <Route element={<AppShell />}>
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />

          <Route path="/contacts" element={<ContactsPage />} />
          <Route path="/contacts/import" element={<ImportPage />} />
          <Route path="/contacts/:id" element={<ContactDetailPage />} />

          <Route path="/lists" element={<ListsPage />} />
          <Route path="/lists/:id" element={<ListDetailPage />} />

          <Route path="/campaigns" element={<CampaignsPage />} />
          <Route path="/campaigns/new" element={<CampaignEditorPage />} />
          <Route path="/campaigns/:id/edit" element={<CampaignEditorPage />} />
          <Route path="/campaigns/:id/report" element={<CampaignReportPage />} />

          <Route path="/reports" element={<ReportsPage />} />
          <Route path="/suppressions" element={<SuppressionsPage />} />

          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/settings/profile" element={<ProfilePage />} />
          <Route path="/settings/audit" element={<AuditLogPage />} />

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Route>
      </Routes>
    </Suspense>
  );
}

export default function App() {
  return (
    <ToastProvider>
      <AuthProvider>
        <AuthenticatedApp />
      </AuthProvider>
    </ToastProvider>
  );
}
