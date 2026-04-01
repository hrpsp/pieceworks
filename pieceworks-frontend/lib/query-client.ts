import { QueryClient } from '@tanstack/react-query';

/**
 * Factory used by the Providers component via React.useState so each
 * Next.js request gets its own QueryClient (avoids cross-request cache sharing).
 *
 * Settings:
 *   staleTime:            5 minutes  — data is considered fresh for 5 min after fetch
 *   retry:                1          — one automatic retry on failure
 *   refetchOnWindowFocus: false      — no background refetch when tab regains focus
 */
export function makeQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 5 * 60 * 1000,
        retry: 1,
        refetchOnWindowFocus: false,
      },
    },
  });
}
