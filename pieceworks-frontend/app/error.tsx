'use client';

import { useEffect } from 'react';

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  useEffect(() => {
    // Log to your error reporting service here
    console.error(error);
  }, [error]);

  return (
    <div className="min-h-screen bg-background flex items-center justify-center p-6">
      <div className="text-center space-y-6 max-w-md">

        {/* Brand mark */}
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-red-100 mx-auto">
          <span className="text-2xl font-bold text-red-600 font-mono">!</span>
        </div>

        {/* Copy */}
        <div className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            Something went wrong
          </p>
          <h1 className="text-3xl font-bold text-foreground">
            Unexpected error
          </h1>
          <p className="text-muted-foreground text-sm leading-relaxed">
            PieceWorks encountered an unexpected error. The issue has been logged.
          </p>
          {error.digest && (
            <p className="text-xs text-muted-foreground/60 font-mono">
              ref: {error.digest}
            </p>
          )}
        </div>

        {/* Actions */}
        <div className="flex items-center justify-center gap-3">
          <button
            onClick={reset}
            className="inline-flex items-center gap-2 bg-brand-dark text-white text-sm font-semibold px-5 py-2.5 rounded-lg hover:bg-brand-mid transition-colors"
          >
            Retry
          </button>
          <a
            href="/"
            className="inline-flex items-center gap-2 border border-border text-foreground text-sm font-semibold px-5 py-2.5 rounded-lg hover:bg-muted transition-colors"
          >
            Go home
          </a>
        </div>
      </div>
    </div>
  );
}
