-- Remove settings that no longer drive live pricing or bank payment instructions.
-- Bank accounts are maintained in app/config.php under `banks` (multi-bank/currency data).
-- The class fee rule is now defined by the live enquiry/search pricing flow.
DELETE FROM `settings`
 WHERE `k` IN (
   'base_fee_per_class',
   'free_items_per_class',
   'extra_item_fee',
   'bank_beneficiary',
   'bank_ben_addr',
   'bank_name',
   'bank_account',
   'bank_swift',
   'bank_addr',
   'bank_note'
 );
