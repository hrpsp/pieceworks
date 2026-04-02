'use client';

import React from 'react';
import { useAuth } from '@/contexts/auth-context';

interface PermissionGateProps {
  permission: string;
  children: React.ReactNode;
  fallback?: React.ReactNode;
}

export function PermissionGate({ permission, children, fallback = null }: PermissionGateProps) {
  const { user } = useAuth();
  if (!user) return <>{fallback}</>;
  const userPermissions: string[] = (user as any).permissions ?? [];
  // Admins have all permissions
  if ((user as any).role === 'admin' || (user as any).role?.slug === 'admin') return <>{children}</>;
  if (!userPermissions.includes(permission)) return <>{fallback}</>;
  return <>{children}</>;
}
