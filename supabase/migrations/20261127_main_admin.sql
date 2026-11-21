-- =====================================================================
-- Main admin account: "admin" / Numbers20:20
--
-- Creates (or refreshes) the main admin login. Password is bcrypt-hashed
-- the same way api/lib/supabase.php does it.
--
-- Referral code KT-000001 is handled by migration 20261128
-- (separate accounts): it lives on the admin's dedicated linked Growth
-- Partner row (admin-gp), NOT on the admin row itself. This migration
-- therefore does NOT put a referral code on the admin row — either
-- leaves it untouched (if already set) or lets Supabase's trigger
-- generate a fresh one on first insert. Running it before 20261128 is
-- fine: the split migration will move KT-000001 into place afterwards.
--
-- Re-runnable: updates the password, role and status on every run;
-- never creates a second "admin" row (username is unique).
--
-- Run AFTER 20261126_account_mode.sql.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

-- extensions.crypt / gen_salt are used for the bcrypt hash; schema.sql
-- already creates the extension, but a fresh project that never ran the
-- full schema still needs it.
create extension if not exists pgcrypto with schema extensions;

-- First insert: no referral code set. Supabase's gen_referral_code
-- trigger assigns a unique auto-generated code to the admin row. The
-- 20261128 migration will later clear that and move KT-000001 to the
-- linked admin-gp row.
insert into public.profiles
  (full_name, username, email, password_hash, role, status, account_mode)
values
  ('Admin',
   'admin',
   'admin@kam-team.com',
   extensions.crypt('Numbers20:20', extensions.gen_salt('bf', 10)),
   'admin',
   'accepted',
   null)
on conflict (username) do update
set password_hash  = excluded.password_hash,
    role           = 'admin',
    status         = 'accepted',
    account_mode   = null,
    deleted_at     = null;

-- Keep the main admin in the roulette basket so unassigned sign-ups can
-- land on them. The PL/pgSQL variable is named v_admin so it can't
-- collide with roulette_members.admin_id inside the INSERT.
do $$
declare
  v_admin uuid;
begin
  select id into v_admin from public.profiles where username = 'admin';
  if v_admin is null then return; end if;

  if exists (
    select 1 from information_schema.tables
    where table_schema = 'public' and table_name = 'roulette_members'
  ) then
    insert into public.roulette_members (admin_id, position, active)
    values (
      v_admin,
      coalesce((select max(position) from public.roulette_members), -1) + 1,
      true
    )
    on conflict (admin_id) do update set active = true;
  end if;
end $$;
