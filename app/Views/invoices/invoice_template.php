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

/* Itinerary */
.inv-itin { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.inv-itin th {
    background: #f8fafc; font-size: 9px; text-transform: uppercase; letter-spacing: .6px;
    color: #64748b; font-weight: 800; text-align: left; padding: 7px 12px;
}
.inv-itin td { padding: 9px 12px; border-top: 1px solid #f1f5f9; font-size: 12px; color: #475569; }
.inv-itin td.route { font-weight: 800; color: #0f1e3c; }

/* Charges */
.inv-fare { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
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
            <table class="inv-itin">
                <thead><tr><th>Route</th><th>Flight</th><th>Date</th><th>Departs</th><th>Arrives</th></tr></thead>
                <tbody id="inv_itin_rows"></tbody>
            </table>
        </div>

        <!-- Charges -->
        <div class="inv-section">
            <div class="inv-section-title">Charges</div>
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
    const esc = s => (s == null ? '' : String(s));
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
})();
</script>
