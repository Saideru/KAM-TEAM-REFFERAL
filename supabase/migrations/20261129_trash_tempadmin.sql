-- =====================================================================
-- Move the "Temp Admin" account (username: tempadmin) to the recycle bin
--
-- Same effect as the Trash button in Admin > Users: the row gets a
-- deleted_at timestamp and status 'suspended'. It can no longer sign in,
-- it disappears from All users, and it shows in Admin > Recycle bin,
-- where it can be restored or erased for good.
--
-- Also trashes a linked Growth Partner row if one was created for it
-- (e.g. tempadmin-gp), and takes it out of the roulette rotation so it
-- stops receiving new sign-ups.
--
-- Nothing is erased here. Re-runnable.
-- Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

-- What will be trashed (check this first if you like):
--   select id, username, full_name, role, referral_code, deleted_at
--     from public.profiles
--    where username = 'tempadmin'
--       or linked_profile_id = (select id from public.profiles where username = 'tempadmin');

do $$
declare
  v_temp uuid;
begin
  select id into v_temp from public.profiles where username = 'tempadmin';
  if v_temp is null then
    raise notice 'No tempadmin account found - nothing to do.';
    return;
  end if;

  -- The admin account itself, plus any Growth Partner row linked to it.
  update public.profiles
     set deleted_at = coalesce(deleted_at, now()),
         status     = 'suspended'
   where id = v_temp
      or (linked_profile_id = v_temp and role = 'growth_partner');

  -- Out of the roulette, so no new sign-ups are assigned to it.
  if exists (select 1 from information_schema.tables
              where table_schema = 'public' and table_name = 'roulette_members') then
    delete from public.roulette_members where admin_id = v_temp;
  end if;

  -- Same audit trail the Trash button leaves.
  insert into public.system_logs (action, entity_type, entity_id, summary)
  values ('soft_delete', 'profile', v_temp::text, 'Temp Admin (tempadmin) moved to the recycle bin');
end $$;
