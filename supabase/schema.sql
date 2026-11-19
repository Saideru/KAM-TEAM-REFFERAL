-- =====================================================================
-- KAMTEAM // NETWORK MARKETING & REFERRAL PORTAL — Supabase schema v3
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- Required for bcrypt password hashing
create extension if not exists pgcrypto with schema extensions;

-- ---------------------------------------------------------------------
-- 01 // PROFILES  (standalone — passwords stored here, no auth.users)
-- Roles: admin | client_basic | client_standard | referral
-- Status: pending | accepted | suspended
-- ---------------------------------------------------------------------
drop table if exists public.client_reports cascade;
drop table if exists public.client_task_updates cascade;
drop table if exists public.client_tasks cascade;
drop sequence if exists public.client_task_seq;
drop table if exists public.consents cascade;
drop table if exists public.roulette_members cascade;
drop sequence if exists public.roulette_seq;
drop table if exists public.package_changes cascade;
drop table if exists public.notifications cascade;
drop table if exists public.app_settings cascade;
drop table if exists public.system_logs cascade;
drop table if exists public.transactions cascade;
drop table if exists public.referrals cascade;
drop table if exists public.client_services cascade;
drop table if exists public.packages cascade;
drop table if exists public.profiles cascade;

create table public.profiles (
  id                  uuid primary key default gen_random_uuid(),
  full_name           text not null,
  username            text not null unique,
  email               text unique,
  password_hash       text not null,
  phone               text,
  company             text,
  referral_code       text not null unique,
  referred_by         uuid references public.profiles (id) on delete set null,
  role                text not null default 'referral'
                        check (role in ('admin', 'client_basic', 'client_standard', 'referral')),
  status              text not null default 'pending'
                        check (status in ('pending', 'accepted', 'suspended', 'rejected')),
  -- Document images live in kyc_submissions; this is just the cheap flag
  kyc_status          text not null default 'not_submitted'
                        check (kyc_status in ('not_submitted', 'pending', 'approved', 'rejected')),
  review_note         text,
  notif_seen_at       timestamptz not null default now(),
  lifecycle_expires_at timestamptz,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  deleted_at          timestamptz
);

create index profiles_username_idx on public.profiles (username);
create index profiles_kyc_status_idx on public.profiles (kyc_status);
create index profiles_role_idx on public.profiles (role);
create index profiles_status_idx on public.profiles (status);
create index profiles_referral_code_idx on public.profiles (referral_code);

-- ---------------------------------------------------------------------
-- 02 // PACKAGES  (subscription items admins create)
-- ---------------------------------------------------------------------
create table public.packages (
  id                   uuid primary key default gen_random_uuid(),
  name                 text not null,
  description          text,
  price                numeric(10, 2) not null default 0,   -- one-time setup fee
  monthly_price        numeric(10, 2) not null default 0,   -- monthly recurring fee
  commission_outright  numeric(10, 2) not null default 0,
  commission_unilevel  numeric(5, 2) not null default 0,
  tier                 text not null default 'basic'
                         check (tier in ('basic', 'standard')),
  active               boolean not null default true,
  created_at           timestamptz not null default now(),
  constraint packages_basic_is_free check (tier <> 'basic' or price = 0)
);

-- ---------------------------------------------------------------------
-- 03 // TRANSACTIONS  (financial ledger)
-- ---------------------------------------------------------------------
create table public.transactions (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid not null references public.profiles (id) on delete cascade,
  package_id  uuid references public.packages (id) on delete set null,
  amount      numeric(10, 2) not null default 0,
  type        text not null check (type in ('activation', 'commission', 'payout')),
  status      text not null default 'pending'
                check (status in ('pending', 'completed', 'failed', 'reversed')),
  notes       text,
  payment_ref text,
  reviewed_by uuid references public.profiles (id) on delete set null,
  reviewed_at timestamptz,
  -- For commission rows: the referred user whose activation earned it
  source_user_id uuid references public.profiles (id) on delete set null,
  created_at  timestamptz not null default now()
);

