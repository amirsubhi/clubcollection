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
  /**
   * Members and guests: their member-side language (App\Support\UserLocale: saved on the account, else the browser's
   * cp_lang, else the portal default). Admins: the app locale (e.g. "en").
   */
  locale: string;
  /** Where a signed-in member's language switch saves the choice (POST { lang }); null for everyone else. */
  langSaveUrl: string | null;
  /** The route-bound {club}, or null when the route has none. */
  club: SharedClub | null;
  /** Portal settings: the portal name (else APP_NAME / "Club Portal"). */
  portal: { name: string };
}

declare module "@inertiajs/core" {
  export interface InertiaConfig {
    sharedPageProps: SharedProps;
  }
}
