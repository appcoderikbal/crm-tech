import {
  BarChart3,
  ChevronRight,
  LayoutDashboard,
  ListChecks,
  LogOut,
  Mail,
  Menu,
  Settings as SettingsIcon,
  ShieldBan,
  Users,
  X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import { Button, StatusDot } from '@/components/ui';
import { useAuth } from '@/features/auth/AuthContext';
import { cn, initials } from '@/lib/utils';

interface NavItem {
  to: string;
  label: string;
  icon: typeof LayoutDashboard;
  /** Shows the unresolved-critical indicator. */
  alertable?: boolean;
}

const NAVIGATION: NavItem[] = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard, alertable: true },
  { to: '/contacts', label: 'Contacts', icon: Users },
  { to: '/lists', label: 'Lists', icon: ListChecks },
  { to: '/campaigns', label: 'Campaigns', icon: Mail, alertable: true },
  { to: '/reports', label: 'Reports', icon: BarChart3, alertable: true },
  { to: '/suppressions', label: 'Suppressions', icon: ShieldBan },
  { to: '/settings', label: 'Settings', icon: SettingsIcon, alertable: true },
];

/** Human labels for path segments in the breadcrumb trail. */
const SEGMENT_LABELS: Record<string, string> = {
  dashboard: 'Dashboard',
  contacts: 'Contacts',
  lists: 'Lists',
  campaigns: 'Campaigns',
  reports: 'Reports',
  suppressions: 'Suppressions',
  settings: 'Settings',
  import: 'Import',
  new: 'New',
  edit: 'Edit',
  report: 'Report',
  members: 'Members',
  profile: 'Profile',
  audit: 'Audit log',
  deliverability: 'Deliverability',
};

function Breadcrumbs() {
  const { pathname } = useLocation();
  const segments = pathname.split('/').filter(Boolean);

  if (segments.length === 0) return null;

  return (
    <nav aria-label="Breadcrumb" className="flex min-w-0 items-center gap-1 text-[13px] text-ink-faint">
      {segments.map((segment, index) => {
        const href = '/' + segments.slice(0, index + 1).join('/');
        const isLast = index === segments.length - 1;
        const label = SEGMENT_LABELS[segment] ?? (/^\d+$/.test(segment) ? `#${segment}` : segment);

        return (
          <span key={href} className="flex min-w-0 items-center gap-1">
            {index > 0 && <ChevronRight className="h-3.5 w-3.5 shrink-0 opacity-50" aria-hidden />}
            {isLast ? (
              <span className="truncate font-medium text-ink" aria-current="page">
                {label}
              </span>
            ) : (
              <Link to={href} className="truncate hover:text-ink">
                {label}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}

function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
  const { user, criticalAlerts, logout } = useAuth();

  return (
    <div className="flex h-full flex-col">
      <div className="flex h-14 items-center gap-2.5 border-b border-line px-4">
        <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand text-[13px] font-bold text-white">
          T
        </span>
        <span className="text-[15px] font-semibold tracking-tight text-ink">Techzapp CRM</span>
      </div>

      <nav className="flex-1 space-y-0.5 overflow-y-auto p-2.5">
        {NAVIGATION.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            onClick={onNavigate}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors',
                isActive ? 'bg-brand-soft text-brand-strong' : 'text-ink-soft hover:bg-muted hover:text-ink',
              )
            }
          >
            <item.icon className="h-4 w-4 shrink-0" aria-hidden />
            <span className="flex-1 truncate">{item.label}</span>
            {item.alertable && criticalAlerts > 0 && (
              <span
                className="h-2 w-2 shrink-0 rounded-full bg-danger"
                title={`${criticalAlerts} unresolved critical alert(s)`}
                aria-label={`${criticalAlerts} unresolved critical alerts`}
              />
            )}
          </NavLink>
        ))}
      </nav>

      <div className="border-t border-line p-2.5">
        <Link
          to="/settings/profile"
          onClick={onNavigate}
          className="flex items-center gap-2.5 rounded-lg px-2.5 py-2 hover:bg-muted"
        >
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-ink-soft">
            {initials(user?.name || user?.email || '')}
          </span>
          <span className="min-w-0 flex-1">
            <span className="block truncate text-[13px] font-medium text-ink">{user?.name || user?.email}</span>
            <span className="block truncate text-[11px] text-ink-faint capitalize">{user?.role}</span>
          </span>
        </Link>
        <button
          type="button"
          onClick={() => void logout()}
          className="mt-0.5 flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium text-ink-soft transition-colors hover:bg-muted hover:text-ink"
        >
          <LogOut className="h-4 w-4 shrink-0" aria-hidden />
          Logout
        </button>
      </div>
    </div>
  );
}

export function AppShell() {
  const [mobileOpen, setMobileOpen] = useState(false);
  const { criticalAlerts } = useAuth();
  const { pathname } = useLocation();

  useEffect(() => {
    setMobileOpen(false);
  }, [pathname]);

  return (
    <div className="min-h-screen lg:flex">
      {/* Desktop sidebar */}
      <aside className="hidden w-60 shrink-0 border-r border-line bg-surface lg:fixed lg:inset-y-0 lg:flex lg:flex-col">
        <SidebarContent />
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <div className="absolute inset-0 bg-ink/35" onClick={() => setMobileOpen(false)} aria-hidden />
          <aside className="relative h-full w-64 border-r border-line bg-surface">
            <button
              type="button"
              onClick={() => setMobileOpen(false)}
              className="absolute top-3.5 right-3 text-ink-faint hover:text-ink"
              aria-label="Close navigation"
            >
              <X className="h-4 w-4" />
            </button>
            <SidebarContent onNavigate={() => setMobileOpen(false)} />
          </aside>
        </div>
      )}

      <div className="min-w-0 flex-1 lg:pl-60">
        <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur-sm sm:px-6">
          <Button
            variant="ghost"
            size="icon"
            className="lg:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Open navigation"
          >
            <Menu className="h-4.5 w-4.5" />
          </Button>

          <Breadcrumbs />

          <div className="ml-auto flex items-center gap-2">
            {criticalAlerts > 0 && (
              <Link
                to="/reports"
                className="flex items-center gap-1.5 rounded-full border border-danger/25 bg-danger-soft px-2.5 py-1 text-[12px] font-medium text-danger"
              >
                <StatusDot status="red" />
                {criticalAlerts} critical
              </Link>
            )}
          </div>
        </header>

        <main className="mx-auto w-full max-w-[1400px] p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}

export function PageContainer({ children }: { children: ReactNode }) {
  return <div className="space-y-5">{children}</div>;
}
