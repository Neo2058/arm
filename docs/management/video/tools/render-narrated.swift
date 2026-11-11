// Narration-timed renderer. Inputs are an explicit scene plan and an empty output folder.
import AppKit
import AVFoundation
import CoreVideo
import Foundation
let plan=URL(fileURLWithPath:CommandLine.arguments[1]),folder=URL(fileURLWithPath:CommandLine.arguments[2])
let sourceRoot=plan.deletingLastPathComponent()
let chapters=try JSONSerialization.jsonObject(with:Data(contentsOf:plan)) as! [[String:Any]]
let mov=false,codec:AVVideoCodecType = .h264
let file=folder.appendingPathComponent("ARM_narrated_picture.mp4")
guard !FileManager.default.fileExists(atPath:file.path) else { fatalError("Refusing to overwrite existing render: " + file.path) }
let writer=try AVAssetWriter(outputURL:file,fileType:mov ? .mov : .mp4)
var settings:[String:Any]=[AVVideoCodecKey:codec,AVVideoWidthKey:1920,AVVideoHeightKey:1080]
if !mov {settings[AVVideoCompressionPropertiesKey]=[AVVideoAverageBitRateKey:8_000_000,AVVideoExpectedSourceFrameRateKey:30,AVVideoMaxKeyFrameIntervalKey:30]}
let input=AVAssetWriterInput(mediaType:.video,outputSettings:settings)
let adaptor=AVAssetWriterInputPixelBufferAdaptor(assetWriterInput:input,sourcePixelBufferAttributes:[kCVPixelBufferPixelFormatTypeKey as String:kCVPixelFormatType_32ARGB,kCVPixelBufferWidthKey as String:1920,kCVPixelBufferHeightKey as String:1080,kCVPixelBufferCGImageCompatibilityKey as String:true,kCVPixelBufferCGBitmapContextCompatibilityKey as String:true])
writer.add(input);guard writer.startWriting() else{throw writer.error!};writer.startSession(atSourceTime:.zero)
let white=NSColor(srgbRed:0.94,green:0.96,blue:0.99,alpha:1),muted=NSColor(srgbRed:0.65,green:0.72,blue:0.81,alpha:1),accent=NSColor(srgbRed:1,green:0.62,blue:0.30,alpha:1)
func text(_ value:String,_ x:CGFloat,_ top:CGFloat,_ w:CGFloat,_ h:CGFloat,_ size:CGFloat,_ ink:NSColor,_ weight:NSFont.Weight = .regular){let p=NSMutableParagraphStyle();p.lineSpacing=10;(value as NSString).draw(in:NSRect(x:x,y:1080-top-h,width:w,height:h),withAttributes:[.font:NSFont.systemFont(ofSize:size,weight:weight),.foregroundColor:ink,.paragraphStyle:p])}
let diagrams:[String:[String]]=["API":["Веб и Android","API сервера","ARM-NRD"],"SECURITY":["Доступ к сети","Права и сервисы","Копии и контроль"],"DEVICES":["Персональные поля","Ключ данных","Устройства"],"ARCHIVE":["Рабочий архив","Независимая копия","Изолированная копия"],"CAPACITY":["Сервер","Сеть и файлы","Сопровождение"],"AI":["Материалы и права","Локальная модель","Ответ с источниками"],"END":["Машинист","Инструктор","Нарядчик и учёт"]]
var absolute=0
for (chapterIndex,c) in chapters.enumerated(){
 let frameCount=c["duration_frames"] as! Int
 let seconds=Double(frameCount)/30.0
 var entries:[[String:Any]]=[],sourceFolder="",begin=0.0,end=0.0
 if let sequence=c["sequence"] as? [String:Any]{sourceFolder=sourceRoot.appendingPathComponent(sequence["folder"] as! String).standardized.path;begin=sequence["from"] as! Double;end=sequence["to"] as! Double;let m=try JSONSerialization.jsonObject(with:Data(contentsOf:URL(fileURLWithPath:sourceFolder).appendingPathComponent("manifest.json"))) as! [String:Any];entries=m["frames"] as! [[String:Any]]}
 var previous="",image:NSImage?
 if let relative=c["image"] as? String {let p=sourceRoot.appendingPathComponent(relative).standardized.path;guard let loaded=NSImage(contentsOfFile:p)else{fatalError("Missing image: "+p)};image=loaded}
 for f in 0..<frameCount{
  while !input.isReadyForMoreMediaData {if writer.status == .failed{throw writer.error!};Thread.sleep(forTimeInterval:0.002)}
  try autoreleasepool{
   let t=Double(f)/30.0
   if !entries.isEmpty {let stamp=min(end,begin+t);let item=entries.last(where:{($0["time_seconds"] as! Double)<=stamp}) ?? entries[0];let name=item["file"] as! String;if previous != name{previous=name;image=NSImage(contentsOfFile:sourceFolder+"/"+name)!}}
   var optional:CVPixelBuffer?;guard CVPixelBufferPoolCreatePixelBuffer(nil,adaptor.pixelBufferPool!,&optional)==kCVReturnSuccess,let buffer=optional else{fatalError("Pixel buffer failed")}
   CVPixelBufferLockBaseAddress(buffer,[])
   let ctx=CGContext(data:CVPixelBufferGetBaseAddress(buffer),width:1920,height:1080,bitsPerComponent:8,bytesPerRow:CVPixelBufferGetBytesPerRow(buffer),space:CGColorSpaceCreateDeviceRGB(),bitmapInfo:CGImageAlphaInfo.noneSkipFirst.rawValue|CGBitmapInfo.byteOrder32Big.rawValue)!
   ctx.setFillColor(NSColor(srgbRed:0.035,green:0.055,blue:0.086,alpha:1).cgColor);ctx.fill(CGRect(x:0,y:0,width:1920,height:1080))
   NSGraphicsContext.saveGraphicsState();NSGraphicsContext.current=NSGraphicsContext(cgContext:ctx,flipped:false)
   let fade=max(0,min(1,min(t/0.35,(Double(seconds)-t)/0.35)));ctx.setAlpha(CGFloat(fade))
   text("ТЧ-15 / ARM",96,72,800,60,30,accent,.semibold)
   text((c["kicker"] as! String).uppercased(),96,210,710,80,18,accent,.semibold)
   text(c["title"] as! String,96,302,730,260,59,white,.bold)
   for (i,b) in (c["bullets"] as! [String]).enumerated(){text(b,96,610+CGFloat(i*85),700,82,27,muted)}
   let card=NSRect(x:846,y:230,width:978,height:620)
   NSColor(srgbRed:0.08,green:0.12,blue:0.18,alpha:1).setFill();NSBezierPath(roundedRect:card,xRadius:26,yRadius:26).fill()
   if let im=image{let s=min(938/im.size.width,580/im.size.height),w=im.size.width*s,h=im.size.height*s;NSGraphicsContext.saveGraphicsState();NSBezierPath(roundedRect:card.insetBy(dx:14,dy:14),xRadius:16,yRadius:16).addClip();im.draw(in:NSRect(x:card.midX-w/2,y:card.midY-h/2,width:w,height:h));NSGraphicsContext.restoreGraphicsState()}
   else if let key=c["diagram"] as? String,let boxes=diagrams[key]{
    for (i,label) in boxes.enumerated(){let x=875+CGFloat(i*310);NSColor(srgbRed:0.13,green:0.20,blue:0.28,alpha:1).setFill();NSBezierPath(roundedRect:NSRect(x:x,y:485,width:285,height:160),xRadius:18,yRadius:18).fill();text(String(i+1),x+20,450,230,70,44,accent,.bold);text(label,x+20,515,245,100,25,white,.semibold);if i<2{text("→",x+286,500,30,60,26,accent)}}
    let caption=key=="ARCHIVE" ? "Проверка целостности · восстановление · обновления" : key=="AI" ? "Развитие · без передачи закрытых материалов наружу" : key=="DEVICES" ? "Проектирование · отзыв доступа и восстановление" : "Компоненты единого рабочего контура"
    text(caption,886,725,900,100,23,muted)
   }
   text(c["note"] as! String,96,923,1700,88,18,muted)
   text("ДЕМОНСТРАЦИЯ АРМ · УЧЕБНЫЕ ДАННЫЕ",96,1013,1400,32,15,muted,.medium)
   text(String(format:"%02d / %02d",chapterIndex+1,chapters.count),1660,1013,170,32,15,accent,.medium)
   NSGraphicsContext.restoreGraphicsState()
   if f==frameCount/2 && !mov {let png=NSBitmapImageRep(cgImage:ctx.makeImage()!).representation(using:.png,properties:[:])!;try png.write(to:folder.appendingPathComponent(String(format:"overview-%02d.png",chapterIndex+1)))}
   CVPixelBufferUnlockBaseAddress(buffer,[])
   guard adaptor.append(buffer,withPresentationTime:CMTime(value:Int64(absolute),timescale:30))else{throw writer.error!};absolute+=1
  }
 }
 print("CHAPTER_DONE \(chapterIndex+1) \(c["code"]!)")
}
input.markAsFinished();let sem=DispatchSemaphore(value:0);writer.finishWriting{sem.signal()};sem.wait();guard writer.status == .completed else{throw writer.error!}
print("OVERVIEW_CREATED frames=\(absolute) seconds=\(Double(absolute)/30) file=\(file.path)")
