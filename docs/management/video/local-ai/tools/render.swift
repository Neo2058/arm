// Original vector artwork and animation. No production APIs, models or external assets.
import AppKit
import AVFoundation
import CoreVideo
import Foundation
let plan=URL(fileURLWithPath:CommandLine.arguments[1]),out=URL(fileURLWithPath:CommandLine.arguments[2])
let scenes=try JSONSerialization.jsonObject(with:Data(contentsOf:plan)) as! [[String:Any]]
let fm=FileManager.default;try fm.createDirectory(at:out,withIntermediateDirectories:true)
let file=out.appendingPathComponent("ARM_local_AI_silent.mp4")
guard !fm.fileExists(atPath:file.path) else{fatalError("Output exists; use a new output directory")}
let writer=try AVAssetWriter(outputURL:file,fileType:.mp4)
let input=AVAssetWriterInput(mediaType:.video,outputSettings:[AVVideoCodecKey:AVVideoCodecType.h264,AVVideoWidthKey:1920,AVVideoHeightKey:1080,AVVideoCompressionPropertiesKey:[AVVideoAverageBitRateKey:7_000_000,AVVideoExpectedSourceFrameRateKey:30,AVVideoMaxKeyFrameIntervalKey:30]])
let adaptor=AVAssetWriterInputPixelBufferAdaptor(assetWriterInput:input,sourcePixelBufferAttributes:[kCVPixelBufferPixelFormatTypeKey as String:kCVPixelFormatType_32ARGB,kCVPixelBufferWidthKey as String:1920,kCVPixelBufferHeightKey as String:1080,kCVPixelBufferCGImageCompatibilityKey as String:true,kCVPixelBufferCGBitmapContextCompatibilityKey as String:true])
writer.add(input);guard writer.startWriting()else{throw writer.error!};writer.startSession(atSourceTime:.zero)
let bg=NSColor(srgbRed:0.025,green:0.045,blue:0.075,alpha:1),panel=NSColor(srgbRed:0.065,green:0.10,blue:0.15,alpha:1),card=NSColor(srgbRed:0.10,green:0.16,blue:0.22,alpha:1),white=NSColor(srgbRed:0.93,green:0.97,blue:1,alpha:1),muted=NSColor(srgbRed:0.57,green:0.68,blue:0.76,alpha:1),cyan=NSColor(srgbRed:0.23,green:0.90,blue:0.81,alpha:1),gold=NSColor(srgbRed:1,green:0.67,blue:0.30,alpha:1),red=NSColor(srgbRed:1,green:0.38,blue:0.40,alpha:1)
func box(_ x:Double,_ y:Double,_ w:Double,_ h:Double,_ color:NSColor,_ r:Double=18){color.setFill();NSBezierPath(roundedRect:NSRect(x:x,y:1080-y-h,width:w,height:h),xRadius:r,yRadius:r).fill()}
func txt(_ s:String,_ x:Double,_ y:Double,_ w:Double,_ h:Double,_ size:Double=25,_ color:NSColor=white,_ weight:NSFont.Weight = .regular){let p=NSMutableParagraphStyle();p.lineSpacing=7;(s as NSString).draw(in:NSRect(x:x,y:1080-y-h,width:w,height:h),withAttributes:[.font:NSFont.systemFont(ofSize:size,weight:weight),.foregroundColor:color,.paragraphStyle:p])}
func line(_ ax:Double,_ ay:Double,_ bx:Double,_ by:Double,_ color:NSColor,_ width:Double=2){color.setStroke();let p=NSBezierPath();p.move(to:NSPoint(x:ax,y:1080-ay));p.line(to:NSPoint(x:bx,y:1080-by));p.lineWidth=width;p.stroke()}
func dot(_ x:Double,_ y:Double,_ r:Double,_ color:NSColor){color.setFill();NSBezierPath(ovalIn:NSRect(x:x-r,y:1080-y-r,width:r*2,height:r*2)).fill()}
func pulse(_ a:Double,_ b:Double,_ c:Double,_ d:Double,_ t:Double,_ count:Int=4){line(a,b,c,d,cyan.withAlphaComponent(0.2),2);for i in 0..<count{let q=(t*0.19+Double(i)/Double(count)).truncatingRemainder(dividingBy:1);dot(a+(c-a)*q,b+(d-b)*q,4,cyan)}}
func typed(_ s:String,_ t:Double,_ speed:Double=22)->String{String(s.prefix(max(0,min(s.count,Int(t*speed)))))}
func badge(_ s:String,_ x:Double,_ y:Double,_ w:Double,_ color:NSColor=cyan){box(x,y,w,38,color.withAlphaComponent(0.13),10);txt(s,x+12,y+7,w-20,30,16,color,.semibold)}
func stage(_ t:Double,_ delay:Double)->Double{max(0,min(1,(t-delay)/1.3))}
func document(_ x:Double,_ y:Double,_ w:Double,_ h:Double,_ title:String){box(x,y,w,h,NSColor(srgbRed:0.88,green:0.91,blue:0.91,alpha:1),12);txt(title,x+20,y+22,w-40,70,21,bg,.bold);for i in 0..<6{box(x+22,y+120+Double(i)*26,w-50-Double(i%3)*30,5,bg.withAlphaComponent(0.22),2)}}
func node(_ x:Double,_ y:Double,_ label:String,_ subtitle:String){box(x,y,230,145,card);dot(x+30,y+30,7,cyan);txt(label,x+20,y+53,195,48,27,white,.semibold);txt(subtitle,x+20,y+107,195,30,16,muted)}
func draw(_ mode:String,_ t:Double,_ duration:Double){
 let x=855.0,y=260.0
 switch mode {
 case "mesh":
  // Symmetric bus routing: every connection stays in the gaps between cards.
  let hub=x+450, bus=y+245, left=x+130, right=x+770
  pulse(hub,y+180,hub,bus,t,2)
  pulse(hub,bus,left,bus,t+0.5,3);pulse(hub,bus,right,bus,t+1,3)
  for (i,xx) in [left,hub,right].enumerated(){pulse(xx,bus,xx,y+315,t+Double(i)*0.6,2)}
  node(x+335,y+35,"Локальный ИИ","Внутренняя сеть")
  node(x+15,y+315,"Веб АРМ","Права сотрудника")
  node(x+335,y+315,"Android","Доступные сценарии")
  node(x+655,y+315,"Документы","Существующий MinIO")
  badge("БЕЗ ОБЛАЧНОЙ МОДЕЛИ",x+225,y+510,470)
 case "closing":
  txt("Начнём с одной проверяемой задачи",x+45,y+45,860,90,30,cyan,.semibold)
  let labels=["Найти\nоснование","Разобрать\nдокумент","Подготовить\nчерновик"]
  for i in 0..<2{pulse(x+280+Double(i)*310,y+300,x+320+Double(i)*310,y+300,t+Double(i),2)}
  for i in 0..<3{
   let xx=x+15+Double(i)*310
   box(xx,y+185,265,245,card)
   dot(xx+43,y+230,22,cyan.withAlphaComponent(0.14))
   txt(String(i+1),xx+34,y+214,45,45,25,cyan,.bold)
   txt(labels[i],xx+22,y+280,225,120,27,white,.semibold)
  }
  badge("ВЫ ПРОВЕРЯЕТЕ И ПРИНИМАЕТЕ РЕШЕНИЕ",x+80,y+500,780,gold)
 case "chat":
  box(x+20,y+10,900,515,card,18);txt("АРМ / ПОМОЩНИК",x+45,y+32,500,40,20,cyan,.bold);badge("ВАШЕ РАБОЧЕЕ ОКНО",x+650,y+25,240,gold)
  box(x+44,y+95,840,80,panel);txt(typed("Найди требования в доступной мне инструкции",t-1),x+65,y+118,800,55,24)
  if t>4{box(x+44,y+201,840,175,panel);txt(typed("Давайте посмотрим вашу инструкцию. Здесь есть нужный раздел — можно открыть страницу и проверить подробности.",t-4,15),x+65,y+225,790,145,25)}
  if t>10{badge("Открыть документ на нужной странице",x+55,y+405,455);badge("Вы решаете, что сохранить",x+530,y+405,340,gold)}
  box(x+44,y+465,840,40,bg);txt("Задать вопрос или приложить документ…",x+58,y+474,790,28,17,muted)
 case "rag":
  for i in 0..<3{let yy=y+30+Double(i)*140;box(x+10,yy,260,105,card);txt(["Инструкция А","Редакция Б","Чужой документ"][i],x+27,yy+18,235,40,22);txt(i==2 ? "Нет доступа" : "Разрешено",x+27,yy+64,230,28,17,i==2 ? red : cyan)}
  pulse(x+275,y+100,x+440,y+210,t);pulse(x+275,y+240,x+440,y+210,t+1);line(x+275,y+380,x+350,y+380,red,3);txt("×",x+320,y+345,70,65,48,red)
  node(x+450,y+140,"Модель","Доступные источники");pulse(x+680,y+220,x+775,y+220,t);box(x+760,y+110,160,260,card);txt("Ответ",x+780,y+139,135,50,25,cyan,.bold);txt("+ источник\n+ страница\n+ редакция",x+780,y+205,135,140,21)
  badge("Каждому — доступные ему материалы",x+335,y+465,460)
 case "ocr", "review":
  document(x+10,y+20,390,470,"Рапорт")
  let handwritten="Прошу уточнить дату\nи подготовить ответ.\nСрок: 18 ноября.\nПодпись"
  box(x+28,y+115,350,240,NSColor(srgbRed:0.88,green:0.91,blue:0.91,alpha:1),0)
  let p=NSMutableParagraphStyle();p.lineSpacing=16
  (handwritten as NSString).draw(in:NSRect(x:x+35,y:1080-(y+125)-260,width:330,height:260),withAttributes:[.font:NSFont(name:"TimesNewRomanPS-ItalicMT",size:29) ?? NSFont.systemFont(ofSize:29),.foregroundColor:bg,.paragraphStyle:p])
  let scan=(t/7).truncatingRemainder(dividingBy:1);box(x+20,y+100+scan*320,370,3,cyan)
  pulse(x+405,y+220,x+485,y+220,t,2)
  for i in 0..<4{let labels=["Вид: рапорт","Дата: требует проверки","Задача: подготовить ответ","Статус: черновик"];let color=i==1 ? gold : cyan;box(x+495,y+30+Double(i)*100,420,80,card);txt(String(labels[i].prefix(Int(Double(labels[i].count)*stage(t,Double(i)*1.3+1)))),x+512,y+52+Double(i)*100,390,60,22,color)}
  badge(mode=="review" && t>9 ? "ГОТОВО, МОЖНО СОХРАНЯТЬ" : "ПОСМОТРИТЕ, ВСЁ ЛИ ВЕРНО",x+445,y+465,470,mode=="review" && t>9 ? cyan : gold)
 case "tasks":
  let headers=["Предложено ИИ","Проверка","Назначено"]
  for i in 0..<3{box(x+Double(i)*310,y+20,292,475,card);txt(headers[i],x+18+Double(i)*310,y+42,268,50,24,i==2 ? cyan : white,.semibold)}
  let q=min(1,max(0,(t-4)/10)),xx=x+12+620*q
  box(xx,y+135,265,210,panel);txt("Подготовить ответ",xx+18,y+155,230,70,25,white,.semibold);txt("Исполнитель: А\nСрок: согласовать",xx+18,y+235,230,95,19,muted)
  badge(t>12 ? "Создано через API" : "Ожидает подтверждения",x+320,y+415,285,t>12 ? cyan : gold)
 case "draft":
  box(x+10,y+15,270,475,card);txt("ИСХОДНЫЕ ДАННЫЕ",x+30,y+38,245,60,19,cyan,.bold);txt("Шаблон отдела\n\nПодтверждённые факты\n\nСсылки на материалы",x+30,y+120,230,290,23)
  document(x+310,y+15,600,475,"ПРОЕКТ СЛУЖЕБНОЙ ЗАПИСКИ")
  box(x+325,y+110,570,320,NSColor(srgbRed:0.88,green:0.91,blue:0.91,alpha:1),0)
  txt(typed("На основании проверенных материалов\nпредлагается подготовить ответ.\n\nНеобходимо уточнить: [дата].\n\nОтветственный: [подтвердить].\n\nДокумент направляется на согласование.",t-2,13),x+342,y+130,540,315,24,bg)
  badge("ПРОЧИТАЙТЕ И ДОПОЛНИТЕ",x+440,y+440,430,gold)
 case "security":
  box(x+40,y+25,835,440,card,25);txt("ЗАКРЫТЫЙ КОНТУР ОРГАНИЗАЦИИ",x+70,y+48,800,60,24,cyan,.bold)
  node(x+80,y+150,"АРМ","Права и лимиты");node(x+530,y+150,"Сервис ИИ","Закрытый API");pulse(x+310,y+220,x+530,y+220,t)
  line(x+650,y+300,x+650,y+410,red,3);txt("×",x+620,y+365,80,70,45,red);txt("ВНЕШНИЙ ДОСТУП ЗАКРЫТ",x+350,y+417,500,40,21,red,.semibold)
  badge("Рабочие материалы остаются внутри организации",x+120,y+495,620,gold)
 case "audit":
  box(x+15,y+15,890,490,NSColor(srgbRed:0.025,green:0.065,blue:0.085,alpha:1));txt("ИСТОРИЯ ОБРАЩЕНИЙ",x+40,y+40,820,40,21,cyan,.bold)
  let logs=["09:00  user-001  вход: разрешён","09:01  запрос к разрешённым материалам","09:01  чужой документ: доступ запрещён","09:02  результат: черновик","09:03  сотрудник подтвердил действие","09:03  API: задача создана"]
  for (i,l) in logs.enumerated(){if t>Double(i)*1.6+1{txt(typed(l,t-Double(i)*1.6-1,30),x+40,y+110+Double(i)*51,830,42,22,i==2 ? gold : white)}}
  badge("Без паролей и токенов в логах",x+70,y+450,710)
 case "hardware", "scale":
  let labels=mode=="hardware" ? ["CPU: 8–12 ядер","RAM: 32 ГБ","GPU: 24 ГБ VRAM"] : ["NVMe: от 1 ТБ","RAM: 128 ГБ","VRAM: 48 ГБ+"]
  for i in 0..<3{let xx=x+Double(i)*310;box(xx,y+50,285,340,card);txt(["СТЕНД","ПАМЯТЬ","РАСШИРЕНИЕ"][i],xx+20,y+75,245,50,17,cyan,.bold);for j in 0..<5{let yy=y+150+Double(j)*30;box(xx+22,yy,240,16,panel,4);let progress=min(1,stage(t,Double(i))*0.82+0.08*sin(t+Double(j)));box(xx+22,yy,max(5,240*progress),16,cyan.withAlphaComponent(0.6),4)};txt(labels[i],xx+20,y+320,245,60,25,white,.semibold)}
  badge(mode=="hardware" ? "Для проекта: 64 ГБ памяти + отдельная видеокарта" : "Очереди и нагрузка проверяются измерением",x+20,y+445,875,gold)
 case "training":
  let labels=["База знаний","Примеры","Адаптер LoRA"]
  for i in 0..<3{node(x+Double(i)*310,y+105,labels[i],["Документы и редакции","Проверено специалистом","Отдельный эксперимент"][i]);if i<2{pulse(x+230+Double(i)*310,y+180,x+310+Double(i)*310,y+180,t+Double(i),2)}}
  txt("Новая инструкция — новое знание для поиска",x+50,y+355,850,70,30,cyan,.semibold);txt("Для обучения выбираем подходящие примеры вместе со специалистом",x+50,y+440,850,80,25,muted)
 case "quality":
  let labels=["Подтверждённый источник","Правильность полей","Чужие данные недоступны","Отказ при недостатке данных","Скорость и очередь"]
  for (i,l) in labels.enumerated(){let yy=y+20+Double(i)*92;box(x+20,yy,870,72,card);let ready=t>Double(i)*2+2;dot(x+55,yy+35,9,ready ? cyan : muted);txt(l,x+82,yy+20,770,50,25,ready ? white : muted)}
 case "timeline":
  let milestones=["1–2 недели","2–4 недели","4–6 недель","6–10 недель"],labels=["Поиск и ответ","Рукописи / черновик","Задачи / интеграция","Запуск проекта"]
  line(x+65,y+225,x+855,y+225,muted.withAlphaComponent(0.4),4)
  for i in 0..<4{let xx=x+40+Double(i)*220;dot(xx+35,y+225,14,stage(t,Double(i)*3)>0.5 ? cyan : muted);txt(milestones[i],xx,y+120,215,80,25,cyan,.bold);txt(labels[i],xx,y+285,210,120,23)}
  badge("Сроки уточним по вашим документам и оборудованию",x+20,y+455,880,gold)
 case "operations":
  let labels=["Разработчик","Эксперт цеха","Администратор"]
  for i in 0..<3{let xx=x+Double(i)*310;dot(xx+125,y+115,42,cyan.withAlphaComponent(0.18));dot(xx+125,y+104,16,cyan);box(xx+91,y+128,68,35,cyan,15);box(xx+10,y+205,270,140,card);txt(labels[i],xx+27,y+240,245,70,25,white,.semibold)}
  txt("Оборудование, поддержка и люди, знающие свою работу",x+30,y+430,860,100,28,cyan)
 default:break
 }
}
let totalFrames=scenes.reduce(0){$0+($1["seconds"] as! Int)*30}
var frame=0
for (index,s) in scenes.enumerated(){let seconds=s["seconds"] as! Int
 for f in 0..<(seconds*30){while !input.isReadyForMoreMediaData{if writer.status == .failed{throw writer.error!};Thread.sleep(forTimeInterval:0.002)}
  try autoreleasepool{
   let t=Double(f)/30
   var optional:CVPixelBuffer?;guard CVPixelBufferPoolCreatePixelBuffer(nil,adaptor.pixelBufferPool!,&optional)==kCVReturnSuccess,let buffer=optional else{fatalError("Pixel buffer unavailable")}
   CVPixelBufferLockBaseAddress(buffer,[])
   let ctx=CGContext(data:CVPixelBufferGetBaseAddress(buffer),width:1920,height:1080,bitsPerComponent:8,bytesPerRow:CVPixelBufferGetBytesPerRow(buffer),space:CGColorSpaceCreateDeviceRGB(),bitmapInfo:CGImageAlphaInfo.noneSkipFirst.rawValue|CGBitmapInfo.byteOrder32Big.rawValue)!
   NSGraphicsContext.saveGraphicsState();NSGraphicsContext.current=NSGraphicsContext(cgContext:ctx,flipped:false)
   box(0,0,1920,1080,bg,0)
   for i in 0..<18{let xx=Double(i)*115;line(xx,0,xx,1080,cyan.withAlphaComponent(0.025),1)}
   for i in 0..<10{let yy=Double(i)*115;line(0,yy,1920,yy,cyan.withAlphaComponent(0.025),1)}
   for i in 0..<15{let xx=(Double(i)*137+t*12).truncatingRemainder(dividingBy:1920);let yy=Double(i*83%900)+70;dot(xx,yy,2,cyan.withAlphaComponent(0.18))}
   let fade=max(0,min(1,min(t/0.3,(Double(seconds)-t)/0.3)));ctx.setAlpha(fade)
   txt("ТЧ-15 / ARM",88,65,550,60,30,cyan,.bold);badge("ПОМОЩНИК ВНУТРИ АРМ",1300,64,530,gold)
   txt((s["kicker"] as! String).uppercased(),88,212,700,80,19,cyan,.semibold)
   txt(s["title"] as! String,88,315,710,250,60,white,.bold)
   for (i,b) in (s["bullets"] as! [String]).enumerated(){txt(b,88,625+Double(i)*95,690,90,26,muted)}
   box(825,230,1010,640,panel,30);draw(s["mode"] as! String,t,Double(seconds))
   txt(s["note"] as! String,88,920,1735,80,19,muted)
   txt("РАБОТА С ДОКУМЕНТАМИ · ПОМОЩЬ СОТРУДНИКАМ",88,1010,1500,40,15,muted,.medium)
   txt(String(format:"%02d / %02d",index+1,scenes.count),1695,1010,150,40,17,cyan,.semibold)
   box(88,1061,1740,4,card,0);box(88,1061,1740*(Double(frame)+1)/Double(totalFrames),4,cyan,0)
   NSGraphicsContext.restoreGraphicsState()
   if f==seconds*15 || f==seconds*10 {let data=NSBitmapImageRep(cgImage:ctx.makeImage()!).representation(using:.png,properties:[:])!;try data.write(to:out.appendingPathComponent(String(format:"scene-%02d-%@.png",index+1,f==seconds*15 ? "mid" : "early")))}
   CVPixelBufferUnlockBaseAddress(buffer,[])
   guard adaptor.append(buffer,withPresentationTime:CMTime(value:Int64(frame),timescale:30))else{throw writer.error!};frame+=1
  }
 }
 print("SCENE_DONE \(index+1) \(s["code"]!)");fflush(stdout)
}
input.markAsFinished();let semaphore=DispatchSemaphore(value:0);writer.finishWriting{semaphore.signal()};semaphore.wait();guard writer.status == .completed else{throw writer.error!}
print("VIDEO_DONE frames=\(frame) seconds=\(Double(frame)/30) file=\(file.path)")
