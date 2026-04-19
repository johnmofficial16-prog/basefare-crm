    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 4: Fare & Payment                                         -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="step-panel" id="step-4">
      <div class="space-y-4">

        <!-- Fare Breakdown -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">
              <span class="material-symbols-outlined text-base align-text-bottom mr-1">receipt_long</span> Fare Breakdown
            </h2>
            <p class="text-xs text-slate-500 mt-0.5">Add line items — total is calculated automatically.</p>
          </div>
          <div class="p-6 space-y-3">
            <div id="fare-items" class="space-y-2"></div>
            <button type="button" onclick="fareMgr.addItem()"
              class="inline-flex items-center gap-1.5 text-sm text-primary font-semibold hover:text-primary-500 transition-colors">
              <span class="material-symbols-outlined text-base">add_circle</span> Add Line Item
            </button>
            <div class="border-t border-slate-100 pt-3">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                  <label class="field-label">Currency</label>
                  <?php $cur = $pre['currency'] ?: 'USD'; ?>
                  <select name="currency" id="field_currency" class="field-input" onchange="syncSummary()">
                    <option value="USD" <?= $cur==='USD'?'selected':'' ?>>USD</option>
                    <option value="CAD" <?= $cur==='CAD'?'selected':'' ?>>CAD</option>
                    <option value="GBP" <?= $cur==='GBP'?'selected':'' ?>>GBP</option>
                    <option value="EUR" <?= $cur==='EUR'?'selected':'' ?>>EUR</option>
                    <option value="INR" <?= $cur==='INR'?'selected':'' ?>>INR</option>
                    <option value="AED" <?= $cur==='AED'?'selected':'' ?>>AED</option>
                    <option value="SGD" <?= $cur==='SGD'?'selected':'' ?>>SGD</option>
                  </select>
                </div>
                <div>
                  <label class="field-label">Total Charged to Card <span class="text-rose-500">*</span></label>
                  <input type="number" name="total_amount" id="field_total_amount" step="0.01" min="0" required
                    value="<?= $pre['total_amount'] ?>" placeholder="0.00" oninput="syncSummary()"
                    class="field-input text-lg font-bold text-emerald-700">
                  <p class="text-[10px] text-slate-500 mt-1">Amount billed to the customer's card</p>
                </div>
                <div>
                  <label class="field-label">Cost / Net (Supplier Price)</label>
                  <input type="number" name="cost_amount" id="field_cost_amount" step="0.01" min="0" placeholder="0.00"
                    class="field-input">
                  <p class="text-[10px] text-slate-500 mt-1">What we paid to the airline / supplier</p>
                </div>
              </div>
              <div class="mt-4 p-3 bg-emerald-50 border border-emerald-100 rounded-lg flex flex-col gap-1">
                <div class="text-xs font-bold text-emerald-800">MCO — Profit Margin</div>
                <input type="number" name="profit_mco" id="field_profit_mco" step="0.01" value="0.00"
                   class="w-full border border-emerald-300 rounded px-3 py-1.5 text-lg font-black text-emerald-700 bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500">
                <div class="text-[10px] text-emerald-600/80">Manual entry</div>
              </div>
            </div>
            <div id="step4-amount-error" class="hidden text-rose-600 text-xs font-medium flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">error</span> Total amount is required.
            </div>
          </div>
        </div>

        <!-- Payment Details -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">
              <span class="material-symbols-outlined text-base align-text-bottom mr-1">credit_card</span> Payment Details
            </h2>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">Payment Method</label>
                <select name="payment_method" id="field_payment_method" class="field-input">
                  <option value="credit_card">Credit Card</option>
                  <option value="debit_card">Debit Card</option>
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="cash">Cash</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div>
                <label class="field-label">Payment Status</label>
                <select name="payment_status" id="field_payment_status" class="field-input">
                  <option value="pending">Pending</option>
                  <option value="captured">Captured</option>
                  <option value="refunded">Refunded</option>
                  <option value="failed">Failed</option>
                </select>
              </div>
              <div>
                <label class="field-label">Card Type</label>
                <select name="card_type" id="field_card_type" class="field-input">
                  <option value="">-- Select --</option>
                  <option <?= $pre['card_type']==='Visa'?'selected':'' ?>>Visa</option>
                  <option <?= $pre['card_type']==='Mastercard'?'selected':'' ?>>Mastercard</option>
                  <option <?= $pre['card_type']==='Amex'?'selected':'' ?>>Amex</option>
                  <option <?= $pre['card_type']==='Discover'?'selected':'' ?>>Discover</option>
                  <option <?= $pre['card_type']==='UnionPay'?'selected':'' ?>>UnionPay</option>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="field-label">Cardholder Name</label>
                <input type="text" name="cardholder_name" id="field_cardholder_name" value="<?= $pre['cardholder_name'] ?>" placeholder="As on card" class="field-input" autocomplete="off">
              </div>
              <div>
                <label class="field-label">Card Number</label>
                <input type="text" name="card_number" id="field_card_number" maxlength="19" placeholder="•••• •••• •••• ••••"
                  class="field-input font-mono tracking-wider" autocomplete="off"
                  oninput="this.value=this.value.replace(/[^\d\s]/g,'')">
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="field-label">Expiry</label>
                  <input type="text" name="card_expiry" id="field_card_expiry" maxlength="5" placeholder="MM/YY" class="field-input font-mono" autocomplete="off">
                </div>
                <div>
                  <label class="field-label">CVV</label>
                  <input type="text" name="card_cvv" id="field_card_cvv" maxlength="4" placeholder="•••" class="field-input font-mono tracking-widest" autocomplete="off">
                </div>
              </div>
            </div>
            <div>
              <label class="field-label">Billing Address</label>
              <textarea name="billing_address" id="field_billing_address" rows="2" placeholder="123 Main St, City, Province, Postal Code, Country"
                class="field-input resize-none"><?= $pre['billing_address'] ?></textarea>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">Statement Descriptor</label>
                <input type="text" name="statement_descriptor" id="field_statement_descriptor" placeholder="e.g. Lufthansa / Date Change Fee" class="field-input">
              </div>
              <div>
                <label class="field-label">Split Charge Note</label>
                <input type="text" name="split_charge_note" id="field_split_charge_note" placeholder="e.g. Card 1: $500, Card 2: $320" class="field-input">
              </div>
            </div>

            <!-- Additional Cards -->
            <div>
              <div class="flex items-center justify-between mb-2">
                <label class="field-label mb-0">Additional Cards (Split Charge)</label>
                <button type="button" onclick="cardMgr.add()"
                  class="inline-flex items-center gap-1 text-xs text-primary font-semibold hover:text-primary-500 transition-colors">
                  <span class="material-symbols-outlined text-sm">add_circle</span> Add Card
                </button>
              </div>
              <div id="additional-cards" class="space-y-2"></div>
            </div>
          </div>
        </div>

        <!-- Ticket Conditions -->
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
          <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/50">
            <h2 class="font-bold text-slate-900" style="font-family:Manrope">
              <span class="material-symbols-outlined text-base align-text-bottom mr-1">gavel</span> Ticket Conditions & Notes
            </h2>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">Class of Service</label>
                <select name="class_of_service" id="field_class_of_service" class="field-input">
                  <option value="">-- Select --</option>
                  <option value="Economy">Economy</option>
                  <option value="Premium Economy">Premium Economy</option>
                  <option value="Business">Business</option>
                  <option value="First">First</option>
                </select>
              </div>
              <div>
                <label class="field-label">Seat Number(s)</label>
                <input type="text" name="seat_number" id="field_seat_number" placeholder="e.g. 14A, 14B" class="field-input">
              </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="field-label">Endorsements</label>
                <input type="text" name="endorsements" id="field_endorsements" value="NON END/NON REF/NON RRT" placeholder="e.g. NON END/NON REF/NON RRT" class="field-input">
              </div>
              <div>
                <label class="field-label">Baggage Info</label>
                <input type="text" name="baggage_info" id="field_baggage_info" placeholder="e.g. 2PC / 23KG" class="field-input">
              </div>
            </div>
            <div>
              <label class="field-label">Fare Rules</label>
              <textarea name="fare_rules" id="field_fare_rules" rows="3" placeholder="Paste fare rules or key restrictions..." class="field-input resize-none">Exchange : Permitted with Fee
