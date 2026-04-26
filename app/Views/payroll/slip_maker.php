<?php
$activePage = 'payroll';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Salary Slip Maker — Base Fare CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#f1f5f9;color:#1e293b}
.page-shell{display:flex;min-height:100vh}
.main-content{flex:1;margin-left:240px;padding:32px 28px;display:flex;flex-direction:column;gap:24px}
/* Top bar */
.top-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.top-bar h1{font-size:22px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px}
.top-bar h1 span{font-size:22px;color:#4f46e5}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s ease;text-decoration:none}
.btn-primary{background:#4f46e5;color:#fff}
.btn-primary:hover{background:#4338ca;box-shadow:0 4px 16px rgba(79,70,229,.35)}
.btn-ghost{background:#fff;color:#475569;border:1.5px solid #e2e8f0}
.btn-ghost:hover{border-color:#4f46e5;color:#4f46e5}
.btn-danger{background:#fee2e2;color:#dc2626;border:none}
.btn-danger:hover{background:#fecaca}
/* Layout */
.editor-grid{display:grid;grid-template-columns:420px 1fr;gap:24px;align-items:start}
@media(max-width:1100px){.editor-grid{grid-template-columns:1fr}}
/* Editor panel */
.editor-panel{background:#fff;border-radius:16px;border:1.5px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,.05);overflow:hidden}
.ep-section{padding:20px 22px;border-bottom:1px solid #f1f5f9}
.ep-section:last-child{border-bottom:none}
.ep-section-title{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.ep-section-title span{font-size:14px;color:#4f46e5}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-grid.full{grid-template-columns:1fr}
.field{display:flex;flex-direction:column;gap:4px}
.field label{font-size:11px;font-weight:600;color:#64748b;letter-spacing:.04em}
.field input,.field select,.field textarea{padding:8px 11px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;color:#1e293b;transition:border-color .15s;background:#fafafa;width:100%}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:#4f46e5;background:#fff}
/* Line items */
.line-items{display:flex;flex-direction:column;gap:6px}
.line-item{display:grid;grid-template-columns:1fr 120px 34px;gap:6px;align-items:center}
.line-item input{padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12.5px;color:#1e293b;background:#fafafa;font-family:'Inter',sans-serif}
.line-item input:focus{outline:none;border-color:#4f46e5;background:#fff}
.remove-btn{width:30px;height:30px;border-radius:7px;border:none;background:#fee2e2;color:#dc2626;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background .15s;flex-shrink:0}
.remove-btn:hover{background:#fecaca}
.add-row-btn{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:#4f46e5;background:transparent;border:1.5px dashed #c7d2fe;border-radius:8px;padding:6px 12px;cursor:pointer;transition:all .15s;margin-top:4px;width:100%;justify-content:center}
.add-row-btn:hover{background:#ede9fe;border-color:#4f46e5}
/* Preview panel */
.preview-wrap{position:sticky;top:24px}
.preview-panel{background:#fff;border-radius:16px;border:1.5px solid #e2e8f0;box-shadow:0 2px 12px rgba(0,0,0,.05);padding:24px}
.preview-label{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
/* SLIP */
#slip-preview{font-family:'Noto Sans',sans-serif;background:#fff;max-width:680px;margin:0 auto;border:1px solid #e2e8f0;border-radius:4px;overflow:hidden;font-size:12px;color:#1e293b}
.slip-header{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#fff;padding:22px 28px;display:flex;align-items:center;justify-content:space-between}
.slip-company-name{font-size:18px;font-weight:800;letter-spacing:-.02em;line-height:1.2}
.slip-company-sub{font-size:10px;opacity:.8;margin-top:2px}
.slip-title-block{text-align:right}
.slip-title{font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;opacity:.95}
.slip-period{font-size:10px;opacity:.75;margin-top:3px}
.slip-meta{background:#f8fafc;padding:14px 28px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;border-bottom:1px solid #e2e8f0}
.meta-item{}
.meta-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8}
.meta-value{font-size:12px;font-weight:700;color:#1e293b;margin-top:2px}
.slip-body{padding:18px 28px;display:flex;flex-direction:column;gap:14px}
.slip-table{width:100%;border-collapse:collapse}
.slip-table-title{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#4f46e5;margin-bottom:6px;padding-bottom:4px;border-bottom:2px solid #ede9fe}
.slip-table tr:nth-child(even){background:#f8fafc}
.slip-table td{padding:6px 8px;font-size:12px}
.slip-table td:last-child{text-align:right;font-weight:600}
.slip-table tfoot td{border-top:2px solid #e2e8f0;font-weight:800;font-size:13px;padding:8px 8px}
.slip-table tfoot td.label{color:#475569}
.slip-table tfoot td.amount{color:#4f46e5}
.slip-net{background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;margin-top:2px}
.slip-net-label{font-size:11px;font-weight:700;opacity:.9;text-transform:uppercase;letter-spacing:.05em}
.slip-net-amount{font-size:22px;font-weight:900;letter-spacing:-.03em}
.slip-footer{padding:12px 28px;display:flex;justify-content:space-between;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9}
.sig-block{text-align:center}
.sig-line{width:100px;border-top:1.5px solid #cbd5e1;margin:0 auto 4px}

/* Print */
@media print{
  body *{visibility:hidden}
  #slip-preview,#slip-preview *{visibility:visible}
  #slip-preview{position:fixed;left:0;top:0;width:100%;box-shadow:none;border:none}
}
</style>
</head>
<body>
<div class="page-shell">
<?php require __DIR__ . '/../partials/admin_sidebar.php'; ?>
<div class="main-content">

  <div class="top-bar">
    <h1><span class="material-symbols-outlined">receipt_long</span>Salary Slip Maker</h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-ghost" onclick="resetForm()">
        <span class="material-symbols-outlined" style="font-size:16px">restart_alt</span> Reset
      </button>
      <button class="btn btn-ghost" onclick="window.print()">
        <span class="material-symbols-outlined" style="font-size:16px">print</span> Print
      </button>
      <button class="btn btn-primary" onclick="downloadPDF()">
        <span class="material-symbols-outlined" style="font-size:16px">download</span> Download PDF
      </button>
    </div>
  </div>

  <div class="editor-grid">
    <!-- ═══════ EDITOR PANEL ═══════ -->
    <div class="editor-panel">

      <!-- Company -->
      <div class="ep-section">
        <div class="ep-section-title"><span class="material-symbols-outlined">business</span>Company Details</div>
        <div class="form-grid full" style="gap:10px">
          <div class="field">
            <label>Company Name</label>
            <input id="c_name" type="text" value="Base Fare Travels Pvt. Ltd." oninput="render()"/>
          </div>
          <div class="field">
            <label>Company Address</label>
            <input id="c_addr" type="text" value="Mumbai, Maharashtra, India" oninput="render()"/>
          </div>
          <div class="form-grid" style="gap:10px">
            <div class="field">
              <label>CIN / GST No.</label>
              <input id="c_cin" type="text" placeholder="Optional" oninput="render()"/>
            </div>
            <div class="field">
              <label>Contact Email</label>
              <input id="c_email" type="text" placeholder="hr@company.com" oninput="render()"/>
            </div>
          </div>
        </div>
      </div>

      <!-- Employee -->
      <div class="ep-section">
        <div class="ep-section-title"><span class="material-symbols-outlined">person</span>Employee Details</div>
        <div class="form-grid" style="gap:10px">
          <div class="field">
            <label>Full Name</label>
            <input id="e_name" type="text" value="Rahul Sharma" oninput="render()"/>
          </div>
          <div class="field">
            <label>Employee ID</label>
            <input id="e_id" type="text" value="EMP-001" oninput="render()"/>
          </div>
          <div class="field">
            <label>Designation</label>
            <input id="e_desig" type="text" value="Travel Agent" oninput="render()"/>
          </div>
          <div class="field">
            <label>Department</label>
            <input id="e_dept" type="text" value="Operations" oninput="render()"/>
          </div>
          <div class="field">
            <label>Date of Joining</label>
            <input id="e_doj" type="text" placeholder="01 Jan 2024" oninput="render()"/>
          </div>
          <div class="field">
            <label>PAN Number</label>
            <input id="e_pan" type="text" placeholder="ABCDE1234F" oninput="render()"/>
          </div>
          <div class="field">
            <label>Bank Name</label>
            <input id="e_bank" type="text" placeholder="HDFC Bank" oninput="render()"/>
          </div>
          <div class="field">
            <label>Account No. (last 4)</label>
            <input id="e_acc" type="text" placeholder="XXXX4321" oninput="render()"/>
          </div>
        </div>
      </div>

      <!-- Pay Period -->
      <div class="ep-section">
        <div class="ep-section-title"><span class="material-symbols-outlined">calendar_month</span>Pay Period</div>
        <div class="form-grid" style="gap:10px">
          <div class="field">
            <label>Pay Month</label>
            <select id="p_month" onchange="render()">
              <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December'];
              $cm = (int)date('n');
              foreach($months as $i=>$m): ?>
              <option value="<?= $m ?>" <?= ($i+1)===$cm?'selected':'' ?>><?= $m ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Year</label>
            <input id="p_year" type="number" value="<?= date('Y') ?>" min="2020" max="2099" oninput="render()"/>
          </div>
          <div class="field">
            <label>Working Days</label>
            <input id="p_wdays" type="number" value="26" min="1" max="31" oninput="render()"/>
          </div>
          <div class="field">
            <label>Days Present</label>
            <input id="p_present" type="number" value="26" min="0" max="31" oninput="render()"/>
          </div>
        </div>
      </div>

      <!-- Earnings -->
      <div class="ep-section">
        <div class="ep-section-title"><span class="material-symbols-outlined">payments</span>Earnings</div>
        <div class="line-items" id="earnings-list"></div>
        <button class="add-row-btn" onclick="addEarning()">
          <span class="material-symbols-outlined" style="font-size:15px">add</span> Add Earning
        </button>
      </div>

      <!-- Deductions -->
      <div class="ep-section">
        <div class="ep-section-title"><span class="material-symbols-outlined">remove_circle</span>Deductions</div>
        <div class="line-items" id="deductions-list"></div>
        <button class="add-row-btn" onclick="addDeduction()">
          <span class="material-symbols-outlined" style="font-size:15px">add</span> Add Deduction
        </button>
      </div>

      <!-- Notes -->
      <div class="ep-section">
        <div class="ep-section-title"><span class="material-symbols-outlined">sticky_note_2</span>Notes</div>
        <div class="field">
          <textarea id="slip_notes" rows="2" placeholder="e.g. Salary for April 2025. Includes performance bonus." oninput="render()" style="resize:vertical"></textarea>
        </div>
      </div>

    </div><!-- /editor-panel -->

    <!-- ═══════ PREVIEW PANEL ═══════ -->
    <div class="preview-wrap">
      <div class="preview-panel">
        <div class="preview-label">
          <span>📄 Live Preview</span>
          <span style="font-size:11px;color:#cbd5e1;font-weight:500">Updates as you type</span>
        </div>
        <div id="slip-preview"></div>
      </div>
    </div>

  </div><!-- /editor-grid -->
</div><!-- /main-content -->
</div><!-- /page-shell -->

<script>
// ─── State ────────────────────────────────────────────────────
let earnings = [
  {label:'Basic Salary', amount:25000},
  {label:'House Rent Allowance (HRA)', amount:5000},
  {label:'Travel Allowance', amount:1600},
  {label:'Medical Allowance', amount:1250},
];
let deductions = [
  {label:'Provident Fund (PF)', amount:1800},
  {label:'Professional Tax', amount:200},
];

// ─── Formatters ───────────────────────────────────────────────
const fmt = n => '₹ ' + Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
const v = id => document.getElementById(id)?.value?.trim() ?? '';

// ─── Render line items editor ─────────────────────────────────
function renderList(containerId, arr, removeFunc) {
  const el = document.getElementById(containerId);
  el.innerHTML = '';
  arr.forEach((row, i) => {
    const div = document.createElement('div');
    div.className = 'line-item';
    div.innerHTML = `
      <input type="text" placeholder="Description" value="${escHtml(row.label)}" oninput="arr[${i}].label=this.value;render()"/>
      <input type="number" placeholder="Amount" value="${row.amount||''}" min="0" oninput="arr[${i}].amount=parseFloat(this.value)||0;render()"/>
      <button class="remove-btn" onclick="${removeFunc}(${i})" title="Remove">×</button>`;
    el.appendChild(div);
  });
}

function escHtml(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ─── Add / Remove ─────────────────────────────────────────────
function addEarning(){ earnings.push({label:'',amount:0}); renderAll(); }
function removeEarning(i){ earnings.splice(i,1); renderAll(); }
function addDeduction(){ deductions.push({label:'',amount:0}); renderAll(); }
function removeDeduction(i){ deductions.splice(i,1); renderAll(); }

function renderAll(){ renderList('earnings-list',earnings,'removeEarning'); renderList('deductions-list',deductions,'removeDeduction'); render(); }

// ─── Main slip render ─────────────────────────────────────────
function render() {
  const cName  = v('c_name')  || 'Company Name';
  const cAddr  = v('c_addr')  || '';
  const cCin   = v('c_cin')   || '';
  const cEmail = v('c_email') || '';
  const eName  = v('e_name')  || 'Employee Name';
  const eId    = v('e_id')    || '';
  const eDesig = v('e_desig') || '';
  const eDept  = v('e_dept')  || '';
  const eDoj   = v('e_doj')   || '';
  const ePan   = v('e_pan')   || '';
  const eBank  = v('e_bank')  || '';
  const eAcc   = v('e_acc')   || '';
  const pMonth = v('p_month') || '';
  const pYear  = v('p_year')  || '';
  const pWdays = v('p_wdays') || '26';
  const pPresent = v('p_present') || '26';
  const notes  = v('slip_notes') || '';

  const gross = earnings.reduce((s,r)=>s+(r.amount||0),0);
  const totalDed = deductions.reduce((s,r)=>s+(r.amount||0),0);
  const net = gross - totalDed;

  // Earnings rows
  let eRows = earnings.filter(r=>r.label||r.amount).map(r=>`
    <tr><td>${escHtml(r.label)}</td><td>${fmt(r.amount)}</td></tr>`).join('');
  if(!eRows) eRows='<tr><td colspan="2" style="color:#94a3b8;font-style:italic">No earnings added</td></tr>';

  // Deduction rows
  let dRows = deductions.filter(r=>r.label||r.amount).map(r=>`
    <tr><td>${escHtml(r.label)}</td><td>- ${fmt(r.amount)}</td></tr>`).join('');
  if(!dRows) dRows='<tr><td colspan="2" style="color:#94a3b8;font-style:italic">No deductions added</td></tr>';

  document.getElementById('slip-preview').innerHTML = `
<div id="slip-preview-inner">
  <div class="slip-header">
    <div>
      <div class="slip-company-name">${escHtml(cName)}</div>
      <div class="slip-company-sub">${escHtml(cAddr)}${cCin?' &nbsp;·&nbsp; '+escHtml(cCin):''}</div>
      ${cEmail?`<div class="slip-company-sub" style="margin-top:2px">${escHtml(cEmail)}</div>`:''}
    </div>
    <div class="slip-title-block">
      <div class="slip-title">Salary Slip</div>
      <div class="slip-period">${escHtml(pMonth)} ${escHtml(pYear)}</div>
    </div>
  </div>

  <div class="slip-meta">
    <div class="meta-item"><div class="meta-label">Employee Name</div><div class="meta-value">${escHtml(eName)}</div></div>
    <div class="meta-item"><div class="meta-label">Employee ID</div><div class="meta-value">${escHtml(eId)||'—'}</div></div>
    <div class="meta-item"><div class="meta-label">Designation</div><div class="meta-value">${escHtml(eDesig)||'—'}</div></div>
    <div class="meta-item"><div class="meta-label">Department</div><div class="meta-value">${escHtml(eDept)||'—'}</div></div>
    <div class="meta-item"><div class="meta-label">Date of Joining</div><div class="meta-value">${escHtml(eDoj)||'—'}</div></div>
    <div class="meta-item"><div class="meta-label">PAN</div><div class="meta-value">${escHtml(ePan)||'—'}</div></div>
  </div>

  <div class="slip-meta" style="grid-template-columns:repeat(4,1fr);background:#fff;border-top:1px solid #f1f5f9">
    <div class="meta-item"><div class="meta-label">Working Days</div><div class="meta-value">${escHtml(pWdays)}</div></div>
    <div class="meta-item"><div class="meta-label">Days Present</div><div class="meta-value" style="color:#16a34a">${escHtml(pPresent)}</div></div>
    <div class="meta-item"><div class="meta-label">Days Absent</div><div class="meta-value" style="color:${(parseInt(pWdays)-parseInt(pPresent))>0?'#dc2626':'#94a3b8'}">${Math.max(0,parseInt(pWdays||0)-parseInt(pPresent||0))}</div></div>
    <div class="meta-item"><div class="meta-label">Bank / Account</div><div class="meta-value">${escHtml(eBank||'—')}${eAcc?' / '+escHtml(eAcc):''}</div></div>
  </div>

  <div class="slip-body">
    <div>
      <div class="slip-table-title">Earnings</div>
      <table class="slip-table">
        <tbody>${eRows}</tbody>
        <tfoot>
          <tr>
            <td class="label">Gross Earnings</td>
            <td class="amount">${fmt(gross)}</td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div>
      <div class="slip-table-title" style="color:#dc2626;border-color:#fee2e2">Deductions</div>
      <table class="slip-table">
        <tbody>${dRows}</tbody>
        <tfoot>
          <tr>
            <td class="label">Total Deductions</td>
            <td class="amount" style="color:#dc2626">- ${fmt(totalDed)}</td>
          </tr>
        </tfoot>
      </table>
    </div>
    ${notes?`<div style="font-size:10.5px;color:#64748b;background:#f8fafc;border-radius:6px;padding:10px 12px;border:1px solid #e2e8f0"><strong style="color:#475569">Note:</strong> ${escHtml(notes)}</div>`:''}
  </div>

  <div class="slip-net">
    <div>
      <div class="slip-net-label">Net Pay</div>
      <div style="font-size:10px;opacity:.7;margin-top:1px">${escHtml(pMonth)} ${escHtml(pYear)} · ${escHtml(pPresent)}/${escHtml(pWdays)} days</div>
    </div>
    <div class="slip-net-amount">${fmt(net)}</div>
  </div>

  <div class="slip-footer">
    <div class="sig-block"><div class="sig-line"></div><div>Employee Signature</div></div>
    <div style="text-align:center;font-size:9px;color:#cbd5e1;align-self:center">This is a computer generated salary slip.</div>
    <div class="sig-block"><div class="sig-line"></div><div>Authorized Signatory</div></div>
  </div>
</div>`;
}

// ─── PDF Download ─────────────────────────────────────────────
function downloadPDF() {
  const el = document.getElementById('slip-preview-inner') || document.getElementById('slip-preview');
  const name = (document.getElementById('e_name')?.value || 'Salary').replace(/\s+/g,'_');
  const month = document.getElementById('p_month')?.value || '';
  const year  = document.getElementById('p_year')?.value  || '';
  const filename = `SalarySlip_${name}_${month}_${year}.pdf`;

  const opt = {
    margin: 0,
    filename: filename,
    image: {type:'jpeg',quality:.98},
    html2canvas:{scale:2,useCORS:true,letterRendering:true},
    jsPDF:{unit:'mm',format:'a4',orientation:'portrait'}
  };
  html2pdf().set(opt).from(el).save();
}

// ─── Reset ────────────────────────────────────────────────────
function resetForm() {
  if (!confirm('Reset all fields to default?')) return;
  earnings = [{label:'Basic Salary',amount:25000},{label:'House Rent Allowance (HRA)',amount:5000},{label:'Travel Allowance',amount:1600},{label:'Medical Allowance',amount:1250}];
  deductions = [{label:'Provident Fund (PF)',amount:1800},{label:'Professional Tax',amount:200}];
  ['c_name','c_addr','c_cin','c_email','e_name','e_id','e_desig','e_dept','e_doj','e_pan','e_bank','e_acc','p_year','p_wdays','p_present','slip_notes'].forEach(id=>{
    const el=document.getElementById(id); if(el && el.defaultValue !== undefined) el.value = el.defaultValue;
  });
  renderAll();
}

// ─── Init ─────────────────────────────────────────────────────
renderAll();
</script>
</body>
</html>
