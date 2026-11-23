-- Commission split 7.5% / 2.5% + correct existing totals — re-runnable.
--
-- New rule: of each client's 50% advance deposit,
--   7.5%  Outright  → the referring Growth Partner
--   2.5%  Uni-Level → that partner's direct upline (or the Main Basket when
--                     the upline is an admin's own Growth Partner account,
--                     or there is no eligible upline)
--   = 10% in total (before: 10% outright + 5% of that to the sponsor).
--
-- Self-contained: step 0 adds what it needs from 20261203 / 20261204 /
-- 20261205 if those haven't been run yet (all guarded, nothing is dropped).
-- Every step only touches rows that are still wrong, so running it twice
-- changes nothing the second time.

-- 0) Prerequisites (safe if the earlier migrations already ran) ----------------
--    Main Basket transaction type (20261203)
alter table public.transactions drop constraint if exists transactions_type_check;
alter table public.transactions add constraint transactions_type_check
  check (type in ('activation', 'deposit', 'commission', 'payout', 'basket'));
create unique index if not exists transactions_one_basket_per_deposit
  on public.transactions (source_txn_id)
  where type = 'basket' and source_txn_id is not null;
--    Fully-paid columns + actor names on logs (20261205)
alter table public.transactions add column if not exists balance_paid_at timestamptz;
alter table public.transactions add column if not exists balance_paid_by uuid
  references public.profiles (id) on delete set null;
alter table public.transactions add column if not exists balance_ref text;
alter table public.transactions add column if not exists balance_amount numeric(10, 2);
alter table public.system_logs add column if not exists actor_name text;
--    v1.7 package prices (20261204)
update public.packages p
   set price = v.price, monthly_price = v.monthly_price, commission_outright = round(v.price * 0.05, 2)
  from (values
    ('GHL Business Systems Development & Automation',                    2000.00, 1000.00),
    ('Complete Google SEO Setup & Optimization',                          1000.00,  600.00),
    ('Full-Service Digital Marketing Package',                            3000.00, 1500.00),
    ('Custom Business Operations Management System',                      4000.00, 1500.00),
    ('Customized Integrated Digital & Network Marketing Business System', 4500.00, 2000.00),
    ('Complete AI SEO, AEO & GEO Optimization',                           1500.00,  750.00)
  ) as v(name, price, monthly_price)
 where p.name = v.name and p.kind = 'service'
   and (p.price <> v.price or p.monthly_price <> v.monthly_price);

-- 1) Rates -------------------------------------------------------------------
insert into public.app_settings (key, value, updated_at) values
  ('referral_commission_pct', '7.5', now()),
  ('upline_commission_pct',   '2.5', now())
on conflict (key) do update set value = excluded.value, updated_at = now();

-- 2) Deposits recorded at the OLD price list → v1.7 prices ------------------
--    Only deposits whose amount is exactly 50% of the old price are fixed
--    (e.g. Custom Business Operations $12,500 → $2,000, Google SEO $1,500 → $500).
with old(name, old_price) as (values
  ('GHL Business Systems Development & Automation',                     3500.00),
  ('Complete Google SEO Setup & Optimization',                           3000.00),
  ('Full-Service Digital Marketing Package',                             7500.00),
  ('Custom Business Operations Management System',                      25000.00),
  ('Customized Integrated Digital & Network Marketing Business System', 45000.00),
  ('Complete AI SEO, AEO & GEO Optimization',                            5500.00)
)
update public.transactions t
   set amount = round(p.price * 0.5, 2),
       balance_amount = case when t.balance_amount = round(o.old_price * 0.5, 2) then round(p.price * 0.5, 2) else t.balance_amount end,
       notes = coalesce(t.notes, '') || ' [amount corrected to v1.7 price]'
  from public.packages p
  join old o on o.name = p.name
 where t.package_id = p.id
   and t.type = 'deposit'
   and t.status in ('pending', 'completed')
   and t.amount = round(o.old_price * 0.5, 2)
   and p.price <> o.old_price;

-- 3) Referrer (Outright) commissions → 7.5% of the deposit --------------------
update public.transactions c
   set amount = round(d.amount * 0.075, 2),
       notes  = regexp_replace(coalesce(c.notes, ''), '^[0-9.]+% Outright', '7.5% Outright')
  from public.transactions d
 where c.type = 'commission'
   and c.source_txn_id = d.id
   and d.type = 'deposit' and d.status = 'completed'
   and coalesce(c.notes, '') not ilike '%Uni-Level%'
   and c.amount <> round(d.amount * 0.075, 2);

