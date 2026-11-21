-- Linked accounts (Admin ⇄ its own Growth Partner account, Client ⇄ GP).
-- Re-runnable. Hardens profiles.linked_profile_id so the two IDs always
-- point at each other:
--   1) foreign key to profiles(id)  (on delete set null)
--   2) an account can't link to itself
--   3) one-to-one: no two accounts can claim the same partner
--   4) a trigger keeps the pair symmetric — set A → B and B → A follows;
--      unlink or relink A and B's old pointer back to A is cleared
--   5) backfill: one-sided links are completed

-- 1) Foreign key -----------------------------------------------------------
alter table public.profiles
  add column if not exists linked_profile_id uuid;

do $$
begin
  if not exists (
    select 1 from pg_constraint
     where conrelid = 'public.profiles'::regclass
       and contype  = 'f'
       and conkey   = array[(select attnum from pg_attribute
                              where attrelid = 'public.profiles'::regclass
                                and attname  = 'linked_profile_id')]::int2[]
  ) then
    alter table public.profiles
      add constraint profiles_linked_profile_id_fkey
      foreign key (linked_profile_id) references public.profiles (id) on delete set null;
  end if;
end $$;

-- 2) No self-links ----------------------------------------------------------
update public.profiles set linked_profile_id = null where linked_profile_id = id;
alter table public.profiles drop constraint if exists profiles_linked_not_self;
alter table public.profiles add constraint profiles_linked_not_self
  check (linked_profile_id is null or linked_profile_id <> id);

-- 5) Backfill one-sided links (before the unique index, so it only adds
--    the missing half where the partner is free).
update public.profiles b
   set linked_profile_id = a.id
  from public.profiles a
 where a.linked_profile_id = b.id
   and b.linked_profile_id is null
   and not exists (select 1 from public.profiles c
                    where c.linked_profile_id = a.id and c.id <> b.id);

-- 3) One-to-one ---------------------------------------------------------
do $$
begin
  if exists (select linked_profile_id from public.profiles
              where linked_profile_id is not null
              group by linked_profile_id having count(*) > 1) then
    raise notice 'Some accounts share the same linked partner — fix them, then re-run this migration to add the one-to-one index.';
  else
    create unique index if not exists profiles_linked_profile_id_one_to_one
      on public.profiles (linked_profile_id) where linked_profile_id is not null;
  end if;
end $$;

-- 4) Keep both sides in step ------------------------------------------------
create or replace function public.sync_linked_profile()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  -- The partner update below fires this trigger again; stop there.
  if pg_trigger_depth() > 1 then return new; end if;

  -- Unlinked or relinked: clear the old partner's pointer back to us.
  if tg_op = 'UPDATE' and old.linked_profile_id is not null
     and old.linked_profile_id is distinct from new.linked_profile_id then
    update public.profiles set linked_profile_id = null
     where id = old.linked_profile_id and linked_profile_id = new.id;
  end if;

  -- Linked: point the new partner back at us.
  if new.linked_profile_id is not null then
    update public.profiles set linked_profile_id = new.id
     where id = new.linked_profile_id
       and linked_profile_id is distinct from new.id;
  end if;
  return new;
end $$;

drop trigger if exists profiles_sync_linked on public.profiles;
create trigger profiles_sync_linked
  after insert or update of linked_profile_id on public.profiles
  for each row execute function public.sync_linked_profile();
