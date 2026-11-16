import * as React from "react";
import { joinIds, useFieldState } from "@/components/ui/field-context";
import { cn } from "@/lib/utils";

export interface TextareaProps extends React.TextareaHTMLAttributes<HTMLTextAreaElement> {
  invalid?: boolean;
}

/** Inside a <Field>, the id, aria-describedby (hint/error), required and invalid state come from the Field unless set here. */
export const Textarea = React.forwardRef<HTMLTextAreaElement, TextareaProps>(({ className, invalid, id, required, "aria-describedby": describedBy, ...props }, ref) => {
  const field = useFieldState();
  const bad = invalid ?? field.invalid;
  const req = required ?? field.required;
  return (
    <textarea
      ref={ref}
      id={id ?? field.id}
      required={req || undefined}
      aria-required={req || undefined}
      aria-invalid={bad || undefined}
      aria-describedby={joinIds(describedBy, field.describedBy)}
      className={cn(
        "flex min-h-[80px] w-full rounded-md border border-input bg-card px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
        bad && "border-danger focus-visible:ring-danger",
        className,
      )}
      {...props}
    />
  );
});
Textarea.displayName = "Textarea";