Cancellation : Non Refundable Ticket
Name Change : Not Allowed</textarea>
            </div>
            <div>
              <label class="field-label">Agent Notes <span class="text-rose-500">*</span></label>
              <textarea name="agent_notes" id="field_agent_notes" rows="3" required
                placeholder="Required: note what was done, any instructions from customer, authorizations given..." class="field-input resize-none"></textarea>
              <p class="text-[10px] text-slate-500 mt-1">Required — your name and timestamp will be recorded regardless.</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 4 Nav -->
      <div class="flex justify-between mt-4">
        <button type="button" onclick="wizard.prev()" class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
          <span class="material-symbols-outlined text-base">arrow_back</span> Back
        </button>
        <button type="button" onclick="wizard.next()" class="inline-flex items-center gap-2 bg-primary hover:bg-primary-500 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition-colors">
          Next: Review <span class="material-symbols-outlined text-base">arrow_forward</span>
        </button>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- STEP 5: Review & Submit                                        -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="step-panel" id="step-5">
      <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 bg-emerald-50">
          <h2 class="font-bold text-emerald-900" style="font-family:Manrope">
            <span class="material-symbols-outlined text-base align-text-bottom mr-1">fact_check</span> Review Transaction
          </h2>
          <p class="text-xs text-emerald-700 mt-0.5">Verify all details before recording.</p>
        </div>
        <div class="p-6 space-y-4">
          <!-- Type Badge -->
          <div class="flex items-center gap-2">
            <span class="px-3 py-1 bg-primary text-white text-xs font-bold rounded-full" id="preview-type-badge">--</span>
            <span class="text-sm font-bold font-mono text-primary" id="prev-pnr">--</span>
          </div>

          <!-- Customer grid -->
          <div class="grid grid-cols-3 gap-3">
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
              <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Customer</p>
              <p id="prev-name" class="text-sm font-semibold text-slate-900 mt-1 truncate">—</p>
            </div>
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
              <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Email</p>
              <p id="prev-email" class="text-xs font-medium text-slate-700 mt-1 truncate">—</p>
            </div>
            <div class="bg-slate-50 rounded-lg p-3 border border-slate-100">
              <p class="text-[9px] font-bold text-slate-400 uppercase tracking-wider">Passengers</p>
              <p id="prev-pax-count" class="text-sm font-bold text-slate-900 mt-1">0</p>
            </div>
          </div>

          <!-- Passengers list -->
          <div id="prev-pax-section" class="hidden">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Passenger Names</p>
            <div id="prev-pax-list" class="flex flex-wrap gap-2"></div>
          </div>

          <!-- Flight segments -->
          <div id="prev-flights-section" class="hidden">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Flight Segments</p>
            <div id="prev-flights" class="space-y-2"></div>
          </div>

          <!-- Fare -->
          <div id="prev-fare-section" class="hidden">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Fare Breakdown</p>
            <div id="prev-fare" class="bg-slate-50 rounded-lg border border-slate-100 overflow-hidden">
              <table class="w-full text-sm"><tbody id="prev-fare-rows"></tbody></table>
              <div class="flex justify-between items-center px-3 py-2 bg-primary text-white">
                <span class="font-bold text-xs uppercase tracking-wider">Total</span>
                <span id="prev-total" class="font-black font-mono text-base">—</span>
              </div>
            </div>
          </div>

          <!-- Proof of Sale -->
          <div id="proof-upload-section">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Proof of Sale (Required) <span class="text-rose-500">*</span></p>
            <div class="border-2 border-dashed border-slate-300 rounded-xl p-6 text-center bg-slate-50 hover:bg-slate-100 transition-colors relative cursor-pointer" onclick="document.getElementById('proof_of_sale').click()">
              <input type="file" name="proof_of_sale" id="proof_of_sale" accept="image/*,application/pdf" class="hidden" onchange="document.getElementById('proof_filename').textContent = this.files[0] ? this.files[0].name : 'No file selected'">
              <span class="material-symbols-outlined text-3xl text-slate-400">add_photo_alternate</span>
              <p class="text-sm font-bold text-slate-700 mt-2">Click to Upload Screenshot / PDF</p>
              <p id="proof_filename" class="text-xs text-primary-600 font-semibold mt-1">No file selected</p>
            </div>
            <div id="step5-proof-error" class="hidden mt-2 text-rose-600 text-xs font-medium flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">error</span> Proof of sale document is mandatory.
            </div>
          </div>

          <!-- Card -->
          <div id="prev-card-section" class="hidden">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Payment Card</p>
            <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-lg border border-slate-100">
              <span class="material-symbols-outlined text-slate-500">credit_card</span>
              <div>
                <p id="prev-card-holder" class="text-sm font-semibold text-slate-900">—</p>
                <p id="prev-card-num" class="text-xs font-mono text-slate-500">—</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Step 5 Nav + Submit -->
      <div class="flex justify-between items-center mt-4">
        <button type="button" onclick="wizard.prev()"
          class="inline-flex items-center gap-2 bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold py-2.5 px-4 rounded-lg text-sm transition-colors">
          <span class="material-symbols-outlined text-base">arrow_back</span> Back & Edit
        </button>
        <button type="button" id="btn-submit" onclick="formAssembly.submit()"
          class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 px-8 rounded-lg text-base transition-colors shadow-md">
          <span class="material-symbols-outlined">save</span> Record Transaction
        </button>
      </div>
    </div>
