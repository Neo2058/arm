// Real loopback application, real local mutations; no production requests.
const {chromium}=require('/private/tmp/arm-video-tools/node_modules/playwright');
const fs=require('node:fs'),path=require('node:path');
const work='/private/tmp/arm-video-demo',out=path.join(work,'web-recording');fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true,args:['--disable-background-networking']});
 const accounts=JSON.parse(fs.readFileSync(work+'/accounts.json'));const manifest={source:'real local Laravel actions; isolated SQLite; synthetic users; external requests blocked',frames:[],scenes:[],checks:[]};
 let start,recording=false,finished=false,activeScene='';let frame=0;
 const context=await browser.newContext({viewport:{width:1600,height:900},deviceScaleFactor:1,locale:'ru-RU'});
 await context.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());
 const page=await context.newPage();page.on('pageerror',e=>manifest.checks.push({type:'js_error',message:e.message}));
 const pause=ms=>page.waitForTimeout(ms);
 async function login(role){
  await context.clearCookies();await page.goto('http://127.0.0.1:8786/login');await page.locator('[name=email]').fill(accounts[role].email);await page.locator('[name=password]').fill(accounts[role].password);
  await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('/login')),page.locator('button[type=submit]').click()]);
  const welcome=page.getByText('Перейти к меню',{exact:true});if(await welcome.isVisible().catch(()=>false))await welcome.click();
 }
 function scene(code,title){activeScene=code;manifest.scenes.push({code,title,start_seconds:(performance.now()-start)/1000});}
 const capture=(async()=>{while(!finished){if(recording){try{const file='frame-'+String(frame++).padStart(5,'0')+'.png';await page.screenshot({path:path.join(out,file)});manifest.frames.push({file,time_seconds:(performance.now()-start)/1000,scene:activeScene});}catch{}}await new Promise(r=>setTimeout(r,160));}})();
 try{
  await login('instructor');await page.goto('http://127.0.0.1:8786/journal');await pause(800);
  start=performance.now();recording=true;scene('I01','Инструктор · задача проходит полный цикл');await pause(1800);
  const form=page.locator('form[action$="/journal/todo"]').first();await form.locator('[name=title]').pressSequentially('Провести разбор учебного случая',{delay:75});await pause(600);
  await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'+ Добавить'}).click()]);await pause(1800);
  const task=page.getByText('Провести разбор учебного случая',{exact:true}).first();await task.scrollIntoViewIfNeeded();await pause(800);
  const card=task.locator('xpath=../..');await Promise.all([page.waitForNavigation(),card.locator('button[title="Отметить выполненной"]').click()]);await pause(1800);
  if(!await page.getByText('Провести разбор учебного случая',{exact:true}).first().evaluate(e=>e.parentElement.parentElement.classList.contains('line-through')))throw new Error('Task was not completed');
  manifest.checks.push({type:'journal_task_created_and_completed',ok:true});
  recording=false;await pause(500);await login('driver');await page.goto('http://127.0.0.1:8786/rosisi');await pause(800);recording=true;scene('U07','Машинист · персональная запись в формуляр');await pause(1600);
  const inputs=await page.locator('input:not([type=hidden]),textarea').evaluateAll(es=>es.map(e=>({name:e.name,type:e.type,placeholder:e.placeholder})));
  fs.writeFileSync(out+'/formular-fields.json',JSON.stringify(inputs,null,2));
  const logForm=page.locator('form[action*="log-formular"]').first();await logForm.scrollIntoViewIfNeeded();await pause(700);await logForm.locator('[name=entry_text]').pressSequentially('Учебный материал изучен, запись внесена',{delay:65});await pause(500);await Promise.all([page.waitForNavigation(),logForm.getByRole('button',{name:'Отметить выполнение'}).click()]);await page.getByText('Учебный материал изучен, запись внесена',{exact:true}).waitFor();await pause(1800);manifest.checks.push({type:'formular_entry_saved',ok:true});
  recording=false;await pause(500);await page.goto('http://127.0.0.1:8786/worktime');await pause(800);recording=true;scene('U02','Машинист · учёт смены и оплачиваемого времени');await pause(1400);
  await page.getByRole('button',{name:'Добавить смену',exact:true}).click();await pause(1400);
  fs.writeFileSync(out+'/shift-fields.json',JSON.stringify(await page.locator('input,select').evaluateAll(es=>es.map(e=>({name:e.name,type:e.type,label:e.closest('label')?.innerText,value:e.value}))),null,2));
  await page.getByLabel('Начало · ГГГГ-ММ-ДД ЧЧ:ММ').fill('2026-11-10 20:00');await pause(500);
  await page.getByLabel('Окончание · ГГГГ-ММ-ДД ЧЧ:ММ').fill('2026-11-11 04:30');
  await page.getByLabel('Оплачиваемые перерывы, минут').fill('30');await pause(700);
  await page.getByRole('button',{name:'Проверить расчёт',exact:true}).click();await page.locator('.wt-result').waitFor();await pause(2000);
  const saved=await Promise.all([page.waitForResponse(r=>r.url().endsWith('/api/worktime/v2/shifts')&&r.request().method()==='POST'),page.getByRole('button',{name:'Сохранить на сервере',exact:true}).click()]);
  if(saved[0].status()!==201)throw new Error('Shift was not saved: '+saved[0].status());
  const shift=await saved[0].json();if(shift.shift.calculation_result.paid_minutes!==510)throw new Error('Unexpected shift minutes');
  manifest.checks.push({type:'shift_saved_with_paid_break',minutes:510,ok:true});await pause(2000);
  manifest.duration_seconds=(performance.now()-start)/1000;
 }finally{recording=false;finished=true;await capture;fs.writeFileSync(out+'/manifest.json',JSON.stringify(manifest,null,2));await browser.close();}
 console.log(JSON.stringify({frames:manifest.frames.length,duration:manifest.duration_seconds,scenes:manifest.scenes,checks:manifest.checks}));
})().catch(e=>{console.error(e.message);process.exitCode=1});
