import { ArrowRight, ArrowUpRight, FileText, LayoutDashboard, Monitor, Smartphone, Sparkles, UserRound, type LucideIcon } from "lucide-react";
import { MockupStrip } from "@/components/app/MockupStrip";
import { ThemeToggle } from "@/components/app/ThemeToggle";
import { IconBubble, NodePattern, type Tone } from "@/components/app/Visuals";
import { screens, type Area } from "@/routes";
import { cn } from "@/lib/utils";

const groups: { area: Area; title: string; blurb: string; Icon: LucideIcon; tone: Tone }[] = [
  { area: "member", title: "Member app", blurb: "Phone-first, BM first. Bottom tabs Utama · Bayaran · Dana · Akaun; 44px+ targets, 16px+ text.", Icon: UserRound, tone: "accent" },
  { area: "admin", title: "Admin · Fund Usage", blurb: "Collapsible sidebar, search, theme and user menu. One admin records, a different admin approves.", Icon: LayoutDashboard, tone: "accent" },
  { area: "report", title: "Reports", blurb: "The AGM statement on paper, and the decisions we need from the committee.", Icon: FileText, tone: "accent" },
];

const starts = [
  { hash: "member-home", title: "Member home", sub: "Balance card, Bayar, quick actions", Icon: Smartphone, tone: "accent" as Tone },
  { hash: "admin-dashboard", title: "Admin dashboard", sub: "KPIs, charts, approvals queue", Icon: LayoutDashboard, tone: "accent" as Tone },
  { hash: "fund-item", title: "Record & approval", sub: "Maker → checker, LULUS stamp", Icon: Sparkles, tone: "accent" as Tone },
];

const isPhone = (hash: string, area: Area) => area === "member" || hash === "fund-approve";

