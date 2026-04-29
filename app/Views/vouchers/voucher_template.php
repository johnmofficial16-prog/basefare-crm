<?php
/**
 * voucher_template.php
 * Shared markup for both the live preview and PDF export.
 * All IDs are used by the render() JS function in maker.php.
 */
?>
<div id="voucher-printable">
  <div class="vc-bg"></div>

  <!-- ══ MAIN BODY ══ -->
  <div class="vc-main">

    <!-- Header -->
    <div class="vc-header">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="white" style="flex-shrink:0">
        <path d="M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z"/>
      </svg>
      FLIGHT TRAVEL CREDIT VOUCHER
    </div>

    <div class="vc-body">

      <!-- Left col: issuer + pax + sig -->
      <div class="vc-left">
        <div class="vc-issuer">
          <strong>BASE FARE</strong><br>
          reservation@base-fare.com<br>
          Toll-Free: 888 608 4011
        </div>

        <div class="vc-pax-name" id="p_name">JOHN DOE</div>

        <div class="vc-field">
          <span class="vc-label">Booking Ref (PNR):</span>
          <span class="vc-val" id="p_pnr">ABC123</span>
        </div>
        <div class="vc-field">
          <span class="vc-label">Original Ticket No:</span>
          <span class="vc-val" id="p_ticket">1234567890</span>
        </div>

        <div class="vc-sig-area">
          <div class="vc-sig-line">PASSENGER SIGNATURE</div>
          <div style="margin-top:18px; border-top:1px solid #94a3b8; padding-top:4px;">
            <div class="vc-sig-line">AUTHORIZED BY (BASE FARE)</div>
          </div>
        </div>
      </div>

      <!-- Mid col: meta + amount + validity + terms -->
      <div class="vc-mid">

        <div class="vc-top-meta">
          <div class="vc-meta-item">
            <span class="vc-label">Voucher No:</span>
            <span style="font-size:18px;font-weight:800;color:#163274;text-transform:uppercase;" id="p_vno">VCH-XXXXXX</span>
          </div>
          <div class="vc-meta-item">
            <span class="vc-label">Date of Issue:</span>
            <span style="font-size:12px;font-weight:700;color:#1e293b;" id="p_issue">01 JAN 2025</span>
          </div>
        </div>

        <div class="vc-amount-box">
          <div>
            <div class="vc-amount-label">Amount</div>
            <div class="vc-amount-val" id="p_amt">USD 0.00</div>
          </div>
          <div style="text-align:right;">
            <div class="vc-label">Valid Until:</div>
            <div style="font-size:12px;font-weight:700;color:#1e293b;" id="p_expiry">31 DEC 2025</div>
            <div class="vc-label" style="margin-top:4px;">Reason:</div>
            <div style="font-size:11px;font-weight:700;color:#163274;" id="p_reason">CANCELLATION</div>
          </div>
        </div>

        <div class="vc-terms-box">
          <div class="vc-terms-title">Terms &amp; Conditions</div>
          <div class="vc-terms-text" id="p_terms">1. The voucher is non-refundable and non-transferable.
2. Valid for new flight bookings made through Base Fare only.
3. Must be redeemed before expiry; no extension allowed.
4. If new booking exceeds the voucher value, difference must be paid by the passenger.
5. If the new booking is lower, the remaining balance will not be refunded.
6. No cash value; cannot be exchanged for cash.</div>
        </div>

      </div>

    </div>
  </div>

  <!-- ══ STUB ══ -->
  <div class="vc-stub">
    <div class="vc-header-stub">
      FLIGHT CREDIT<br>
      <span class="big">VOUCHER</span>
    </div>

    <div class="vc-stub-body">
      <div class="vc-stub-field">
        <span class="vc-stub-label">Voucher No</span>
        <span class="vc-stub-val" id="s_vno">VCH-XXXXXX</span>
      </div>
      <div class="vc-stub-field">
        <span class="vc-stub-label">Valid Until</span>
        <span class="vc-stub-val-sm" id="s_expiry">31 DEC 2025</span>
      </div>
      <div class="vc-stub-field">
        <span class="vc-stub-label">Passenger</span>
        <span class="vc-stub-val-sm" id="s_name">JOHN DOE</span>
      </div>
      <div class="vc-stub-field">
        <span class="vc-stub-label">Amount</span>
        <span style="font-size:18px;font-weight:800;color:#163274;" id="s_amt">USD 0.00</span>
      </div>
      <div class="vc-stub-field">
        <span class="vc-stub-label">Reason</span>
        <span class="vc-stub-val-sm" id="s_reason">CANCELLATION</span>
      </div>

      <div class="vc-barcode-wrap">
        <svg id="barcode"></svg>
        <div class="vc-barcode-num" id="s_vno_bc">VCH-XXXXXX</div>
      </div>

      <div class="vc-brand-tag">
        <div class="vc-brand-name">BASE FARE</div>
        <div class="vc-brand-sub">Flight Travel Voucher</div>
      </div>
    </div>
  </div>

</div>
