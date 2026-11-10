INSERT INTO arm_personnel(tab_number,full_name,position_code,class_code,brigade_code,column_num,is_active)
SELECT 'D'||lpad(i::text,3,'0'),'Учебный машинист '||lpad(i::text,2,'0'),'М','1', ((i-1)/8+1)::text,((i-1)/8+1)::text,true FROM generate_series(1,24) i;
INSERT INTO arm_schedule_types(code,name) VALUES('1','Учебный рабочий график');
INSERT INTO arm_monthly_norms(month_year,norm_hours) VALUES('11.2026',168);
INSERT INTO arm_calendar_quotas(plan_date,schedule_code,train_quota)
SELECT d::date,'1',CASE WHEN extract(isodow from d)<6 THEN 24 ELSE 0 END FROM generate_series('2026-11-01'::timestamp,'2026-11-30'::timestamp,'1 day') d;
INSERT INTO arm_shift_breakdowns(schedule_code,route_code,shift_code,sequence,start_time,end_time,start_minutes,end_minutes,hours_total,hours_line,appearance_start,appearance_end,content)
SELECT '1',(100+i)::text,'1','1',6,14,360,840,8,8,'Депо · 06:00','Депо · 14:00','Учебный маршрут' FROM generate_series(1,24) i;
INSERT INTO arm_naryad_assignments(tab_number,assignment_date,work_code,shift_code,route_code,hours_total,hours_line)
SELECT p.tab_number,d::date,CASE WHEN extract(isodow from d)<6 THEN (100+p.id)::text ELSE 'В' END,CASE WHEN extract(isodow from d)<6 THEN '1' ELSE '' END,(100+p.id)::text,CASE WHEN extract(isodow from d)<6 THEN 8 ELSE 0 END,CASE WHEN extract(isodow from d)<6 THEN 8 ELSE 0 END
FROM arm_personnel p CROSS JOIN generate_series('2026-11-01'::timestamp,'2026-11-30'::timestamp,'1 day') d;
