-- =====================================================================
-- KAMTEAM // Migration: KYC verification + referral commission rate
-- Run AFTER 20261118b_admin_controls.sql.
-- Safe to run on the LIVE database (no drops, no data loss).
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- 1) Cheap KYC flag on the profile.
-- Document images live in kyc_submissions, not here: current_profile()
-- selects * from profiles on every page load, so large data URIs in this
-- table would be re-fetched on every request.
alter table public.profiles
  add column if not exists kyc_status text not null default 'not_submitted';

alter table public.profiles drop constraint if exists profiles_kyc_status_check;
alter table public.profiles
  add constraint profiles_kyc_status_check
  check (kyc_status in ('not_submitted', 'pending', 'approved', 'rejected'));

create index if not exists profiles_kyc_status_idx on public.profiles (kyc_status);

-- 2) KYC submissions — one row per user, replaced on resubmission
create table if not exists public.kyc_submissions (
  id           uuid primary key default gen_random_uuid(),
  user_id      uuid not null unique references public.profiles (id) on delete cascade,
  legal_name   text not null,
  dob          date,
  address      text,
  id_type      text not null
                 check (id_type in ('national_id', 'passport', 'drivers_license', 'postal_id', 'other')),
  id_number    text not null,
  id_front     text,          -- data URI
  id_back      text,          -- data URI
  selfie       text,          -- data URI, holding the ID
  status       text not null default 'pending'
                 check (status in ('pending', 'approved', 'rejected')),
  note         text,          -- admin's reason, shown to the user
  reviewed_by  uuid references public.profiles (id) on delete set null,
  reviewed_at  timestamptz,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

create index if not exists kyc_status_idx  on public.kyc_submissions (status);
create index if not exists kyc_created_idx on public.kyc_submissions (created_at desc);

alter table public.kyc_submissions enable row level security;

drop trigger if exists kyc_touch_updated_at on public.kyc_submissions;
create trigger kyc_touch_updated_at
  before update on public.kyc_submissions
  for each row execute function public.touch_updated_at();

-- Keep profiles.kyc_status in step with the submission
create or replace function public.sync_kyc_status()
returns trigger language plpgsql security definer set search_path = ''
as $$
begin
  update public.profiles set kyc_status = new.status where id = new.user_id;
  return new;
end;
$$;

drop trigger if exists kyc_sync_status on public.kyc_submissions;
create trigger kyc_sync_status
  after insert or update of status on public.kyc_submissions
  for each row execute function public.sync_kyc_status();

-- 3) Referral commission rate (percent of the activation amount).
-- Base rate 5%, admin-configurable in the Payment tab.
insert into public.app_settings (key, value)
values ('referral_commission_pct', '5')
on conflict (key) do nothing;

-- Commission payouts reference the referred user's activation
alter table public.transactions
  add column if not exists source_user_id uuid references public.profiles (id) on delete set null;

-- One commission per activation, so a double-approve cannot pay twice
create unique index if not exists transactions_one_commission_per_source
  on public.transactions (user_id, source_user_id)
  where type = 'commission' and source_user_id is not null;