create index transactions_user_idx on public.transactions (user_id);
create index transactions_type_idx on public.transactions (type);
create unique index transactions_one_pending_activation
  on public.transactions (user_id)
  where type = 'activation' and status = 'pending';
-- One commission per activation, so a double-approve cannot pay twice
create unique index transactions_one_commission_per_source
  on public.transactions (user_id, source_user_id)
  where type = 'commission' and source_user_id is not null;

-- ---------------------------------------------------------------------
-- 03b // APP SETTINGS  (payment QR, account name, instructions)
-- ---------------------------------------------------------------------
create table public.app_settings (
  key         text primary key,
  value       text,
  updated_at  timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- 03c // NOTIFICATIONS  (per user, or broadcast when user_id is null)
-- ---------------------------------------------------------------------
create table public.notifications (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid references public.profiles (id) on delete cascade,
  audience    text not null default 'all'
                check (audience in ('all', 'admin', 'client_basic', 'client_standard', 'referral')),
  title       text not null,
  body        text,
  link        text,
  created_at  timestamptz not null default now()
);
create index notifications_user_idx    on public.notifications (user_id);
create index notifications_created_idx on public.notifications (created_at desc);

-- ---------------------------------------------------------------------
-- 03d // PACKAGE CHANGES  (admin edits, applied 1-7 days later)
-- ---------------------------------------------------------------------
create table public.package_changes (
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
create index package_changes_due_idx on public.package_changes (status, effective_at);
create unique index package_changes_one_scheduled
  on public.package_changes (package_id) where status = 'scheduled';

-- ---------------------------------------------------------------------
-- 04 // REFERRALS  (user-to-user referral tree)
-- ---------------------------------------------------------------------
create table public.referrals (
  id          uuid primary key default gen_random_uuid(),
  referrer_id uuid not null references public.profiles (id) on delete cascade,
  referred_id uuid not null references public.profiles (id) on delete cascade,
  level       int not null default 1,
  status      text not null default 'pending'
                check (status in ('pending', 'active', 'completed', 'cancelled')),
  created_at  timestamptz not null default now(),
  unique (referrer_id, referred_id)
);

create index referrals_referrer_idx on public.referrals (referrer_id);
create index referrals_referred_idx on public.referrals (referred_id);

-- ---------------------------------------------------------------------
-- 05 // CLIENT SERVICES  (kept from v2)
-- ---------------------------------------------------------------------
create table public.client_services (
  id          uuid primary key default gen_random_uuid(),
  client_id   uuid not null references public.profiles (id) on delete cascade,
  service     text not null check (service in (
                'S-01 Marketing',
                'S-02 Virtual Assistance',
                'S-03 Video Editing',
                'S-04 Accounting',
                'S-05 Call Center Support',
                'S-06 Project Management',
                'S-07 AI Implementation')),
  status      text not null default 'active' check (status in ('active', 'paused', 'completed')),
  started_at  timestamptz not null default now(),
  notes       text,
  unique (client_id, service)
);

create index client_services_client_idx on public.client_services (client_id);

-- ---------------------------------------------------------------------
-- 05b // KYC SUBMISSIONS  (one row per user, replaced on resubmission)
-- Kept out of profiles so page loads don't re-fetch the ID images.
-- ---------------------------------------------------------------------
create table public.kyc_submissions (
  id           uuid primary key default gen_random_uuid(),
  user_id      uuid not null unique references public.profiles (id) on delete cascade,
  legal_name   text not null,
  dob          date,
  address      text,
  id_type      text not null
                 check (id_type in ('national_id', 'passport', 'drivers_license', 'postal_id', 'other')),
  id_number    text not null,
  id_front     text,
  id_back      text,
  selfie       text,
  status       text not null default 'pending'
                 check (status in ('pending', 'approved', 'rejected')),
  note         text,
  reviewed_by  uuid references public.profiles (id) on delete set null,
  reviewed_at  timestamptz,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

create index kyc_status_idx  on public.kyc_submissions (status);
create index kyc_created_idx on public.kyc_submissions (created_at desc);

-- ---------------------------------------------------------------------
-- 06 // SYSTEM LOGS  (audit trail — CCTV-like)
-- ---------------------------------------------------------------------
create table public.system_logs (
  id          uuid primary key default gen_random_uuid(),
  actor_id    uuid references public.profiles (id) on delete set null,
  action      text not null,
  entity_type text,
  entity_id   text,
  summary     text,
  ip_address  text,
  created_at  timestamptz not null default now()
);

create index system_logs_actor_idx on public.system_logs (actor_id);
create index system_logs_action_idx on public.system_logs (action);
create index system_logs_created_idx on public.system_logs (created_at desc);

-- ---------------------------------------------------------------------
-- 06b // CONSENTS  (program terms checklist accepted at sign-up —
--   version, sections, date/time, IP and device; Manual Appendix A)
-- ---------------------------------------------------------------------
create table public.consents (
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

create index consents_user_idx on public.consents (user_id, accepted_at desc);

-- ---------------------------------------------------------------------
-- 07 // HELPER FUNCTIONS
-- ---------------------------------------------------------------------
create or replace function public.touch_updated_at()
returns trigger language plpgsql set search_path = ''
as $$
begin
  new.updated_at := now();
  return new;
end;
$$;

drop trigger if exists profiles_touch_updated_at on public.profiles;
create trigger profiles_touch_updated_at
  before update on public.profiles
  for each row execute function public.touch_updated_at();

-- Extend lifecycle by 3 months when a standard user gets a new referral
create or replace function public.extend_lifecycle_on_referral()
returns trigger language plpgsql security definer set search_path = ''
as $$
declare
  referrer_role text;
begin
  select role into referrer_role from public.profiles where id = new.referrer_id;
  if referrer_role = 'client_standard' then
    update public.profiles
    set lifecycle_expires_at = coalesce(lifecycle_expires_at, now()) + interval '3 months'
    where id = new.referrer_id;
  end if;
  return new;
end;
$$;

drop trigger if exists referrals_extend_lifecycle on public.referrals;
create trigger referrals_extend_lifecycle
  after insert on public.referrals
  for each row execute function public.extend_lifecycle_on_referral();

-- Auto-generate referral code on profile insert
create or replace function public.gen_referral_code()
returns trigger language plpgsql set search_path = ''
as $$
declare
  new_code text;
begin
  if new.referral_code is null or new.referral_code = '' then
    loop
      new_code := 'KT-' || upper(substr(md5(random()::text || clock_timestamp()::text), 1, 6));
      exit when not exists (select 1 from public.profiles where referral_code = new_code);
    end loop;
    new.referral_code := new_code;
  end if;
  return new;
end;
$$;

drop trigger if exists profiles_gen_referral_code on public.profiles;
create trigger profiles_gen_referral_code
  before insert on public.profiles
  for each row execute function public.gen_referral_code();

-- Create the referral tree record when a profile is referred by someone
create or replace function public.link_referral()
returns trigger language plpgsql security definer set search_path = ''
as $$
begin
  if new.referred_by is not null then
    insert into public.referrals (referrer_id, referred_id, status)
    values (new.referred_by, new.id, 'pending')
    on conflict (referrer_id, referred_id) do nothing;
  end if;
  return new;
end;
$$;

drop trigger if exists profiles_link_referral on public.profiles;
create trigger profiles_link_referral
  after insert on public.profiles
  for each row execute function public.link_referral();

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

-- ---------------------------------------------------------------------
-- 08 // ROW LEVEL SECURITY — deny-all to anon/authenticated
--
-- Authentication happens in PHP (password_hash in profiles), so there is
-- no auth.uid() to write policies against. RLS is left ON with no
-- policies, which denies every request made with the public anon key.
-- The app reaches these tables with the service-role key from the PHP
-- server only, which bypasses RLS; access control lives in require_role().
-- Never expose the service-role key to the browser.
-- ---------------------------------------------------------------------
alter table public.profiles        enable row level security;
alter table public.referrals       enable row level security;
alter table public.client_services enable row level security;
alter table public.packages        enable row level security;
alter table public.transactions    enable row level security;
alter table public.system_logs     enable row level security;
alter table public.app_settings    enable row level security;
alter table public.notifications   enable row level security;
alter table public.package_changes enable row level security;
alter table public.kyc_submissions enable row level security;
alter table public.consents        enable row level security;

-- ---------------------------------------------------------------------
-- 09 // SEED SETTINGS + DEFAULT PACKAGES
-- ---------------------------------------------------------------------
-- Percent of an activation paid to the referrer. Admin-editable.
insert into public.app_settings (key, value) values ('referral_commission_pct', '5')
on conflict (key) do nothing;

insert into public.packages (name, description, price, commission_outright, commission_unilevel, tier) values
  ('Basic Starter',    'Entry-level 3-month access',          0.00,   10.00, 0,    'basic'),
  ('Standard Pro',     'Full 27-month access with unilevel',  100.00, 25.00, 5.00, 'standard');

-- KAM Team service packages. price = one-time fee, monthly_price = recurring
-- fee, commission_outright = 10% of the 50% down payment.
insert into public.packages (name, description, price, monthly_price, commission_outright, commission_unilevel, tier) values
  ('GHL Business Systems Development & Automation',
   'Website, SEO/AEO/GEO, landing page or sales funnel, email & SMS automation, CRM and pipeline setup, lead nurturing. Monthly: CRM maintenance, workflow monitoring, automation management, dedicated GHL system management.',
   3500.00, 1000.00, 175.00, 5.00, 'standard'),
  ('Complete Google SEO Setup & Optimization',
   'Search Console & GA4, Google Business Profile (where eligible), technical SEO audit and fixes, keyword & competitor research, on-page optimization for up to 10 pages, sitemap/indexing/schema, internal linking, final report. Monthly: monitoring, reporting, content optimization, technical maintenance.',
   3000.00, 700.00, 150.00, 5.00, 'standard'),
  ('Full-Service Digital Marketing Package',
   'Digital marketing strategy, business website, Google SEO & Search Console, Google Business Profile (if eligible), Google & Meta Ads setup, social media branding, GHL CRM and pipelines, email & SMS automation, AI lead qualification. Monthly: SEO, ads management, social content, email marketing, CRM monitoring, reporting, optimization.',
   7500.00, 2500.00, 375.00, 5.00, 'standard'),
  ('Custom Business Operations Management System',
   'Planning, development, testing and deployment of an operations dashboard with user management, role-based permissions, workflow automation, client management, reporting & analytics, database/API integration, security controls and admin training. Monthly: monitoring, security patches, bug fixes, backup oversight, limited support.',
   25000.00, 600.00, 1250.00, 5.00, 'standard'),
  ('Customized Integrated Digital & Network Marketing Business System',
   'System planning and architecture, digital marketing, CRM and automation, network marketing and commission system, member and admin dashboards, database and integrations, testing, deployment and training. Monthly: monitoring, backup oversight, security patches, bug fixes, limited support.',
   45000.00, 2000.00, 2250.00, 5.00, 'standard'),
  ('Complete AI SEO, AEO & GEO Optimization',
   'Technical Google SEO, Search Console/GA4/tracking, keyword & competitor research, on-page optimization for up to 10 pages, schema and structured data, content optimization for AI answers, entity & online presence optimization, AI visibility baseline, audit report and roadmap. Monthly: technical monitoring, content improvement, AI visibility checks, performance analysis, reporting.',
   5500.00, 1500.00, 275.00, 5.00, 'standard');

-- ---------------------------------------------------------------------
-- 10 // ADMIN ACCOUNTS
--   username: michael | azi | siedel
--   password: Numbers20:20
-- bcrypt cost 10 to match PHP's password_hash() default.
-- ---------------------------------------------------------------------
insert into public.profiles (full_name, username, email, password_hash, role, status)
values
  ('Michael', 'michael', 'michael@kam-team.com', extensions.crypt('Numbers20:20', extensions.gen_salt('bf', 10)), 'admin', 'accepted'),
  ('Azi',     'azi',     'azi@kam-team.com',     extensions.crypt('Numbers20:20', extensions.gen_salt('bf', 10)), 'admin', 'accepted'),
  ('Siedel',  'siedel',  'siedel@kam-team.com',  extensions.crypt('Numbers20:20', extensions.gen_salt('bf', 10)), 'admin', 'accepted')
on conflict (username) do update
set password_hash = excluded.password_hash,
    role          = 'admin',
    status        = 'accepted',
    deleted_at    = null;

-- ---------------------------------------------------------------------
-- 11 // ROULETTE + BUSINESS PROFILE  (same as migration 20261118e)
-- ---------------------------------------------------------------------
-- 1) Business profile captured at registration
alter table public.profiles
  add column if not exists business_nature    text,
  add column if not exists business_struggles text[] not null default '{}',
  add column if not exists business_notes     text;

-- 2) Roulette: every Basic or Standard plan a user avails (an approved
--    activation) is handed to the next admin in a fixed rotation
--    (1, 2, 3, 1, 2, 3 ...). The transaction keeps who got that package;
--    the profile keeps who currently manages the account. Who *referred*
--    them is unchanged — that still lives in referred_by.
alter table public.transactions
  add column if not exists assigned_admin uuid references public.profiles (id) on delete set null;
create index if not exists transactions_assigned_admin_idx on public.transactions (assigned_admin);

alter table public.profiles
  add column if not exists assigned_admin uuid references public.profiles (id) on delete set null;
create index if not exists profiles_assigned_admin_idx on public.profiles (assigned_admin);

create table if not exists public.roulette_members (
  admin_id  uuid primary key references public.profiles (id) on delete cascade,
  position  int  not null,
  active    boolean not null default true
);
alter table public.roulette_members enable row level security;

create sequence if not exists public.roulette_seq start 0 minvalue 0;

-- Active members, in rotation order
create or replace function public.roulette_lineup()
returns table (admin_id uuid, slot int) language sql stable security definer set search_path = ''
as $$
  select m.admin_id, (row_number() over (order by m.position, m.admin_id))::int - 1
  from public.roulette_members m
  join public.profiles p on p.id = m.admin_id
  where m.active and p.role = 'admin' and p.deleted_at is null;
$$;

-- Takes the next turn (advances the rotation)
create or replace function public.roulette_next()
returns uuid language plpgsql security definer set search_path = ''
as $$
declare
  n    int;
  turn bigint;
  pick uuid;
begin
  select count(*) into n from public.roulette_lineup();
  if n = 0 then return null; end if;
  turn := nextval('public.roulette_seq');
  select admin_id into pick from public.roulette_lineup() where slot = (turn % n);
  return pick;
end;
$$;

-- Shows whose turn is next without advancing the rotation
create or replace function public.roulette_peek()
returns uuid language plpgsql stable security definer set search_path = ''
as $$
declare
  n     int;
  last  bigint;
  used  boolean;
  pick  uuid;
begin
  select count(*) into n from public.roulette_lineup();
  if n = 0 then return null; end if;
  select last_value, is_called into last, used from public.roulette_seq;
  select admin_id into pick from public.roulette_lineup()
   where slot = ((case when used then last + 1 else last end) % n);
  return pick;
end;
$$;

-- The app takes a turn (rpc/roulette_next) when an admin approves a plan.
drop trigger if exists profiles_assign_roulette on public.profiles;
drop function if exists public.assign_from_roulette();

-- Seed the rotation with the three admins: michael (1), azi (2), siedel (3)
insert into public.roulette_members (admin_id, position)
select id, coalesce(array_position(array['michael', 'azi', 'siedel'], username), 100)
from public.profiles
where role = 'admin' and deleted_at is null
on conflict (admin_id) do nothing;

-- ---------------------------------------------------------------------
-- 12 // AFFILIATES vs CLIENTS + CLIENT WORK REPORTING
--       (same as migration 20261121_affiliates_clients_work.sql)
-- ---------------------------------------------------------------------
-- 1) Account types: admin | client | affiliate
--    * Client    — permanent account; buys KAM TEAM service packages.
--                  Has its own affiliate side (referral code) in a tab.
--    * Affiliate — refers clients. Basic (free, 3 months) or Standard
--                  (US$100 per 2-year term); expires (Manual §4).
-- ---------------------------------------------------------------------
alter table public.profiles add column if not exists affiliate_tier text;
alter table public.profiles drop constraint if exists profiles_affiliate_tier_check;
alter table public.profiles add constraint profiles_affiliate_tier_check
  check (affiliate_tier is null or affiliate_tier in ('basic', 'standard'));

