-- =====================================================================
-- KAMTEAM // Migration: Growth Partnership Program terms acceptance
-- Run AFTER 20261119_kam_service_packages.sql.
-- Safe to run on the LIVE database (no drops, no data loss). Re-runnable.
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- One row per acceptance of the program terms checklist at sign-up
-- (Manual v1.7, Appendix A: date, time, IP address and version accepted).
-- `sections` holds every section the user ticked, e.g.
--   {"privacy": "Section 9 — Privacy, Data & Consent", ...}
-- so the record stays accurate as new sections are added to the checklist.
create table if not exists public.consents (
  id            uuid primary key default gen_random_uuid(),
  user_id       uuid not null references public.profiles (id) on delete cascade,
  program       text not null,
  terms_version text not null,
  sections      jsonb not null default '{}'::jsonb,
  signature     text not null,
  ip_address    text,
  user_agent    text,
  accepted_at   timestamptz not null default now()
);

create index if not exists consents_user_idx on public.consents (user_id, accepted_at desc);
alter table public.consents enable row level security;
