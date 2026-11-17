-- =====================================================================
-- KAMTEAM // Migration: hand existing accounts to the roulette
-- Run AFTER 20261118e_roulette_business.sql.
-- Safe to run on the LIVE database (no drops, no data loss).
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- The roulette now assigns an admin when an ACCOUNT is approved rather
-- than when a package is bought, and that admin sticks with the account
-- for good. Accounts approved before this change have no owner, so they
-- would never appear on anyone's list. This deals them out round-robin,
-- oldest first, using the same sequence the live rotation uses so the
-- running order carries on uninterrupted.

do $$
declare
  n    int;
  r    record;
  turn bigint;
  pick uuid;
  cnt  int := 0;
begin
  select count(*) into n from public.roulette_lineup();
  if n = 0 then
    raise notice 'Rotation is empty — add admins first, then re-run this.';
    return;
  end if;

  for r in
    select id from public.profiles
    where role <> 'admin'
      and status = 'accepted'
      and assigned_admin is null
      and deleted_at is null
    order by created_at
  loop
    turn := nextval('public.roulette_seq');
    select admin_id into pick from public.roulette_lineup() where slot = (turn % n);
    update public.profiles set assigned_admin = pick where id = r.id;
    cnt := cnt + 1;
  end loop;

  raise notice 'Assigned % account(s) across % admin(s).', cnt, n;
end $$;

-- Packages follow their account's owner.
update public.transactions t
set assigned_admin = p.assigned_admin
from public.profiles p
where p.id = t.user_id
  and t.type = 'activation'
  and t.status = 'completed'
  and t.assigned_admin is null
  and p.assigned_admin is not null;

-- Check the spread:
--   select a.full_name as admin, count(*) as accounts
--   from public.profiles c
--   join public.profiles a on a.id = c.assigned_admin
--   where c.deleted_at is null
--   group by a.full_name order by accounts desc;
