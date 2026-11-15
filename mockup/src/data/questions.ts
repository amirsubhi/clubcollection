export interface Question {
  id: string;
  topic: string;
  question: string;
  options: string[];
  context?: string;
  link?: { hash: string; label: string };
}

export const questions: Question[] = [
  {
    id: "q1",
    topic: "Navigation",
    question: "How should members and admins move around the app?",
    options: [
      "Members: bottom tabs (Home, Payments, Fund, Account), as shown",
      "Members: one long home page, no tabs",
      "Admins: left sidebar on desktop + menu sheet on phone, as shown",
    ],
    link: { hash: "member-home", label: "See member home" },
  },
  {
    id: "q2",
    topic: "Approving on a phone",
    question: "Must a second admin be able to approve spending from their phone?",
    options: ["Yes, phone approval is required", "Nice to have, desktop is fine for now", "No, approvals only on a computer"],
    link: { hash: "fund-approve", label: "See mobile approval" },
  },
  {
    id: "q3",
    topic: "Uploader",
    question: "How should attaching invoices and receipts work?",
    options: [
      "Several files per record, each tagged (Invoice, Receipt, Bank slip …), as shown",
      "Open the camera directly on phones",
      "One file per record is enough",
    ],
    context: "Files are frozen once a record is submitted.",
    link: { hash: "fund-new", label: "See the form" },
  },
  {
    id: "q4",
    topic: "Status words in BM",
    question: "Which Bahasa Melayu words should we use for statuses?",
    options: [
      "Draf / Menunggu kelulusan / Diluluskan / Dibatalkan",
      "Draf / Dihantar / Diluluskan / Dikembalikan / Batal (as in the diagram)",
      "Committee to suggest",
    ],
  },
  {
    id: "q5",
    topic: "What members see",
    question: "How much spending detail should ordinary members see?",
    options: [
      "Totals by category + welfare count only (as shown)",
      "Also each approved item (payee and amount), without documents",
      "Nothing until the AGM",
    ],
    context: "Documents and welfare names are never shown to members.",
    link: { hash: "member-fund", label: "See member Fund tab" },
  },
  {
    id: "q6",
    topic: "App or website",
    question: "Is an installable web app (PWA, 'Add to Home Screen') enough, or do we need Play Store / App Store apps?",
    options: ["PWA is enough", "Need Play Store (Android) app", "Need both Play Store and App Store"],
    context: "Store apps add yearly fees and review delays.",
  },
  {
    id: "q7",
    topic: "Brand colours",
    question: "This mockup uses the “Buku Tunai” theme: Pine green and Marigold, with light and dark modes. What should the real one use?",
    options: ["Keep Pine & Marigold as shown", "Club logo colours (please send the logo file)", "Committee to choose from 2–3 options"],
  },
];
