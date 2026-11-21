-- Growth Partner payouts (Crypto / PayPal) — re-runnable.
-- A payout is a transactions row with type 'payout' (already allowed by
-- transactions_type_check). One payout may be waiting to be sorted per
-- partner at a time; the app checks this too.
create unique index if not exists transactions_one_pending_payout
  on public.transactions (user_id)
  where type = 'payout' and status = 'pending';

-- Killswitch + minimum payout settings (the app treats missing keys as
-- "closed until an admin opens payouts" and a US$100 minimum).
insert into public.app_settings (key, value) values ('payout_min', '100')
on conflict (key) do nothing;
