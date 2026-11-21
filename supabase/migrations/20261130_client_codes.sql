-- =====================================================================
-- Every Client gets a unique referral code (KT-XXXXXX)
--
-- New rows already get one from the gen_referral_code trigger. This gives
-- a code to any existing Client row that has none (e.g. rows created while
-- client codes were being cleared). Admin and staff rows are left alone.
--
-- Codes on client rows are shown on the Client dashboard ("Your client
-- code"). A sign-up made with a client's code is linked to that client;
-- commission is still paid only to Growth Partners (see
-- pay_referral_commission in api/lib/supabase.php).
--
-- Re-runnable. Run in: Supabase Dashboard -> SQL Editor -> New query -> Run
-- =====================================================================

do $$
declare
  r record;
  v_code text;
begin
  for r in
    select id from public.profiles
     where role = 'client'
       and (referral_code is null or referral_code = '')
  loop
    loop
      v_code := 'KT-' || upper(substr(md5(random()::text || clock_timestamp()::text), 1, 6));
      exit when not exists (select 1 from public.profiles where referral_code = v_code);
    end loop;
    update public.profiles set referral_code = v_code where id = r.id;
  end loop;
end $$;

-- Check:
--   select username, role, referral_code from public.profiles where role = 'client' order by created_at;
