#!/usr/bin/env node
// End-to-end smoke test for Option A (member pay flow + Fund Usage maker-checker).
// See tests/e2e/README.md for the database and server set-up. Exits non-zero on failure.
import { createRequire } from "node:module";
import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { deflateSync } from "node:zlib";

const require = createRequire(import.meta.url);
const { chromium, request: pwRequest } = require("playwright");

const BASE = (process.env.E2E_BASE_URL || "http://127.0.0.1:8000").replace(/\/$/, "");
const OUT = process.env.E2E_OUT_DIR || join(tmpdir(), "clubportal-e2e-shots");
const SECRET = process.env.E2E_WEBHOOK_SECRET || "e2e-webhook-secret-local-only";
const PASSWORD = process.env.E2E_PASSWORD || "E2e-Smoke-Pass-2026"; // Database\Seeders\E2eSeeder::PASSWORD
const USERS = {
  member: "e2e.member@clubportal.test",
  adminA: "e2e.admin.a@clubportal.test",
  adminB: "e2e.admin.b@clubportal.test",
};
const GATEWAY_HOST = "dev.toyyibpay.com"; // TOYYIBPAY_SANDBOX=true
// Sandbox-only network noise: Google Fonts, and the Bootstrap CDN used by the legacy Blade login/home pages.
const IGNORED_HOSTS = (process.env.E2E_IGNORE_HOSTS || "fonts.googleapis.com,fonts.gstatic.com,cdn.jsdelivr.net").split(",").filter(Boolean);
const EXPENSE_SEN = 1234; // RM 12.34

mkdirSync(OUT, { recursive: true });
const failures = [];
const log = (...a) => console.log("•", ...a);
const fail = (msg) => {
  failures.push(msg);
  console.error("✗", msg);
};
const isIgnored = (url) => {
  try {
    return IGNORED_HOSTS.includes(new URL(url).hostname);
  } catch {
    return false;
  }
};

/* ---------- tiny fixture files (valid PNG + PDF) ---------- */
function crc32(buf) {
  let c,
    crc = 0xffffffff;
  for (const b of buf) {
    c = (crc ^ b) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}
function pngChunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length);
  const td = Buffer.concat([Buffer.from(type), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(td));
  return Buffer.concat([len, td, crc]);
}
function makePng(w = 16, h = 16) {
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 2; // RGB
  const rows = [];
  for (let y = 0; y < h; y++) {
    rows.push(0);
    for (let x = 0; x < w; x++) rows.push((x * 16) & 255, (y * 16) & 255, 160);
  }
  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    pngChunk("IHDR", ihdr),
    pngChunk("IDAT", deflateSync(Buffer.from(rows))),
    pngChunk("IEND", Buffer.alloc(0)),
  ]);
}
const PDF = `%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj
trailer<</Root 1 0 R>>
%%EOF
`;
const fixtures = join(OUT, "fixtures");
mkdirSync(fixtures, { recursive: true });
const pngPath = join(fixtures, "e2e-receipt.png");
const pdfPath = join(fixtures, "e2e-invoice.pdf");
writeFileSync(pngPath, makePng());
writeFileSync(pdfPath, PDF);

/* ---------- page guards: console errors, CSP violations, overflow ---------- */
function guard(page, label) {
  page.on("console", (msg) => {
    if (msg.type() !== "error") return;
    const url = msg.location()?.url || "";
    if (isIgnored(url) || IGNORED_HOSTS.some((h) => msg.text().includes(h))) return;
    // Inertia's external redirect (Inertia::location) is a 409 + X-Inertia-Location by design; Chrome logs it.
    if (/status of 409/.test(msg.text()) && /\/my\/payments\/\d+\/pay$/.test(url)) return;
    fail(`[${label}] console error on ${page.url()}: ${msg.text()} ${url}`);
  });
  page.on("pageerror", (err) => fail(`[${label}] uncaught error on ${page.url()}: ${err.message}`));
  page.on("requestfailed", (req) => {
    const err = req.failure()?.errorText || "";
    if (isIgnored(req.url()) || err.includes("ERR_ABORTED")) return;
    fail(`[${label}] request failed: ${req.method()} ${req.url()} ${err}`);
  });
}
const CSP_PROBE = () => {
  document.addEventListener("securitypolicyviolation", (e) => {
    const w = window;
    w.__cspViolations = w.__cspViolations || [];
    w.__cspViolations.push(`${e.violatedDirective} blocked ${e.blockedURI || "(inline)"}`);
  });
};
async function checkPage(page, label) {
  const csp = await page.evaluate(() => window.__cspViolations || []).catch(() => []);
  for (const v of csp) fail(`[${label}] CSP violation on ${page.url()}: ${v}`);
  await page.evaluate(() => (window.__cspViolations = [])).catch(() => {});
  const o = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
  if (o.sw > o.cw + 1) fail(`[${label}] horizontal overflow on ${page.url()}: scrollWidth ${o.sw} > ${o.cw}`);
}
async function shot(page, name) {
  for (const scheme of ["light", "dark"]) {
    await page.emulateMedia({ colorScheme: scheme });
    await page.waitForTimeout(250);
    await checkPage(page, `${name}/${scheme}`);
    await page.screenshot({ path: join(OUT, `${name}-${scheme}.png`), fullPage: true, animations: "disabled" });
  }
  await page.emulateMedia({ colorScheme: "light" });
  log("screenshot", name);
}