-- 4) Upline (Uni-Level) commissions → 2.5% of the deposit --------------------
update public.transactions c
   set amount = round(d.amount * 0.025, 2),
       notes  = regexp_replace(coalesce(c.notes, ''), '^[0-9.]+% Uni-Level \(Level 1\)', '2.5% Uni-Level (upline)')
  from public.transactions d
 where c.type = 'commission'
   and c.source_txn_id = d.id
   and d.type = 'deposit' and d.status = 'completed'
   and coalesce(c.notes, '') ilike '%Uni-Level%'
   and c.amount <> round(d.amount * 0.025, 2);

-- 5) Main Basket entries → 2.5% of the deposit ------------------------------
update public.transactions b
   set amount = round(d.amount * 0.025, 2)
  from public.transactions d
 where b.type = 'basket'
   and b.source_txn_id = d.id
   and d.type = 'deposit' and d.status = 'completed'
   and b.amount <> round(d.amount * 0.025, 2);

-- 6) Approved deposits that never paid an upline share (approved before the
--    Uni-Level step existed): pay it now — to the upline when that is a
--    regular Growth Partner, otherwise into the Main Basket.
with missing as (
  select d.id as dep_id, d.user_id as client_id, d.package_id, d.amount as deposit,
         ref.id as referrer_id, ref.full_name as referrer_name,
         up.id as upline_id, up.role as upline_role, up.affiliate_tier as upline_tier, up.deleted_at as upline_deleted,
         ref.affiliate_tier as referrer_tier, cl.full_name as client_name
    from public.transactions d
    join public.transactions oc on oc.source_txn_id = d.id and oc.type = 'commission' and coalesce(oc.notes, '') not ilike '%Uni-Level%'
    join public.profiles ref on ref.id = oc.user_id
    join public.profiles cl  on cl.id = d.user_id
    left join public.profiles up on up.id = ref.referred_by
   where d.type = 'deposit' and d.status = 'completed'
     and not exists (select 1 from public.transactions x where x.source_txn_id = d.id and x.type = 'commission' and coalesce(x.notes, '') ilike '%Uni-Level%')
     and not exists (select 1 from public.transactions x where x.source_txn_id = d.id and x.type = 'basket')
),
to_upline as (
  select * from missing
   where coalesce(referrer_tier, '') <> 'unlimited'
     and upline_id is not null and upline_deleted is null
     and (upline_role = 'growth_partner' or upline_tier is not null)
     and coalesce(upline_tier, '') <> 'unlimited'
)
insert into public.transactions (user_id, package_id, source_user_id, source_txn_id, amount, type, status, notes)
select upline_id, package_id, client_id, dep_id, round(deposit * 0.025, 2), 'commission', 'completed',
       '2.5% Uni-Level (upline) on the advance deposit of ' || client_name || ', referred by ' || referrer_name
  from to_upline
on conflict do nothing;

insert into public.transactions (user_id, package_id, source_user_id, source_txn_id, amount, type, status, notes)
select (select id from public.profiles where role = 'admin' and deleted_at is null order by (username = 'admin') desc, created_at asc limit 1),
       d.package_id, d.user_id, d.id, round(d.amount * 0.025, 2), 'basket', 'completed',
       'Uni-Level to Main Basket — added by the 7.5% / 2.5% split update'
  from public.transactions d
 where d.type = 'deposit' and d.status = 'completed'
   and exists (select 1 from public.transactions oc where oc.source_txn_id = d.id and oc.type = 'commission')
   and not exists (select 1 from public.transactions x where x.source_txn_id = d.id and x.type = 'commission' and coalesce(x.notes, '') ilike '%Uni-Level%')
   and not exists (select 1 from public.transactions x where x.source_txn_id = d.id and x.type = 'basket')
on conflict do nothing;

-- 7) Audit trail ---------------------------------------------------------------
insert into public.system_logs (action, entity_type, summary, actor_name)
values ('commission_rate', 'app_settings',
        'Commission split set to 7.5% referrer + 2.5% upline of the advance deposit; existing deposits and commissions recalculated (migration 20261206)',
        'system migration');

-- Check: every approved deposit with its split.
select cl.full_name as client, p.name as package, d.amount as deposit,
       (select sum(amount) from public.transactions c where c.source_txn_id = d.id and c.type = 'commission' and coalesce(c.notes,'') not ilike '%Uni-Level%') as referrer_7_5,
       (select sum(amount) from public.transactions c where c.source_txn_id = d.id and (c.type = 'basket' or (c.type = 'commission' and c.notes ilike '%Uni-Level%'))) as upline_or_basket_2_5
  from public.transactions d
  join public.profiles cl on cl.id = d.user_id
  left join public.packages p on p.id = d.package_id
 where d.type = 'deposit' and d.status = 'completed'
 order by d.created_at desc;
