#!/usr/bin/env python3
"""Generate provisioned Grafana dashboard with fixed aggregate metric names."""
import json
from pathlib import Path
panels=[]
def panel(title,expr,unit='short',kind='timeseries',width=8):
    i=len(panels);x=sum(p['gridPos']['w'] for p in panels if p['gridPos']['y']==(panels[-1]['gridPos']['y'] if panels else 0)) if panels else 0
    y=panels[-1]['gridPos']['y'] if panels else 0
    if x+width>24:x=0;y+=8
    panels.append({'id':i+1,'title':title,'type':kind,'gridPos':{'x':x,'y':y,'w':width,'h':8},'datasource':{'type':'prometheus','uid':'arm-prometheus'},'targets':[{'expr':expr,'refId':'A','legendFormat':'{{service}}{{kind}}{{status_class}}{{alert}}'}],'fieldConfig':{'defaults':{'unit':unit,'color':{'mode':'palette-classic'}},'overrides':[]},'options':{'legend':{'displayMode':'list','placement':'bottom'},'tooltip':{'mode':'multi'}}})
panel('CPU хоста','arm_host_cpu_percent','percent')
panel('Занятая RAM','100 * (1 - arm_host_memory_bytes{kind="MemAvailable"} / ignoring(kind) arm_host_memory_bytes{kind="MemTotal"})','percent')
panel('Свободный диск','arm_host_disk_bytes{kind="free"}','bytes')
panel('Состояние контейнеров: 1 = здоров','arm_container_healthy','short','stat',12)
panel('Действующие предупреждения','arm_alert_active','short','stat',12)
panel('HTTP-запросы в секунду (без отключённых access-log)','sum(rate(arm_http_requests_total[5m]))','reqps')
panel('HTTP-ошибки 5xx','sum(rate(arm_http_requests_total{status_class="5xx"}[5m]))','reqps')
panel('p95 времени HTTP-запросов','histogram_quantile(0.95,sum by(le)(rate(arm_http_request_duration_seconds_bucket[5m])))','s')
panel('Локальная HTTPS-проверка /login: 1 = доступен','arm_http_probe_up','short','stat')
panel('Время HTTPS-проверки /login','arm_http_probe_duration_seconds','s')
panel('Свежесть сборщика метрик','time() - arm_collector_timestamp_seconds','s')
panel('CPU контейнеров','arm_container_cpu_percent','percent',width=12)
panel('Память контейнеров относительно лимита','arm_container_memory_percent','percent',width=12)
panel('Соединения PostgreSQL','arm_db_connections')
panel('Видимые активные запросы > 5 сек','arm_db_slow')
panel('Процессы PHP-FPM workers (не занятость)','arm_fpm_workers')
panel('Задания в очереди БД (включая уведомления)','arm_queue_pending')
panel('Неуспешные задания в БД','arm_queue_failed')
panel('Возраст старейшего задания БД','arm_queue_oldest_age','s')
panel('Возраст последней серверной копии (не копии на ПК)','time() - arm_backup_created_timestamp_seconds','s')
panel('Состояние этапов сбора','arm_collection_stage_up','short','stat')
panel('Длительность сбора','arm_collector_duration_seconds','s')
panel('Сеть: приём','rate(arm_host_network_receive_bytes_total[5m])','Bps')
panel('Сеть: передача','rate(arm_host_network_transmit_bytes_total[5m])','Bps')
panel('Дисковые операции чтения/записи','rate(arm_host_disk_read_bytes_total[5m]) + rate(arm_host_disk_write_bytes_total[5m])','Bps')
panel('SWAP занято','arm_host_memory_bytes{kind="SwapTotal"} - ignoring(kind) arm_host_memory_bytes{kind="SwapFree"}','bytes')
panel('Достигнут предел чтения HTTP-логов','arm_nginx_log_tail_limit_hit','short','stat')
panel('Последняя успешная отправка уведомления (0 = ещё не было)','arm_notification_last_success_timestamp_seconds','dateTimeAsIso','stat')
dashboard={'uid':'arm-operations','title':'ARM — сервер и приложение','tags':['ARM','operations'],'timezone':'Europe/Moscow','schemaVersion':39,'version':1,'editable':False,'refresh':'15s','time':{'from':'now-1h','to':'now'},'panels':panels,'annotations':{'list':[]},'description':'Агрегаты без персональных данных. Сбор раз в 30 сек, scrape 15 сек. HTTP без static/access_log off; p95 по журналируемым запросам. DB stats в пределах видимости runtime-роли. Queue: только задания БД, не deferred analytics. Возраст backup не подтверждает его получение ПК.'}
Path(__file__).with_name('dashboard.json').write_text(json.dumps(dashboard,ensure_ascii=False,indent=2)+'\n')
print(len(panels),'panels')
