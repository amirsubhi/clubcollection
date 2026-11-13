import { MemberShell, LangToggle } from "@/components/app/MemberShell";
import { Card, CardContent } from "@/components/ui/card";
import { currentMember } from "@/data/club";
import { useT } from "@/i18n";

export function MemberAccount() {
  const t = useT();
  const rows: [string, string][] = [
    [t("memberNo"), currentMember.memberNo],
    [t("email"), currentMember.email],
    [t("phone"), currentMember.phone],
  ];
  return (
    <MemberShell tab="account" title={t("account")}>
      <Card>
        <CardContent className="flex items-center gap-3 p-4 sm:p-4">
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-accent font-display font-semibold text-accent-foreground" aria-hidden>
            MH
          </div>
          <div className="min-w-0">
            <div className="truncate font-semibold">{currentMember.name}</div>
            <div className="text-sm text-muted-foreground">Kelab Contoh</div>
          </div>
        </CardContent>
      </Card>
      <Card>
        <dl className="divide-y text-sm">
          {rows.map(([k, v]) => (
            <div key={k} className="flex justify-between gap-3 px-4 py-3">
              <dt className="text-muted-foreground">{k}</dt>
              <dd className="min-w-0 truncate text-right font-medium">{v}</dd>
            </div>
          ))}
          <div className="flex items-center justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{t("language")}</dt>
            <dd>
              <LangToggle />
            </dd>
          </div>
        </dl>
      </Card>
      <p className="text-xs text-muted-foreground">Mockup: profile editing, password and sign-out are out of scope.</p>
    </MemberShell>
  );
}