async function newPage(browser, label, viewport, mobile) {
  const ctx = await browser.newContext({
    viewport,
    isMobile: mobile,
    hasTouch: mobile,
    serviceWorkers: "block", // keep page.route() interception and screenshots deterministic
    colorScheme: "light",
    locale: "ms-MY",
  });
  await ctx.addInitScript(CSP_PROBE);
  const page = await ctx.newPage();
  page.setDefaultTimeout(15000);
  guard(page, label);
  return { ctx, page };
}
async function login(page, email) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith("/login")), page.click('button[type="submit"]')]);
  if (page.url().includes("/login")) throw new Error(`login failed for ${email}`);
}
const parseRm = (s) => Math.round(parseFloat(String(s).replace(/[^0-9.]/g, "")) * 100);
async function spentThisYear(page) {
  const tile = page.locator("div.rounded-lg.border", { has: page.getByText("Spent this year", { exact: true }) }).first();
  return parseRm(await tile.locator(".figure").innerText());
}
async function step(name, fn) {
  log(`step: ${name}`);
  try {
    await fn();
  } catch (e) {
    fail(`${name}: ${e.message.split("\n").slice(0, 3).join(" | ")}`);
    throw e;
  }
}

const MEMBER_VIEW = { width: 390, height: 844 };
const ADMIN_VIEW = { width: 1366, height: 860 };

