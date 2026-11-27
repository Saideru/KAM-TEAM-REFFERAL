-- =====================================================================
-- KAMTEAM // Projects & roadmaps
--
-- When an admin approves a client's 50% advance deposit, that package
-- becomes a PROJECT with a starting ROADMAP of milestones. The staff
-- member assigned to the client leads it, fills in the milestones and
-- hangs tasks off them; the client watches the same board from their
-- own dashboard and can request work on it.
--
--   client_projects    one per approved package (per deposit)
--   project_milestones the roadmap: ordered phases, each with a status
--   client_tasks       gains project_id / milestone_id, so the existing
--                      SOP-OPS-001 task record is what fills a milestone
--   project_requests   what the client asks for; staff accept a request
--                      and it becomes a task on the roadmap
--
-- Nothing here replaces the task tracker — it groups it.
--
-- Re-runnable. Run AFTER 20261209_chat.sql.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1 // PROJECTS
-- ---------------------------------------------------------------------
create table if not exists public.client_projects (
  id             uuid primary key default gen_random_uuid(),
  client_id      uuid not null references public.profiles (id) on delete cascade,
  package_id     uuid references public.packages (id) on delete set null,
  -- The approved deposit that opened this project. Unique, so approving
  -- the same payment twice can never create a second project.
  deposit_txn_id uuid references public.transactions (id) on delete set null,
  name           text not null,
  summary        text,
  status         text not null default 'planning'
                   check (status in ('planning', 'active', 'on_hold', 'completed', 'cancelled')),
  start_date     date not null default current_date,
  target_date    date,
  lead_staff_id  uuid references public.profiles (id) on delete set null,
  created_by     uuid references public.profiles (id) on delete set null,
  created_at     timestamptz not null default now(),
  updated_at     timestamptz not null default now()
);

create index if not exists client_projects_client_idx on public.client_projects (client_id, status);
create index if not exists client_projects_lead_idx   on public.client_projects (lead_staff_id);
create unique index if not exists client_projects_one_per_deposit
  on public.client_projects (deposit_txn_id) where deposit_txn_id is not null;

-- ---------------------------------------------------------------------
-- 2 // MILESTONES  (the roadmap itself — ordered by position)
-- ---------------------------------------------------------------------
create table if not exists public.project_milestones (
  id           uuid primary key default gen_random_uuid(),
  project_id   uuid not null references public.client_projects (id) on delete cascade,
  title        text not null,
  detail       text,
  position     int  not null default 0,
  status       text not null default 'not_started'
                 check (status in ('not_started', 'in_progress', 'for_review', 'completed', 'blocked', 'on_hold', 'cancelled')),
  start_date   date,
  target_date  date,
  completed_on date,
  owner        text,          -- a name tag, like the task record's roles
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);

create index if not exists project_milestones_project_idx
  on public.project_milestones (project_id, position);

-- ---------------------------------------------------------------------
-- 3 // TASKS belong to a milestone
--     The free-text client_tasks.milestone column stays and is kept in
--     step with the milestone title, so existing reports keep working.
-- ---------------------------------------------------------------------
alter table public.client_tasks
  add column if not exists project_id   uuid references public.client_projects (id) on delete set null;
alter table public.client_tasks
  add column if not exists milestone_id uuid references public.project_milestones (id) on delete set null;
-- Who asked for the task: KAM TEAM, or the client (an accepted request).
alter table public.client_tasks
  add column if not exists source text not null default 'team';
alter table public.client_tasks drop constraint if exists client_tasks_source_check;
alter table public.client_tasks add constraint client_tasks_source_check
  check (source in ('team', 'client'));

create index if not exists client_tasks_project_idx   on public.client_tasks (project_id);
create index if not exists client_tasks_milestone_idx on public.client_tasks (milestone_id);

-- ---------------------------------------------------------------------
-- 4 // CLIENT REQUESTS  (the client's own "set a task" on the roadmap)
--     A request is a question, not a commitment: staff accept it and it
--     becomes a real task, or decline it with a reason.
-- ---------------------------------------------------------------------
create table if not exists public.project_requests (
  id            uuid primary key default gen_random_uuid(),
  project_id    uuid not null references public.client_projects (id) on delete cascade,
  milestone_id  uuid references public.project_milestones (id) on delete set null,
  client_id     uuid not null references public.profiles (id) on delete cascade,
  title         text not null,
  detail        text,
  status        text not null default 'open'
                  check (status in ('open', 'accepted', 'declined', 'withdrawn')),
  decision_note text,
  decided_by    uuid references public.profiles (id) on delete set null,
  decided_at    timestamptz,
  task_id       uuid references public.client_tasks (id) on delete set null,
  created_at    timestamptz not null default now()
);

create index if not exists project_requests_project_idx on public.project_requests (project_id, status);
create index if not exists project_requests_client_idx  on public.project_requests (client_id, status);

-- ---------------------------------------------------------------------
-- 5 // Housekeeping
-- ---------------------------------------------------------------------
drop trigger if exists client_projects_touch_updated_at on public.client_projects;
create trigger client_projects_touch_updated_at
  before update on public.client_projects
  for each row execute function public.touch_updated_at();

drop trigger if exists project_milestones_touch_updated_at on public.project_milestones;
create trigger project_milestones_touch_updated_at
  before update on public.project_milestones
  for each row execute function public.touch_updated_at();

-- Access is decided in PHP with the service key, as everywhere else in
-- this app: RLS on, no anon policies.
alter table public.client_projects    enable row level security;
alter table public.project_milestones enable row level security;
alter table public.project_requests   enable row level security;
