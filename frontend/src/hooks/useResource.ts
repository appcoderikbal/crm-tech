import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError, type ApiMeta } from '@/lib/api';

interface ResourceState<T> {
  data: T | null;
  meta: ApiMeta | null;
  loading: boolean;
  error: string | null;
}

/**
 * Small data-fetching hook: load, reload, cancel-on-unmount.
 *
 * Deliberately not a cache layer — every screen here is a live operational
 * view, and a stale deliverability number is worse than a short spinner.
 */
export function useResource<T>(
  path: string | null,
  query: Record<string, string | number | boolean | null | undefined> = {},
  deps: unknown[] = [],
) {
  const [state, setState] = useState<ResourceState<T>>({
    data: null,
    meta: null,
    loading: path !== null,
    error: null,
  });

  const controller = useRef<AbortController | null>(null);
  // Serialised so the effect compares by value, not identity.
  const querySignature = JSON.stringify(query);

  const load = useCallback(
    async (showSpinner = true) => {
      if (path === null) return;

      controller.current?.abort();
      const current = new AbortController();
      controller.current = current;

      if (showSpinner) {
        setState((previous) => ({ ...previous, loading: true, error: null }));
      }

      try {
        const response = await api.get<T>(path, JSON.parse(querySignature), current.signal);
        if (current.signal.aborted) return;
        setState({ data: response.data, meta: response.meta ?? null, loading: false, error: null });
      } catch (error) {
        if (current.signal.aborted || (error as Error).name === 'AbortError') return;
        setState({
          data: null,
          meta: null,
          loading: false,
          error: error instanceof ApiError ? error.message : 'Something went wrong.',
        });
      }
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [path, querySignature, ...deps],
  );

  useEffect(() => {
    void load();
    return () => controller.current?.abort();
  }, [load]);

  return {
    ...state,
    reload: load,
    /** Refresh without flashing the spinner — used by polling screens. */
    refresh: () => load(false),
    setData: (data: T) => setState((previous) => ({ ...previous, data })),
  };
}

/** Debounces a value — used for search inputs so each keystroke is not a request. */
export function useDebounced<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}

/** Polls a callback on an interval while `active` is true. */
export function usePoll(callback: () => void, intervalMs: number, active = true): void {
  const saved = useRef(callback);
  saved.current = callback;

  useEffect(() => {
    if (!active) return;
    const timer = setInterval(() => saved.current(), intervalMs);
    return () => clearInterval(timer);
  }, [intervalMs, active]);
}
