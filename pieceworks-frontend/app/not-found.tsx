import Link from 'next/link';

export default function NotFound() {
  return (
    <div className="min-h-screen bg-background flex items-center justify-center p-6">
      <div className="text-center space-y-6 max-w-md">

        {/* Brand mark */}
        <div className="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-brand-dark/10 mx-auto">
          <span className="text-2xl font-bold text-brand-dark font-mono">PW</span>
        </div>

        {/* Copy */}
        <div className="space-y-2">
          <p className="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
            404 — Page not found
          </p>
          <h1 className="text-3xl font-bold text-foreground">
            Nothing here.
          </h1>
          <p className="text-muted-foreground text-sm leading-relaxed">
            The page you&apos;re looking for doesn&apos;t exist or has been moved.
          </p>
        </div>

        {/* Action */}
        <Link
          href="/"
          className="inline-flex items-center gap-2 bg-brand-dark text-white text-sm font-semibold px-5 py-2.5 rounded-lg hover:bg-brand-mid transition-colors"
        >
          Back to dashboard
        </Link>
      </div>
    </div>
  );
}