-- Manual version the user last accepted (blocks the dashboard until current).
alter table public.profiles add column if not exists terms_version text;

alter table public.profiles drop constraint if exists profiles_role_check;

-- Referrals who picked a Basic/Standard plan were stored as client_basic /
-- client_standard. Anyone with an approved plan request is an affiliate.
update public.profiles p
set role = 'affiliate',
    affiliate_tier = case when p.role = 'client_standard' then 'standard' else 'basic' end
where p.role in ('client_basic', 'client_standard')
  and exists (select 1 from public.transactions t
              where t.user_id = p.id and t.type = 'activation' and t.status = 'completed');

update public.profiles set role = 'affiliate' where role = 'referral';

-- Everyone else on a client_* role signed up as a client: permanent account.
update public.profiles set role = 'client', lifecycle_expires_at = null
where role in ('client_basic', 'client_standard');

alter table public.profiles alter column role set default 'affiliate';
alter table public.profiles add constraint profiles_role_check
  check (role in ('admin', 'client', 'affiliate'));

alter table public.notifications drop constraint if exists notifications_audience_check;
update public.notifications set audience = 'affiliate' where audience = 'referral';
update public.notifications set audience = 'client' where audience in ('client_basic', 'client_standard');
alter table public.notifications add constraint notifications_audience_check
  check (audience in ('all', 'admin', 'client', 'affiliate'));

