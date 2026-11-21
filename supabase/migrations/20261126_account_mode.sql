-- =====================================================================
-- Account mode — which side a member picks at registration
--
-- Every member row still carries both sides (Client + Growth Partner),
-- but account_mode decides which one is "open" after sign-up:
--
--   'client'     Default. Client dashboard is primary. The Growth Partner
--                side exists and activates when a Basic/Standard plan is
--                picked (the plan gate, Manual §1.3). This matches the
--                behaviour every existing account already has.
--
--   'affiliate'  Only the Growth Partner side is open. The Client
--                dashboard is dormant until the member opts in from
--                /dashboard-affiliate.php (Activate Client account),
--                which flips this column back to 'client'.
--
-- Admins and staff never need a mode; the column stays null for them.
-- Run AFTER 20261125_admin_unlimited_partner.sql. Re-runnable.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

alter table public.profiles
  add column if not exists account_mode text;

alter table public.profiles drop constraint if exists profiles_account_mode_check;
alter table public.profiles add constraint profiles_account_mode_check
  check (account_mode is null or account_mode in ('client', 'affiliate'));

-- Everyone that existed before this migration kept the current flow:
-- Client dashboard primary, Growth Partner behind the plan gate.
update public.profiles
   set account_mode = 'client'
 where role = 'client' and account_mode is null;

create index if not exists profiles_account_mode_idx
  on public.profiles (account_mode);
