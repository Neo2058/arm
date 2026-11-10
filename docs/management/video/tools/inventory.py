#!/usr/bin/env python3
"""Build an auditable route/screen inventory; makes no production requests."""
import csv,hashlib,json,re,subprocess,os
from pathlib import Path
ROOT=Path(__file__).resolve().parents[4]
OUT=ROOT/'docs/management/video'
# PHP route list is captured separately using an isolated in-memory SQLite configuration.
env=dict(os.environ,APP_ENV='testing',DB_CONNECTION='sqlite',DB_DATABASE=':memory:',CACHE_STORE='array')
routes=json.loads(subprocess.check_output(['php','artisan','route:list','--json','--except-vendor'],cwd=ROOT,env=env,text=True))
def scene(uri):
    p=re.sub(r'^api/mobile/?','',uri)
    if p.startswith('api/'):p=p[4:]
    for prefix,code in [('naryady','U01'),('naryads','U01'),('files/','SEC03'),('mainMenu','OPEN'),('about','SUP01'),('backstage','SUP02'),('webhooks/','SUP03'),('password/','SEC01'),('telegram/','U08'),('install','INSTALL'),('results-history','U06'),('teaching','U09'),('timer','U09'),('work-shifts','U02'),('actions/log','SEC01'),('bug-report','SUP01'),('barrier','SEC01'),('device','SEC01'),('login','SEC01'),('logout','SEC01'),('me','SEC01')]:
        if p.startswith(prefix):return code
    if p in ('','/'):return 'OPEN'
    for prefix,code in [('api/monitor','SEC02'),('journal/training','I03'),('journal/naryad','I04'),('journal','I01'),('naryad','D01'),('uchet','O01'),('nsi','D04'),('personnel','D03'),('discipline','I05'),('chat','C01'),('admin','A01'),('training','U05'),('quizzes','U06'),('quiz','U06'),('general-quiz','U06'),('documents','U04'),('rosisi','U07'),('podstroiki','U03'),('worktime','U02'),('account','U08'),('phones','C02'),('naryads','U01'),('naryady','U01'),('monitor','SEC02'),('filament','A01')]:
        if p.startswith(prefix):return code
    return 'REVIEW'
with (OUT/'ROUTES_INVENTORY.csv').open('w') as f:
    w=csv.writer(f);w.writerow(['method','uri','name','controller','middleware','scene_group','verification'])
    for r in routes:w.writerow([r.get('method'),r.get('uri'),r.get('name'),r.get('action'),' | '.join(r.get('middleware',[])),scene(r['uri']),'route_found; behavior and recording pending'])
app=(ROOT/'mobile/App.js').read_text()
screens=re.findall(r'<(?:Stack|Tab)\.Screen\s+name=["\']([^"\']+)',app)
resources=sorted(str(p.relative_to(ROOT)) for p in (ROOT/'app/Filament/Resources').glob('*Resource.php'))
sources=sorted((ROOT/'routes').glob('*.php'))+[ROOT/'mobile/App.js',ROOT/'app/Services/Chat/ChatCrypto.php',ROOT/'app/Services/Chat/ChatAuthorization.php']
meta={'date':'2026-11-09','git_head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'routes':len(routes),'routes_needing_assignment':sum(scene(r['uri'])=='REVIEW' for r in routes),'mobile_screen_declarations':screens,'filament_resources':resources,'sources':{str(p.relative_to(ROOT)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sources},'status':'Source inventory only; not production or end-to-end verification. Dirty podstroiki changes excluded from readiness claims.'}
(OUT/'SOURCE_INVENTORY.json').write_text(json.dumps(meta,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'routes':len(routes),'unassigned_routes':meta['routes_needing_assignment'],'mobile_declarations':len(screens),'filament_resources':len(resources)}))
