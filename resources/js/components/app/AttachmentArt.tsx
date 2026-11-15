import type { AttachmentKind } from "@/types/domain";

type Art = "receipt" | "invoice" | "slip" | "letter";
const artFor: Record<AttachmentKind, Art> = { receipt: "receipt", invoice: "invoice", bank_slip: "slip", acknowledgement: "letter", other: "letter" };

/** Placeholder drawing by document kind, used only when an attachment has no URL to preview. */
export function AttachmentArt({ kind, className, title }: { kind: AttachmentKind; className?: string; title?: string }) {
  const art = artFor[kind];
  const line = (y: number, w: number, x = 20, o = 1) => <rect key={`${x}-${y}`} x={x} y={y} width={w} height="5" rx="2.5" fill="#94a3b8" opacity={o} />;
  return (
    <svg viewBox="0 0 160 200" role="img" aria-label={title ?? `${art} placeholder`} className={className}>
      <rect width="160" height="200" fill={art === "slip" ? "#F0EFF8" : "#f8fafc"} />
      {art === "receipt" && (
        <g>
          <path d="M35 14h90v168l-7-5-8 5-8-5-7 5-8-5-7 5-8-5-8 5-7-5-8 5-7-5-7 5z" fill="#fff" stroke="#cbd5e1" />
          <rect x="55" y="26" width="50" height="7" rx="3" fill="#475569" />
          {line(44, 70, 45, 0.6)}
          {[62, 74, 86, 98, 110].map((y) => (
            <g key={y}>
              {line(y, 44, 45)}
              {line(y, 16, 99)}
            </g>
          ))}
          <rect x="45" y="128" width="70" height="1" fill="#94a3b8" />
          <rect x="45" y="136" width="30" height="7" rx="3" fill="#334155" />
          <rect x="88" y="136" width="27" height="7" rx="3" fill="#334155" />
          <text x="80" y="164" textAnchor="middle" fontSize="8" fill="#64748b" fontFamily="sans-serif">RESIT / RECEIPT</text>
        </g>
      )}
      {art === "invoice" && (
        <g>
          <rect x="18" y="12" width="124" height="176" fill="#fff" stroke="#cbd5e1" />
          <rect x="28" y="24" width="22" height="22" rx="4" fill="#5B4FCF" opacity="0.8" />
          <text x="132" y="36" textAnchor="end" fontSize="11" fontWeight="700" fill="#334155" fontFamily="sans-serif">INVOICE</text>
          {line(54, 50, 28, 0.6)}
          {line(62, 40, 28, 0.6)}
          <rect x="28" y="78" width="104" height="10" fill="#e2e8f0" />
          {[96, 108, 120, 132].map((y) => (
            <g key={y}>
              {line(y, 60, 28)}
              {line(y, 22, 110)}
            </g>
          ))}
          <rect x="88" y="150" width="44" height="9" rx="3" fill="#334155" />
          {line(172, 70, 28, 0.4)}
        </g>
      )}
      {art === "slip" && (
        <g>
          <rect x="22" y="40" width="116" height="120" rx="8" fill="#fff" stroke="#cbd5e1" />
          <circle cx="80" cy="66" r="12" fill="#1F8A64" opacity="0.85" />
          <path d="M74 66l4 4 8-8" stroke="#fff" strokeWidth="3" fill="none" strokeLinecap="round" />
          <text x="80" y="94" textAnchor="middle" fontSize="8" fill="#334155" fontFamily="sans-serif">Transfer successful</text>
          {line(104, 76, 42, 0.6)}
          {line(116, 60, 50, 0.6)}
          <rect x="52" y="130" width="56" height="9" rx="3" fill="#334155" />
        </g>
      )}
      {art === "letter" && (
        <g>
          <rect x="18" y="12" width="124" height="176" fill="#fff" stroke="#cbd5e1" />
          {line(28, 60, 28, 0.8)}
          {[48, 58, 68, 78, 88, 98, 108].map((y, i) => line(y, i % 3 === 2 ? 70 : 100, 28, 0.5))}
          <path d="M34 150c10-14 18 8 26-4s14 6 24-2" stroke="#241C6B" strokeWidth="2" fill="none" />
          {line(160, 50, 28, 0.6)}
          <text x="132" y="180" textAnchor="end" fontSize="7" fill="#64748b" fontFamily="sans-serif">Akuan terima</text>
        </g>
      )}
    </svg>
  );
}
