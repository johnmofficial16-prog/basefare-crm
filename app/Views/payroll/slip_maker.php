<?php
$activePage = 'payroll';
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width,initial-scale=1.0" name="viewport"/>
<title>Salary Slip Maker — Base Fare CRM</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@300;400;500;600&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<script>
tailwind.config={darkMode:"class",theme:{extend:{colors:{primary:"#163274","primary-container":"#314a8d",background:"#f8f9fa","surface-container-low":"#f3f4f5","on-surface":"#191c1d","on-surface-variant":"#434653"},fontFamily:{headline:["Manrope"],body:["Inter"],label:["Inter"]}}}};
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
/* Line item rows */
.li-row{display:grid;grid-template-columns:1fr 130px 32px;gap:6px;align-items:center}
.li-input{width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 10px;font-size:12.5px;font-family:'Inter',sans-serif;color:#1e293b;background:#f8fafc;transition:border-color .12s}
.li-input:focus{outline:none;border-color:#163274;background:#fff}
.rm-btn{width:30px;height:30px;border-radius:7px;border:none;background:#fee2e2;color:#dc2626;cursor:pointer;font-size:17px;display:flex;align-items:center;justify-content:center;flex-shrink:0;line-height:1}
.rm-btn:hover{background:#fecaca}
/* Slip styles */
#slip-inner{font-family:'Noto Sans',sans-serif;color:#1e293b;font-size:12px}
.slip-hdr{background:linear-gradient(135deg,#163274 0%,#314a8d 100%);color:#fff;padding:20px 24px;display:flex;align-items:flex-start;justify-content:space-between}
.slip-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
.slip-meta-grid.four{grid-template-columns:repeat(4,1fr);background:#fff;border-top:none}
.meta-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8}
.meta-val{font-size:12px;font-weight:700;color:#1e293b;margin-top:2px}
.slip-body{padding:16px 24px;display:flex;flex-direction:column;gap:12px}
.slip-tbl{width:100%;border-collapse:collapse}
.slip-tbl-title{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#163274;padding-bottom:4px;border-bottom:2px solid #dbeafe;margin-bottom:4px}
.slip-tbl tr:nth-child(even){background:#f8fafc}
.slip-tbl td{padding:5px 6px;font-size:11.5px}
.slip-tbl td:last-child{text-align:right;font-weight:600}
.slip-tbl tfoot td{border-top:2px solid #e2e8f0;font-weight:800;font-size:12.5px;padding:7px 6px}
.slip-net{background:linear-gradient(135deg,#163274,#314a8d);color:#fff;padding:14px 24px;display:flex;align-items:center;justify-content:space-between}
.slip-footer{padding:10px 24px;display:flex;justify-content:space-between;border-top:1px solid #f1f5f9;font-size:9.5px;color:#94a3b8}
.sig-line{width:90px;border-top:1.5px solid #cbd5e1;margin:0 auto 3px}
@media print{body *{visibility:hidden}#slip-inner,#slip-inner *{visibility:visible}#slip-inner{position:fixed;left:0;top:0;width:100%;box-shadow:none;border:none}}
</style>
</head>
<body class="bg-background font-body text-on-surface antialiased min-h-screen">

<?php $activePage='payroll'; require __DIR__.'/../partials/admin_sidebar.php'; ?>

<main class="ml-60 pt-6 pb-20 px-8">

  <!-- Page Header -->
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-headline font-extrabold text-primary tracking-tight flex items-center gap-2">
        <span class="material-symbols-outlined text-2xl">receipt_long</span>
        Salary Slip Maker
      </h1>
      <p class="text-sm text-on-surface-variant mt-0.5">Create, preview and download salary slips as PDF</p>
    </div>
    <div class="flex items-center gap-3">
      <button onclick="resetForm()" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">restart_alt</span> Reset
      </button>
      <button onclick="window.print()" class="inline-flex items-center gap-1.5 px-4 py-2.5 bg-white border border-slate-200 text-slate-600 font-bold rounded-xl hover:border-primary hover:text-primary transition-all text-sm shadow-sm">
        <span class="material-symbols-outlined text-base">print</span> Print
      </button>
      <button onclick="downloadPDF()" class="inline-flex items-center gap-1.5 px-5 py-2.5 bg-primary text-white font-bold rounded-xl hover:bg-primary-container shadow-lg shadow-primary/20 transition-all text-sm">
        <span class="material-symbols-outlined text-base">download</span> Download PDF
      </button>
    </div>
  </div>

  <!-- Grid -->
  <div class="grid grid-cols-1 xl:grid-cols-[400px_1fr] gap-6 items-start">

    <!-- ═══ EDITOR ═══ -->
    <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">

      <!-- Company -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">business</span> Company Details
        </p>
        <div class="grid grid-cols-1 gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Company Name</label>
            <input id="c_name" type="text" value="Base Fare Travels Pvt. Ltd." oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Address</label>
            <input id="c_addr" type="text" value="Mumbai, Maharashtra, India" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div class="grid grid-cols-2 gap-2">
            <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">CIN / GST No.</label>
              <input id="c_cin" type="text" placeholder="Optional" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
            <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Contact Email</label>
              <input id="c_email" type="text" placeholder="hr@company.com" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          </div>
        </div>
      </div>

      <!-- Employee -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">person</span> Employee Details
        </p>
        <div class="grid grid-cols-2 gap-2.5">
          <?php $fields=[['e_name','Full Name','Rahul Sharma'],['e_id','Employee ID','EMP-001'],['e_desig','Designation','Travel Agent'],['e_dept','Department','Operations'],['e_doj','Date of Joining',''],['e_pan','PAN Number',''],['e_bank','Bank Name',''],['e_acc','Account No. (last 4)','']];
          foreach($fields as [$id,$lbl,$val]): ?>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide"><?= $lbl ?></label>
            <input id="<?= $id ?>" type="text" value="<?= $val ?>" placeholder="<?= $val?:'—' ?>" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Pay Period -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">calendar_month</span> Pay Period
        </p>
        <div class="grid grid-cols-2 gap-2.5">
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Pay Month</label>
            <select id="p_month" onchange="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary">
              <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December']; $cm=(int)date('n');
              foreach($months as $i=>$m): ?><option value="<?= $m ?>" <?= ($i+1)===$cm?'selected':'' ?>><?= $m ?></option><?php endforeach; ?>
            </select></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Year</label>
            <input id="p_year" type="number" value="<?= date('Y') ?>" min="2020" max="2099" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Working Days</label>
            <input id="p_wdays" type="number" value="26" min="1" max="31" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
          <div><label class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Days Present</label>
            <input id="p_present" type="number" value="26" min="0" max="31" oninput="render()" class="mt-1 w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary"/></div>
        </div>
      </div>

      <!-- Earnings -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">payments</span> Earnings
        </p>
        <div id="earnings-list" class="flex flex-col gap-1.5"></div>
        <button onclick="addEarning()" class="mt-2 w-full inline-flex items-center justify-center gap-1 py-2 border border-dashed border-primary/40 text-primary text-xs font-bold rounded-lg hover:bg-primary/5 transition-all">
          <span class="material-symbols-outlined text-sm">add</span> Add Earning
        </button>
      </div>

      <!-- Deductions -->
      <div class="px-5 py-4 border-b border-slate-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-red-500">remove_circle</span> Deductions
        </p>
        <div id="deductions-list" class="flex flex-col gap-1.5"></div>
        <button onclick="addDeduction()" class="mt-2 w-full inline-flex items-center justify-center gap-1 py-2 border border-dashed border-red-300 text-red-500 text-xs font-bold rounded-lg hover:bg-red-50 transition-all">
          <span class="material-symbols-outlined text-sm">add</span> Add Deduction
        </button>
      </div>

      <!-- Notes -->
      <div class="px-5 py-4">
        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 flex items-center gap-1.5">
          <span class="material-symbols-outlined text-sm text-primary">sticky_note_2</span> Notes
        </p>
        <textarea id="slip_notes" rows="2" placeholder="e.g. Salary for April 2026. Includes performance bonus." oninput="render()" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm bg-slate-50 focus:ring-2 focus:ring-primary/30 focus:border-primary resize-none"></textarea>
      </div>

    </div>

    <!-- ═══ PREVIEW ═══ -->
    <div class="sticky top-6">
      <div class="bg-white border border-slate-200 rounded-2xl shadow-sm p-5">
        <div class="flex items-center justify-between mb-4">
          <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 flex items-center gap-1.5">
            <span class="material-symbols-outlined text-sm text-primary">visibility</span> Live Preview
          </p>
          <span class="text-[10px] text-slate-300 font-medium">Updates as you type</span>
        </div>
        <div id="slip-preview" class="border border-slate-100 rounded-lg overflow-hidden shadow-sm"></div>
      </div>
    </div>

  </div>
</main>

<script>
let earnings=[{label:'Basic Salary',amount:25000},{label:'House Rent Allowance (HRA)',amount:5000},{label:'Travel Allowance',amount:1600},{label:'Medical Allowance',amount:1250}];
let deductions=[{label:'Provident Fund (PF)',amount:1800},{label:'Professional Tax',amount:200}];

const fmt=n=>'₹ '+Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
const v=id=>document.getElementById(id)?.value?.trim()??'';
const esc=s=>(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function renderList(cid,arr,rmFn){
  const el=document.getElementById(cid); el.innerHTML='';
  arr.forEach((r,i)=>{
    const d=document.createElement('div'); d.className='li-row';
    d.innerHTML=`<input class="li-input" type="text" placeholder="Description" value="${esc(r.label)}" oninput="${rmFn==='removeEarning'?'earnings':'deductions'}[${i}].label=this.value;render()"/>
      <input class="li-input" type="number" placeholder="Amount" value="${r.amount||''}" min="0" oninput="${rmFn==='removeEarning'?'earnings':'deductions'}[${i}].amount=parseFloat(this.value)||0;render()"/>
      <button class="rm-btn" onclick="${rmFn}(${i})">×</button>`;
    el.appendChild(d);
  });
}

function addEarning(){earnings.push({label:'',amount:0});renderAll();}
function removeEarning(i){earnings.splice(i,1);renderAll();}
function addDeduction(){deductions.push({label:'',amount:0});renderAll();}
function removeDeduction(i){deductions.splice(i,1);renderAll();}
function renderAll(){renderList('earnings-list',earnings,'removeEarning');renderList('deductions-list',deductions,'removeDeduction');render();}

function render(){
  const cName=v('c_name')||'Company Name',cAddr=v('c_addr'),cCin=v('c_cin'),cEmail=v('c_email');
  const eName=v('e_name')||'Employee Name',eId=v('e_id'),eDesig=v('e_desig'),eDept=v('e_dept');
  const eDoj=v('e_doj'),ePan=v('e_pan'),eBank=v('e_bank'),eAcc=v('e_acc');
  const pMonth=v('p_month'),pYear=v('p_year'),pWdays=v('p_wdays')||'26',pPresent=v('p_present')||'26';
  const notes=v('slip_notes');
  const absent=Math.max(0,parseInt(pWdays||0)-parseInt(pPresent||0));

  const gross=earnings.reduce((s,r)=>s+(r.amount||0),0);
  const totalDed=deductions.reduce((s,r)=>s+(r.amount||0),0);
  const net=gross-totalDed;

  let eRows=earnings.filter(r=>r.label||r.amount).map(r=>`<tr><td>${esc(r.label)}</td><td>${fmt(r.amount)}</td></tr>`).join('');
  if(!eRows)eRows='<tr><td colspan="2" style="color:#94a3b8;font-style:italic;padding:6px">No earnings added</td></tr>';

  let dRows=deductions.filter(r=>r.label||r.amount).map(r=>`<tr><td>${esc(r.label)}</td><td style="color:#dc2626">- ${fmt(r.amount)}</td></tr>`).join('');
  if(!dRows)dRows='<tr><td colspan="2" style="color:#94a3b8;font-style:italic;padding:6px">No deductions added</td></tr>';

  document.getElementById('slip-preview').innerHTML=`<div id="slip-inner">
  <div class="slip-hdr">
    <div>
      <div style="font-size:17px;font-weight:800;letter-spacing:-.02em;line-height:1.2">${esc(cName)}</div>
      <div style="font-size:10px;opacity:.8;margin-top:2px">${esc(cAddr)}${cCin?' &nbsp;·&nbsp; '+esc(cCin):''}</div>
      ${cEmail?`<div style="font-size:10px;opacity:.7;margin-top:1px">${esc(cEmail)}</div>`:''}
    </div>
    <div style="text-align:right">
      <div style="font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em">Salary Slip</div>
      <div style="font-size:10px;opacity:.75;margin-top:2px">${esc(pMonth)} ${esc(pYear)}</div>
    </div>
  </div>
  <div class="slip-meta-grid">
    <div><div class="meta-lbl">Employee Name</div><div class="meta-val">${esc(eName)}</div></div>
    <div><div class="meta-lbl">Employee ID</div><div class="meta-val">${esc(eId)||'—'}</div></div>
    <div><div class="meta-lbl">Designation</div><div class="meta-val">${esc(eDesig)||'—'}</div></div>
    <div><div class="meta-lbl">Department</div><div class="meta-val">${esc(eDept)||'—'}</div></div>
    <div><div class="meta-lbl">Date of Joining</div><div class="meta-val">${esc(eDoj)||'—'}</div></div>
    <div><div class="meta-lbl">PAN</div><div class="meta-val">${esc(ePan)||'—'}</div></div>
  </div>
  <div class="slip-meta-grid four">
    <div><div class="meta-lbl">Working Days</div><div class="meta-val">${esc(pWdays)}</div></div>
    <div><div class="meta-lbl">Days Present</div><div class="meta-val" style="color:#16a34a">${esc(pPresent)}</div></div>
    <div><div class="meta-lbl">Days Absent</div><div class="meta-val" style="color:${absent>0?'#dc2626':'#94a3b8'}">${absent}</div></div>
    <div><div class="meta-lbl">Bank / Account</div><div class="meta-val">${esc(eBank)||'—'}${eAcc?' / '+esc(eAcc):''}</div></div>
  </div>
  <div class="slip-body">
    <div>
      <div class="slip-tbl-title">Earnings</div>
      <table class="slip-tbl"><tbody>${eRows}</tbody>
        <tfoot><tr><td style="color:#475569">Gross Earnings</td><td style="color:#163274">${fmt(gross)}</td></tr></tfoot>
      </table>
    </div>
    <div>
      <div class="slip-tbl-title" style="color:#dc2626;border-color:#fee2e2">Deductions</div>
      <table class="slip-tbl"><tbody>${dRows}</tbody>
        <tfoot><tr><td style="color:#475569">Total Deductions</td><td style="color:#dc2626">- ${fmt(totalDed)}</td></tr></tfoot>
      </table>
    </div>
    ${notes?`<div style="font-size:10.5px;color:#64748b;background:#f8fafc;border-radius:6px;padding:10px 12px;border:1px solid #e2e8f0"><strong style="color:#475569">Note:</strong> ${esc(notes)}</div>`:''}
  </div>
  <div class="slip-net">
    <div>
      <div style="font-size:10px;font-weight:700;opacity:.85;text-transform:uppercase;letter-spacing:.06em">Net Pay</div>
      <div style="font-size:10px;opacity:.65;margin-top:1px">${esc(pMonth)} ${esc(pYear)} · ${esc(pPresent)}/${esc(pWdays)} days</div>
    </div>
    <div style="font-size:22px;font-weight:900;letter-spacing:-.03em">${fmt(net)}</div>
  </div>
  <div class="slip-footer">
    <div style="text-align:center"><div class="sig-line"></div>Employee Signature</div>
    <div style="text-align:center;align-self:center;font-size:9px;color:#cbd5e1">This is a computer generated salary slip.</div>
    <div style="text-align:center"><div class="sig-line"></div>Authorized Signatory</div>
  </div>
</div>`;
}

function downloadPDF(){
  const el=document.getElementById('slip-inner');
  const name=(document.getElementById('e_name')?.value||'Salary').replace(/\s+/g,'_');
  const month=document.getElementById('p_month')?.value||'';
  const year=document.getElementById('p_year')?.value||'';
  html2pdf().set({margin:0,filename:`SalarySlip_${name}_${month}_${year}.pdf`,image:{type:'jpeg',quality:.98},html2canvas:{scale:2,useCORS:true},jsPDF:{unit:'mm',format:'a4',orientation:'portrait'}}).from(el).save();
}

function resetForm(){
  if(!confirm('Reset all fields to defaults?'))return;
  earnings=[{label:'Basic Salary',amount:25000},{label:'House Rent Allowance (HRA)',amount:5000},{label:'Travel Allowance',amount:1600},{label:'Medical Allowance',amount:1250}];
  deductions=[{label:'Provident Fund (PF)',amount:1800},{label:'Professional Tax',amount:200}];
  document.getElementById('c_name').value='Base Fare Travels Pvt. Ltd.';
  document.getElementById('c_addr').value='Mumbai, Maharashtra, India';
  ['c_cin','c_email','e_doj','e_pan','e_bank','e_acc','slip_notes'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
  document.getElementById('e_name').value='Rahul Sharma';
  document.getElementById('e_id').value='EMP-001';
  document.getElementById('e_desig').value='Travel Agent';
  document.getElementById('e_dept').value='Operations';
  document.getElementById('p_wdays').value='26';
  document.getElementById('p_present').value='26';
  document.getElementById('p_year').value='<?= date('Y') ?>';
  renderAll();
}

renderAll();
</script>
</body>
</html>
