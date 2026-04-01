/**
 * Trigger a file download from an authenticated API endpoint.
 *
 * Fetches the resource with the current Bearer token, creates a temporary
 * object URL, clicks it, then revokes it — all without opening a new tab.
 *
 * @param path      API path, e.g. '/reports/daily-production'
 * @param params    Query-string params (merged with path)
 * @param filename  Suggested filename for the download dialog
 */
export async function downloadFromApi(
  path: string,
  params: Record<string, string>,
  filename: string
): Promise<void> {
  const token = typeof window !== 'undefined'
    ? localStorage.getItem('pw_token')
    : null;

  const qs = new URLSearchParams(params).toString();
  const url = `${process.env.NEXT_PUBLIC_API_URL}${path}${qs ? `?${qs}` : ''}`;

  const res = await fetch(url, {
    headers: {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      Accept: '*/*',
    },
  });

  if (!res.ok) {
    throw new Error(`Download failed: HTTP ${res.status}`);
  }

  const blob = await res.blob();
  const objectUrl = URL.createObjectURL(blob);

  const a = document.createElement('a');
  a.href = objectUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(objectUrl);
}
