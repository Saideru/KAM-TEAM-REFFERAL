-- Referral pipeline + Main Basket — re-runnable.
--
-- Each referred client moves through stages the admin controls:
--   Pending → Converted (package) → Approved
--   Pending → Rejected (reason)
--   Pending → Converted (package) → Rejected (reason)
-- Approved is when the 50% deposit is confirmed: the Outright Commission is
-- credited to the referrer (and shows in their Payout balance) and the
-- Uni-Level goes to the referrer's upline — or to the Main Basket when that
-- upline is an admin's own Growth Partner account (or there is none).

-- 1) Stage columns on the referral row ---------------------------------------
alter table public.referrals add column if not exists stage text not null default 'pending';
alter table public.referrals drop constraint if exists referrals_stage_check;
alter table public.referrals add constraint referrals_stage_check
  check (stage in ('pending', 'converted', 'approved', 'rejected'));

alter table public.referrals add column if not exists stage_package_id uuid
  references public.packages (id) on delete set null;
alter table public.referrals add column if not exists stage_txn_id uuid
  references public.transactions (id) on delete set null;
alter table public.referrals add column if not exists stage_by uuid
  references public.profiles (id) on delete set null;
alter table public.referrals add column if not exists stage_reason text;
alter table public.referrals add column if not exists stage_at timestamptz;

create index if not exists referrals_stage_idx on public.referrals (stage);

-- 2) Every referred member has a referral row (older rows may be missing) ----
insert into public.referrals (referrer_id, referred_id, status)
select p.referred_by, p.id, 'pending'
  from public.profiles p
 where p.referred_by is not null
on conflict (referrer_id, referred_id) do nothing;

-- 3) Backfill stages from deposits already on file (latest deposit wins) ----
with latest as (
  select distinct on (t.user_id) t.user_id, t.id, t.status, t.package_id, t.notes, t.reviewed_at, t.created_at
    from public.transactions t
   where t.type = 'deposit'
     and t.notes is distinct from 'Cancelled by client'
   order by t.user_id, t.created_at desc
)
update public.referrals r
   set stage = case l.status when 'completed' then 'approved'
                             when 'pending'   then 'converted'
                             else 'rejected' end,
       stage_package_id = l.package_id,
       stage_txn_id     = l.id,
       stage_reason     = case when l.status in ('failed', 'reversed') then l.notes end,
       stage_at         = coalesce(l.reviewed_at, l.created_at)
  from latest l
 where r.referred_id = l.user_id
   and r.stage = 'pending'
   and r.stage_at is null;

-- 4) Main Basket entries: transactions of type 'basket' ----------------------
alter table public.transactions drop constraint if exists transactions_type_check;
alter table public.transactions add constraint transactions_type_check
  check (type in ('activation', 'deposit', 'commission', 'payout', 'basket'));

-- One basket entry per approved deposit.
create unique index if not exists transactions_one_basket_per_deposit
  on public.transactions (source_txn_id)
  where type = 'basket' and source_txn_id is not null;