-- Backfill the accepted manual version from the consent records.
update public.profiles p set terms_version = c.terms_version
from (select distinct on (user_id) user_id, terms_version
      from public.consents order by user_id, accepted_at desc) c
where c.user_id = p.id and p.terms_version is null;

-- ---------------------------------------------------------------------
-- 2) Packages: affiliate plans vs client service packages
-- ---------------------------------------------------------------------
alter table public.packages add column if not exists kind text not null default 'service';
alter table public.packages drop constraint if exists packages_kind_check;
alter table public.packages add constraint packages_kind_check check (kind in ('affiliate', 'service'));
update public.packages set kind = 'affiliate'
where tier = 'basic' or name in ('Basic Starter', 'Standard Pro');

-- ---------------------------------------------------------------------
-- 3) Transactions: client 50% advance deposits, one commission per deposit
-- ---------------------------------------------------------------------
alter table public.transactions drop constraint if exists transactions_type_check;
alter table public.transactions add constraint transactions_type_check
  check (type in ('activation', 'deposit', 'commission', 'payout'));

alter table public.transactions
  add column if not exists source_txn_id uuid references public.transactions (id) on delete set null;

-- Commission is earned once per Qualified Client package purchase (§3.3),
-- not once per referrer/client pair.
drop index if exists public.transactions_one_commission_per_source;
create unique index if not exists transactions_one_commission_per_deposit
  on public.transactions (user_id, source_txn_id)
  where type = 'commission' and source_txn_id is not null;

