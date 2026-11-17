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
                        check (status in ('pending', 'accepted', 'suspended')),
  lifecycle_expires_at timestamptz,
  created_at          timestamptz not null default now(),
  updated_at          timestamptz not null default now(),
  deleted_at          timestamptz
);

create index profiles_username_idx on public.profiles (username);
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
  price                numeric(10, 2) not null default 0,
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
  created_at  timestamptz not null default now()
);

create index transactions_user_idx on public.transactions (user_id);
create index transactions_type_idx on public.transactions (type);
create unique index transactions_one_pending_activation
  on public.transactions (user_id)
  where type = 'activation' and status = 'pending';

-- ---------------------------------------------------------------------
-- 03b // APP SETTINGS  (payment QR, account name, instructions)
-- ---------------------------------------------------------------------
create table public.app_settings (
  key         text primary key,
  value       text,
  updated_at  timestamptz not null default now()
);

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

-- ---------------------------------------------------------------------
-- 09 // SEED DEFAULT PACKAGES
-- ---------------------------------------------------------------------
insert into public.packages (name, description, price, commission_outright, commission_unilevel, tier) values
  ('Basic Starter',    'Entry-level 3-month access',          0.00,   10.00, 0,    'basic'),
  ('Standard Pro',     'Full 27-month access with unilevel',  100.00, 25.00, 5.00, 'standard'),
  ('Marketing Suite',  'Complete marketing service bundle',    199.00, 40.00, 8.00, 'standard'),
  ('AI Implementation','AI workflow automation package',       299.00, 60.00, 12.00,'standard');

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
