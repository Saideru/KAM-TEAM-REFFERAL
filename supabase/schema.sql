-- =====================================================================
-- KAMTEAM // NETWORK MARKETING & REFERRAL PORTAL — Supabase schema v3
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- ---------------------------------------------------------------------
-- 01 // PROFILES  (extends auth.users)
-- Roles: admin | client_basic | client_standard | referral
-- Status: pending | accepted | suspended
-- ---------------------------------------------------------------------
drop table if exists public.system_logs cascade;
drop table if exists public.transactions cascade;
drop table if exists public.referrals cascade;
drop table if exists public.client_services cascade;
drop table if exists public.packages cascade;
drop table if exists public.profiles cascade;

create table public.profiles (
  id                  uuid primary key references auth.users (id) on delete cascade,
  full_name           text not null,
  email               text not null unique,
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
  created_at           timestamptz not null default now()
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
  created_at  timestamptz not null default now()
);

create index transactions_user_idx on public.transactions (user_id);
create index transactions_type_idx on public.transactions (type);

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
create or replace function public.is_admin()
returns boolean
language sql stable security definer set search_path = ''
as $$
  select exists (
    select 1 from public.profiles
    where id = auth.uid() and role = 'admin' and status = 'accepted' and deleted_at is null
  );
$$;

create or replace function public.user_role()
returns text
language sql stable security definer set search_path = ''
as $$
  select role from public.profiles where id = auth.uid() and deleted_at is null;
$$;

create or replace function public.user_status()
returns text
language sql stable security definer set search_path = ''
as $$
  select status from public.profiles where id = auth.uid() and deleted_at is null;
$$;

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

-- Handle new user registration
create or replace function public.handle_new_user()
returns trigger language plpgsql security definer set search_path = ''
as $$
declare
  new_code   text;
  referrer   uuid;
  new_role   text;
begin
  loop
    new_code := 'KT-' || upper(substr(md5(random()::text || clock_timestamp()::text), 1, 6));
    exit when not exists (select 1 from public.profiles where referral_code = new_code);
  end loop;

  if coalesce(new.raw_user_meta_data ->> 'ref', '') <> '' then
    select id into referrer
    from public.profiles
    where referral_code = upper(trim(new.raw_user_meta_data ->> 'ref'))
      and deleted_at is null;
  end if;

  new_role := coalesce(nullif(new.raw_user_meta_data ->> 'role', ''), 'referral');
  if new_role not in ('client_basic', 'client_standard', 'referral') then
    new_role := 'referral';
  end if;

  insert into public.profiles (id, full_name, email, phone, company, referral_code, referred_by, role, status)
  values (
    new.id,
    coalesce(nullif(new.raw_user_meta_data ->> 'full_name', ''), split_part(new.email, '@', 1)),
    new.email,
    nullif(new.raw_user_meta_data ->> 'phone', ''),
    nullif(new.raw_user_meta_data ->> 'company', ''),
    new_code,
    referrer,
    new_role,
    'pending'
  );

  -- Create referral link record if referred by someone
  if referrer is not null then
    insert into public.referrals (referrer_id, referred_id, status)
    values (referrer, new.id, 'pending');
  end if;

  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

-- ---------------------------------------------------------------------
-- 08 // ROW LEVEL SECURITY
-- ---------------------------------------------------------------------
alter table public.profiles        enable row level security;
alter table public.referrals       enable row level security;
alter table public.client_services enable row level security;
alter table public.packages        enable row level security;
alter table public.transactions    enable row level security;
alter table public.system_logs     enable row level security;

-- Profiles
create policy "profiles_select" on public.profiles
  for select to authenticated
  using (id = auth.uid() or (select public.is_admin()));

create policy "profiles_update_own" on public.profiles
  for update to authenticated
  using (id = auth.uid())
  with check (id = auth.uid());

create policy "profiles_admin_update" on public.profiles
  for update to authenticated
  using ((select public.is_admin()))
  with check ((select public.is_admin()));

revoke update on public.profiles from authenticated;
grant update (full_name, phone, company, role, status, lifecycle_expires_at, deleted_at) on public.profiles to authenticated;

-- Packages: everyone reads active, admins manage
create policy "packages_select" on public.packages
  for select to authenticated using (true);

create policy "packages_admin_insert" on public.packages
  for insert to authenticated with check ((select public.is_admin()));

create policy "packages_admin_update" on public.packages
  for update to authenticated
  using ((select public.is_admin())) with check ((select public.is_admin()));

create policy "packages_admin_delete" on public.packages
  for delete to authenticated using ((select public.is_admin()));

-- Transactions: own or admin
create policy "transactions_select" on public.transactions
  for select to authenticated
  using (user_id = auth.uid() or (select public.is_admin()));

create policy "transactions_insert" on public.transactions
  for insert to authenticated
  with check (user_id = auth.uid() or (select public.is_admin()));

create policy "transactions_admin_update" on public.transactions
  for update to authenticated
  using ((select public.is_admin())) with check ((select public.is_admin()));

-- Referrals: own or admin
create policy "referrals_select" on public.referrals
  for select to authenticated
  using (referrer_id = auth.uid() or referred_id = auth.uid() or (select public.is_admin()));

create policy "referrals_admin_insert" on public.referrals
  for insert to authenticated with check (true);

create policy "referrals_admin_update" on public.referrals
  for update to authenticated
  using ((select public.is_admin())) with check ((select public.is_admin()));

-- Client services
create policy "client_services_select" on public.client_services
  for select to authenticated
  using (client_id = auth.uid() or (select public.is_admin()));

create policy "client_services_admin_insert" on public.client_services
  for insert to authenticated with check ((select public.is_admin()));

create policy "client_services_admin_update" on public.client_services
  for update to authenticated
  using ((select public.is_admin())) with check ((select public.is_admin()));

create policy "client_services_admin_delete" on public.client_services
  for delete to authenticated using ((select public.is_admin()));

-- System logs: admins only (insert open for logging functions)
create policy "system_logs_admin_select" on public.system_logs
  for select to authenticated using ((select public.is_admin()));

create policy "system_logs_insert" on public.system_logs
  for insert to authenticated with check (true);

-- ---------------------------------------------------------------------
-- 09 // SEED DEFAULT PACKAGES
-- ---------------------------------------------------------------------
insert into public.packages (name, description, price, commission_outright, commission_unilevel, tier) values
  ('Basic Starter',    'Entry-level 3-month access',          49.00,  10.00, 0,    'basic'),
  ('Standard Pro',     'Full 27-month access with unilevel',  100.00, 25.00, 5.00, 'standard'),
  ('Marketing Suite',  'Complete marketing service bundle',    199.00, 40.00, 8.00, 'standard'),
  ('AI Implementation','AI workflow automation package',       299.00, 60.00, 12.00,'standard');

-- ---------------------------------------------------------------------
-- 10 // ADMIN ACCOUNTS — Run seed-admins.php after schema setup
-- Admins: Michael, Azi, Siedel (password: Numbers 2020)
-- They are created via the Supabase Auth API in seed-admins.php,
-- then promoted here. Run this AFTER seed-admins.php:
-- ---------------------------------------------------------------------
-- UPDATE public.profiles SET role = 'admin', status = 'accepted' WHERE email IN ('michael@kam-team.com', 'azi@kam-team.com', 'siedel@kam-team.com');
