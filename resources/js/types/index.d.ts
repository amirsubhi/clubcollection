import "@inertiajs/core";

export type UserRole = "super_admin" | "admin" | "member";

export interface AuthUser {
  id: number;
  name: string;
  role: UserRole;
}

export interface SharedClub {
  id: number;
  name: string;
}

/** Props shared on every Inertia response by App\Http\Middleware\HandleInertiaRequests. */
export interface SharedProps {
  [key: string]: unknown;
  auth: { user: AuthUser | null };
  flash: { success: string | null; error: string | null };
  /** "ms" for members and guests, otherwise the app locale (e.g. "en"). */
  locale: string;
  /** The route-bound {club}, or null when the route has none. */
  club: SharedClub | null;
}

declare module "@inertiajs/core" {
  export interface InertiaConfig {
    sharedPageProps: SharedProps;
  }
}
