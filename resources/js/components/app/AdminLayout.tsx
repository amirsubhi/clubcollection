import type { ReactNode } from "react";
import { Head, usePage } from "@inertiajs/react";
import {
  AlertTriangle,
  BarChart3,
  Building2,
  CheckCircle2,
  FolderTree,
  Home,
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
  billing: SlidersHorizontal,
  discounts: Percent,
  categories: FolderTree,
  ledger: BookOpen,
  audit: ScrollText,
  home: Home,
  clubs: Building2,
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

/** Flash + first validation error, shown at the top of admin pages. */
export function AdminFlash() {
  const { flash, errors } = usePage<{ errors?: Record<string, string> }>().props;
  const firstError = errors ? Object.values(errors)[0] : undefined;
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
export function AdminLayout({ nav, active, title, wide = true, children }: { nav: AdminNavProps; active: string; title: string; wide?: boolean; children: ReactNode }) {
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
        homeHref={nav.homeHref}
        brandSubtitle={nav.brandSubtitle}
        memberHref={nav.memberHref}
        onLogout={() => postForm(nav.logoutUrl)}
        wide={wide}
      >
        <AdminFlash />
        {children}
      </AdminShell>
    </>
  );
}
