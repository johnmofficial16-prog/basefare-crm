<?php
/**
 * voucher_template.php — Pixel-perfect match to travel voucher template.jpeg
 * Used by maker.php for both the live preview and PDF export.
 */
?>
<div id="voucher-printable" style="
    font-family: 'Inter', Arial, Helvetica, sans-serif;
    width: 1122px;
    height: 398px;
    background: linear-gradient(160deg, #c5dcef 0%, #d9ecf8 45%, #e8f4fb 100%);
    display: flex;
    overflow: hidden;
    position: relative;
    color: #1e293b;
    box-sizing: border-box;
    flex-shrink: 0;
">

  <!-- Cloud texture overlay -->
  <div style="position:absolute;inset:0;background:url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22><ellipse cx=%2260%22 cy=%2260%22 rx=%2280%22 ry=%2240%22 fill=%22rgba(255,255,255,0.18)%22/><ellipse cx=%22160%22 cy=%2290%22 rx=%2270%22 ry=%2235%22 fill=%22rgba(255,255,255,0.12)%22/><ellipse cx=%2240%22 cy=%22150%22 rx=%2260%22 ry=%2230%22 fill=%22rgba(255,255,255,0.1)%22/></svg>') repeat;z-index:0;pointer-events:none;"></div>

  <!-- ══════════════ MAIN BODY (left ~75%) ══════════════ -->
  <div style="flex:1;display:flex;flex-direction:column;position:relative;z-index:1;border-right:3px dashed #7baed4;">

    <!-- HEADER -->
    <div style="background:linear-gradient(90deg,#163274 0%,#1e4fad 60%,#2760c8 100%);color:#fff;padding:10px 24px;display:flex;align-items:center;justify-content:center;gap:10px;">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="white"><path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/></svg>
      <span style="font-size:19px;font-weight:900;letter-spacing:0.1em;text-transform:uppercase;">FLIGHT TRAVEL CREDIT VOUCHER</span>
    </div>

    <!-- BODY: 3-column grid -->
    <div style="flex:1;display:grid;grid-template-columns:240px 1fr;gap:0;overflow:hidden;">

      <!-- ─── LEFT COL: Issuer + Pax ─── -->
      <div style="padding:10px 12px 8px 16px;display:flex;flex-direction:column;gap:6px;border-right:1px solid #bdd5e8;">

        <!-- Issuer block -->
        <div style="font-size:10.5px;line-height:1.6;color:#334155;">
          <div style="font-size:12px;font-weight:800;color:#163274;">BASE FARE</div>
          <div>reservation@base-fare.com</div>
          <div>Toll-Free: 888-608-4011</div>
        </div>

        <!-- Voucher No + Issue Date (top right of left col) -->
        <div style="display:flex;justify-content:flex-end;flex-direction:column;gap:3px;border-top:1px solid #bdd5e8;padding-top:6px;">
          <div style="display:flex;justify-content:space-between;align-items:baseline;">
            <span style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">VOUCHER NO:</span>
            <span id="p_vno" style="font-size:16px;font-weight:900;color:#163274;text-transform:uppercase;letter-spacing:0.05em;">VCH-XXXXXX</span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:baseline;">
            <span style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">DATE OF ISSUE:</span>
            <span id="p_issue" style="font-size:11px;font-weight:700;color:#1e293b;">01 JAN 2025</span>
          </div>
        </div>

        <!-- Pax Name -->
        <div style="border-top:1px solid #bdd5e8;padding-top:6px;">
          <div id="p_name" style="font-size:22px;font-weight:900;color:#1e293b;text-transform:uppercase;line-height:1.1;">JOHN DOE</div>
        </div>

        <!-- Booking refs -->
        <div style="display:flex;flex-direction:column;gap:3px;">
          <div style="display:flex;gap:6px;align-items:baseline;">
            <span style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#64748b;">BOOKING REF (PNR):</span>
            <span id="p_pnr" style="font-size:11px;font-weight:700;color:#1e293b;">ABC123</span>
          </div>
          <div style="display:flex;gap:6px;align-items:baseline;">
            <span style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#64748b;">ORIGINAL TICKET NO:</span>
            <span id="p_ticket" style="font-size:11px;font-weight:700;color:#1e293b;">1234567890</span>
          </div>
        </div>

        <!-- Signature area -->
        <div style="margin-top:auto;display:flex;flex-direction:column;gap:8px;">
          <div>
            <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;color:#475569;letter-spacing:0.05em;border-top:1px solid #94a3b8;padding-top:4px;">PASSENGER SIGNATURE</div>
          </div>
          <div>
            <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;color:#475569;letter-spacing:0.05em;border-top:1px solid #94a3b8;padding-top:4px;">AUTHORIZED BY (BASE FARE)</div>
          </div>
        </div>

      </div>

      <!-- ─── RIGHT COL: Amount + Terms ─── -->
      <div style="padding:10px 14px 8px 14px;display:flex;flex-direction:column;gap:8px;">

        <!-- Amount + Validity row -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div style="background:rgba(255,255,255,0.75);border:1px solid #aac8e0;border-radius:6px;padding:8px 12px;">
            <div style="font-size:8.5px;font-weight:800;text-transform:uppercase;color:#163274;letter-spacing:0.05em;margin-bottom:3px;">AMOUNT</div>
            <div id="p_amt" style="font-size:24px;font-weight:900;color:#163274;line-height:1;">USD 0.00</div>
          </div>
          <div style="display:flex;flex-direction:column;gap:4px;justify-content:center;padding:0 4px;">
            <div>
              <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">VALID UNTIL:</div>
              <div id="p_expiry" style="font-size:13px;font-weight:800;color:#1e293b;">31 DEC 2025</div>
            </div>
            <div>
              <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.05em;">REASON:</div>
              <div id="p_reason" style="font-size:12px;font-weight:800;color:#163274;">CANCELLATION</div>
            </div>
          </div>
        </div>

        <!-- Terms & Conditions -->
        <div style="background:rgba(255,255,255,0.65);border:1px solid #aac8e0;border-radius:6px;padding:8px 10px;flex:1;overflow:hidden;">
          <div style="font-size:9px;font-weight:900;text-transform:uppercase;color:#163274;letter-spacing:0.05em;margin-bottom:5px;border-bottom:1px solid #bcd4e8;padding-bottom:4px;">TERMS &amp; CONDITIONS</div>
          <div id="p_terms" style="font-size:8px;color:#334155;line-height:1.6;white-space:pre-wrap;overflow:hidden;">1. The voucher is non-refundable and non-transferable.
2. Valid for new flight bookings made through Base Fare only.
3. Must be redeemed before expiry; no extension allowed.
4. If new booking exceeds the voucher value, difference must be paid by the passenger.
5. If the new booking is lower, the remaining balance will not be refunded.
6. No cash value; cannot be exchanged for cash.</div>
        </div>

        <!-- Stamp area -->
        <div style="border:2px dashed #aac8e0;border-radius:6px;padding:5px 10px;text-align:center;background:rgba(255,255,255,0.4);">
          <div style="font-size:9px;font-weight:800;text-transform:uppercase;color:#163274;letter-spacing:0.1em;">BASE FARE — AUTHORIZED</div>
        </div>

      </div>
    </div>
  </div>

  <!-- ══════════════ STUB (right ~22%) ══════════════ -->
  <div style="width:232px;flex-shrink:0;display:flex;flex-direction:column;position:relative;z-index:1;">

    <!-- Stub Header -->
    <div style="background:linear-gradient(90deg,#163274 0%,#1e4fad 100%);color:#fff;padding:10px 14px;display:flex;flex-direction:column;gap:1px;">
      <div style="font-size:9px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;opacity:0.85;">FLIGHT CREDIT</div>
      <div style="font-size:16px;font-weight:900;letter-spacing:0.08em;text-transform:uppercase;">VOUCHER</div>
    </div>

    <!-- Stub body -->
    <div style="padding:10px 14px 8px;display:flex;flex-direction:column;gap:8px;flex:1;overflow:hidden;">

      <div style="display:flex;flex-direction:column;gap:1px;">
        <div style="font-size:8px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">VOUCHER NO</div>
        <div id="s_vno" style="font-size:17px;font-weight:900;color:#163274;text-transform:uppercase;letter-spacing:0.04em;">VCH-XXXXXX</div>
      </div>

      <div style="display:flex;flex-direction:column;gap:1px;">
        <div style="font-size:8px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">VALID UNTIL</div>
        <div id="s_expiry" style="font-size:11px;font-weight:700;color:#1e293b;">31 DEC 2025</div>
      </div>

      <div style="display:flex;flex-direction:column;gap:1px;">
        <div style="font-size:8px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">PASSENGER</div>
        <div id="s_name" style="font-size:16px;font-weight:900;color:#1e293b;text-transform:uppercase;line-height:1.1;">JOHN DOE</div>
      </div>

      <div style="display:flex;flex-direction:column;gap:1px;">
        <div style="font-size:8px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">AMOUNT</div>
        <div id="s_amt" style="font-size:19px;font-weight:900;color:#163274;">USD 0.00</div>
      </div>

      <div style="display:flex;flex-direction:column;gap:1px;">
        <div style="font-size:8px;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:0.06em;">REASON</div>
        <div id="s_reason" style="font-size:11px;font-weight:700;color:#163274;">CANCELLATION</div>
      </div>

      <!-- Barcode -->
      <div style="text-align:center;margin-top:auto;">
        <svg id="barcode" style="width:100%;height:38px;display:block;"></svg>
        <div id="s_vno_bc" style="font-size:8px;font-family:monospace;letter-spacing:0.12em;color:#334155;margin-top:2px;">VCH-XXXXXX</div>
      </div>

      <!-- Brand tag — pinned to bottom with no overflow -->
      <div style="border-top:2px dashed #7baed4;padding-top:6px;text-align:center;">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;color:#475569;letter-spacing:0.06em;">FLIGHT TRAVEL</div>
        <div style="font-size:18px;font-weight:900;color:#163274;line-height:1;letter-spacing:0.04em;">BASE FARE</div>
        <div id="s_vno_tag" style="font-size:8px;font-family:monospace;color:#64748b;margin-top:2px;letter-spacing:0.1em;">VCH-XXXXXX</div>
      </div>

    </div>
  </div>

</div>
