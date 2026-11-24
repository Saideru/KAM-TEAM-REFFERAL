-- Proof-of-payment picture for the remaining 50% balance (Client payments →
-- Mark fully paid). Stored as a data URI, like KYC images. Re-runnable.
alter table public.transactions add column if not exists balance_proof text;
