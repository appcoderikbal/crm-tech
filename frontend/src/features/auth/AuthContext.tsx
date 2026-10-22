import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { api, ApiError, setCsrfToken, setUnauthenticatedHandler } from '@/lib/api';

export interface CurrentUser {
  id: number;
  email: string;
  name: string;
  role: 'admin' | 'operator' | 'viewer';
  timezone: string;
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
}

interface MeResponse {
  user: CurrentUser;
  csrf_token: string;
  critical_alerts?: number;
  open_alerts?: number;
}

interface AuthContextValue {
  user: CurrentUser | null;
  loading: boolean;
  criticalAlerts: number;
  openAlerts: number;
  canWrite: boolean;
  isAdmin: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
  setUser: (user: CurrentUser) => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUserState] = useState<CurrentUser | null>(null);
  const [loading, setLoading] = useState(true);
  const [criticalAlerts, setCriticalAlerts] = useState(0);
  const [openAlerts, setOpenAlerts] = useState(0);
  const sessionExpired = useRef(false);

  const applyMe = useCallback((payload: MeResponse) => {
    setUserState(payload.user);
    setCsrfToken(payload.csrf_token);
    setCriticalAlerts(payload.critical_alerts ?? 0);
    setOpenAlerts(payload.open_alerts ?? 0);
    sessionExpired.current = false;
  }, []);

  const refresh = useCallback(async () => {
    try {
      const { data } = await api.get<MeResponse>('/auth/me');
      applyMe(data);
    } catch (error) {
      if (error instanceof ApiError && error.isUnauthenticated) {
        setUserState(null);
        setCsrfToken('');
      } else {
        throw error;
      }
    }
  }, [applyMe]);

  useEffect(() => {
    // A 401 from anywhere clears the session so the app falls back to login
    // instead of showing a half-broken screen.
    setUnauthenticatedHandler(() => {
      if (sessionExpired.current) return;
      sessionExpired.current = true;
      setUserState(null);
      setCsrfToken('');
    });

    refresh().finally(() => setLoading(false));
  }, [refresh]);

  // Keep the alert badge current without a websocket: a poll every 60s is
  // plenty for a deliverability warning and costs one small query.
  useEffect(() => {
    if (!user) return;
    const timer = setInterval(() => {
      void refresh().catch(() => undefined);
    }, 60_000);
    return () => clearInterval(timer);
  }, [user, refresh]);

  const login = useCallback(
    async (email: string, password: string) => {
      const { data } = await api.post<MeResponse>('/auth/login', { email, password });
      applyMe(data);
    },
    [applyMe],
  );

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } finally {
      setUserState(null);
      setCsrfToken('');
    }
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      loading,
      criticalAlerts,
      openAlerts,
      canWrite: user?.role === 'admin' || user?.role === 'operator',
      isAdmin: user?.role === 'admin',
      login,
      logout,
      refresh,
      setUser: setUserState,
    }),
    [user, loading, criticalAlerts, openAlerts, login, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used inside AuthProvider');
  return context;
}
