-- =====================================================================
-- KAMTEAM // Migration: admin roulette + business profile at sign-up
-- Run AFTER 20261118d_backfill_lifecycle.sql.
-- Safe to run on the LIVE database (no drops, no data loss).
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- 1) Business profile captured at registration
alter table public.profiles
  add column if not exists business_nature    text,
  add column if not exists business_struggles text[] not null default '{}',
  add column if not exists business_notes     text;

-- 2) Roulette: every Basic or Standard plan a user avails (an approved
--    activation) is handed to the next admin in a fixed rotation
--    (1, 2, 3, 1, 2, 3 ...). The transaction keeps who got that package;
--    the profile keeps who currently manages the account. Who *referred*
--    them is unchanged — that still lives in referred_by.
alter table public.transactions
  add column if not exists assigned_admin uuid references public.profiles (id) on delete set null;
create index if not exists transactions_assigned_admin_idx on public.transactions (assigned_admin);

alter table public.profiles
  add column if not exists assigned_admin uuid references public.profiles (id) on delete set null;
create index if not exists profiles_assigned_admin_idx on public.profiles (assigned_admin);

create table if not exists public.roulette_members (
  admin_id  uuid primary key references public.profiles (id) on delete cascade,
  position  int  not null,
  active    boolean not null default true
);
alter table public.roulette_members enable row level security;

create sequence if not exists public.roulette_seq start 0 minvalue 0;

-- Active members, in rotation order
create or replace function public.roulette_lineup()
returns table (admin_id uuid, slot int) language sql stable security definer set search_path = ''
as $$
  select m.admin_id, (row_number() over (order by m.position, m.admin_id))::int - 1
  from public.roulette_members m
  join public.profiles p on p.id = m.admin_id
  where m.active and p.role = 'admin' and p.deleted_at is null;
$$;

-- Takes the next turn (advances the rotation)
create or replace function public.roulette_next()
returns uuid language plpgsql security definer set search_path = ''
as $$
declare
  n    int;
  turn bigint;
  pick uuid;
begin
  select count(*) into n from public.roulette_lineup();
  if n = 0 then return null; end if;
  turn := nextval('public.roulette_seq');
  select admin_id into pick from public.roulette_lineup() where slot = (turn % n);
  return pick;
end;
$$;

-- Shows whose turn is next without advancing the rotation
create or replace function public.roulette_peek()
returns uuid language plpgsql stable security definer set search_path = ''
as $$
declare
  n     int;
  last  bigint;
  used  boolean;
  pick  uuid;
begin
  select count(*) into n from public.roulette_lineup();
  if n = 0 then return null; end if;
  select last_value, is_called into last, used from public.roulette_seq;
  select admin_id into pick from public.roulette_lineup()
   where slot = ((case when used then last + 1 else last end) % n);
  return pick;
end;
$$;

-- The app takes a turn (rpc/roulette_next) when an admin approves a plan.
drop trigger if exists profiles_assign_roulette on public.profiles;
drop function if exists public.assign_from_roulette();

-- Seed the rotation with the three admins: michael (1), azi (2), siedel (3)
insert into public.roulette_members (admin_id, position)
select id, coalesce(array_position(array['michael', 'azi', 'siedel'], username), 100)
from public.profiles
where role = 'admin' and deleted_at is null
on conflict (admin_id) do nothing;
