import { MemberShell, LangToggle } from "@/components/app/MemberShell";
import { ThemeToggle } from "@/components/app/ThemeToggle";
import { Avatar } from "@/components/app/Visuals";
import { currentMember } from "@/data/club";
import { useLang, useT } from "@/i18n";

export function MemberAccount() {
  const t = useT();
  const { lang } = useLang();
  const rows: [string, string, boolean?][] = [
    [t("memberNo"), currentMember.memberNo, true],
    [t("email"), currentMember.email],
    [t("phone"), currentMember.phone],
  ];
  return (
    <MemberShell tab="account" title={t("account")}>
      <section className="flex items-center gap-4 rounded-[20px] border bg-card p-4">
        <Avatar name={currentMember.name} size={44} className="h-14 w-14 text-lg" />
        <div className="min-w-0">
          <div className="font-display text-xl font-bold leading-tight">{currentMember.name}</div>
          <div className="text-base text-muted-foreground">Kelab Contoh</div>
        </div>
      </section>
      <dl className="divide-y rounded-[20px] border bg-card text-base">
        {rows.map(([k, v, mono]) => (
          <div key={k} className="flex min-h-14 items-center justify-between gap-3 px-4 py-3">
            <dt className="text-muted-foreground">{k}</dt>
            <dd className={`min-w-0 truncate text-right font-semibold ${mono ? "font-mono font-medium" : ""}`}>{v}</dd>
          </div>
        ))}
        <div className="flex min-h-14 items-center justify-between gap-3 px-4 py-3">
          <dt className="text-muted-foreground">{t("language")}</dt>
          <dd>
            <LangToggle />
          </dd>
        </div>
        <div className="flex flex-col gap-2 px-4 py-3">
          <dt className="text-muted-foreground">{t("theme")}</dt>
          <dd>
            <ThemeToggle size="lg" withLabels lang={lang} className="w-full justify-between [&>button]:flex-1" />
          </dd>
        </div>
      </dl>
      <p className="px-1 text-sm text-muted-foreground">Mockup: profile editing, password and sign-out are out of scope.</p>
    </MemberShell>
  );
}
