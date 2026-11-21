-- =====================================================================
-- Four account types, with linking
--
-- Before this migration every member was one row with two sides
-- (Client + Growth Partner). This migration separates the account
-- TYPES so each is its own row:
--
--   admin            KAM TEAM runs the program
--   staff            KAM TEAM's own people, client work only
--   client           buys service packages, permanent
--   growth_partner   refers clients, earns commission, has a term
--
-- A single person can own more than one row (e.g. an admin who also
-- refers clients, or a client who also wants to earn commission).
-- Linked rows share a key: profiles.linked_profile_id points at the
-- "primary" row, and the primary row points back. Session switching
-- flips between them without re-authenticating.
--
-- Scope of this migration:
--   (1) schema support — the role, the column, the index
--   (2) the main admin is separated right now: its referral slot
--       (KT-000001, Unlimited) moves to a new dedicated Growth Partner
--       row linked to the admin
--
-- Legacy dual-side member rows (role='client' with an affiliate_tier
-- set) are LEFT AS-IS and keep working. Converting them to separate
-- rows is a follow-up migration.
--
-- Re-runnable. Run AFTER 20261127_main_admin.sql.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

-- 1) The new role --------------------------------------------------------
alter table public.profiles drop constraint if exists profiles_role_check;
alter table public.profiles add constraint profiles_role_check
  check (role in ('admin', 'staff', 'client', 'growth_partner'));

-- Broadcasts to growth partners address the same audience.
alter table public.notifications drop constraint if exists notifications_audience_check;
alter table public.notifications add constraint notifications_audience_check
  check (audience in ('all', 'admin', 'staff', 'client', 'growth_partner'));

-- 2) referral_code: nullable, so admin/staff/client rows carry no code --
alter table public.profiles alter column referral_code drop not null;

-- 3) Link column ---------------------------------------------------------
alter table public.profiles
  add column if not exists linked_profile_id uuid
    references public.profiles (id) on delete set null;
create index if not exists profiles_linked_profile_id_idx
  on public.profiles (linked_profile_id);

-- 4) Main admin split ----------------------------------------------------
-- Give the admin a dedicated Growth Partner row (KT-000001, Unlimited).
-- Linked both ways. Password is shared so one sign-in reaches both.
do $$
declare
  v_admin   uuid;
  v_gp      uuid;
  v_pwhash  text;
begin
  select id, password_hash into v_admin, v_pwhash
    from public.profiles
   where username = 'admin' and role = 'admin';
  if v_admin is null then return; end if;

  -- Already got a linked GP from a previous run?
  select id into v_gp
    from public.profiles
   where linked_profile_id = v_admin and role = 'growth_partner'
   limit 1;

  if v_gp is null then
    -- Make space for KT-000001: if the admin row still has it, clear it
    -- first so the unique index is free for the new GP row.
    update public.profiles
       set referral_code = null,
           affiliate_tier = null,
           lifecycle_expires_at = null
     where id = v_admin;

    insert into public.profiles
      (full_name, username, email, password_hash, role, status,
       referral_code, affiliate_tier, lifecycle_expires_at,
       linked_profile_id, account_mode)
    values
      ('Admin — Growth Partner',
       'admin-gp',
       'admin-gp@kam-team.com',
       v_pwhash,
       'growth_partner',
       'accepted',
       'KT-000001',
       'unlimited',
       null,
       v_admin,
       null)
    returning id into v_gp;
  else
    -- Keep the existing linked row canonical on every re-run.
    update public.profiles
       set referral_code       = 'KT-000001',
           affiliate_tier      = 'unlimited',
           lifecycle_expires_at = null,
           status              = 'accepted',
           password_hash       = v_pwhash,
           deleted_at          = null
     where id = v_gp;
  end if;

  -- Point the admin row back at its GP twin, and make sure its own
  -- affiliate fields are cleared (the admin row is admin-only now).
  update public.profiles
     set linked_profile_id  = v_gp,
         referral_code      = null,
         affiliate_tier     = null,
         lifecycle_expires_at = null
   where id = v_admin;
end $$;
