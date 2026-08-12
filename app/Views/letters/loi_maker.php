<?php
/**
 * Letter of Intent (Employment) Maker — Trio Tours And Travels Pvt. Ltd.
 *
 * Layout conventions follow app/Views/payroll/slip_maker.php (same logo, navy
 * primary, editor-left / live-preview-right, Print + Download PDF).
 *
 * PRINT FIDELITY — the slip maker captures whatever width the preview happens
 * to be, so its PDF changes shape with the browser window. Here the letter is
 * authored ONCE as a fixed 174mm content column (A4 210mm less 18mm side
 * margins) and all three outputs reuse it:
 *   • preview — wrapped in a 210x297mm sheet, CSS-scaled to fit the column
 *   • print   — iframe with @page{margin:16mm 18mm 14mm}, content at 174mm
 *   • pdf     — off-screen clone at natural 174mm, html2pdf margin 16/18/14/18
 * Nothing bleeds to the page edge, so page 2 keeps the same margins as page 1.
 */
$activePage = 'letters';
$logoPath = __DIR__ . '/../../../salary slip logo.jpeg';
$logoB64  = file_exists($logoPath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($logoPath)) : '';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Letter of Intent Maker — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&family=Noto+Sans:wght@400;600;700&family=Dancing+Script:wght@600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}};
</script>
<script src="/assets/js/html2pdf.bundle.min.js"></script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}

