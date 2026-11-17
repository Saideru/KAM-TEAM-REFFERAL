-- =====================================================================
-- KAMTEAM // Migration: backfill missing client lifecycles
-- Run AFTER 20261118c_kyc_commission.sql.
-- Safe to run on the LIVE database (no drops, no data loss).
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- Editing a user in the admin panel used to write lifecycle_expires_at =
-- null whenever the expiry box was left blank, so changing a phone number
-- silently gave a Basic client unlimited access. The PHP side now leaves a
-- blank box alone; this repairs the accounts it already cleared.
--
-- The clock is measured from created_at, so a Basic account older than
-- 3 months lands already expired. That is the intended meaning of the
-- limit — extend individual accounts from the admin Users tab if needed.

update public.profiles
set lifecycle_expires_at = created_at + interval '3 months'
where role = 'client_basic'
  and status = 'accepted'
  and lifecycle_expires_at is null
  and deleted_at is null;

update public.profiles
set lifecycle_expires_at = created_at + interval '27 months'
where role = 'client_standard'
  and status = 'accepted'
  and lifecycle_expires_at is null
  and deleted_at is null;

-- Check what the backfill did:
--   select username, role, created_at, lifecycle_expires_at,
--          lifecycle_expires_at < now() as already_expired
--   from public.profiles
--   where role in ('client_basic', 'client_standard') and deleted_at is null
--   order by lifecycle_expires_at;
