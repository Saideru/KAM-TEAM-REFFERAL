-- =====================================================================
-- Staff / employee accounts
--
-- Admins register staff directly from the admin dashboard (no application,
-- no program terms, no expiry). Staff work in the Client work area
-- (/work.php): they keep the task log and draft reports; approving and
-- delivering a client report stays with admins.
-- =====================================================================

alter table public.profiles add column if not exists job_title text;

alter table public.profiles drop constraint if exists profiles_role_check;
alter table public.profiles add constraint profiles_role_check
  check (role in ('admin', 'staff', 'client', 'affiliate'));

alter table public.notifications drop constraint if exists notifications_audience_check;
alter table public.notifications add constraint notifications_audience_check
  check (audience in ('all', 'admin', 'staff', 'client', 'affiliate'));
