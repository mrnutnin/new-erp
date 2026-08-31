# POS Promotion manual QA

## Intake to HS/IV

1. Create Sales Intake for an eligible customer/item and select a line promotion. Confirm its unit price/discount replaces the Price List result; an ineligible or expired promotion must not be selectable or savable.
2. Optionally select a document promotion. Confirm a non-stackable promotion is rejected when another promotion is used, and that its discount is allocated to eligible lines before VAT.
3. Convert the Intake through either Intake → Quotation → Sales Order or Intake → RFQ → Quotation → Sales Order. Confirm each document retains the same promotion code, line price, discount, tax base, and total after changing the promotion master or Price List.
4. Create HS/IV from the Sales Order. Confirm it retains the frozen line price/discount and VAT total, including a 100% discounted item with a zero total. The direct Invoice entry page must offer Price List pricing only; it must not auto-apply a promotion.
