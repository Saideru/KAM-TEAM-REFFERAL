-- =====================================================================
-- KAMTEAM // Migration: approval wall + notifications + scheduled changes
-- Run AFTER 20261118_paywall_approvals.sql.
-- Safe to run on the LIVE database (no drops, no data loss).
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- 1) Approval wall: applications can be rejected, with a reason
alter table public.profiles drop constraint if exists profiles_status_check;
alter table public.profiles
  add constraint profiles_status_check check (status in ('pending', 'accepted', 'suspended', 'rejected'));

alter table public.profiles
  add column if not exists review_note   text,
  add column if not exists notif_seen_at timestamptz not null default now();

-- 2) Notifications (per user, or broadcast to an audience)
create table if not exists public.notifications (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid references public.profiles (id) on delete cascade,   -- null = broadcast
  audience    text not null default 'all'
                check (audience in ('all', 'admin', 'client_basic', 'client_standard', 'referral')),
  title       text not null,
  body        text,
  link        text,
  created_at  timestamptz not null default now()
);
create index if not exists notifications_user_idx    on public.notifications (user_id);
create index if not exists notifications_created_idx on public.notifications (created_at desc);
alter table public.notifications enable row level security;

-- 3) Scheduled package changes (applied 1-7 days after the admin saves them)
create table if not exists public.package_changes (
  id           uuid primary key default gen_random_uuid(),
  package_id   uuid not null references public.packages (id) on delete cascade,
  changes      jsonb not null,
  summary      text,
  effective_at timestamptz not null,
  status       text not null default 'scheduled'
                 check (status in ('scheduled', 'applied', 'cancelled')),
  created_by   uuid references public.profiles (id) on delete set null,
  created_at   timestamptz not null default now(),
  applied_at   timestamptz
);
create index if not exists package_changes_due_idx on public.package_changes (status, effective_at);
-- At most one scheduled change per package at a time
create unique index if not exists package_changes_one_scheduled
  on public.package_changes (package_id) where status = 'scheduled';
alter table public.package_changes enable row level security;
