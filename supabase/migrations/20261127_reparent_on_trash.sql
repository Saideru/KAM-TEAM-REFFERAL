-- Track which downline accounts were reparented when a GP is trashed,
-- so restoring the GP can undo the reparenting.
-- Column holds a JSON array of {id, old_referred_by} objects, or null.
alter table public.profiles add column if not exists reparented_children jsonb;
