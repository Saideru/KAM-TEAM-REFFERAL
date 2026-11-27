-- =====================================================================
-- KAMTEAM // Client onboarding contract pack + e-signature
--
-- The path a client takes to avail a service, end to end:
--
--   1. Client picks a service package and opens the agreement
--   2. Client completes their side (party details, intake, access
--      authorisations), e-signs it and SENDS IT TO THE ADMIN
--   3. Admin reviews, completes the commercial/legal terms left open in
--      the order form, and counter-signs  -> approved
--   4. The 50% advance deposit unlocks for that package
--   5. Approving the deposit opens the project + roadmap (20261210)
--
-- One row is one contract pack: master service agreement + order form +
-- the selected service schedule + onboarding authorisation + both
-- signatures, frozen at signing.
--
-- Re-runnable. Run AFTER 20261210_projects.sql.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

create sequence if not exists public.contract_seq;

create table if not exists public.contracts (
  id            uuid primary key default gen_random_uuid(),
  reference     text not null unique
                  default ('KT-C-' || to_char(now(), 'YYYY') || '-' || lpad(nextval('public.contract_seq')::text, 4, '0')),
  client_id     uuid not null references public.profiles (id) on delete cascade,
  package_id    uuid references public.packages (id) on delete set null,
  project_id    uuid references public.client_projects (id) on delete set null,

  status        text not null default 'draft'
                  check (status in ('draft', 'submitted', 'approved', 'declined', 'void')),

  -- ---- Order form: money (figures come from the package at signing) ----
  setup_fee     numeric(10, 2) not null default 0,
  monthly_fee   numeric(10, 2) not null default 0,
  down_payment  numeric(10, 2) not null default 0,   -- the 50% advance
  setup_balance numeric(10, 2) not null default 0,

  -- ---- Order form: terms the admin completes before counter-signing ----
  balance_due_terms   text,
  billing_start       text,
  taxes_payment       text,
  kickoff_date        date,
  target_launch       date,
  deliverable_limits  text,
  acceptance_criteria text,
  content_cadence     text,
  support_terms       text,
  term_renewal        text,
  refund_exit         text,
  ownership_terms     text,
  liability_terms     text,
  governing_law       text,
  package_exclusions  text,

  -- ---- The parties ----
  kam_legal_name    text,
  kam_address       text,
  client_legal_name text,
  client_address    text,
  client_rep        text,          -- authorised representative / title
  client_email      text,
  client_phone      text,

  -- ---- Onboarding authorisation (04 / client intake and access) ----
  business_profile    text,        -- business name / website / industry
  contacts            text,        -- primary contact / decision maker / billing
  audience_goals      text,
  brand_assets        text,
  existing_platforms  text,
  access_ack          jsonb not null default '{}'::jsonb,
  access_restrictions text,

  -- ---- Frozen at signing ----
  schedule      jsonb,             -- the selected service schedule, as signed
  -- The Growth Partner who referred this client, if any.
  referred_by_name text,
  -- Who at KAM TEAM has actually dealt with this client: the assigned
  -- staff, the project lead and everyone they exchanged messages with
  -- (api/lib/chat.php). Snapshotted here so the signed pack records it.
  kam_contacts  jsonb not null default '[]'::jsonb,

  -- ---- E-signatures ----
  client_signature  text,          -- data URI (PNG drawn in the browser)
  client_signed_name  text,        -- typed full legal name
  client_signed_title text,
  client_signed_at  timestamptz,
  client_sign_ip    text,
  client_sign_agent text,

  kam_signature     text,          -- data URI
  kam_signed_name   text,
  kam_signed_title  text,
  kam_signed_at     timestamptz,
  kam_signed_by     uuid references public.profiles (id) on delete set null,

  decline_note  text,
  reviewed_by   uuid references public.profiles (id) on delete set null,
  reviewed_at   timestamptz,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index if not exists contracts_client_idx  on public.contracts (client_id, status);
create index if not exists contracts_status_idx  on public.contracts (status, created_at desc);
create index if not exists contracts_package_idx on public.contracts (client_id, package_id);

-- One live contract per client + package: a second one can only exist
-- once the first is declined or voided.
create unique index if not exists contracts_one_live_per_package
  on public.contracts (client_id, package_id)
  where status in ('draft', 'submitted', 'approved');

drop trigger if exists contracts_touch_updated_at on public.contracts;
create trigger contracts_touch_updated_at
  before update on public.contracts
  for each row execute function public.touch_updated_at();

-- A signed agreement is what unlocks the deposit, so the project a
-- deposit opens can point back at the contract it came from.
alter table public.client_projects
  add column if not exists contract_id uuid references public.contracts (id) on delete set null;

alter table public.contracts enable row level security;
