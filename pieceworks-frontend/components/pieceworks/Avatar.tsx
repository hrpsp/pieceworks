/**
 * Avatar — PieceWorks brand-colour initials avatar
 *
 * Renders a circular avatar that is either:
 *   a) An <img> if a `src` URL is provided and loads successfully
 *   b) Initials computed from `name`, on a deterministically-chosen
 *      background from the PieceWorks brand palette
 *
 * The background colour is stable per name — the same person always
 * gets the same colour across page reloads and different components.
 *
 * Sizes
 *   xs  — 24px  (sidebar compact, table cells)
 *   sm  — 32px  (list items, comments)
 *   md  — 40px  (cards, default)
 *   lg  — 48px  (profile headers)
 *   xl  — 64px  (worker detail page hero)
 *
 * Usage
 *   <Avatar name="Ahmed Ali Khan" />
 *   <Avatar name="Farida Begum" size="lg" />
 *   <Avatar name="System" src={user.avatar_url} size="sm" />
 *   <Avatar name="Admin" role="admin" size="md" />
 */

import { useState }       from 'react';
import { getInitials }    from '@/lib/formatters';

// ── Brand palette ─────────────────────────────────────────────────────────────
//   Derived from PieceWorks design tokens.
//   Each entry: [background, text].
const PALETTE: [string, string][] = [
  ['#322E53', '#EEC293'],   // dark purple  / peach
  ['#49426E', '#F3AB9D'],   // mid purple   / salmon
  ['#EEC293', '#322E53'],   // peach        / dark purple
  ['#F3AB9D', '#322E53'],   // salmon       / dark purple
  ['#5B5280', '#EEC293'],   // muted purple / peach
  ['#3D3868', '#F3AB9D'],   // deep purple  / salmon
];

// ── Role overrides ────────────────────────────────────────────────────────────
//   Special roles always get the same colour so they stand out in lists.
const ROLE_COLORS: Record<string, [string, string]> = {
  admin:      ['#322E53', '#EEC293'],
  supervisor: ['#49426E', '#F3AB9D'],
  contractor: ['#EEC293', '#322E53'],
};

// ── Deterministic colour picker ───────────────────────────────────────────────

function pickColor(name: string): [string, string] {
  let hash = 0;
  for (let i = 0; i < name.length; i++) {
    hash = (hash * 31 + name.charCodeAt(i)) & 0xffffffff;
  }
  return PALETTE[Math.abs(hash) % PALETTE.length];
}

// ── Size map ──────────────────────────────────────────────────────────────────

const SIZE_CLASS: Record<AvatarSize, string> = {
  xs: 'w-6  h-6  text-[9px]',
  sm: 'w-8  h-8  text-[11px]',
  md: 'w-10 h-10 text-xs',
  lg: 'w-12 h-12 text-sm',
  xl: 'w-16 h-16 text-base',
};

export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

// ── Props ─────────────────────────────────────────────────────────────────────

export interface AvatarProps {
  /** Full name — used for initials and colour hashing */
  name: string;
  /** Optional photo URL — shown when it loads successfully */
  src?: string | null;
  /** Optional role string — admin / supervisor / contractor get fixed colours */
  role?: string;
  /** Circle size */
  size?: AvatarSize;
  /** Additional className forwarded to the root element */
  className?: string;
  /** Accessible label override (defaults to name) */
  aria_label?: string;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function Avatar({
  name,
  src,
  role,
  size = 'md',
  className = '',
  aria_label,
}: AvatarProps) {
  const [imgError, setImgError] = useState(false);
  const showImage = !!src && !imgError;

  // Colour: role override wins, then deterministic hash
  const [bg, fg] =
    (role ? ROLE_COLORS[role.toLowerCase()] : undefined) ?? pickColor(name);

  const initials  = getInitials(name);
  const sizeClass = SIZE_CLASS[size];
  const label     = aria_label ?? name;

  if (showImage) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img
        src={src!}
        alt={label}
        onError={() => setImgError(true)}
        className={`rounded-full object-cover shrink-0 ${sizeClass} ${className}`}
      />
    );
  }

  return (
    <span
      role="img"
      aria-label={label}
      title={name}
      className={`
        inline-flex items-center justify-center rounded-full
        font-bold tracking-wide leading-none select-none shrink-0
        ${sizeClass} ${className}
      `}
      style={{ backgroundColor: bg, color: fg }}
    >
      {initials}
    </span>
  );
}

// ── AvatarGroup ───────────────────────────────────────────────────────────────
//   Renders up to `max` avatars in an overlapping stack,
//   with a "+N" overflow badge using the dark brand colour.

export interface AvatarGroupProps {
  /** Array of { name, src?, role? } */
  users: Array<Pick<AvatarProps, 'name' | 'src' | 'role'>>;
  /** Maximum avatars to show before the overflow badge */
  max?: number;
  /** Size applied to every avatar in the group */
  size?: AvatarSize;
  className?: string;
}

export function AvatarGroup({
  users,
  max = 4,
  size = 'sm',
  className = '',
}: AvatarGroupProps) {
  const visible  = users.slice(0, max);
  const overflow = users.length - max;

  return (
    <div className={`flex items-center ${className}`}>
      {visible.map((u, i) => (
        <span
          key={`${u.name}-${i}`}
          className="ring-2 ring-background rounded-full -ml-2 first:ml-0"
          style={{ zIndex: visible.length - i }}
        >
          <Avatar name={u.name} src={u.src} role={u.role} size={size} />
        </span>
      ))}
      {overflow > 0 && (
        <span
          className={`
            inline-flex items-center justify-center rounded-full
            ring-2 ring-background -ml-2 font-bold
            ${SIZE_CLASS[size]}
          `}
          style={{ backgroundColor: '#322E53', color: '#EEC293', fontSize: '9px' }}
          aria-label={`${overflow} more`}
          title={`${overflow} more`}
        >
          +{overflow}
        </span>
      )}
    </div>
  );
}
