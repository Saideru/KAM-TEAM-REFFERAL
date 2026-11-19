-- =====================================================================
-- KAMTEAM // Migration: KAM Team service packages (Nov 2026 price list)
-- Run AFTER 20261118f_roulette_accounts.sql.
-- Safe to run on the LIVE database (no drops, no data loss). Re-runnable.
-- Run in: Supabase Dashboard → SQL Editor → New query → Run
-- =====================================================================

-- The unilevel plans (Basic Starter, Standard Pro) stay exactly as they
-- are. The old service bundles are retired and replaced by the six
-- packages from KAM_TEAM_Service_Packages_Updated.pdf.

-- 1) Packages now carry a monthly recurring fee next to the one-time price.
alter table public.packages
  add column if not exists monthly_price numeric(10, 2) not null default 0;

-- 2) Retire the old service bundles. Hidden, not deleted, so past
--    transactions keep pointing at them.
update public.packages set active = false
where name in ('Marketing Suite', 'AI Implementation');

-- 3) The six KAM Team service packages.
--    price           = one-time setup / development fee
--    monthly_price   = monthly maintenance / management fee
--    commission_outright = 10% of the 50% down payment (5% of the one-time price)
insert into public.packages (name, description, price, monthly_price, commission_outright, commission_unilevel, tier)
select v.name, v.description, v.price, v.monthly_price, v.commission_outright, v.commission_unilevel, 'standard'
from (values
  ('GHL Business Systems Development & Automation',
   'Website, SEO/AEO/GEO, landing page or sales funnel, email & SMS automation, CRM and pipeline setup, lead nurturing. Monthly: CRM maintenance, workflow monitoring, automation management, dedicated GHL system management.',
   3500.00, 1000.00, 175.00, 5.00),
  ('Complete Google SEO Setup & Optimization',
   'Search Console & GA4, Google Business Profile (where eligible), technical SEO audit and fixes, keyword & competitor research, on-page optimization for up to 10 pages, sitemap/indexing/schema, internal linking, final report. Monthly: monitoring, reporting, content optimization, technical maintenance.',
   3000.00, 700.00, 150.00, 5.00),
  ('Full-Service Digital Marketing Package',
   'Digital marketing strategy, business website, Google SEO & Search Console, Google Business Profile (if eligible), Google & Meta Ads setup, social media branding, GHL CRM and pipelines, email & SMS automation, AI lead qualification. Monthly: SEO, ads management, social content, email marketing, CRM monitoring, reporting, optimization.',
   7500.00, 2500.00, 375.00, 5.00),
  ('Custom Business Operations Management System',
   'Planning, development, testing and deployment of an operations dashboard with user management, role-based permissions, workflow automation, client management, reporting & analytics, database/API integration, security controls and admin training. Monthly: monitoring, security patches, bug fixes, backup oversight, limited support.',
   25000.00, 600.00, 1250.00, 5.00),
  ('Customized Integrated Digital & Network Marketing Business System',
   'System planning and architecture, digital marketing, CRM and automation, network marketing and commission system, member and admin dashboards, database and integrations, testing, deployment and training. Monthly: monitoring, backup oversight, security patches, bug fixes, limited support.',
   45000.00, 2000.00, 2250.00, 5.00),
  ('Complete AI SEO, AEO & GEO Optimization',
   'Technical Google SEO, Search Console/GA4/tracking, keyword & competitor research, on-page optimization for up to 10 pages, schema and structured data, content optimization for AI answers, entity & online presence optimization, AI visibility baseline, audit report and roadmap. Monthly: technical monitoring, content improvement, AI visibility checks, performance analysis, reporting.',
   5500.00, 1500.00, 275.00, 5.00)
) as v(name, description, price, monthly_price, commission_outright, commission_unilevel)
where not exists (select 1 from public.packages p where p.name = v.name);
