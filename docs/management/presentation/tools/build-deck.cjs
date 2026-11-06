// Local artifact generator; no external assets or publication.
const fs=require('node:fs'),path=require('node:path');
const PptxGenJS=require('/private/tmp/arm-presentation-tools/node_modules/pptxgenjs');
const baseDir=path.resolve(__dirname,'..');
const technical=process.argv.includes('--technical');
const dir=technical?path.join(baseDir,'technical'):baseDir;
fs.mkdirSync(dir,{recursive:true});
function contain(p,x,y,w,h){const b=fs.readFileSync(p);const iw=b.readUInt32BE(16),ih=b.readUInt32BE(20),k=Math.min(w/iw,h/ih);return {x:x+(w-iw*k)/2,y:y+(h-ih*k)/2,w:iw*k,h:ih*k};}
const slides=JSON.parse(fs.readFileSync(path.join(__dirname,technical?'technical-slides.json':'general-slides.json'),'utf8'));
const pptx=new PptxGenJS();pptx.layout='LAYOUT_WIDE';pptx.author='ARM';pptx.subject='ARM: возможности, защита и развитие';pptx.title='ARM — работа, контроль и обучение';pptx.lang='ru-RU';pptx.theme={headFontFace:'Arial',bodyFontFace:'Arial',lang:'ru-RU'};
const C={bg:'0B1220',panel:'152238',text:'F2F6FC',muted:'ACBCD0',gold:'FFBC66'};
const esc=s=>s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let html=[];
for(const [i,s] of slides.entries()){
 const slide=pptx.addSlide();slide.background={color:C.bg};
 slide.addShape(pptx.ShapeType.rect,{x:0,y:0,w:.13,h:7.5,line:{color:C.gold},fill:{color:C.gold}});
 slide.addText(s.k,{x:.5,y:.35,w:12,h:.25,fontSize:11,bold:true,charSpacing:2,color:C.gold,margin:0});
 slide.addText(s.title,{x:.5,y:.87,w:12.3,h:.65,fontSize:30,bold:true,color:C.text,margin:0,breakLine:false});
 slide.addText(s.lead,{x:.52,y:1.65,w:12,h:.65,fontSize:17,color:C.muted,margin:0});
 let y=2.55;
 if(s.steps){const gap=.18,w=(12-(s.steps.length-1)*gap)/s.steps.length;s.steps.forEach((label,j)=>{slide.addShape(pptx.ShapeType.roundRect,{x:.55+j*(w+gap),y,w,h:.9,rectRadius:.1,line:{color:'314460'},fill:{color:C.panel}});slide.addText(label,{x:.65+j*(w+gap),y:y+.16,w:w-.2,h:.55,fontSize:s.steps.length>4?15:18,bold:true,color:C.text,margin:0,align:'center',valign:'mid'});});y+=1.3;}
 if(s.images){let boxes=[];
 if(s.images.length===2) boxes=[{x:.55,y:2.5,w:9.25,h:4.15},{x:10.05,y:2.4,w:2.65,h:4.4}];
 else if(s.body) boxes=[{x:9.35,y:2.45,w:3.1,h:4.25}];
 else boxes=[{x:.55,y:2.5,w:12.15,h:4.05}];
 for(const [j,img] of s.images.entries()){const p=path.join(baseDir,'screenshots',img);if(!fs.existsSync(p))throw new Error('Missing '+img);slide.addImage({path:p,...contain(p,boxes[j].x,boxes[j].y,boxes[j].w,boxes[j].h)});}
 }
 if(s.body){let w=s.images?8.25:11.8;for(const line of s.body){slide.addText('• '+line,{x:.6,y,w,h:.62,fontSize:s.images?19:21,color:C.text,margin:0,breakLine:false,valign:'top'});y+=s.steps?.75:.78;}}
 slide.addShape(pptx.ShapeType.line,{x:.5,y:6.94,w:12.25,h:0,line:{color:'314460',width:1}});
 slide.addText(s.status,{x:.55,y:7.05,w:11.6,h:.22,fontSize:9.5,color:C.gold,margin:0});slide.addText(String(i+1).padStart(2,'0'),{x:12.25,y:7.03,w:.45,h:.25,fontSize:11,color:C.muted,margin:0,align:'right'});slide.addNotes(s.notes);
 html.push(`<section class="slide" id="s${i+1}"><header>${esc(s.k)}</header><h1>${esc(s.title)}</h1><p class="lead">${esc(s.lead)}</p>${s.steps?`<div class="steps">${s.steps.map(x=>`<div>${esc(x)}</div>`).join('')}</div>`:''}<div class="content ${s.images?'with-images':''}">${s.body?`<ul>${s.body.map(x=>`<li>${esc(x)}</li>`).join('')}</ul>`:''}${s.images?`<div class="images ${s.images.length===2?'pair':''}">${s.images.map(x=>`<img src="${technical?'../':''}screenshots/${x}" alt="${esc(x)}">`).join('')}</div>`:''}</div><footer>${esc(s.status)}<span>${i+1} / ${slides.length}</span></footer></section>`);
}
const css=`*{box-sizing:border-box}body{margin:0;background:#070d16;color:#f2f6fc;font-family:Arial,sans-serif}.slide{width:1280px;height:720px;margin:24px auto;background:#0b1220;position:relative;padding:32px 46px 44px;border-left:12px solid #ffbc66;overflow:hidden;page-break-after:always}header{font-size:14px;letter-spacing:2px;color:#ffbc66;font-weight:bold}h1{font-size:36px;line-height:1.15;margin:26px 0 14px}.lead{color:#acbcd0;font-size:22px;line-height:1.35;margin:0 0 22px}.steps{display:flex;gap:12px;margin:25px 0}.steps div{flex:1;background:#152238;border:1px solid #314460;padding:20px 8px;border-radius:12px;text-align:center;font-size:20px}.content{display:flex;gap:25px;align-items:flex-start}.content ul{margin:8px 0;padding-left:24px;font-size:25px;line-height:1.5}.content li{margin-bottom:13px}.with-images ul{flex:1;font-size:23px}.images{display:flex;justify-content:center;width:100%;height:405px;gap:28px}.images img{object-fit:contain;max-width:100%;height:100%;min-width:0}.images.pair img:first-child{width:78%}.images.pair img:last-child{width:20%}.with-images ul+.images{width:290px;height:405px}footer{position:absolute;bottom:20px;left:46px;right:38px;color:#ffbc66;border-top:1px solid #314460;padding-top:10px;font-size:13px}footer span{float:right;color:#acbcd0}@media print{@page{size:1280px 720px;margin:0}body{background:#0b1220}.slide{margin:0;break-after:page;print-color-adjust:exact;-webkit-print-color-adjust:exact}}`;
fs.writeFileSync(path.join(dir,'index.html'),`<!doctype html><html lang="ru"><meta charset="utf-8"><title>ARM — презентация</title><style>${css}</style><body>${html.join('')}<script>let i=0;addEventListener('keydown',e=>{if(['ArrowRight','PageDown','ArrowLeft','PageUp'].includes(e.key)){e.preventDefault();i=Math.max(0,Math.min(${slides.length-1},i+(['ArrowRight','PageDown'].includes(e.key)?1:-1)));document.querySelectorAll('.slide')[i].scrollIntoView({behavior:'smooth'});}});</script></body></html>`);
fs.writeFileSync(path.join(dir,'SPEAKER_NOTES.md'),'# Заметки выступающего\n\nРедакция от 02.11.2026. '+(technical?'Техническая версия.':'Версия для пользователей и руководства.')+'\n\n'+slides.map((s,i)=>`## ${i+1}. ${s.title}\n\n${s.notes}\n`).join('\n'));
fs.writeFileSync(path.join(dir,'slides.json'),JSON.stringify(slides,null,2));
pptx.writeFile({fileName:path.join(dir,'ARM_Management_2026-11-02.pptx'),compression:true}).then(()=>console.log('Built '+slides.length+' slides: PPTX / HTML / notes.'));
