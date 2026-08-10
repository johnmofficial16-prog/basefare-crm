<?php
/**
 * Invoice — shared printable document (receipt-style, navy/gold brand).
 *
 * Pure markup + a window.renderInvoice(data) function. Used by:
 *   - maker.php : live preview (re-render on every form input)
 *   - view.php  : saved invoice (render once from embedded data) + PDF capture
 *
 * Visual language mirrors app/Views/acceptance/receipt.php.
 */
?>
<style>
#invoice-printable {
    font-family: 'Inter', Arial, sans-serif;
    width: 760px;
    background: #fff;
    color: #1e293b;
    box-sizing: border-box;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    border-radius: 12px;
    overflow: hidden;
    flex-shrink: 0;
}
#invoice-printable * { box-sizing: border-box; }

.inv-header {
    background: linear-gradient(135deg, #0f1e3c 0%, #1a3a6b 100%);
    padding: 26px 32px;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.inv-brand-name { color: #fff; font-size: 19px; font-weight: 800; letter-spacing: .5px; font-family: 'Manrope', sans-serif; }
.inv-brand-dba  { color: #c9a84c; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; margin-top: 2px; }
.inv-meta { text-align: right; }
.inv-meta-lbl { color: #93c5fd; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
.inv-meta-no  { color: #fff; font-size: 22px; font-weight: 900; font-family: 'Manrope', sans-serif; letter-spacing: 1px; }
.inv-meta-date { color: #cbd5e1; font-size: 11px; margin-top: 2px; }

.inv-purpose-strip {
    background: #eef4ff; border-bottom: 1px solid #dbe7ff;
    padding: 10px 32px; display: flex; align-items: center; gap: 10px;
}
.inv-purpose-lbl { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #64748b; }
.inv-purpose-val { font-size: 13px; font-weight: 800; color: #1a3a6b; }

.inv-body { padding: 26px 32px; }
.inv-section { margin-bottom: 22px; }
.inv-section:last-child { margin-bottom: 0; }
.inv-section-title {
    font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px;
    color: #94a3b8; margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #f1f5f9;
}
.inv-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
.inv-cell { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; }
.inv-cell.hide { display: none; }
.inv-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #94a3b8; margin-bottom: 4px; }
.inv-value { font-size: 13px; font-weight: 600; color: #1e293b; word-break: break-word; }
.inv-value.mono { font-family: 'Courier New', monospace; letter-spacing: 1px; font-weight: 700; color: #0f1e3c; }

/* Tables sit inside a wrapper that carries the border + radius.
   border-radius is ignored on an element with border-collapse:collapse, so the
   rounded corners declared directly on these tables never actually rendered —
   they read as square boxes next to the rounded .inv-cell blocks. */
.inv-table-wrap { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }

/* Itinerary */
.inv-itin { width: 100%; border-collapse: collapse; }
.inv-itin th {
    background: #f8fafc; font-size: 9px; text-transform: uppercase; letter-spacing: .6px;
    color: #64748b; font-weight: 800; text-align: left; padding: 7px 12px;
}
.inv-itin td { padding: 9px 12px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #475569; }
.inv-itin td.route { font-weight: 800; color: #0f1e3c; }

/* Charges */
.inv-fare { width: 100%; border-collapse: collapse; }
.inv-fare td { padding: 10px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #475569; }
.inv-fare td:last-child { text-align: right; font-family: 'Courier New', monospace; font-weight: 700; color: #1e293b; }
.inv-fare tr.row-hide { display: none; }
.inv-fare-total td { background: #0f1e3c; color: #fff; font-weight: 800; border: none; font-size: 15px; }
.inv-fare-total td:last-child { color: #4ade80; font-size: 18px; }

.inv-card-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; }
.inv-card-name { font-size: 13px; font-weight: 700; color: #1e293b; }
.inv-card-billing { font-size: 12px; color: #64748b; margin-top: 4px; white-space: pre-wrap; }

.inv-footer {
    background: #f8fafc; border-top: 1px solid #e2e8f0; padding: 16px 32px;
    display: flex; align-items: center; justify-content: space-between; font-size: 10px; color: #94a3b8;
}
.inv-footer .brand { font-weight: 700; color: #0f1e3c; }

/* ─────────────────────────────────────────────────────────────────────────
   PRINT
   The document is produced as a real print (vector text, selectable and
   sharp at any zoom) rather than a rasterised screenshot.
   ───────────────────────────────────────────────────────────────────────── */
@media print {
    /* margin:0 is deliberate. Chrome draws its page header/footer — document
       title, source URL, date, page number — into the @page margin, and there is
       no CSS switch to disable it. With no margin there is nowhere to draw them,
       so the URL disappears. The white space is restored as padding on the
       document itself instead. */
    @page { size: A4 portrait; margin: 0; }

    html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; }
    .no-print { display: none !important; }

    /* printInvoice() lifts the document into #__invPrintHost at the top level and
       sets .inv-printing, so everything else is removed from the page entirely.
       display:none rather than visibility:hidden — hidden elements still occupy
       layout and produce blank leading pages. */
    html.inv-printing body > *:not(#__invPrintHost) { display: none !important; }
    html.inv-printing #__invPrintHost { display: block !important; }

    #invoice-printable {
        /* On paper this is the document, not a card floating on a page — the
           shadow and rounded corners are screen affordances only. */
        width: 100% !important;
        max-width: 100% !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 14mm 12mm !important;
    }

    /* Browsers drop background colours when printing (including "Save as PDF"),
       which would render the navy header and the dark total row white — with
       their white text on top, i.e. invisible. Force them on so the output
       doesn't depend on the user ticking "Background graphics". */
    #invoice-printable,
    #invoice-printable * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    /* Keep logical blocks whole across a page boundary. */
    .inv-section,
    .inv-card-box,
    .inv-footer      { page-break-inside: avoid; break-inside: avoid; }
    .inv-itin tr,
    .inv-fare tr     { page-break-inside: avoid; break-inside: avoid; }
    .inv-section-title { page-break-after: avoid; break-after: avoid; }

    /* Repeat the itinerary header when a long trip spills onto a second page. */
    .inv-itin thead { display: table-header-group; }
    .inv-fare tfoot { display: table-row-group; }

    /* The muted greys read fine backlit on a screen but wash out on paper —
       the footer contact details in particular were near-illegible at 10px. */
    .inv-footer        { color: #475569 !important; font-size: 10.5px; }
    .inv-section-title { color: #64748b !important; }
    .inv-label         { color: #64748b !important; }
    .inv-itin td       { color: #334155 !important; }
    .inv-fare td       { color: #334155 !important; }
    .inv-card-billing  { color: #475569 !important; }
}
</style>

<div id="invoice-printable">
    <div class="inv-header">
        <div>
            <div class="inv-brand-name">BASE FARE</div>
            <div class="inv-brand-dba">Lets Fly Travel LLC DBA Base Fare</div>
        </div>
        <div class="inv-meta">
            <div class="inv-meta-lbl">Invoice No.</div>
            <div class="inv-meta-no" id="inv_no">INV-XXXXXX</div>
            <div class="inv-meta-date" id="inv_date">—</div>
        </div>
    </div>

    <div class="inv-purpose-strip">
        <span class="inv-purpose-lbl">Purpose of Charge</span>
        <span class="inv-purpose-val" id="inv_purpose">New Booking</span>
    </div>

    <div class="inv-body">
        <!-- Customer -->
        <div class="inv-section">
            <div class="inv-section-title">Customer</div>
            <div class="inv-grid">
                <div class="inv-cell"><div class="inv-label">Passenger Name</div><div class="inv-value" id="inv_pax">—</div></div>
                <div class="inv-cell" id="cell_phone"><div class="inv-label">Phone</div><div class="inv-value" id="inv_phone">—</div></div>
                <div class="inv-cell" id="cell_email"><div class="inv-label">Email</div><div class="inv-value" id="inv_email" style="font-size:12px;">—</div></div>
                <div class="inv-cell" id="cell_pnr"><div class="inv-label">PNR</div><div class="inv-value mono" id="inv_pnr">—</div></div>
                <div class="inv-cell" id="cell_airline"><div class="inv-label">Airline</div><div class="inv-value" id="inv_airline">—</div></div>
            </div>
        </div>

        <!-- Itinerary -->
        <div class="inv-section" id="sec_itinerary">
            <div class="inv-section-title">Itinerary</div>
            <div class="inv-table-wrap">
                <table class="inv-itin">
                    <thead><tr><th>Route</th><th>Flight</th><th>Date</th><th>Departs</th><th>Arrives</th></tr></thead>
                    <tbody id="inv_itin_rows"></tbody>
                </table>
            </div>
        </div>

        <!-- Charges -->
        <div class="inv-section">
            <div class="inv-section-title">Charges</div>
            <div class="inv-table-wrap">
                <table class="inv-fare">
                    <tbody>
                        <tr id="row_airline"><td>Airline Charge</td><td id="inv_airline_charge">—</td></tr>
                        <tr><td>Base Fare Service Charge</td><td id="inv_base_charge">—</td></tr>
                    </tbody>
                    <tfoot>
                        <tr class="inv-fare-total"><td>Total Amount</td><td id="inv_total">—</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Payment card (holder + billing only) -->
        <div class="inv-section" id="sec_card">
            <div class="inv-section-title">Payment</div>
            <div class="inv-card-box">
                <div class="inv-card-name" id="inv_cch">—</div>
                <div class="inv-card-billing" id="inv_billing"></div>
            </div>
        </div>
    </div>

    <div class="inv-footer">
        <div><span class="brand">Lets Fly Travel LLC DBA Base Fare</span><br>reservation@base-fare.com &middot; Toll-Free 888 608 4011</div>
        <div style="text-align:right;">Invoice: <strong id="inv_no_foot">INV-XXXXXX</strong></div>
    </div>
</div>

<script>
(function () {
    const esc = s => (s == null ? '' : String(s)).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const showCell = (id, on) => { const el = document.getElementById(id); if (el) el.classList.toggle('hide', !on); };

    function fmtDate(d) {
        if (!d) return '—';
        const dt = new Date(d + 'T00:00:00');
        if (isNaN(dt)) return d;
        return dt.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    }
    function money(cur, v) {
        const n = parseFloat(v);
        return (cur || 'USD') + ' ' + (isNaN(n) ? '0.00' : n.toFixed(2));
    }

    window.renderInvoice = function (d) {
        d = d || {};
        const cur = d.currency || 'USD';

        set('inv_no', d.invoice_no || 'INV-XXXXXX');
        set('inv_no_foot', d.invoice_no || 'INV-XXXXXX');
        set('inv_date', fmtDate(d.issue_date));
        set('inv_purpose', d.purpose_label || 'New Booking');

        set('inv_pax', d.customer_name || '—');
        set('inv_phone', d.customer_phone || '—');
        set('inv_email', d.customer_email || '—');
        set('inv_pnr', d.pnr || '—');
        set('inv_airline', d.airline || '—');
        showCell('cell_phone', !!d.customer_phone);
        showCell('cell_email', !!d.customer_email);
        showCell('cell_pnr', !!d.pnr);
        showCell('cell_airline', !!d.airline);

        // Itinerary
        const tbody = document.getElementById('inv_itin_rows');
        const segs = Array.isArray(d.itinerary) ? d.itinerary.filter(s => s && (s.from || s.to || s.flight)) : [];
        const secItin = document.getElementById('sec_itinerary');
        if (tbody) {
            tbody.innerHTML = '';
            segs.forEach(s => {
                const tr = document.createElement('tr');
                let flight = esc(s.flight || '');
                if (s.cabin) flight += (flight ? ' · ' : '') + esc(s.cabin);
                tr.innerHTML =
                    '<td class="route">' + esc((s.from || '').toUpperCase()) + ' → ' + esc((s.to || '').toUpperCase()) + '</td>' +
                    '<td>' + (flight || '—') + '</td>' +
                    '<td>' + (esc(s.date) || '—') + '</td>' +
                    '<td>' + (esc(s.dep) || '—') + '</td>' +
                    '<td>' + (esc(s.arr) || '—') + '</td>';
                tbody.appendChild(tr);
            });
        }
        if (secItin) secItin.style.display = segs.length ? '' : 'none';

        // Charges
        const hasAirline = d.airline_charge !== '' && d.airline_charge !== null && d.airline_charge !== undefined && !isNaN(parseFloat(d.airline_charge));
        const rowAirline = document.getElementById('row_airline');
        if (rowAirline) rowAirline.classList.toggle('row-hide', !hasAirline);
        set('inv_airline_charge', money(cur, d.airline_charge || 0));
        set('inv_base_charge', money(cur, d.base_fare_charge || 0));
        const total = (hasAirline ? parseFloat(d.airline_charge) : 0) + (parseFloat(d.base_fare_charge) || 0);
        set('inv_total', money(cur, total));

        // Payment card
        const hasCard = !!(d.cardholder_name || d.card_billing);
        const secCard = document.getElementById('sec_card');
        if (secCard) secCard.style.display = hasCard ? '' : 'none';
        set('inv_cch', d.cardholder_name || '—');
        const bill = document.getElementById('inv_billing');
        if (bill) bill.textContent = d.card_billing || '';
    };

    /**
     * PRIMARY OUTPUT — print the invoice as a real document.
     *
     * Renders into an isolated off-screen iframe rather than printing the page,
     * so none of the app chrome (sidebar, toolbars, form) can leak in. Same
     * approach as payroll/slip_maker.php, including the webfont wait: printing
     * before fonts resolve produces fallback glyphs, and a hard timeout covers
     * the case where fonts.ready never settles.
     *
     * Output is vector — sharp at any zoom, selectable and searchable — unlike
     * the html2canvas path below, which can only ever produce a bitmap.
     */
    /**
     * Print by isolating the document inside THIS page, rather than cloning it
     * into an iframe.
     *
     * An iframe built with document.write never fetches the Google Fonts
     * stylesheet — measured in-browser, Inter and Manrope render at exactly the
     * fallback metrics there and no face ever reaches 'loaded'. The PDF then
     * embeds Arial instead of the brand faces. (FontFaceSet.check() is no help:
     * with no @font-face registered it reports "available" and returns true.)
     *
     * The page we are already on has both families loaded and rendering, so we
     * print it directly: lift #invoice-printable into a top-level host, flag the
     * root, and let the print stylesheet drop everything else. A placeholder
     * marks the original position so the DOM is restored exactly afterwards.
     */
    window.printInvoice = function () {
        const el = document.getElementById('invoice-printable');
        if (!el) { alert('Nothing to print yet.'); return; }
        if (document.getElementById('__invPrintHost')) return;   // already printing

        const placeholder = document.createComment('invoice-print-placeholder');
        el.parentNode.insertBefore(placeholder, el);

        const host = document.createElement('div');
        host.id = '__invPrintHost';
        host.appendChild(el);
        document.body.appendChild(host);
        document.documentElement.classList.add('inv-printing');

        let restored = false;
        const restore = () => {
            if (restored) return;
            restored = true;
            document.documentElement.classList.remove('inv-printing');
            if (placeholder.parentNode) {
                placeholder.parentNode.insertBefore(el, placeholder);
                placeholder.remove();
            }
            host.remove();
        };

        window.addEventListener('afterprint', restore, { once: true });

        // Give the browser a frame to apply the print layout, then print.
        // afterprint is well supported, but a timed fallback guarantees the page
        // is never left in its stripped-down state if it doesn't fire.
        setTimeout(() => {
            try { window.print(); }
            catch (e) { console.error('[invoice] print failed', e); }
            setTimeout(restore, 500);
        }, 60);
        setTimeout(restore, 60000);
    };

    /**
     * SECONDARY — one-click bitmap PDF via html2canvas.
     *
     * Kept for users who want a file without the print dialog, but it is
     * inherently a photograph of the document: text is not selectable and
     * sharpness is capped by the capture resolution. printInvoice() is better
     * wherever the print dialog is acceptable.
     *
     * Tuned since the original: PNG instead of JPEG (JPEG rings badly around
     * white text on the navy header), scale 3 rather than 2 (~300 DPI at A4
     * instead of ~199), and the screen-only shadow and rounded corners are
     * stripped during capture so they aren't baked into the page.
     */
    window.captureInvoicePdf = async function (filename) {
        const el = document.getElementById('invoice-printable');
        if (!el || typeof html2pdf === 'undefined') return;

        const prevScroll = window.scrollY;
        const prev = {
            width: el.style.width, maxWidth: el.style.maxWidth,
            shadow: el.style.boxShadow, radius: el.style.borderRadius
        };

        el.style.width        = '760px';
        el.style.maxWidth     = 'none';
        el.style.boxShadow    = 'none';
        el.style.borderRadius = '0';
        window.scrollTo(0, 0);
        // Let layout settle (web fonts / reflow) before snapshotting.
        await new Promise(r => setTimeout(r, 120));

        try {
            await html2pdf().set({
                margin:   10,
                filename: filename,
                image:    { type: 'png' },
                html2canvas: {
                    scale: 3, useCORS: true, logging: false, backgroundColor: '#ffffff',
                    scrollX: 0, scrollY: 0,
                    windowWidth: 820, width: 760,
                    windowHeight: el.scrollHeight + 40
                },
                jsPDF:     { unit: 'mm', format: 'a4', orientation: 'portrait', compress: true },
                // 'avoid-all' fought against sensible breaks on long itineraries;
                // css + legacy honours the page-break rules in the stylesheet.
                pagebreak: { mode: ['css', 'legacy'] }
            }).from(el).save();
        } finally {
            el.style.width        = prev.width;
            el.style.maxWidth     = prev.maxWidth;
            el.style.boxShadow    = prev.shadow;
            el.style.borderRadius = prev.radius;
            window.scrollTo(0, prevScroll);
        }
    };
})();
</script>
