import { ArrowRight, Monitor, Smartphone } from "lucide-react";
import { MockupStrip } from "@/components/app/MockupStrip";
import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { screens, type Area } from "@/routes";
import { cn } from "@/lib/utils";

const groups: { area: Area; title: string; blurb: string }[] = [
  { area: "member", title: "Member", blurb: "Phone-first. Bottom tabs: Utama, Bayaran, Dana, Akaun. BM/EN toggle at the top." },
  { area: "admin", title: "Admin · Fund Usage", blurb: "Desktop sidebar, menu sheet on phones. One admin records, a different admin approves." },
  { area: "report", title: "Reports", blurb: "AGM statement and the decisions we need from the committee." },
];

export function Overview({ phoneFrame, setPhoneFrame }: { phoneFrame: boolean; setPhoneFrame: (v: boolean) => void }) {
  return (
    <div className="flex min-h-full flex-col">
      <MockupStrip showIndexLink={false} />
      <main className="flex-1 py-6 sm:py-10">
        <div className="mx-auto flex max-w-6xl flex-col gap-8">
          <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <div className="text-sm font-medium text-primary">Kelab Contoh (Demo Club)</div>
              <h1 className="font-display text-3xl font-semibold sm:text-4xl">Club Portal: clickable mockup</h1>
              <p className="mt-2 max-w-2xl text-muted-foreground">
                Every screen below uses made-up names and amounts. Nothing is saved and no money moves. Click a card to open the screen; use “All screens” in the
                dark strip to come back.
              </p>
            </div>
            <div className="hidden flex-col gap-1.5 md:flex">
              <span className="text-xs font-medium text-muted-foreground">Member screens on desktop</span>
              <div role="radiogroup" aria-label="Member screen preview size" className="inline-flex rounded-md border bg-card p-1">
                {[
                  { v: true, label: "Phone frame (390px)", Icon: Smartphone },
                  { v: false, label: "Full width", Icon: Monitor },
                ].map(({ v, label, Icon }) => (
                  <button
                    key={label}
                    type="button"
                    role="radio"
                    aria-checked={phoneFrame === v}
                    onClick={() => setPhoneFrame(v)}
                    className={cn(
                      "inline-flex items-center gap-1.5 rounded-sm px-3 py-1.5 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                      phoneFrame === v ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:text-foreground",
                    )}
                  >
                    <Icon className="h-4 w-4" aria-hidden /> {label}
                  </button>
                ))}
              </div>
            </div>
          </header>

          {groups.map((g) => (
            <section key={g.area} aria-labelledby={`g-${g.area}`} className="flex flex-col gap-3">
              <div>
                <h2 id={`g-${g.area}`} className="font-display text-xl font-semibold">
                  {g.title}
                </h2>
                <p className="text-sm text-muted-foreground">{g.blurb}</p>
              </div>
              <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {screens
                  .filter((s) => s.area === g.area)
                  .map((s) => (
                    <li key={s.hash}>
                      <a href={`#${s.hash}`} className="group block h-full rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
                        <Card className="h-full transition-colors group-hover:border-primary/50 group-hover:bg-accent/40">
                          <CardHeader>
                            <CardTitle className="flex items-center justify-between gap-2">
                              {s.title}
                              <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground group-hover:text-primary" aria-hidden />
                            </CardTitle>
                            <CardDescription>{s.description}</CardDescription>
                            <code className="text-xs text-muted-foreground">#{s.hash}</code>
                          </CardHeader>
                        </Card>
                      </a>
                    </li>
                  ))}
              </ul>
            </section>
          ))}
          <p className="text-xs text-muted-foreground">
            Purple dashed boxes on some screens are mockup controls for showing other states (draft, approved, void, errors). They won't exist in the real app.
          </p>
        </div>
      </main>
    </div>
  );
}
