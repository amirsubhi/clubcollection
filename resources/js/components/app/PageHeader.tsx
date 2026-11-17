import type { ReactNode } from "react";

export function PageHeader({ title, description, actions, eyebrow }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; eyebrow?: ReactNode }) {
  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        {eyebrow && <div className="mb-1 text-sm font-medium text-primary">{eyebrow}</div>}
        <h1 className="break-words font-display text-[1.75rem] font-extrabold leading-tight tracking-tight sm:text-[2rem]">{title}</h1>
        {description && <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{description}</p>}
      </div>
      {/* With large text on a phone the button labels wrap rather than push the page sideways. */}
      {actions && <div className="flex max-w-full flex-wrap gap-2 [&>*]:h-auto [&>*]:min-h-10 [&>*]:max-w-full [&>*]:whitespace-normal">{actions}</div>}
    </div>
  );
}
