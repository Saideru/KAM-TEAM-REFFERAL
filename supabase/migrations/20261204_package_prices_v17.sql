-- Service package prices → Program Terms v1.7 §3.4 (and the Growth Partner
-- presentation). Re-runnable. The older price list in the database made a
-- Google SEO deposit $1,500 instead of $500.
--   price               = one-time setup fee (the client pays 50% up front)
--   monthly_price       = monthly fee (never commissionable)
--   commission_outright = 10% of the 50% deposit = 5% of the setup fee
-- Deposits already submitted keep their amount; only new ones use these.
update public.packages p
   set price = v.price,
       monthly_price = v.monthly_price,
       commission_outright = round(v.price * 0.05, 2)
  from (values
    ('GHL Business Systems Development & Automation',                    2000.00, 1000.00),
    ('Complete Google SEO Setup & Optimization',                          1000.00,  600.00),
    ('Full-Service Digital Marketing Package',                            3000.00, 1500.00),
    ('Custom Business Operations Management System',                      4000.00, 1500.00),
    ('Customized Integrated Digital & Network Marketing Business System', 4500.00, 2000.00),
    ('Complete AI SEO, AEO & GEO Optimization',                           1500.00,  750.00)
  ) as v(name, price, monthly_price)
 where p.name = v.name
   and p.kind = 'service';

-- Check: every service package and its 50% deposit.
select name, price as setup, round(price * 0.5, 2) as deposit_50, monthly_price, active
  from public.packages where kind = 'service' order by price;
