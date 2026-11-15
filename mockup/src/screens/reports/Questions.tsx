import { useState } from "react";
import { ArrowRight } from "lucide-react";
import { AdminShell } from "@/components/app/AdminShell";
import { PageHeader } from "@/components/app/PageHeader";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Textarea } from "@/components/ui/textarea";
import { ApprovalFlowDiagram } from "@/components/app/Diagrams";
import { questions } from "@/data/questions";
import { cn } from "@/lib/utils";

export function Questions() {
  const [picked, setPicked] = useState<Record<string, number>>({});
  return (
    <AdminShell active="questions" wide={false}>
      <PageHeader
        eyebrow="For the committee"
        title="Questions for committee"
        description="Please discuss these seven decisions. Picks here are only for the meeting; they are not saved."
      />
      <ol className="flex flex-col gap-4">
        {questions.map((q, n) => (
          <li key={q.id}>
            <Card>
              <CardHeader className="pb-3">
                <div className="font-mono text-xs font-medium uppercase tracking-wide text-primary">
                  {n + 1}. {q.topic}
                </div>
                <CardTitle className="text-lg">{q.question}</CardTitle>
                {q.context && <CardDescription>{q.context}</CardDescription>}
              </CardHeader>
              <CardContent className="flex flex-col gap-3">
                {q.id === "q4" && (
                  <div className="rounded-lg border bg-muted/40 p-3">
                    <ApprovalFlowDiagram current="submitted" />
                  </div>
                )}
                <div role="radiogroup" aria-label={q.topic} className="flex flex-col gap-2">
                  {q.options.map((o, i) => {
                    const on = picked[q.id] === i;
                    return (
                      <button
                        key={o}
                        type="button"
                        role="radio"
                        aria-checked={on}
                        onClick={() => setPicked({ ...picked, [q.id]: i })}
                        className={cn(
                          "flex min-h-11 items-start gap-3 rounded-md border p-3 text-left text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                          on ? "border-primary bg-accent" : "hover:bg-muted",
                        )}
                      >
                        <span className={cn("mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border", on ? "border-primary" : "border-input")} aria-hidden>
                          {on && <span className="h-2 w-2 rounded-full bg-primary" />}
                        </span>
                        {o}
                      </button>
                    );
                  })}
                </div>
                <Textarea rows={2} placeholder="Notes from the meeting (optional)" aria-label={`Notes for ${q.topic}`} />
                {q.link && (
                  <a href={`#${q.link.hash}`} className="inline-flex items-center gap-1 self-start text-sm font-medium text-primary hover:underline">
                    {q.link.label} <ArrowRight className="h-4 w-4" aria-hidden />
                  </a>
                )}
              </CardContent>
            </Card>
          </li>
        ))}
      </ol>
    </AdminShell>
  );
}
