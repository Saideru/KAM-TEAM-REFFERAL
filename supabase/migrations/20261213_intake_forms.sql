-- =====================================================================
-- KAMTEAM // Client intake form responses
--
-- The client intake questionnaire lives in a Google Form; its answers
-- land in a Google Sheet. This brings those answers into the portal so
-- everyone can keep tabs on them in one place:
--
--   client          sees the form to fill, and their own answers once in
--   growth partner  sees the submissions from the clients they referred
--   staff / admin   see every submission, match it to an account and
--                   work it (new -> reviewed -> contacted -> closed)
--
-- Nothing is typed twice: a sync pulls the sheet's published CSV and
-- upserts by row_key, so re-syncing is safe and never duplicates.
--
-- Re-runnable. Run AFTER 20261212_weekly_roadmap.sql.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

create table if not exists public.intake_responses (
  id            uuid primary key default gen_random_uuid(),
  -- Fingerprint of the sheet row (timestamp + email + name): the same
  -- response can be pulled a hundred times and still be one row.
  row_key       text not null unique,
  submitted_at  timestamptz,

  -- The handful of answers worth having as columns, for lists and search
  full_name     text,
  company       text,
  job_title     text,
  email         text,
  phone         text,
  country       text,
  website       text,
  industry      text,
  packages_wanted text,
  budget_setup  text,
  budget_monthly text,
  preferred_start text,
  decision_maker  text,
  heard_from    text,
  partner_name  text,          -- "Referring partner's full name"
  partner_code  text,          -- "Partner ID / referral code"
  consult_when  text,

  -- Everything the form asked, exactly as the sheet holds it
  answers       jsonb not null default '{}'::jsonb,

  -- Who it belongs to, once matched
  client_id     uuid references public.profiles (id) on delete set null,
  partner_id    uuid references public.profiles (id) on delete set null,
  matched_by    text,          -- email | code | name | manual

  status        text not null default 'new'
                  check (status in ('new', 'reviewed', 'contacted', 'closed', 'spam')),
  note          text,
  handled_by    uuid references public.profiles (id) on delete set null,
  handled_at    timestamptz,
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);

create index if not exists intake_responses_status_idx   on public.intake_responses (status, submitted_at desc);
create index if not exists intake_responses_client_idx   on public.intake_responses (client_id);
create index if not exists intake_responses_partner_idx  on public.intake_responses (partner_id);
create index if not exists intake_responses_email_idx    on public.intake_responses (lower(email));

drop trigger if exists intake_responses_touch_updated_at on public.intake_responses;
create trigger intake_responses_touch_updated_at
  before update on public.intake_responses
  for each row execute function public.touch_updated_at();

alter table public.intake_responses enable row level security;

-- Where the form and its response sheet live. Admins change these in the
-- Intake forms panel; the CSV URL is the sheet's own export link.
insert into public.app_settings (key, value) values
  ('intake_form_url',  'https://forms.gle/XZBdzkNZqTEUXQzj7'),
  ('intake_sheet_url', 'https://docs.google.com/spreadsheets/d/16qswbVYXGOEuTXhZZ49QcIDfLZuYumOdIbQ9luDMUvE/edit?usp=sharing'),
  ('intake_csv_url',   'https://docs.google.com/spreadsheets/d/16qswbVYXGOEuTXhZZ49QcIDfLZuYumOdIbQ9luDMUvE/export?format=csv'),
  ('intake_synced_at', '')
on conflict (key) do nothing;
