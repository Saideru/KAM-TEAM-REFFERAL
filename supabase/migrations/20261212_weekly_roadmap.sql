-- =====================================================================
-- KAMTEAM // Weekly roadmap + "nearly done" notices
--
-- Two things the roadmap needed once contracts went in front of it:
--
--   1. A project opens with WEEKLY tasks already on it, one per week of
--      the plan, filed under the milestone that week belongs to. Staff
--      rename, re-date or delete them; nobody starts from an empty board.
--   2. The client is told when a milestone — and the project — is nearly
--      done. notified_pct remembers the last threshold announced, so a
--      client hears "nearly done" once, not on every task.
--
-- Re-runnable. Run AFTER 20261211_contracts.sql.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

-- Which week of the plan a task belongs to (1-based). Null for anything
-- that is not part of the weekly cadence.
alter table public.client_tasks
  add column if not exists week_no int;

create index if not exists client_tasks_week_idx
  on public.client_tasks (project_id, week_no);

-- The highest completion percentage already announced to the client.
alter table public.client_projects
  add column if not exists notified_pct int not null default 0;
alter table public.project_milestones
  add column if not exists notified_pct int not null default 0;