const browser = await chromium.launch({ args: ["--no-sandbox"] });
try {
  /* (a) member pays a fee through the (faked) gateway */
  const m = await newPage(browser, "member", MEMBER_VIEW, true);
  let paymentId, billCode;
  await step("member logs in and sees the amount due", async () => {
    await login(m.page, USERS.member);
    await m.page.waitForURL(/\/my\/dashboard/);
    await m.page.getByText("Jumlah perlu dibayar").first().waitFor();
    await m.page.getByText("RM 50.00").first().waitFor();
    await shot(m.page, "a1-member-home");
  });
  await step("Review & pay", async () => {
    await m.page.getByRole("link", { name: /Bayar\s+RM 50\.00/ }).first().click();
    await m.page.waitForURL(/\/my\/payments\/\d+\/review/);
    paymentId = m.page.url().match(/payments\/(\d+)\/review/)[1];
    await shot(m.page, "a2-review-pay");
  });
  await step("POST pay redirects to the gateway", async () => {
    await m.page.route(`https://${GATEWAY_HOST}/**`, (route) =>
      route.fulfill({ contentType: "text/html", body: "<!doctype html><title>Fake gateway</title><meta name=viewport content='width=device-width'><h1>Fake ToyyibPay (e2e)</h1>" }),
    );
    await Promise.all([m.page.waitForURL(new RegExp(`^https://${GATEWAY_HOST.replace(/\./g, "\\.")}/`)), m.page.getByRole("button", { name: /Bayar\s+RM 50\.00/ }).click()]);
    billCode = decodeURIComponent(new URL(m.page.url()).pathname.slice(1));
    if (!/^e2e[a-z0-9]{8}$/.test(billCode)) throw new Error(`unexpected bill code "${billCode}" (is E2E_FAKE_GATEWAY=1 set on the server?)`);
    log("redirected to gateway with bill", billCode);
    await shot(m.page, "a3-gateway");
  });
  await step("gateway webhook marks the fee paid", async () => {
    const api = await pwRequest.newContext();
    const form = { billcode: billCode, status_id: "1", amount: "50.00", transaction_id: "E2E-TXN-0001", msg: "ok" };
    const bad = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=wrong-token`, { form });
    if (bad.status() !== 403) throw new Error(`webhook with a wrong token returned ${bad.status()}, expected 403`);
    const res = await api.post(`${BASE}/webhook/toyyibpay?webhook_token=${encodeURIComponent(SECRET)}`, { form });
    const body = await res.text();
    if (res.status() !== 200 || body.trim() !== "ok") throw new Error(`webhook returned ${res.status()} ${body.slice(0, 80)}`);
    await api.dispose();
  });
  await step("PayResult shows Berjaya", async () => {
    await m.page.unroute(`https://${GATEWAY_HOST}/**`);
    await m.page.goto(`${BASE}/my/payments/${paymentId}/thankyou?status_id=1&billcode=${encodeURIComponent(billCode)}`);
    await m.page.getByText("Berjaya", { exact: true }).first().waitFor();
    await shot(m.page, "a4-pay-result");
    await m.page.goto(`${BASE}/my/dashboard`);
    await m.page.getByText("Semua yuran telah dibayar").first().waitFor();
    await shot(m.page, "a5-member-home-paid");
  });
  await m.ctx.close();

  /* (b) admin A records an expense with 2 documents and submits it */
  const a = await newPage(browser, "adminA", ADMIN_VIEW, false);
  let clubId, showUrl, spentBefore;
  await step("admin A opens the dashboard", async () => {
    await login(a.page, USERS.adminA);
    const html = await a.page.content();
    clubId = html.match(/\/admin\/clubs\/(\d+)\//)?.[1];
    if (!clubId) throw new Error("club id not found on /home");
    await a.page.goto(`${BASE}/admin/clubs/${clubId}/dashboard`);
    spentBefore = await spentThisYear(a.page);
    log("spent this year before:", spentBefore / 100);
    await shot(a.page, "b1-admin-dashboard-before");
  });
  await step("admin A opens Fund Usage", async () => {
    await a.page.goto(`${BASE}/admin/clubs/${clubId}/fund-usage`);
    await a.page.waitForLoadState("networkidle");
    await shot(a.page, "b2-fund-usage");
  });
  await step("admin A creates an expense with PNG + PDF and submits", async () => {
    await a.page.goto(`${BASE}/admin/clubs/${clubId}/fund-usage/create`);
    await a.page.locator("#category").click();
    await a.page.getByRole("option", { name: "Supplies" }).click();
    await a.page.fill("#payee", "Kedai E2E Sdn Bhd");
    await a.page.fill("#desc", "E2E smoke: shuttlecocks");
    await a.page.fill("#amount", (EXPENSE_SEN / 100).toFixed(2));
    await a.page.fill("#invoiceNo", "E2E-INV-1");
    await a.page.locator('input[type="file"][multiple]').setInputFiles([pngPath, pdfPath]);
    for (const [file, kind] of [["e2e-receipt.png", "Receipt"], ["e2e-invoice.pdf", "Invoice"]]) {
      await a.page.getByLabel(`Kind of ${file}`).click();
      await a.page.getByRole("option", { name: new RegExp(`^${kind} ·`) }).click();
    }
    await shot(a.page, "b3-create-filled");
    await Promise.all([a.page.waitForURL(/\/admin\/fund-usage\/\d+$/), a.page.getByRole("button", { name: "Submit for approval" }).click()]);
    showUrl = a.page.url();
    await a.page.getByText("submitted for approval").first().waitFor();
    for (const f of ["e2e-receipt.png", "e2e-invoice.pdf"]) await a.page.getByText(f).first().waitFor();
    await shot(a.page, "b4-submitted");
  });
  await a.ctx.close();

  /* (c) admin B approves with the declaration; dashboard total moves */
  const b = await newPage(browser, "adminB", ADMIN_VIEW, false);
  await step("admin B approves with the declaration", async () => {
    await login(b.page, USERS.adminB);
    await b.page.goto(showUrl);
    await shot(b.page, "c1-review");
    await b.page.getByRole("button", { name: /^Approve/ }).first().click();
    const dialog = b.page.getByRole("dialog");
    await dialog.locator("#coi").click();
    await shot(b.page, "c2-approve-dialog");
    await dialog.getByRole("button", { name: /^Approve/ }).click();
    await dialog.waitFor({ state: "detached" });
    await b.page.getByText(/^Approved /).first().waitFor();
    await shot(b.page, "c3-approved");
  });
  await step("dashboard spent increases by the expense amount", async () => {
    await b.page.goto(`${BASE}/admin/clubs/${clubId}/dashboard`);
    const after = await spentThisYear(b.page);
    log("spent this year after:", after / 100);
    if (after - spentBefore !== EXPENSE_SEN) throw new Error(`spent went ${spentBefore / 100} → ${after / 100}, expected +${EXPENSE_SEN / 100}`);
    await shot(b.page, "c4-admin-dashboard-after");
  });
  await b.ctx.close();
} catch {
  /* already recorded by step() */
} finally {
  await browser.close();
}

if (failures.length) {
  console.error(`\nSMOKE FAILED (${failures.length}):\n - ${failures.join("\n - ")}`);
  process.exit(1);
}
console.log(`\nSMOKE PASSED. Screenshots in ${OUT}`);
