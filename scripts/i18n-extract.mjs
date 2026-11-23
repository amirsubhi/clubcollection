// One-off (i18n phase 1): rebuilds lang/member/{ms,en}.json from the inline dictionary that lived in
// resources/js/lib/i18n.ts (and the short month names in resources/js/lib/format.ts) at a given git ref, then checks
// every value of the written files against that source. Usage: node scripts/i18n-extract.mjs [ref] [--check] [--extra-ok]
// --check only compares (exit 1 on any difference); without it the files are (re)written first. --extra-ok allows
// keys added after the move (e.g. `node scripts/i18n-extract.mjs 06e97d6 --check --extra-ok` on a later tree).
import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";
import fs from "node:fs";

const args = process.argv.slice(2);
const checkOnly = args.includes("--check");
const ref = args.find((a) => !a.startsWith("--")) ?? "origin/master";
const root = new URL("..", import.meta.url).pathname;
const ts = createRequire(root + "package.json")("typescript");

const show = (p) => execFileSync("git", ["-C", root, "show", `${ref}:${p}`], { encoding: "utf8" });
function load(src, names) {
  const body = src.replace(/^import .*$/gm, "").replace(/^export /gm, "") + `\nreturn { ${names} };`;
  const js = ts.transpileModule(body, { compilerOptions: { target: ts.ScriptTarget.ES2020 } }).outputText;
  return new Function("createContext", "useContext", js)(() => ({}), () => ({}));
}

const { dict, MONTHS } = load(show("resources/js/lib/i18n.ts"), "dict, MONTHS");
const { MONTHS: SHORT, MONTHS_BM: SHORT_BM } = load(show("resources/js/lib/format.ts"), "MONTHS, MONTHS_BM");

const want = { ms: {}, en: {} };
for (const [k, v] of Object.entries(dict)) {
  want.ms[k] = v.bm;
  want.en[k] = v.en;
}
for (let i = 0; i < 12; i++) {
  want.ms[`month_${i + 1}`] = MONTHS.bm[i];
  want.en[`month_${i + 1}`] = MONTHS.en[i];
  want.ms[`monthShort_${i + 1}`] = SHORT_BM[i];
  want.en[`monthShort_${i + 1}`] = SHORT[i];
}

const dir = root + "lang/member";
if (!checkOnly) {
  fs.mkdirSync(dir, { recursive: true });
  for (const l of ["ms", "en"]) fs.writeFileSync(`${dir}/${l}.json`, JSON.stringify(want[l], null, 2) + "\n");
}

// Value identity, both directions: every source value is in the file unchanged, and the file has nothing else
// (keys added later, after this move, are listed but not failures when --extra-ok is given).
let same = 0;
const problems = [];
for (const l of ["ms", "en"]) {
  const got = JSON.parse(fs.readFileSync(`${dir}/${l}.json`, "utf8"));
  for (const [k, v] of Object.entries(want[l])) {
    if (got[k] === v) same++;
    else problems.push(`${l}.${k}: ${JSON.stringify(got[k])} != ${JSON.stringify(v)}`);
  }
  for (const k of Object.keys(got)) if (!(k in want[l])) (args.includes("--extra-ok") ? () => {} : (m) => problems.push(m))(`${l}.${k}: not in ${ref}`);
}
console.log(`${ref}: ${Object.keys(dict).length} dictionary entries + 24 month names per language; ${same} values identical, ${problems.length} problems`);
for (const p of problems) console.log("  " + p);
process.exit(problems.length ? 1 : 0);
