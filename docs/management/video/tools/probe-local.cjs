const {chromium}=require('/private/tmp/arm-video-tools/node_modules/playwright');
const fs=require('node:fs');
(async()=>{
 const browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true,args:['--disable-background-networking']});
 const accounts=JSON.parse(fs.readFileSync('/private/tmp/arm-video-demo/accounts.json'));
 const results=[];
 for(const [role,paths] of Object.entries({driver:['/mainMenu','/worktime','/rosisi','/podstroiki','/account'],instructor:['/journal','/journal/training-requests','/journal/standards'],dispatcher:['/naryad','/nsi'],operator:['/uchet'],super_admin:['/admin']})){
  const context=await browser.newContext({viewport:{width:1600,height:1000},locale:'ru-RU'});
  await context.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.continue():r.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('http://127.0.0.1:8786/login');await page.locator('[name=email]').fill(accounts[role].email);await page.locator('[name=password]').fill(accounts[role].password);
  await Promise.all([page.waitForURL(u=>!u.pathname.endsWith('/login')),page.locator('button[type=submit]').click()]);
  for(const path of paths){const r=await page.goto('http://127.0.0.1:8786'+path);await page.waitForTimeout(800);const text=await page.locator('body').innerText();results.push({role,path,status:r.status(),characters:text.length,errorPage:/SQLSTATE|Fatal error|Internal Server Error/.test(text),jsErrors:[...errors]});await page.screenshot({path:'/private/tmp/arm-video-demo/'+role+'-'+path.replaceAll('/','-')+'.png'});}
  await context.close();
 }
 fs.writeFileSync('/private/tmp/arm-video-demo/probe.json',JSON.stringify(results,null,2));console.log(JSON.stringify(results,null,2));await browser.close();
})().catch(e=>{console.error(e.message);process.exit(1)});
