-- =====================================================================
-- Admins get a Growth Partner account that never expires
--
-- Every admin can refer clients with their own referral code, like any
-- member, but their partner side has no term: tier 'unlimited' with
-- lifecycle_expires_at left null. Commission is paid the normal way;
-- there is nothing to renew and no grace period.
-- Run AFTER 20261124_unified_member_accounts.sql. Re-runnable.
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

alter table public.profiles drop constraint if exists profiles_affiliate_tier_check;
alter table public.profiles add constraint profiles_affiliate_tier_check
  check (affiliate_tier is null or affiliate_tier in ('basic', 'standard', 'unlimited'));

update public.profiles
set affiliate_tier = 'unlimited', lifecycle_expires_at = null
where role = 'admin' and affiliate_tier is distinct from 'unlimited';

-- Every profile already gets a referral_code from the gen_referral_code
-- trigger, so admins created before this migration keep theirs.
