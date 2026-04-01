'use client';

import { Suspense, useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useAuth }   from '@/hooks/useAuth';
import { ApiError }  from '@/lib/api-client';
import { Button }    from '@/components/ui/button';
import { Input }     from '@/components/ui/input';
import { Label }     from '@/components/ui/label';
import { Loader2 }   from 'lucide-react';

// ── Zod schema ────────────────────────────────────────────────────────────────

const loginSchema = z.object({
  email:    z.string().min(1, 'Email is required').email('Enter a valid email'),
  password: z.string().min(1, 'Password is required'),
});

type LoginFormValues = z.infer<typeof loginSchema>;

// ── Page ──────────────────────────────────────────────────────────────────────

function LoginContent() {
  const router       = useRouter();
  const searchParams = useSearchParams();
  const { login, isAuthenticated, isLoading } = useAuth();

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormValues>({
    resolver: zodResolver(loginSchema),
  });

  // If already authenticated, skip the login screen
  useEffect(() => {
    if (!isLoading && isAuthenticated) {
      const from = searchParams.get('from') ?? '/dashboard';
      router.replace(from);
    }
  }, [isAuthenticated, isLoading, router, searchParams]);

  async function onSubmit(values: LoginFormValues) {
    try {
      await login(values);
      const from = searchParams.get('from') ?? '/dashboard';
      router.push(from);
    } catch (err) {
      const message =
        err instanceof ApiError
          ? err.message
          : 'An unexpected error occurred. Please try again.';
      setError('root', { message });
    }
  }

  return (
    <div className="min-h-screen bg-brand-dark flex items-center justify-center p-4">
      {/* Card */}
      <div className="w-full max-w-sm">
        {/* Logo mark */}
        <div className="flex flex-col items-center mb-8">
          <div className="w-14 h-14 rounded-2xl bg-brand-peach flex items-center justify-center mb-4 shadow-lg">
            <span className="text-brand-dark font-bold text-xl tracking-tight">PW</span>
          </div>
          <h1 className="text-white font-bold text-2xl tracking-tight">PieceWorks</h1>
          <p className="text-white/50 text-sm mt-1">Flexi HRMS — Shoe Manufacturing</p>
        </div>

        {/* Form */}
        <form
          onSubmit={handleSubmit(onSubmit)}
          noValidate
          className="bg-white/[0.06] border border-white/10 rounded-xl p-6 space-y-4 backdrop-blur-sm"
        >
          <div>
            <h2 className="text-white font-semibold text-lg">Sign in</h2>
            <p className="text-white/40 text-xs mt-0.5">Enter your credentials to continue</p>
          </div>

          {errors.root && (
            <div className="bg-red-500/10 border border-red-500/20 rounded-md px-3 py-2">
              <p className="text-red-400 text-sm">{errors.root.message}</p>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="email" className="text-white/70 text-xs">
              Email address
            </Label>
            <Input
              id="email"
              type="email"
              autoComplete="email"
              placeholder="you@flexi.com"
              aria-invalid={!!errors.email}
              className="bg-white/[0.08] border-white/10 text-white placeholder:text-white/25
                         focus-visible:ring-brand-peach focus-visible:border-brand-peach/50
                         aria-[invalid=true]:border-red-500/50"
              {...register('email')}
            />
            {errors.email && (
              <p className="text-red-400 text-xs">{errors.email.message}</p>
            )}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="password" className="text-white/70 text-xs">
              Password
            </Label>
            <Input
              id="password"
              type="password"
              autoComplete="current-password"
              placeholder="••••••••"
              aria-invalid={!!errors.password}
              className="bg-white/[0.08] border-white/10 text-white placeholder:text-white/25
                         focus-visible:ring-brand-peach focus-visible:border-brand-peach/50
                         aria-[invalid=true]:border-red-500/50"
              {...register('password')}
            />
            {errors.password && (
              <p className="text-red-400 text-xs">{errors.password.message}</p>
            )}
          </div>

          <Button
            type="submit"
            disabled={isSubmitting}
            className="w-full bg-brand-peach text-brand-dark font-semibold
                       hover:bg-brand-peach/90 active:scale-[0.98] transition-all"
          >
            {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {isSubmitting ? 'Signing in…' : 'Sign in'}
          </Button>
        </form>

        <p className="text-center text-white/25 text-xs mt-6">
          PieceWorks v1.0 · © {new Date().getFullYear()} Flexi HRMS
        </p>
      </div>
    </div>
  );
}

export default function LoginPage() {
  return (
    <Suspense fallback={<div className="flex min-h-screen items-center justify-center"><div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" /></div>}>
      <LoginContent />
    </Suspense>
  );
}