export function Overview({ phoneFrame, setPhoneFrame }: { phoneFrame: boolean; setPhoneFrame: (v: boolean) => void }) {
  return (
    <div className="flex min-h-full flex-col">
      <MockupStrip showIndexLink={false} />

      <header className="bleed-x relative overflow-hidden bg-primary pb-24 pt-10 text-primary-foreground sm:pb-28 sm:pt-14">
        <NodePattern className="text-primary-foreground opacity-90" />
        <div className="relative mx-auto flex max-w-6xl flex-col gap-4">
          <div className="inline-flex items-center gap-2 self-start rounded-full border border-primary-foreground/30 px-3 py-1 text-sm font-medium text-primary-foreground/90">
            <span className="h-2 w-2 rounded-full bg-mint" aria-hidden /> Kelab Contoh (Demo Club) · v3
          </div>
          <h1 className="max-w-3xl font-display text-[40px] font-extrabold leading-[1.02] tracking-tight text-primary-foreground sm:text-6xl">
            Club Portal, <span className="underline decoration-mint decoration-[6px] underline-offset-[6px]">clickable</span> mockup
          </h1>
          <p className="max-w-2xl text-base text-primary-foreground/85 sm:text-lg">
            Pay fees by FPX, see where the money goes, and approve spending with a second pair of eyes. Every name and ringgit here is made up; nothing is saved and
            no money moves.
          </p>
          <ul className="flex flex-wrap gap-2 text-sm text-primary-foreground/90">
            {[`${screens.length - 1} screens`, "BM / EN", "Light & dark", "390px phone → 1366px desktop"].map((c) => (
              <li key={c} className="rounded-full bg-primary-foreground/10 px-3 py-1">
                {c}
              </li>
            ))}
          </ul>
        </div>
      </header>

      <main className="relative -mt-16 flex-1 pb-12">
        <div className="mx-auto flex max-w-6xl flex-col gap-10">
          {/* Start here + settings */}
          <section aria-label="Start here" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[repeat(4,minmax(0,1fr))]">
            {starts.map((s) => (
              <a
                key={s.hash}
                href={`#${s.hash}`}
                className="group flex items-center gap-3 rounded-xl border bg-card p-4 shadow-[0_18px_40px_-28px_rgba(30,27,58,0.55)] transition-colors hover:border-primary/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <IconBubble Icon={s.Icon} tone={s.tone} size={48} />
                <span className="min-w-0 flex-1">
                  <span className="block text-xs font-semibold uppercase tracking-wide text-muted-foreground">Start here</span>
                  <span className="block font-display text-lg font-bold leading-tight">{s.title}</span>
                  <span className="block text-sm text-muted-foreground">{s.sub}</span>
                </span>
                <ArrowUpRight className="h-5 w-5 shrink-0 text-muted-foreground transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden />
              </a>
            ))}
            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-[0_18px_40px_-28px_rgba(30,27,58,0.55)]">
              <div className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Theme</span>
                <ThemeToggle withLabels className="self-start" />
              </div>
              <div className="hidden flex-col gap-1.5 md:flex">
                <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Member screens on desktop</span>
                <div role="radiogroup" aria-label="Member screen preview size" className="inline-flex self-start rounded-full border bg-card p-0.5">
                  {[
                    { v: true, label: "Phone frame", Icon: Smartphone },
                    { v: false, label: "Full width", Icon: Monitor },
                  ].map(({ v, label, Icon }) => (
                    <button
                      key={label}
                      type="button"
                      role="radio"
                      aria-checked={phoneFrame === v}
                      onClick={() => setPhoneFrame(v)}
                      className={cn(
                        "inline-flex h-8 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                        phoneFrame === v ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
                      )}
                    >
                      <Icon className="h-4 w-4" strokeWidth={1.75} aria-hidden /> {label}
                    </button>
                  ))}
                </div>
              </div>
            </div>
          </section>

          {groups.map((g) => {
            const list = screens.filter((s) => s.area === g.area);
            return (
              <section key={g.area} aria-labelledby={`g-${g.area}`} className="flex flex-col gap-4">
                <div className="flex items-start gap-3">
                  <IconBubble Icon={g.Icon} tone={g.tone} size={44} />
                  <div className="min-w-0">
                    <h2 id={`g-${g.area}`} className="flex items-baseline gap-2 font-display text-2xl font-extrabold tracking-tight">
                      {g.title} <span className="font-mono text-sm font-medium text-muted-foreground">{list.length}</span>
                    </h2>
                    <p className="text-sm text-muted-foreground">{g.blurb}</p>
                  </div>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                  {list.map((s) => {
                    const phone = isPhone(s.hash, s.area);
                    return (
                      <li key={s.hash}>
                        <a
                          href={`#${s.hash}`}
                          className="group flex h-full flex-col gap-2 rounded-xl border bg-card p-4 transition-colors hover:border-primary/60 hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                        >
                          <div className="flex items-center justify-between gap-2">
                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                              {phone ? <Smartphone className="h-3.5 w-3.5" aria-hidden /> : <Monitor className="h-3.5 w-3.5" aria-hidden />}
                              {phone ? "Phone" : "Desktop + phone"}
                              {s.hash === "admin-dashboard" && <span className="rounded-full bg-accent px-2 py-0.5 text-[11px] font-bold text-accent-foreground">New · admin landing</span>}
                            </span>
                            <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-primary" aria-hidden />
                          </div>
                          <h3 className="font-display text-lg font-bold leading-tight tracking-tight">{s.title}</h3>
                          <p className="flex-1 text-sm text-muted-foreground">{s.description}</p>
                          <code className="self-start rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">#{s.hash}</code>
                        </a>
                      </li>
                    );
                  })}
                </ul>
              </section>
            );
          })}
          <p className="flex items-start gap-2 rounded-lg border border-dashed border-mock-border bg-mock-soft p-3 text-sm text-mock">
            Purple dashed boxes on some screens are mockup controls for showing other states (draft, approved, void, errors, returned). They won't exist in the real
            app.
          </p>
        </div>
      </main>
    </div>
  );
}
