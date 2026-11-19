-- =====================================================================
-- KAMTEAM // Migration: Affiliates vs Clients, terms re-acceptance,
--                       Client Work Documentation & Reporting (SOP-OPS-001)
-- Run AFTER 20261120_terms_consents.sql.
-- Safe to run on the LIVE database (no drops of data). Re-runnable.
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

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
