<?php
/**
 * Gmail-style attachment picker for customer emails (compose + reply).
 *
 * Include inside a <form enctype="multipart/form-data">. Renders a paperclip
 * button, a drop zone and removable file chips. Files accumulate across picks
 * in a DataTransfer that is written back to the real <input name="attachments[]">,
 * so the form still submits normally. Limits mirror CustomerEmailAttachments
 * (the server re-checks everything).
 *
 * @var string $pickerId  unique id prefix for this picker on the page
 */
use App\Services\CustomerEmailAttachments;

$pickerId = $pickerId ?? 'att';
?>
<div id="<?= htmlspecialchars($pickerId) ?>" data-attach-picker class="rounded-lg border border-dashed border-slate-300 bg-white px-3 py-2.5 transition-colors">
  <input type="file" name="attachments[]" multiple class="hidden" accept="<?= CustomerEmailAttachments::ACCEPT_ATTR ?>" data-att-input>
  <div class="flex items-center gap-3 flex-wrap">
    <button type="button" data-att-btn
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-xs font-bold text-slate-700 hover:bg-slate-100">
      <span class="material-symbols-outlined text-base -rotate-45">attach_file</span> Attach files
    </button>
    <span class="text-[11px] text-slate-400" data-att-hint>or drag files here · PDF, images, Word, Excel, CSV · up to <?= CustomerEmailAttachments::MAX_FILES ?> files, <?= CustomerEmailAttachments::MAX_TOTAL / 1048576 ?> MB total</span>
  </div>
  <div data-att-list class="flex flex-wrap gap-2 empty:hidden mt-2"></div>
  <p data-att-error class="hidden mt-2 text-xs font-semibold text-rose-600"></p>
</div>
<?php if (empty($GLOBALS['__attachPickerJs'])): $GLOBALS['__attachPickerJs'] = true; ?>
<script>
window.AttachPicker = (function () {
  const MAX_FILES = <?= CustomerEmailAttachments::MAX_FILES ?>;
  const MAX_FILE  = <?= CustomerEmailAttachments::MAX_FILE_BYTES ?>;
  const MAX_TOTAL = <?= CustomerEmailAttachments::MAX_TOTAL ?>;
  const EXTS = <?= json_encode(array_keys(CustomerEmailAttachments::ALLOWED)) ?>;
  const pickers = {};

  const fmt = b => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  const icon = f => /^image\//.test(f.type) ? 'image' : (/pdf$/i.test(f.name) ? 'picture_as_pdf' : 'description');
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function init(root) {
    const input = root.querySelector('[data-att-input]');
    const list  = root.querySelector('[data-att-list]');
    const errEl = root.querySelector('[data-att-error]');
    let files = [];

    function sync() {
      const dt = new DataTransfer();
      files.forEach(f => dt.items.add(f));
      input.files = dt.files;
      list.innerHTML = files.map((f, i) =>
        '<span class="inline-flex items-center gap-1.5 max-w-[260px] pl-2 pr-1 py-1 rounded-lg bg-slate-100 border border-slate-200 text-xs text-slate-700">' +
        '<span class="material-symbols-outlined text-sm text-slate-500">' + icon(f) + '</span>' +
        '<span class="truncate font-semibold" title="' + esc(f.name) + '">' + esc(f.name) + '</span>' +
        '<span class="text-slate-400 flex-none">' + fmt(f.size) + '</span>' +
        '<button type="button" data-rm="' + i + '" class="flex-none w-5 h-5 inline-flex items-center justify-center rounded hover:bg-slate-200 text-slate-500" title="Remove">' +
        '<span class="material-symbols-outlined text-sm">close</span></button></span>'
      ).join('');
      list.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => {
        files.splice(+b.getAttribute('data-rm'), 1); errEl.classList.add('hidden'); sync();
      }));
    }

    function add(incoming) {
      const problems = [];
      Array.from(incoming).forEach(f => {
        const ext = (f.name.split('.').pop() || '').toLowerCase();
        if (!EXTS.includes(ext))            return problems.push('"' + f.name + '" is not an allowed type');
        if (f.size > MAX_FILE)              return problems.push('"' + f.name + '" is over ' + fmt(MAX_FILE));
        if (f.size === 0)                   return problems.push('"' + f.name + '" is empty');
        if (files.length >= MAX_FILES)      return problems.push('max ' + MAX_FILES + ' files');
        if (files.some(x => x.name === f.name && x.size === f.size)) return; // same file picked twice
        if (files.reduce((t, x) => t + x.size, 0) + f.size > MAX_TOTAL) return problems.push('"' + f.name + '" would take the total over ' + fmt(MAX_TOTAL));
        files.push(f);
      });
      errEl.textContent = problems.length ? 'Not attached: ' + problems.join('; ') + '.' : '';
      errEl.classList.toggle('hidden', !problems.length);
      sync();
    }

    root.querySelector('[data-att-btn]').addEventListener('click', () => input.click());
    // input.files is replaced by sync(); read the fresh picks before that happens
    input.addEventListener('change', () => { const picked = Array.from(input.files); input.value = ''; add(picked); });

    ['dragenter', 'dragover'].forEach(ev => root.addEventListener(ev, e => {
      e.preventDefault(); root.classList.add('border-primary', 'bg-primary-50');
    }));
    ['dragleave', 'drop'].forEach(ev => root.addEventListener(ev, e => {
      e.preventDefault(); root.classList.remove('border-primary', 'bg-primary-50');
    }));
    root.addEventListener('drop', e => { if (e.dataTransfer?.files?.length) add(e.dataTransfer.files); });

    return { names: () => files.map(f => f.name), count: () => files.length };
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-attach-picker]').forEach(root => { pickers[root.id] = init(root); });
  });

  return { get: id => pickers[id] };
})();
</script>
<?php endif; ?>