create unique index if not exists transactions_one_pending_deposit
  on public.transactions (user_id, package_id)
  where type = 'deposit' and status = 'pending';

-- The +3 months now follows a Qualified Client's paid deposit (§4.1, §4.2),
-- handled by the app, not every sign-up.
drop trigger if exists referrals_extend_lifecycle on public.referrals;

-- Commission rate is now a % of the 50% advance deposit (§3.1: 10%).
-- 5% of the full price == 10% of the deposit, so an untouched 5 becomes 10.
update public.app_settings set value = '10' where key = 'referral_commission_pct' and value = '5';
insert into public.app_settings (key, value) values ('referral_commission_pct', '10')
on conflict (key) do nothing;

-- ---------------------------------------------------------------------
-- 4) Client Work Documentation & Reporting (SOP-OPS-001)
--    Roles (assignee, reviewer, coordinator, preparer, verifier,
--    approver) are free-text name tags — a formality, not permissions.
-- ---------------------------------------------------------------------
create sequence if not exists public.client_task_seq;

create table if not exists public.client_tasks (
  id                 uuid primary key default gen_random_uuid(),
  task_code          text not null unique
                       default ('T-' || lpad(nextval('public.client_task_seq')::text, 4, '0')),
  client_id          uuid not null references public.profiles (id) on delete cascade,
  package_id         uuid references public.packages (id) on delete set null,
  title              text not null,
  scope_ref          text,
  milestone          text,
  assignee           text,
  reviewer           text,
  assigned_on        date not null default current_date,
  target_date        date,
  completed_on       date,
  work_performed     text,
  deliverable        text,
  evidence           text,
  status             text not null default 'not_started'
                       check (status in ('not_started', 'in_progress', 'for_review', 'completed',
                                         'blocked', 'on_hold', 'cancelled')),
  blockers           text,
  next_action        text,
  next_owner         text,
  next_date          date,
  cancel_reason      text,
  cancel_approved_by text,
  created_by         uuid references public.profiles (id) on delete set null,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now()
);
create index if not exists client_tasks_client_idx on public.client_tasks (client_id, status);