/* ── Editor line-item rows ─────────────────────────────────────────────── */
.li-row{display:grid;grid-template-columns:1fr 32px;gap:6px;align-items:center}
.li-row.kv{grid-template-columns:1fr 1fr 32px}
.li-input{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 10px;font-size:12.5px;font-family:'Inter',sans-serif;color:#1e293b;background:#f8fafc;transition:border-color .12s}
.li-input:focus{outline:none;border-color:#163274;background:#fff}
.rm-btn{width:30px;height:30px;border-radius:7px;border:none;background:#fee2e2;color:#dc2626;cursor:pointer;font-size:17px;display:flex;align-items:center;justify-content:center;flex-shrink:0;line-height:1}
.rm-btn:hover{background:#fecaca}
.fld-lbl{font-size:10px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.03em}
.fld{margin-top:4px;width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px;background:#f8fafc}
.fld:focus{outline:none;border-color:#163274;background:#fff;box-shadow:0 0 0 2px rgba(22,50,116,.18)}
.sec-hd{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px}

/* ═══ THE LETTER ═══════════════════════════════════════════════════════════
   Sizes are in mm/pt so screen, print and PDF agree.
   The printable column is 297 - 16 - 14 = 267mm tall. min-height is set 2mm
   under that: at exactly 267mm html2pdf's height/pageHeight division can round
   to a hair over 1 and emit a blank trailing page. The 2mm is invisible. */
.loi-content{
  width:100%;box-sizing:border-box;display:flex;flex-direction:column;min-height:265mm;
  font-family:'Noto Sans',sans-serif;color:#1f2937;font-size:10.5pt;line-height:1.6;
}
.loi-content *{box-sizing:border-box}

/* Preview-only wrapper: the paper itself. */
.loi-sheet{position:relative;width:210mm;min-height:297mm;padding:16mm 18mm 14mm;background:#fff;box-sizing:border-box;transform-origin:top left}

/* Preview-only page-break guides. Never captured — makeCapture() and
   printLetter() clone .loi-content, which does not contain these. */
.pg-guides{position:absolute;inset:0;pointer-events:none}
.pg-line{position:absolute;left:0;right:0;border-top:1px dashed #b9c4d4}
.pg-lbl{position:absolute;right:5mm;transform:translateY(-115%);font-family:'Inter',sans-serif;
  font-size:7pt;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#94a3b8;background:#fff;padding:0 2mm}

/* Letterhead */
.lh{display:flex;align-items:flex-start;justify-content:space-between;gap:10mm}
.lh-logo{width:19mm;height:19mm;object-fit:contain;display:block;margin-bottom:2mm}
.lh-name{font-family:'Manrope',sans-serif;font-size:15pt;font-weight:800;color:#163274;letter-spacing:-.01em;line-height:1.15}
.lh-tag{font-size:7pt;color:#64748b;letter-spacing:.16em;text-transform:uppercase;margin-top:1.6mm;font-weight:700}
.lh-addr{font-size:8pt;color:#64748b;text-align:right;line-height:1.55;padding-top:1mm;max-width:66mm}
.lh-rule{border-top:2.2pt solid #163274;margin-top:4.5mm}
.lh-rule2{border-top:.7pt solid #c7d2e4;margin-top:1.1mm}

/* Ref / date */
.meta-row{display:flex;justify-content:space-between;align-items:baseline;font-size:9.5pt;margin-top:6mm;color:#334155}
.meta-row b{color:#163274;font-weight:700}

/* Recipient */
.to-block{margin-top:6mm;font-size:10pt;line-height:1.55}
.to-block .k{font-weight:700;color:#163274;margin-bottom:1mm}
.to-name{font-weight:700;color:#1f2937}
.to-addr{color:#475569;white-space:pre-line}

/* Subject */
.subj{margin-top:6mm;background:#eef2f9;border-left:3pt solid #163274;padding:2.8mm 4mm;font-size:10pt;font-weight:700;color:#163274;line-height:1.45}

/* Body copy */
.para{margin-top:4mm;text-align:justify;line-height:1.68;hyphens:none}
.salut{margin-top:5mm;font-weight:600}

/* Terms table */
.terms-wrap{margin-top:5.5mm}
.terms-t{font-size:8pt;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#163274;padding-bottom:1.8mm;border-bottom:1.4pt solid #dbe3ef;margin-bottom:2.4mm}
.terms{width:100%;border-collapse:collapse;font-size:9.5pt;table-layout:fixed}
.terms td{border:.7pt solid #e2e8f0;padding:1.8mm 3mm;vertical-align:top;word-wrap:break-word}
.terms td.k{width:40%;background:#f6f8fc;font-weight:700;color:#475569}
.terms td.v{font-weight:600;color:#1f2937}
.terms tr.hi td{background:#eef6ff;border-color:#cfe0f5}
.terms tr.hi td.k{color:#163274}
.terms tr.hi td.v{color:#163274;font-weight:800}
.terms .words{display:block;font-weight:600;font-size:8pt;color:#64748b;margin-top:.8mm;font-style:italic}

/* Conditions */
.cond-t{margin-top:5.5mm;font-size:8pt;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#163274}
.cond{margin:2.2mm 0 0;padding-left:5.5mm}
.cond li{margin-top:1.7mm;text-align:justify;line-height:1.6}
.cond li::marker{color:#163274}

/* Signature */
.sig-block{margin-top:7mm;display:flex;justify-content:space-between;align-items:flex-end;gap:10mm}
.sig-place{font-size:9pt;color:#475569;line-height:1.7}
.sig-right{text-align:left;min-width:62mm}
.sig-for{font-size:9.5pt;font-weight:700;color:#334155}
.sig-cursive{font-family:'Dancing Script',cursive;font-size:22pt;font-weight:700;color:#1e3a5f;line-height:1.15;margin-top:2.5mm;min-height:11mm}
.sig-rule{border-top:.9pt solid #94a3b8;width:58mm;margin-top:.5mm;padding-top:1.4mm}
.sig-nm{font-size:9.5pt;font-weight:700;color:#1f2937}
.sig-ti{font-size:8pt;color:#64748b;margin-top:.4mm}

/* Acceptance */
.acc{margin-top:6mm;border:.9pt dashed #b6c2d6;border-radius:2mm;padding:4mm 5mm;background:#fbfcfe}
.acc-t{font-size:8pt;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#163274;margin-bottom:2mm}
.acc-p{font-size:9.5pt;line-height:1.7;text-align:justify;color:#334155}
.acc-row{display:flex;gap:10mm;margin-top:6mm;font-size:9pt;color:#64748b}
.acc-row>div{flex:1}
.acc-line{border-top:.8pt solid #94a3b8;padding-top:1.4mm}

/* Footer — margin-top:auto pins it to the bottom of page 1 in all three
   outputs, because .loi-content carries min-height:267mm everywhere. */
.loi-foot{margin-top:auto;padding-top:6mm}
.loi-foot .fr{border-top:.7pt solid #e2e8f0;margin-bottom:2.4mm}
.loi-foot .ft{font-size:7.2pt;color:#94a3b8;text-align:center;line-height:1.6}

/* Preview chrome */
#sheet-viewport{overflow:hidden}
.sheet-shadow{box-shadow:0 8px 30px -8px rgba(15,23,42,.22);border:1px solid #e6eaf0}

/* Fallback for Ctrl+P on the page itself. The Print button uses printLetter()
   (isolated iframe), which is the reliable path — see slip_maker.php. */
@media print{body *{visibility:hidden}.loi-sheet,.loi-sheet *{visibility:visible}
  #sheet-viewport{overflow:visible!important;height:auto!important}
  .loi-sheet{position:absolute;left:0;top:0;transform:none!important;box-shadow:none;border:none}}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php $activePage='letters'; require __DIR__.'/../layout/sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Page Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">assignment_ind</span>
        Letter of Intent Maker
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Employment LOI — create, preview and download as a print-accurate A4 PDF</p>
    </div>
    <div class="flex items-center gap-3">
      <button onclick="resetForm()" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">restart_alt</span> Reset
      </button>
      <button onclick="printLetter()" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">print</span> Print
      </button>
      <button id="dlBtn" onclick="downloadPDF()" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm disabled:opacity-60">
        <span class="material-symbols-outlined text-base">download</span> <span id="dlLabel">Download PDF</span>
      </button>
    </div>
  </div>

  <div class="grid grid-cols-1 xl:grid-cols-[400px_1fr] gap-6 items-start">

    <!-- ═══ EDITOR ═══ -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

      <!-- Company -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">business</span> Company (Letterhead)</p>
        <div class="grid grid-cols-1 gap-2.5">
          <div><label class="fld-lbl">Company Name</label>
            <input id="c_name" type="text" value="Trio Tours And Travels Pvt. Ltd." oninput="render()" class="fld"/></div>
          <div><label class="fld-lbl">Tagline</label>
            <input id="c_tag" type="text" value="Tours · Travels · Holidays" oninput="render()" class="fld"/></div>
          <div><label class="fld-lbl">Registered Office Address</label>
            <textarea id="c_addr" rows="2" oninput="render()" class="fld resize-none">Mumbai, Maharashtra, India</textarea></div>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="fld-lbl">CIN</label><input id="c_cin" type="text" placeholder="Optional" oninput="render()" class="fld"/></div>
            <div><label class="fld-lbl">GSTIN</label><input id="c_gst" type="text" placeholder="Optional" oninput="render()" class="fld"/></div>
            <div><label class="fld-lbl">Phone</label><input id="c_phone" type="text" placeholder="+91 ..." oninput="render()" class="fld"/></div>
            <div><label class="fld-lbl">Email</label><input id="c_email" type="text" placeholder="hr@triotours.com" oninput="render()" class="fld"/></div>
          </div>
          <div><label class="fld-lbl">Website</label><input id="c_web" type="text" placeholder="www.triotours.com" oninput="render()" class="fld"/></div>
        </div>
        <p class="mt-2.5 text-[10px] text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-2.5 py-2 leading-relaxed">
          <span class="font-bold">Set these once.</span> Address, CIN, GSTIN and contacts are carried over from the slip maker's defaults — replace them with Trio's registered details before issuing a letter.
        </p>
      </div>

      <!-- Letter meta -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">tag</span> Letter Reference</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div class="col-span-2"><label class="fld-lbl">Reference No.</label>
            <input id="l_ref" type="text" value="TTT/HR/LOI/<?= date('Y') ?>/001" oninput="render()" class="fld"/></div>
          <div><label class="fld-lbl">Letter Date</label>
            <input id="l_date" type="date" value="<?= date('Y-m-d') ?>" oninput="render()" class="fld"/></div>
          <div><label class="fld-lbl">Valid Until</label>
            <input id="l_valid" type="date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" oninput="render()" class="fld"/></div>
        </div>
      </div>

      <!-- Candidate -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">person</span> Candidate</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="fld-lbl">Salutation</label>
            <select id="k_sal" onchange="render()" class="fld">
              <option>Mr.</option><option>Ms.</option><option>Mrs.</option><option>Mx.</option><option value="">— none —</option>
            </select></div>
          <div><label class="fld-lbl">Full Name</label>
            <input id="k_name" type="text" value="Rahul Sharma" oninput="render()" class="fld"/></div>
          <div class="col-span-2"><label class="fld-lbl">Address</label>
            <textarea id="k_addr" rows="2" placeholder="Street, City, State — PIN" oninput="render()" class="fld resize-none"></textarea></div>
          <div><label class="fld-lbl">Email</label><input id="k_email" type="text" placeholder="Optional" oninput="render()" class="fld"/></div>
          <div><label class="fld-lbl">Phone</label><input id="k_phone" type="text" placeholder="Optional" oninput="render()" class="fld"/></div>
        </div>
      </div>

      <!-- Position & terms -->
      <div class="px-5 py-4 border-b border-slate-100 bg-blue-50/40">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">work</span> Proposed Position &amp; Terms</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="fld-lbl">Designation</label><input id="t_desig" type="text" value="Travel Consultant" oninput="render()" class="fld bg-white"/></div>
          <div><label class="fld-lbl">Department</label><input id="t_dept" type="text" value="Operations" oninput="render()" class="fld bg-white"/></div>
          <div><label class="fld-lbl">Place of Posting</label><input id="t_loc" type="text" value="Mumbai" oninput="render()" class="fld bg-white"/></div>
          <div><label class="fld-lbl">Reporting To</label><input id="t_report" type="text" placeholder="e.g. Operations Manager" oninput="render()" class="fld bg-white"/></div>
          <div><label class="fld-lbl">Employment Type</label>
            <select id="t_type" onchange="render()" class="fld bg-white">
              <option>Full-time, permanent</option><option>Full-time, contractual</option><option>Part-time</option><option>Internship</option>
            </select></div>
          <div><label class="fld-lbl">Proposed Joining Date</label><input id="t_doj" type="date" oninput="render()" class="fld bg-white"/></div>
          <div><label class="fld-lbl">Probation Period</label><input id="t_prob" type="text" value="Six (6) months" oninput="render()" class="fld bg-white"/></div>
          <div><label class="fld-lbl">Notice Period</label><input id="t_notice" type="text" value="Thirty (30) days" oninput="render()" class="fld bg-white"/></div>
          <div class="col-span-2"><label class="fld-lbl">Working Hours / Shift</label><input id="t_hours" type="text" value="9 hours per day, as per the roster assigned" oninput="render()" class="fld bg-white"/></div>
          <div class="col-span-2"><label class="fld-lbl">Annual CTC (₹)</label>
            <input id="t_ctc" type="number" min="0" value="420000" oninput="render()" class="fld bg-white"/>
            <p id="ctc_words" class="mt-1.5 text-[10px] text-primary font-semibold"></p></div>
        </div>
      </div>

      <!-- Additional terms -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">list_alt</span> Additional Terms</p>
        <div id="extra-list" class="flex flex-col gap-1.5"></div>
        <button onclick="addExtra()" class="mt-2 w-full inline-flex items-center justify-center gap-1 py-2 border border-dashed border-primary/40 text-primary text-xs font-bold rounded-lg hover:bg-primary/5 transition-all">
          <span class="material-symbols-outlined text-sm">add</span> Add Term
        </button>
        <p class="mt-2 text-[10px] text-slate-400">Left = label, right = value. Appended to the terms table.</p>
      </div>

      <!-- Conditions -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">rule</span> Conditions Precedent</p>
        <div id="cond-list" class="flex flex-col gap-1.5"></div>
        <button onclick="addCond()" class="mt-2 w-full inline-flex items-center justify-center gap-1 py-2 border border-dashed border-primary/40 text-primary text-xs font-bold rounded-lg hover:bg-primary/5 transition-all">
          <span class="material-symbols-outlined text-sm">add</span> Add Condition
        </button>
      </div>

      <!-- Wording -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">edit_note</span> Wording</p>
        <div class="flex flex-col gap-2.5">
          <div><label class="fld-lbl">Opening Paragraph</label>
            <textarea id="w_open" rows="4" oninput="render()" class="fld resize-none text-[12px] leading-relaxed"></textarea></div>
          <div><label class="fld-lbl">Non-Binding &amp; Validity Clause</label>
            <textarea id="w_bind" rows="5" oninput="render()" class="fld resize-none text-[12px] leading-relaxed"></textarea>
            <p class="mt-1 text-[10px] text-slate-400">Use <code class="text-primary font-bold">{validity}</code> to insert the Valid Until date.</p></div>
          <div><label class="fld-lbl">Closing Paragraph</label>
            <textarea id="w_close" rows="3" oninput="render()" class="fld resize-none text-[12px] leading-relaxed"></textarea></div>
        </div>
      </div>

      <!-- Signatory -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">verified</span> Authorization</p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="fld-lbl">Signatory Name</label><input id="s_name" type="text" value="Paramjeet Singh" oninput="render()" class="fld"/></div>
          <div><label class="fld-lbl">Signatory Title</label><input id="s_title" type="text" value="Authorized Signatory" oninput="render()" class="fld"/></div>
          <div class="col-span-2"><label class="fld-lbl">Place</label><input id="s_place" type="text" value="Mumbai" oninput="render()" class="fld"/></div>
        </div>
      </div>

      <!-- Options -->
      <div class="px-5 py-4">
        <p class="sec-hd"><span class="material-symbols-outlined text-sm text-primary">tune</span> Options</p>
        <div class="flex flex-col gap-2.5 text-[12.5px] text-slate-600">
          <label class="flex items-center gap-2.5 cursor-pointer"><input id="o_accept" type="checkbox" checked onchange="render()" class="rounded border-slate-300 text-primary focus:ring-primary/30"/> Include candidate acceptance block</label>
          <label class="flex items-center gap-2.5 cursor-pointer"><input id="o_conf" type="checkbox" checked onchange="render()" class="rounded border-slate-300 text-primary focus:ring-primary/30"/> Include confidentiality line</label>
          <label class="flex items-center gap-2.5 cursor-pointer"><input id="o_foot" type="checkbox" checked onchange="render()" class="rounded border-slate-300 text-primary focus:ring-primary/30"/> Include letterhead footer strip</label>
          <label class="flex items-center gap-2.5 cursor-pointer"><input id="o_words" type="checkbox" checked onchange="render()" class="rounded border-slate-300 text-primary focus:ring-primary/30"/> Show CTC in words</label>
        </div>
      </div>

    </div>

    <!-- ═══ PREVIEW ═══
         The card is pinned but capped to the viewport and scrolls internally —
         a 2-page letter is taller than the screen, so a plain sticky card would
         leave its bottom half unreachable. -->
    <div class="sticky top-6">
      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5 flex flex-col" style="max-height:calc(100vh - 3rem)">
        <div class="flex items-center justify-between mb-4 flex-shrink-0">
          <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm text-primary">visibility</span> Live Preview
          </p>
          <span class="text-[10px] font-semibold text-slate-400">
            <span id="pg-count" class="text-primary font-bold"></span> · A4 · exactly what prints
          </span>
        </div>
        <div class="flex-1 overflow-y-auto -mx-1 px-1">
          <div id="sheet-viewport">
            <div id="loi-sheet" class="loi-sheet sheet-shadow"></div>
          </div>
        </div>
      </div>
    </div>

  </div>
</main>

<script>
const LOGO_B64 = <?= json_encode($logoB64) ?>;

/* Page geometry — single source of truth for preview, print and PDF. */
const PAGE = { top:16, right:18, bottom:14, left:18 };   // mm
const CONTENT_MM = 210 - PAGE.left - PAGE.right;         // 174mm

const DEFAULT_WORDING = {
  open:  "Further to the discussions and interviews held with you, we are pleased to convey our intent to engage you with {company} in the capacity set out below. This letter records the principal terms on which we propose to make you a formal offer of employment.",
  bind:  "This Letter of Intent is an expression of the Company's present intent only. It does not constitute an offer or a contract of employment, nor does it create any binding obligation on either party. Employment shall commence only upon the issuance of a formal appointment letter and your acceptance of the same.\nThis letter is valid up to {validity} and shall stand automatically withdrawn if your acceptance is not received on or before that date.",
  close: "Kindly confirm your acceptance by signing and returning a copy of this letter. We look forward to welcoming you to the team.",
};

const DEFAULT_CONDS = [
  "Satisfactory verification of the documents, credentials and references furnished by you.",
  "Successful completion of the background and employment-history check.",
  "Submission of the relieving letter and experience certificate from your present employer.",
  "Confirmation that you are not bound by any non-compete, non-solicitation or similar obligation that conflicts with this engagement.",
  "Submission of a certificate of medical fitness, if called for by the Company.",
];

const DEFAULT_EXTRAS = [];

let extras = JSON.parse(JSON.stringify(DEFAULT_EXTRAS));
let conds  = JSON.parse(JSON.stringify(DEFAULT_CONDS));

/* ── helpers ─────────────────────────────────────────────────────────────── */
const v   = id => document.getElementById(id)?.value?.trim() ?? '';
const chk = id => !!document.getElementById(id)?.checked;
const esc = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const fmtINR = n => '₹ ' + Number(n||0).toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0}) + '/-';

function fmtDate(iso){
  if(!iso) return '';
  const [y,m,d] = iso.split('-').map(Number);
  if(!y||!m||!d) return '';
  const MON = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  return `${String(d).padStart(2,'0')} ${MON[m-1]} ${y}`;
}

/* Indian numbering system — crore / lakh / thousand. */
function numToWordsIN(num){
  num = Math.floor(Math.abs(Number(num)||0));
  if(!num) return '';
  const ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
  const tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
  const two   = n => n < 20 ? ones[n] : tens[Math.floor(n/10)] + (n%10 ? ' ' + ones[n%10] : '');
  const three = n => { const h = Math.floor(n/100), r = n%100;
                       return (h ? ones[h] + ' Hundred' + (r ? ' and ' : '') : '') + (r ? two(r) : ''); };
  const out = [];
  const cr = Math.floor(num/10000000); num %= 10000000;
  const lk = Math.floor(num/100000);   num %= 100000;
  const th = Math.floor(num/1000);     num %= 1000;
  if(cr) out.push(three(cr) + ' Crore');
  if(lk) out.push(three(lk) + ' Lakh');
  if(th) out.push(three(th) + ' Thousand');
  if(num) out.push(three(num));
  return out.join(' ').replace(/\s+/g,' ').trim();
}

/* ── editor lists ────────────────────────────────────────────────────────── */
function renderExtras(){
  const el = document.getElementById('extra-list'); el.innerHTML = '';
  extras.forEach((r,i) => {
    const d = document.createElement('div'); d.className = 'li-row kv';
    d.innerHTML =
      `<input class="li-input" type="text" placeholder="Label" value="${esc(r.k)}" oninput="extras[${i}].k=this.value;render()"/>`
    + `<input class="li-input" type="text" placeholder="Value" value="${esc(r.v)}" oninput="extras[${i}].v=this.value;render()"/>`
    + `<button class="rm-btn" onclick="removeExtra(${i})">×</button>`;
    el.appendChild(d);
  });
}
function renderConds(){
  const el = document.getElementById('cond-list'); el.innerHTML = '';
  conds.forEach((c,i) => {
    const d = document.createElement('div'); d.className = 'li-row';
    d.innerHTML =
      `<input class="li-input" type="text" placeholder="Condition" value="${esc(c)}" oninput="conds[${i}]=this.value;render()"/>`
    + `<button class="rm-btn" onclick="removeCond(${i})">×</button>`;
    el.appendChild(d);
  });
}
function addExtra(){ extras.push({k:'',v:''}); renderAll(); }
function removeExtra(i){ extras.splice(i,1); renderAll(); }
function addCond(){ conds.push(''); renderAll(); }
function removeCond(i){ conds.splice(i,1); renderAll(); }
function renderAll(){ renderExtras(); renderConds(); render(); }

/* Free text → justified paragraphs (blank line or newline separates). */
function paras(txt){
  return (txt||'').split(/\n+/).map(s=>s.trim()).filter(Boolean)
    .map(p => `<p class="para">${esc(p)}</p>`).join('');
}

/* ── the letter ──────────────────────────────────────────────────────────── */
function render(){
  const cName = v('c_name') || 'Company Name', cTag = v('c_tag'), cAddr = v('c_addr');
  const cCin = v('c_cin'), cGst = v('c_gst'), cPhone = v('c_phone'), cEmail = v('c_email'), cWeb = v('c_web');

  const lRef = v('l_ref'), lDate = fmtDate(v('l_date')), lValid = fmtDate(v('l_valid'));

  const kSal = v('k_sal'), kName = v('k_name') || 'Candidate Name', kAddr = v('k_addr');
  const kEmail = v('k_email'), kPhone = v('k_phone');
  const kFull = (kSal ? kSal + ' ' : '') + kName;

  const tDesig = v('t_desig'), tDept = v('t_dept'), tLoc = v('t_loc'), tReport = v('t_report');
  const tType = v('t_type'), tDoj = fmtDate(v('t_doj')), tProb = v('t_prob'), tNotice = v('t_notice');
  const tHours = v('t_hours'), tCtc = parseFloat(v('t_ctc')) || 0;

  const sName = v('s_name') || 'Authorized Signatory', sTitle = v('s_title'), sPlace = v('s_place');

  const ctcWords = numToWordsIN(tCtc);
  const wordsEl = document.getElementById('ctc_words');
  if(wordsEl) wordsEl.textContent = ctcWords ? `Rupees ${ctcWords} Only` : '';

  /* Token substitution shared by every editable paragraph. */
  const sub = s => (s||'').replace(/\{validity\}/g, lValid || '—')
                          .replace(/\{company\}/g, cName)
                          .replace(/\{candidate\}/g, kFull)
                          .replace(/\{designation\}/g, tDesig || '—');

  /* Letterhead ------------------------------------------------------------ */
  const addrLines = cAddr.split(/\n+/).map(s=>s.trim()).filter(Boolean).map(esc).join('<br/>');
  const logoHtml  = LOGO_B64 ? `<img class="lh-logo" src="${LOGO_B64}" alt=""/>` : '';

  const rightBits = [];
  if(addrLines) rightBits.push(addrLines);
  const contact = [cPhone, cEmail].filter(Boolean).map(esc).join(' · ');
  if(contact) rightBits.push(contact);
  if(cWeb) rightBits.push(esc(cWeb));

  /* Terms table ----------------------------------------------------------- */
  const rows = [
    ['Designation',            tDesig],
    ['Department',             tDept],
    ['Place of Posting',       tLoc],
    ['Reporting To',           tReport],
    ['Nature of Employment',   tType],
    ['Proposed Date of Joining', tDoj],
    ['Probation Period',       tProb],
    ['Notice Period',          tNotice],
    ['Working Hours',          tHours],
  ].filter(([,val]) => val);

  extras.filter(r => r.k || r.v).forEach(r => rows.push([r.k, r.v]));

  let termsRows = rows.map(([k,val]) =>
    `<tr><td class="k">${esc(k)}</td><td class="v">${esc(val)}</td></tr>`).join('');

  if(tCtc > 0){
    const words = (chk('o_words') && ctcWords)
      ? `<span class="words">(Rupees ${esc(ctcWords)} Only)</span>` : '';
    termsRows += `<tr class="hi"><td class="k">Annual Cost to Company (CTC)</td><td class="v">${fmtINR(tCtc)}${words}</td></tr>`;
  }

  /* Conditions ------------------------------------------------------------ */
  const condItems = conds.map(c => c.trim()).filter(Boolean)
    .map(c => `<li>${esc(c)}</li>`).join('');

  /* Footer ---------------------------------------------------------------- */
  const footBits = [];
  if(cAddr) footBits.push(cAddr.split(/\n+/).map(s=>s.trim()).filter(Boolean).join(', '));
  const ids = [cCin ? 'CIN: ' + cCin : '', cGst ? 'GSTIN: ' + cGst : ''].filter(Boolean).join('  ·  ');
  const foot = chk('o_foot') && (footBits.length || ids || contact || cWeb)
    ? `<div class="loi-foot"><div class="fr"></div><div class="ft">`
      + `${esc(cName)}${footBits.length ? '  ·  ' + esc(footBits.join(', ')) : ''}`
      + `${contact ? '<br/>' + contact : ''}${cWeb ? (contact ? '  ·  ' : '<br/>') + esc(cWeb) : ''}`
      + `${ids ? '<br/>' + esc(ids) : ''}</div></div>`
    : '';

  /* Acceptance ------------------------------------------------------------ */
  const acceptance = chk('o_accept') ? `
    <div class="acc blk">
      <div class="acc-t">Acceptance by the Candidate</div>
      <div class="acc-p">I, ${esc(kFull)}, have read and understood the terms set out in this Letter of Intent and confirm my acceptance of the same. I confirm my intent to join ${esc(cName)} as ${esc(tDesig || 'per the above')}${tDoj ? ' on ' + esc(tDoj) : ''}.</div>
      <div class="acc-row">
        <div><div class="acc-line">Signature</div></div>
        <div><div class="acc-line">Name</div></div>
        <div><div class="acc-line">Date</div></div>
      </div>
    </div>` : '';

  const confLine = chk('o_conf')
    ? `<p class="para">You are requested to treat the contents of this letter as strictly confidential.</p>` : '';

  /* Assemble -------------------------------------------------------------- */
  document.getElementById('loi-sheet').innerHTML = `<div class="loi-content">

  <div class="blk">
    <div class="lh">
      <div>
        ${logoHtml}
        <div class="lh-name">${esc(cName)}</div>
        ${cTag ? `<div class="lh-tag">${esc(cTag)}</div>` : ''}
      </div>
      <div class="lh-addr">${rightBits.join('<br/>')}</div>
    </div>
    <div class="lh-rule"></div>
    <div class="lh-rule2"></div>
  </div>

  <div class="meta-row blk">
    <div>${lRef ? `<b>Ref:</b> ${esc(lRef)}` : ''}</div>
    <div>${lDate ? `<b>Date:</b> ${esc(lDate)}` : ''}</div>
  </div>

  <div class="to-block blk">
    <div class="k">To,</div>
    <div class="to-name">${esc(kFull)}</div>
    ${kAddr ? `<div class="to-addr">${esc(kAddr)}</div>` : ''}
    ${(kEmail || kPhone) ? `<div class="to-addr">${[kEmail, kPhone].filter(Boolean).map(esc).join('  ·  ')}</div>` : ''}
  </div>

  <div class="subj blk">Subject: Letter of Intent for the position of ${esc(tDesig || '—')}</div>

  <p class="salut">Dear ${esc(kFull)},</p>

  ${paras(sub(v('w_open')))}

  <!-- No .blk here: the table is ~120mm and would otherwise be pushed whole to
       the next page, leaving a large gap. It splits between rows instead. -->
  <div class="terms-wrap">
    <div class="terms-t">Proposed Terms of Engagement</div>
    <table class="terms"><tbody>${termsRows || '<tr><td class="k">—</td><td class="v">—</td></tr>'}</tbody></table>
  </div>

  ${condItems ? `<div><div class="cond-t">Conditions Precedent</div>
    <ol class="cond">${condItems}</ol></div>` : ''}

  ${paras(sub(v('w_bind')))}
  ${paras(sub(v('w_close')))}
  ${confLine}

  <div class="sig-block blk">
    <div class="sig-place">
      ${sPlace ? `Place: <b>${esc(sPlace)}</b><br/>` : ''}
      ${lDate ? `Date: <b>${esc(lDate)}</b>` : ''}
    </div>
    <div class="sig-right">
      <div class="sig-for">For ${esc(cName)}</div>
      <div class="sig-cursive">${esc(sName)}</div>
      <div class="sig-rule"></div>
      <div class="sig-nm">${esc(sName)}</div>
      ${sTitle ? `<div class="sig-ti">${esc(sTitle)}</div>` : ''}
    </div>
  </div>

  ${acceptance}
  ${foot}
</div><div class="pg-guides"></div>`;

  snapToPages();
  fitPreview();
}

/* px-per-mm measured from the browser rather than assumed at 96dpi, so zoom
   and non-standard DPI don't skew the page arithmetic. */
let PX_PER_MM = 0;
function pxPerMm(){
  if(PX_PER_MM) return PX_PER_MM;
  const probe = document.createElement('div');
  probe.style.cssText = 'position:absolute;left:-9999px;top:0;width:100mm;height:0';
  document.body.appendChild(probe);
  PX_PER_MM = probe.offsetWidth / 100;
  probe.remove();
  return PX_PER_MM || (96 / 25.4);
}

/* Grow the letter to a whole number of printable pages, so the footer closes
   the LAST page instead of floating mid-page, and mark the breaks in the
   preview. Measuring with min-height cleared gives the true content height;
   re-applying it only grows the box, so there is no feedback loop. */
function snapToPages(){
  const content = document.querySelector('#loi-sheet .loi-content');
  const guides  = document.querySelector('#loi-sheet .pg-guides');
  if(!content) return 1;
  const PAGE_MM = 297 - PAGE.top - PAGE.bottom;      // 267mm of printable height
  content.style.minHeight = '0';
  const naturalMM = content.offsetHeight / pxPerMm();
  const pages = Math.max(1, Math.ceil((naturalMM - 0.2) / PAGE_MM));
  content.style.minHeight = (pages * PAGE_MM - 2) + 'mm';   // -2mm: blank-page guard
  if(guides){
    let h = '';
    for(let k = 1; k < pages; k++){
      const y = PAGE.top + k * PAGE_MM;               // sheet-relative, past the top padding
      h += `<div class="pg-line" style="top:${y}mm"></div>`
        +  `<div class="pg-lbl" style="top:${y}mm">Page ${k} ends</div>`;
    }
    guides.innerHTML = h;
  }
  const badge = document.getElementById('pg-count');
  if(badge) badge.textContent = pages + (pages === 1 ? ' page' : ' pages');
  return pages;
}

/* ── preview scaling — the A4 sheet always fits the column, so what you see
      is the printed page, not a reflowed approximation. ─────────────────── */
function fitPreview(){
  const vp = document.getElementById('sheet-viewport');
  const sheet = document.getElementById('loi-sheet');
  if(!vp || !sheet) return;
  sheet.style.transform = 'none';
  const natural = sheet.offsetWidth;
  const avail   = vp.clientWidth;
  if(!natural || !avail) return;
  const s = Math.min(1, avail / natural);
  sheet.style.transform = `scale(${s})`;
  vp.style.height = (sheet.offsetHeight * s) + 'px';
}
window.addEventListener('resize', fitPreview);

/* ── export plumbing ─────────────────────────────────────────────────────── */

/* html2canvas paints whatever glyphs are loaded at capture time. The slip
   maker waits for fonts before printing but NOT before the PDF, so the
   cursive signature can land as a fallback serif. Both paths wait here. */
function waitForFonts(){
  try{
    if(document.fonts && document.fonts.ready){
      return Promise.race([
        document.fonts.ready,
        new Promise(r => setTimeout(r, 2500)),
      ]);
    }
  }catch(e){ /* fall through */ }
  return new Promise(r => setTimeout(r, 500));
}

/* Off-screen clone at natural size. The live preview is CSS-scaled, and
   html2canvas cannot see through a transform — cloning is what makes the PDF
   identical regardless of window width. */
function makeCapture(){
  document.getElementById('__loiCapture')?.remove();
  const holder = document.createElement('div');
  holder.id = '__loiCapture';
  holder.style.cssText =
    `position:fixed;left:-10000px;top:0;width:${CONTENT_MM}mm;background:#fff;padding:0;margin:0`;
  const clone = document.querySelector('#loi-sheet .loi-content').cloneNode(true);
  clone.style.width = '100%';
  clone.style.boxShadow = 'none';
  holder.appendChild(clone);
  document.body.appendChild(holder);
  return { holder, clone };
}

function fileName(){
  const who = (v('k_name') || 'Candidate').replace(/[^\w]+/g,'_').replace(/^_|_$/g,'');
  const d   = v('l_date') || '';
  return `LOI_${who}${d ? '_' + d : ''}.pdf`;
}

async function downloadPDF(){
  const btn = document.getElementById('dlBtn'), lbl = document.getElementById('dlLabel');
  btn.disabled = true; lbl.textContent = 'Preparing…';
  let cap = null;
  try{
    await waitForFonts();
    cap = makeCapture();
    await html2pdf().set({
      margin:      [PAGE.top, PAGE.right, PAGE.bottom, PAGE.left],   // mm, applied to EVERY page
      filename:    fileName(),
      image:       { type:'jpeg', quality:0.98 },
      html2canvas: { scale:3, useCORS:true, backgroundColor:'#ffffff', logging:false },
      jsPDF:       { unit:'mm', format:'a4', orientation:'portrait', compress:true },
      // Keep small units whole; let the table and the conditions list split
      // between rows/items rather than jumping to the next page as a lump.
      pagebreak:   { mode:['css','legacy'], avoid:['.blk','tr','li','.acc','.sig-block'] },
    }).from(cap.clone).save();
  }catch(e){
    console.error(e);
    alert('Could not generate the PDF. Use Print → Save as PDF as a fallback.');
  }finally{
    cap?.holder.remove();
    btn.disabled = false; lbl.textContent = 'Download PDF';
  }
}

/* Print the letter only, in an isolated iframe. @page carries the margins so
   page 2 onwards is indented exactly like page 1. */
function printLetter(){
  const content = document.querySelector('#loi-sheet .loi-content');
  if(!content){ alert('Nothing to print yet.'); return; }
  const styles    = [...document.querySelectorAll('style')].map(s => s.outerHTML).join('');
  const fontLinks = [...document.querySelectorAll('link[rel="stylesheet"]')].map(l => l.outerHTML).join('');
  document.getElementById('__printFrame')?.remove();

  const f = document.createElement('iframe');
  f.id = '__printFrame';
  // Off-screen rather than display:none, so fonts and layout resolve first.
  f.style.cssText = 'position:fixed;left:-9999px;top:0;width:210mm;height:297mm;border:0';
  document.body.appendChild(f);

  const doc = f.contentWindow.document;
  doc.open();
  doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8">${fontLinks}${styles}`
    + `<style>@page{size:A4;margin:${PAGE.top}mm ${PAGE.right}mm ${PAGE.bottom}mm ${PAGE.left}mm}`
    + `html,body{margin:0;padding:0;background:#fff}`
    + `.loi-content{width:100%;box-shadow:none;border:none}`
    + `.blk,tr,li,.acc,.sig-block{page-break-inside:avoid;break-inside:avoid}`
    + `.terms-t,.cond-t,.salut{page-break-after:avoid;break-after:avoid}`
    + `</style></head><body>${content.outerHTML}</body></html>`);
  doc.close();

  const go = () => {
    try{ f.contentWindow.focus(); f.contentWindow.print(); }catch(e){ console.error(e); }
    setTimeout(() => f.remove(), 1000);
  };
  let done = false;
  const fire = () => { if(done) return; done = true; go(); };
  try{
    doc.fonts && doc.fonts.ready ? doc.fonts.ready.then(() => setTimeout(fire, 150)) : setTimeout(fire, 500);
  }catch(e){ setTimeout(fire, 500); }
  setTimeout(fire, 1800); // hard fallback if fonts.ready never resolves
}

function resetForm(){
  if(!confirm('Reset all fields to defaults?')) return;
  extras = JSON.parse(JSON.stringify(DEFAULT_EXTRAS));
  conds  = JSON.parse(JSON.stringify(DEFAULT_CONDS));
  const set = (id, val) => { const el = document.getElementById(id); if(el) el.value = val; };
  set('c_name','Trio Tours And Travels Pvt. Ltd.'); set('c_tag','Tours · Travels · Holidays');
  set('c_addr','Mumbai, Maharashtra, India');
  ['c_cin','c_gst','c_phone','c_email','c_web','k_addr','k_email','k_phone','t_report','t_doj'].forEach(id => set(id,''));
  set('l_ref','TTT/HR/LOI/<?= date('Y') ?>/001');
  set('l_date','<?= date('Y-m-d') ?>'); set('l_valid','<?= date('Y-m-d', strtotime('+7 days')) ?>');
  set('k_sal','Mr.'); set('k_name','Rahul Sharma');
  set('t_desig','Travel Consultant'); set('t_dept','Operations'); set('t_loc','Mumbai');
  set('t_type','Full-time, permanent'); set('t_prob','Six (6) months'); set('t_notice','Thirty (30) days');
  set('t_hours','9 hours per day, as per the roster assigned'); set('t_ctc','420000');
  set('s_name','Paramjeet Singh'); set('s_title','Authorized Signatory'); set('s_place','Mumbai');
  set('w_open', DEFAULT_WORDING.open); set('w_bind', DEFAULT_WORDING.bind); set('w_close', DEFAULT_WORDING.close);
  ['o_accept','o_conf','o_foot','o_words'].forEach(id => { const el = document.getElementById(id); if(el) el.checked = true; });
  renderAll();
}

/* boot */
document.getElementById('w_open').value  = DEFAULT_WORDING.open;
document.getElementById('w_bind').value  = DEFAULT_WORDING.bind;
document.getElementById('w_close').value = DEFAULT_WORDING.close;
renderAll();
// Re-fit once webfonts land, so the scaled preview matches the final metrics.
waitForFonts().then(fitPreview);
</script>
</body>
</html>
