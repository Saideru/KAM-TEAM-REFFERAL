-- =====================================================================
-- One account = Client + Growth Partner
--
-- Sign-up no longer asks for an account type. Every member account is
-- both:
--   * a Client      — permanent, buys service packages (no expiry);
--   * a Growth Partner — refers clients, limited by the Basic/Standard
--     plan they choose after an admin approves the application
--     (affiliate_tier + lifecycle_expires_at on the same row).
--
-- The separate 'affiliate' role is therefore retired: those accounts
-- become 'client' and keep their tier, expiry, referrals and commissions.
-- Run AFTER 20261122_staff_role.sql. Re-runnable.
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

update public.profiles set role = 'client' where role = 'affiliate';

alter table public.profiles alter column role set default 'client';
alter table public.profiles drop constraint if exists profiles_role_check;
alter table public.profiles add constraint profiles_role_check
  check (role in ('admin', 'staff', 'client'));

update public.notifications set audience = 'client' where audience = 'affiliate';
alter table public.notifications drop constraint if exists notifications_audience_check;
alter table public.notifications add constraint notifications_audience_check
  check (audience in ('all', 'admin', 'staff', 'client'));
