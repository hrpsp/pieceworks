import { NextRequest, NextResponse } from 'next/server';

/**
 * Route protection middleware.
 *
 * Reads the `pw_token` cookie (set by AuthContext.login on the client side).
 * If absent, redirects to /login with a `from` param so the login page
 * can redirect back after successful authentication.
 */
export function middleware(request: NextRequest) {
  const token = request.cookies.get('pw_token')?.value;

  if (!token) {
    const loginUrl = new URL('/login', request.url);
    loginUrl.searchParams.set('from', request.nextUrl.pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  // Protect all routes except login, Next.js internals, and static assets.
  matcher: ['/((?!login|api|_next/static|_next/image|favicon.ico).*)'],
};