-- The task's running log: updates, evidence, blockers, completion notes.
create table if not exists public.client_task_updates (
  id          uuid primary key default gen_random_uuid(),
  task_id     uuid not null references public.client_tasks (id) on delete cascade,
  kind        text not null default 'update'
                check (kind in ('update', 'evidence', 'blocker', 'completion', 'status', 'review')),
  note        text not null,
  status_from text,
  status_to   text,
  author_tag  text,
  author_id   uuid references public.profiles (id) on delete set null,
  created_at  timestamptz not null default now()
);
create index if not exists client_task_updates_task_idx on public.client_task_updates (task_id, created_at desc);

create table if not exists public.client_reports (
  id                  uuid primary key default gen_random_uuid(),
  client_id           uuid not null references public.profiles (id) on delete cascade,
  package_id          uuid references public.packages (id) on delete set null,
  report_type         text not null default 'monthly'
                        check (report_type in ('weekly', 'monthly', 'milestone', 'incident')),
  period_start        date not null,
  period_end          date not null,
  report_date         date not null default current_date,
  owner               text,               -- KAM TEAM contact shown in the header
  summary             text,               -- executive summary
  results             text,               -- performance & results (metric, source, period)
  next_plan           text,               -- next-period plan
  client_decisions    text,               -- decisions needed from the client
  coordinator         text,
  prepared_by         text,
  verified_by         text,
  approved_by         text,
  qa                  jsonb not null default '{}'::jsonb,
  snapshot            jsonb,              -- frozen task tables at approval
  status              text not null default 'draft'
                        check (status in ('draft', 'approved', 'delivered')),
  version             int not null default 1,
  revision_of         uuid references public.client_reports (id) on delete set null,
  revision_note       text,
  delivered_at        timestamptz,
  delivery_channel    text,
  delivery_recipients text,
  client_feedback     text,
  created_by          uuid references public.profiles (id) on delete set null,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now()
);
create index if not exists client_reports_client_idx on public.client_reports (client_id, period_end desc);

drop trigger if exists client_tasks_touch_updated_at on public.client_tasks;
create trigger client_tasks_touch_updated_at
  before update on public.client_tasks
  for each row execute function public.touch_updated_at();

drop trigger if exists client_reports_touch_updated_at on public.client_reports;
create trigger client_reports_touch_updated_at
  before update on public.client_reports
  for each row execute function public.touch_updated_at();

alter table public.client_tasks        enable row level security;
alter table public.client_task_updates enable row level security;
alter table public.client_reports      enable row level security;
