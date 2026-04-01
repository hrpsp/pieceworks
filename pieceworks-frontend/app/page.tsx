export default function Home() {
  return (
    <main className="min-h-screen flex items-center justify-center bg-brand-bg">
      <div className="text-center space-y-4">
        <h1 className="text-5xl font-bold text-brand-dark tracking-tight">
          PieceWorks
        </h1>
        <p className="text-brand-mid text-lg">
          Piece Rate Production &amp; Payroll
        </p>
        <div className="flex gap-3 justify-center pt-2">
          <span className="px-3 py-1 rounded-full text-xs font-semibold bg-brand-dark text-white">
            Next.js 14
          </span>
          <span className="px-3 py-1 rounded-full text-xs font-semibold bg-brand-peach text-brand-dark">
            Laravel 11
          </span>
          <span className="px-3 py-1 rounded-full text-xs font-semibold bg-brand-salmon text-brand-dark">
            MySQL 8
          </span>
        </div>
      </div>
    </main>
  );
}
