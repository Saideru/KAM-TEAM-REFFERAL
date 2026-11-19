-- =====================================================================
-- Remove the retired service bundles "Marketing Suite" and
-- "AI Implementation" (hidden since 20261119_kam_service_packages.sql).
-- The Growth Partner plans (Basic Starter, Standard Pro) are kept.
--
-- Past transactions / tasks / reports that pointed at these bundles keep
-- their row: the package link is cleared (on delete set null), and the
-- bundle name is copied into the transaction notes first so the history
-- still says what was bought. Re-runnable.
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

update public.transactions t
set notes = trim(both ' ' from coalesce(t.notes, '') || ' [Package: ' || p.name || ']')
from public.packages p
where t.package_id = p.id
  and p.name in ('Marketing Suite', 'AI Implementation')
  and coalesce(t.notes, '') not like ('%[Package: ' || p.name || ']%');

delete from public.packages
where name in ('Marketing Suite', 'AI Implementation');
