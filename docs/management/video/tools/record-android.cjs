// Uses only an emulator running ru.tch15.arm.video against the isolated local API.
const {execFileSync}=require('node:child_process'),fs=require('node:fs'),path=require('node:path');
const adb='/Users/vyacheslavandreevich/Library/Android/sdk/platform-tools/adb';
const out='/private/tmp/arm-video-demo/android-recording';fs.mkdirSync(out,{recursive:true});
const run=(...args)=>execFileSync(adb,['-s','emulator-5554',...args],{encoding:'utf8'});
const pause=ms=>new Promise(r=>setTimeout(r,ms));
function nodes(){run('shell','uiautomator','dump','/sdcard/arm-video-ui.xml');return [...run('shell','cat','/sdcard/arm-video-ui.xml').matchAll(/<node\s+([^>]+)>/g)].map(m=>Object.fromEntries([...m[1].matchAll(/([\w-]+)="([^"]*)"/g)].map(x=>[x[1],x[2]])));}
function tapNode(n){if(!n)throw new Error('Android node not found');const a=n.bounds.match(/\d+/g).map(Number);run('shell','input','tap',String((a[0]+a[2])>>1),String((a[1]+a[3])>>1));}
function tap(text){tapNode(nodes().find(n=>n.text===text||n['content-desc']===text));}
const manifest={source:'real demo Android APK + real local Laravel API; synthetic data',frames:[],scenes:[],checks:[]};
(async()=>{let start=performance.now(),frame=0,finished=false,active='C01';
 function scene(code,title){active=code;manifest.scenes.push({code,title,start_seconds:(performance.now()-start)/1000});}
 const capture=(async()=>{while(!finished){const file='frame-'+String(frame++).padStart(5,'0')+'.png';fs.writeFileSync(path.join(out,file),execFileSync(adb,['-s','emulator-5554','exec-out','screencap','-p']));manifest.frames.push({file,time_seconds:(performance.now()-start)/1000,scene:active});await pause(240);}})();
 try{
  scene('C01','Android · рабочий чат своей колонны');await pause(1500);tap('Рабочий чат · учебная колонна');await pause(1600);
  tapNode(nodes().find(n=>n.class==='android.widget.EditText'));run('shell','input','text','OK');run('shell','input','keyevent','4');await pause(600);tap('Отправить');await pause(2000);
  if(!nodes().some(n=>n.text==='OK'))throw new Error('Message not visible after sending');manifest.checks.push({type:'android_chat_message_sent',ok:true});
  run('shell','input','keyevent','4');await pause(700);tap('Профиль');scene('U08','Android · личный профиль и общие данные');await pause(1700);
  const n=nodes().find(n=>(n.text||n['content-desc']||'').includes('Личный табель'));tapNode(n);scene('U02','Android · та же смена, что сохранена на компьютере');await pause(2000);
  let texts=nodes().filter(n=>n.text).map(n=>n.text);fs.writeFileSync(out+'/worktime-text.json',JSON.stringify(texts,null,2));
  manifest.checks.push({type:'android_worktime_read',has_8h30:texts.some(t=>t.includes('8 ч 30'))});
  run('shell','input','keyevent','4');await pause(700);tap('Тесты');scene('U06','Android · учебный тест');await pause(1500);tap('Учебный пример проверки знаний');await pause(1800);
  fs.writeFileSync(out+'/quiz-ui.json',JSON.stringify(nodes().filter(n=>n.text||n['content-desc']),null,2));
  manifest.duration_seconds=(performance.now()-start)/1000;
 }finally{finished=true;await capture;fs.writeFileSync(out+'/manifest.json',JSON.stringify(manifest,null,2));}
 console.log(JSON.stringify({duration:manifest.duration_seconds,frames:manifest.frames.length,checks:manifest.checks}));
})().catch(e=>{console.error(e.message);process.exitCode=1});
