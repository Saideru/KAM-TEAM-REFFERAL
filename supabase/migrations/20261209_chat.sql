-- =====================================================================
-- KAMTEAM // In-app chat + staff assignments
--
-- Who may talk to whom (enforced in PHP, api/lib/chat.php):
--
--   Admin           -> Client, Staff, Growth Partner          (always)
--   Staff           -> Admin                                  (always)
--   Staff           -> Client, Growth Partner                 (if assigned)
--   Client          -> Admin                                  (always)
--   Client          -> Staff                                  (if assigned)
--   Growth Partner  -> Admin                                  (always)
--   Growth Partner  -> Staff                                  (if assigned)
--
-- "If assigned" means a staff_assignments row created by an admin: an
-- admin decides which staff member serves which client / growth partner,
-- and only that pair can open a conversation.
--
-- Re-runnable. Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1 // STAFF ASSIGNMENTS  (admin assigns a staff member to a member)
--     member_id is a client or growth_partner profile row.
-- ---------------------------------------------------------------------
create table if not exists public.staff_assignments (
  id          uuid primary key default gen_random_uuid(),
  staff_id    uuid not null references public.profiles (id) on delete cascade,
  member_id   uuid not null references public.profiles (id) on delete cascade,
  assigned_by uuid references public.profiles (id) on delete set null,
  note        text,
  created_at  timestamptz not null default now(),
  constraint staff_assignments_not_self check (staff_id <> member_id)
);

create unique index if not exists staff_assignments_pair_idx
  on public.staff_assignments (staff_id, member_id);
create index if not exists staff_assignments_member_idx
  on public.staff_assignments (member_id);

-- ---------------------------------------------------------------------
-- 2 // CHAT THREADS  (exactly two participants, stored in a fixed order
--     so one pair can never end up with two threads)
-- ---------------------------------------------------------------------
create table if not exists public.chat_threads (
  id              uuid primary key default gen_random_uuid(),
  a_id            uuid not null references public.profiles (id) on delete cascade,
  b_id            uuid not null references public.profiles (id) on delete cascade,
  last_message_at timestamptz,
  created_at      timestamptz not null default now(),
  constraint chat_threads_ordered check (a_id < b_id)
);

create unique index if not exists chat_threads_pair_idx
  on public.chat_threads (a_id, b_id);
create index if not exists chat_threads_a_idx on public.chat_threads (a_id, last_message_at desc);
create index if not exists chat_threads_b_idx on public.chat_threads (b_id, last_message_at desc);

-- ---------------------------------------------------------------------
-- 3 // CHAT MESSAGES
-- ---------------------------------------------------------------------
create table if not exists public.chat_messages (
  id         uuid primary key default gen_random_uuid(),
  thread_id  uuid not null references public.chat_threads (id) on delete cascade,
  sender_id  uuid not null references public.profiles (id) on delete set null,
  body       text not null check (length(btrim(body)) > 0),
  read_at    timestamptz,
  created_at timestamptz not null default now()
);

create index if not exists chat_messages_thread_idx
  on public.chat_messages (thread_id, created_at desc);
-- Unread badge: "messages in my threads, not sent by me, not read yet".
create index if not exists chat_messages_unread_idx
  on public.chat_messages (thread_id, sender_id) where read_at is null;

-- A new message moves its thread to the top of everyone's contact list.
create or replace function public.chat_touch_thread()
returns trigger language plpgsql as $$
begin
  update public.chat_threads
     set last_message_at = new.created_at
   where id = new.thread_id;
  return new;
end $$;

drop trigger if exists chat_messages_touch_thread on public.chat_messages;
create trigger chat_messages_touch_thread
  after insert on public.chat_messages
  for each row execute function public.chat_touch_thread();

-- Access is decided in PHP with the service key, as everywhere else in
-- this app: RLS on, no anon policies, so the tables are closed to the
-- anon key.
alter table public.staff_assignments enable row level security;
alter table public.chat_threads      enable row level security;
alter table public.chat_messages     enable row level security;
