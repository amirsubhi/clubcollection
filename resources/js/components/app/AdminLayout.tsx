import type { ReactNode } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import {
  AlertTriangle,
  BarChart3,
  Building2,
  CheckCircle2,
  FolderTree,
  Home,
  Layers,
  LayoutDashboard,
  Menu,
  Percent,
  ReceiptText,
  ScrollText,
  ShieldCheck,
  Users,
  Wallet,
  BookOpen,
  Coins,
  SlidersHorizontal,
  BadgeCheck,
  UserCog,
  IdCard,
  Settings,
} from "lucide-react";
import { AdminShell, type AdminNavGroup } from "./AdminShell";
import { Alert } from "@/components/ui/alert";
import type { AdminNavProps } from "@/types/admin";

const icons: Record<string, typeof Menu> = {
  dashboard: LayoutDashboard,
  fund: ReceiptText,
  verify: BadgeCheck,
  payments: Wallet,
  members: Users,
  rates: Coins,
  levels: Layers,
  billing: SlidersHorizontal,
  profile: IdCard,
  settings: Settings,
  discounts: Percent,
  categories: FolderTree,
  ledger: BookOpen,
  audit: ScrollText,
  home: Home,
  clubs: Building2,
  admins: UserCog,
  statistics: BarChart3,
  security: ShieldCheck,
};

/** Full-page POST (logout ends on a Blade page, so not an Inertia visit). */
function postForm(url: string) {
  const form = document.createElement("form");
  form.method = "POST";
  form.action = url;
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? "";
  const input = document.createElement("input");
  input.type = "hidden";
  input.name = "_token";
  input.value = token;
  form.appendChild(input);
  document.body.appendChild(form);
  form.submit();
}

/**
 * Flash + first validation error, shown at the top of admin pages.
 * ownErrors: the page shows its validation errors itself (an error summary and/or messages under the fields),
 * so they are not repeated here.
 */
export function AdminFlash({ ownErrors = false }: { ownErrors?: boolean }) {
  const { flash, errors } = usePage<{ errors?: Record<string, string> }>().props;
  const firstError = !ownErrors && errors ? Object.values(errors)[0] : undefined;
  return (
    <>
      {flash.success && (
        <Alert variant="success" role="status">
          <CheckCircle2 aria-hidden />
          <p className="text-sm">{flash.success}</p>
        </Alert>
      )}
      {(flash.error || firstError) && (
        <Alert variant="danger" role="alert">
          <AlertTriangle aria-hidden />
          <p className="text-sm">{flash.error ?? firstError}</p>
        </Alert>
      )}
    </>
  );
}

/** AdminShell wired to the server-built nav (App\Support\Admin\AdminNav). */
export function AdminLayout({
  nav,
  active,
  title,
  wide = true,
  ownErrors = false,
  children,
}: {
  nav: AdminNavProps;
  active: string;
  title: string;
  wide?: boolean;
  /** The page shows validation errors itself (summary and inline): the top banner only shows flash messages. */
  ownErrors?: boolean;
  children: ReactNode;
}) {
  const groups: AdminNavGroup[] = nav.groups.map((g) => ({
    title: g.title,
    items: g.items.map((i) => ({ key: i.key, label: i.label, href: i.href, Icon: icons[i.icon] ?? Menu, external: i.external, badge: i.badge ?? undefined })),
  }));
  return (
    <>
      <Head title={title} />
      <AdminShell
        active={active}
        nav={groups}
        user={nav.user}
        userInitials={nav.user.initials}
        homeHref={nav.homeHref}
        brandSubtitle={nav.brandSubtitle ?? undefined}
        brandName={nav.brandName}
        brandLogoUrl={nav.brandLogoUrl ?? undefined}
        memberHref={nav.memberHref}
        onLogout={() => {
          router.clearHistory();
          postForm(nav.logoutUrl);
        }}
        wide={wide}
      >
        <AdminFlash ownErrors={ownErrors} />
        {children}
      </AdminShell>
    </>
  );
}
