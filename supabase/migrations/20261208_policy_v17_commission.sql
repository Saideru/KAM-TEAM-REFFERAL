-- Follow the Program Terms v1.7 (policy PDF) — re-runnable.
--   §2.2 / §3.1  Outright  = 10% of the client's 50% Advance Deposit
--   §2.3 / §3.2  Uni-Level = 5% of that Outright, to the direct sponsor
--                (or the Main Basket when the sponsor is an admin account)
--   §5.6         payouts record transaction fees and tax withheld (net = gross − fees − tax)
-- Replaces the 7.5% / 2.5% split from 20261206. Every step only touches rows
-- that are still wrong, so running it twice changes nothing the second time.

-- 1) Rates (upline rate is now a % of the Outright commission) ---------------
insert into public.app_settings (key, value, updated_at) values
  ('referral_commission_pct', '10', now()),
  ('upline_commission_pct',   '5',  now())
on conflict (key) do update set value = excluded.value, updated_at = now();

-- 2) Payout fees and tax withheld (§5.6) ------------------------------------------
alter table public.transactions add column if not exists payout_fee numeric(10, 2);
alter table public.transactions add column if not exists payout_tax numeric(10, 2);

-- 3) Outright commissions → 10% of the deposit minus the sponsor's 5% (e.g. $100 - $5 = $95) -------------------------------------
update public.transactions c
   set amount = round(d.amount * 0.10, 2) - round(round(d.amount * 0.10, 2) * 0.05, 2),
       notes  = regexp_replace(coalesce(c.notes, ''), '^[0-9.]+% Outright', '10% Outright')
  from public.transactions d
 where c.type = 'commission'
   and c.source_txn_id = d.id
   and d.type = 'deposit' and d.status = 'completed'
   and coalesce(c.notes, '') not ilike '%Uni-Level%'
   and (c.amount <> round(d.amount * 0.10, 2) - round(round(d.amount * 0.10, 2) * 0.05, 2) or coalesce(c.notes, '') ~ '^[0-9.]+% Outright' and coalesce(c.notes, '') !~ '^10% Outright');

-- 4) Uni-Level commissions → 5% of that Outright (= 0.5% of the deposit) -----------
update public.transactions c
   set amount = round(round(d.amount * 0.10, 2) * 0.05, 2),
       notes  = regexp_replace(coalesce(c.notes, ''), '^[0-9.]+% Uni-Level \((upline|Level 1)\)( on| of the [0-9.]+% Outright commission on)?',
                               '5% Uni-Level (upline) of the 10% Outright commission on')
  from public.transactions d
 where c.type = 'commission'
   and c.source_txn_id = d.id
   and d.type = 'deposit' and d.status = 'completed'
   and coalesce(c.notes, '') ilike '%Uni-Level%'
   and c.amount <> round(round(d.amount * 0.10, 2) * 0.05, 2);

-- 5) Main Basket entries → same 5% of the Outright ---------------------------------
update public.transactions b
   set amount = round(round(d.amount * 0.10, 2) * 0.05, 2)
  from public.transactions d
 where b.type = 'basket'
   and b.source_txn_id = d.id
   and d.type = 'deposit' and d.status = 'completed'
   and b.amount <> round(round(d.amount * 0.10, 2) * 0.05, 2);

-- 6) Audit trail ----------------------------------------------------------------------
insert into public.system_logs (action, entity_type, summary, actor_name)
select 'commission_rate', 'app_settings',
       'Commission set to Terms v1.7: 10% Outright of the advance deposit + 5% of that Outright to the direct sponsor; existing commissions recalculated (migration 20261208)',
       'system migration'
 where not exists (select 1 from public.system_logs where summary like '%(migration 20261208)%');

-- Check: every approved deposit with its split, plus any partner whose payouts now exceed earnings.
select cl.full_name as client, p.name as package, d.amount as deposit,
       (select sum(amount) from public.transactions c where c.source_txn_id = d.id and c.type = 'commission' and coalesce(c.notes,'') not ilike '%Uni-Level%') as outright_10,
       (select sum(amount) from public.transactions c where c.source_txn_id = d.id and (c.type = 'basket' or (c.type = 'commission' and c.notes ilike '%Uni-Level%'))) as sponsor_5_of_outright
  from public.transactions d
  join public.profiles cl on cl.id = d.user_id
  left join public.packages p on p.id = d.package_id
 where d.type = 'deposit' and d.status = 'completed'
 order by d.created_at desc;

select pr.full_name, pr.username,
       sum(case when t.type = 'commission' and t.status = 'completed' then t.amount else 0 end) as earned,
       sum(case when t.type = 'payout' and t.status in ('pending', 'completed') then t.amount else 0 end) as paid_or_requested
  from public.profiles pr join public.transactions t on t.user_id = pr.id
 group by pr.id, pr.full_name, pr.username
having sum(case when t.type = 'payout' and t.status in ('pending', 'completed') then t.amount else 0 end)
     > sum(case when t.type = 'commission' and t.status = 'completed' then t.amount else 0 end);
