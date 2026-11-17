-- =====================================================================
-- KAMTEAM // Migration: QR paywall + admin approval + free Basic tier
-- Safe to run on the LIVE database (no drops, no data loss).
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- 1) Payment proof + reviewer on transactions
alter table public.transactions
  add column if not exists payment_ref  text,
  add column if not exists reviewed_by  uuid references public.profiles (id) on delete set null,
  add column if not exists reviewed_at  timestamptz;

-- Only one pending activation request per user at a time
create unique index if not exists transactions_one_pending_activation
  on public.transactions (user_id)
  where type = 'activation' and status = 'pending';

-- 2) App settings (payment QR image, account name, instructions)
create table if not exists public.app_settings (
  key         text primary key,
  value       text,
  updated_at  timestamptz not null default now()
);
alter table public.app_settings enable row level security;

-- 3) Basic packages are free
update public.packages set price = 0 where tier = 'basic';

alter table public.packages drop constraint if exists packages_basic_is_free;
alter table public.packages
  add constraint packages_basic_is_free check (tier <> 'basic' or price = 0);
