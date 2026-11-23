-- Who approved what + Fully paid clients — re-runnable.
--
-- 1) profiles.approved_by / approved_at — who approved the account
--    application (admin or referral accounts).
-- 2) system_logs.actor_name — the actor's full name, saved with every log
--    line so it stays readable even if the account is renamed or deleted.
-- 3) transactions.balance_* — on a client's approved 50% deposit: the
--    remaining 50% marked paid (when, by whom, reference, amount).

-- 1) Account approvals ------------------------------------------------------
alter table public.profiles add column if not exists approved_by uuid
  references public.profiles (id) on delete set null;
alter table public.profiles add column if not exists approved_at timestamptz;

-- Backfill from the audit log (latest approval per account).
update public.profiles p
   set approved_by = l.actor_id,
       approved_at = l.created_at
  from (select distinct on (entity_id) entity_id, actor_id, created_at
          from public.system_logs
         where action = 'approve_application' and entity_id is not null
         order by entity_id, created_at desc) l
 where p.id::text = l.entity_id
   and p.approved_by is null;

-- 2) Actor names on logs ------------------------------------------------------
alter table public.system_logs add column if not exists actor_name text;
update public.system_logs s
   set actor_name = p.full_name
  from public.profiles p
 where s.actor_id = p.id and s.actor_name is null;

-- 3) Fully paid ---------------------------------------------------------------
alter table public.transactions add column if not exists balance_paid_at timestamptz;
alter table public.transactions add column if not exists balance_paid_by uuid
  references public.profiles (id) on delete set null;
alter table public.transactions add column if not exists balance_ref text;
alter table public.transactions add column if not exists balance_amount numeric(10, 2);

create index if not exists transactions_balance_paid_idx
  on public.transactions (balance_paid_at) where type = 'deposit';
