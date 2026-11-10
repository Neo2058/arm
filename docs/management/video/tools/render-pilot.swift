// Native macOS renderer: no cloud services, credentials or production access.
// swift render-pilot.swift <repository root> <output directory>
import AppKit
import AVFoundation
import CoreVideo
import Foundation

let root = URL(fileURLWithPath: CommandLine.arguments[1])
let output = URL(fileURLWithPath: CommandLine.arguments[2])
try FileManager.default.createDirectory(at: output, withIntermediateDirectories: true)
let width = 1920, height = 1080, fps: Int32 = 30
let duration = 18, frames = 18 * 30
let scenes: [(String,String,String,String)] = [
    ("Единое рабочее пространство", "Рабочий день\nначинается здесь", "Документы, обучение и рабочая информация\nв привычном меню ТЧ-15.", "web-carousel.png"),
    ("Машинист", "Пожелание\nне потеряется", "Подстройки собраны в одном разделе.\nЗаявка и её результат остаются на виду.", "web-driver-podstroiki.png"),
    ("Инструктор", "Колонна\nпод контролем", "Задачи и состояние подготовки сотрудников —\nв рабочем журнале инструктора.", "web-journal.png")
]
let images = scenes.map { NSImage(contentsOf: root.appendingPathComponent("docs/management/presentation/screenshots/"+$0.3))! }
func color(_ r: CGFloat,_ g: CGFloat,_ b: CGFloat) -> NSColor { NSColor(srgbRed:r,green:g,blue:b,alpha:1) }
func text(_ value:String, _ x:CGFloat,_ top:CGFloat,_ w:CGFloat,_ h:CGFloat,_ size:CGFloat,_ ink:NSColor,_ weight:NSFont.Weight = .regular) {
    let p=NSMutableParagraphStyle();p.lineSpacing=12;p.lineBreakMode = .byWordWrapping
    (value as NSString).draw(in:NSRect(x:x,y:1080-top-h,width:w,height:h),withAttributes:[.font:NSFont.systemFont(ofSize:size,weight:weight),.foregroundColor:ink,.paragraphStyle:p])
}
func draw(_ context:CGContext,_ frame:Int) {
    context.setFillColor(color(0.035,0.055,0.086).cgColor);context.fill(CGRect(x:0,y:0,width:width,height:height))
    let index=min(2,frame/180), local=Double(frame%180)/30.0
    let fade=min(1,min(local/0.45,(6-local)/0.45))
    NSGraphicsContext.saveGraphicsState();NSGraphicsContext.current=NSGraphicsContext(cgContext:context,flipped:false)
    context.setAlpha(CGFloat(max(0,fade)))
    let accent=color(1,0.62,0.30), pale=color(0.94,0.96,0.99), muted=color(0.65,0.72,0.81)
    text("ТЧ-15 / ARM",96,74,800,60,30,accent,.semibold)
    text(scenes[index].0.uppercased(),96,210,690,80,20,accent,.semibold)
    text(scenes[index].1,96,304,720,240,64,pale,.bold)
    text(scenes[index].2,96,596,710,160,27,muted)
    let card=NSRect(x:846,y:230,width:978,height:620)
    color(0.10,0.14,0.20).setFill();NSBezierPath(roundedRect:card,xRadius:26,yRadius:26).fill()
    let image=images[index], ratio=image.size.width/image.size.height
    let zoom=1.0+local*0.003
    let iw=CGFloat(930*zoom), ih=iw/ratio
    NSGraphicsContext.saveGraphicsState();NSBezierPath(roundedRect:card.insetBy(dx:14,dy:14),xRadius:16,yRadius:16).addClip()
    image.draw(in:NSRect(x:card.midX-iw/2,y:card.midY-ih/2,width:iw,height:ih))
    NSGraphicsContext.restoreGraphicsState()
    text("ПРОБА СТИЛЯ · УЧЕБНЫЕ ДАННЫЕ",96,934,1000,46,18,muted,.medium)
    text("Снимки интерфейса • не запись действий",1040,934,790,46,18,muted)
    for i in 0..<3 {
        (i==index ? accent : color(0.18,0.23,0.30)).setFill()
        NSBezierPath(roundedRect:NSRect(x:96+i*82,y:86,width:64,height:4),xRadius:2,yRadius:2).fill()
    }
    NSGraphicsContext.restoreGraphicsState()
}
func render(_ name:String, _ codec:AVVideoCodecType) throws {
    let url=output.appendingPathComponent(name)
    if FileManager.default.fileExists(atPath:url.path) { try FileManager.default.removeItem(at:url) }
    let writer=try AVAssetWriter(outputURL:url,fileType:codec == .h264 ? .mp4 : .mov)
    var settings:[String:Any]=[AVVideoCodecKey:codec, AVVideoWidthKey:width,AVVideoHeightKey:height]
    if codec == .h264 { settings[AVVideoCompressionPropertiesKey]=[AVVideoAverageBitRateKey:8_000_000,AVVideoExpectedSourceFrameRateKey:fps,AVVideoMaxKeyFrameIntervalKey:30] }
    let input=AVAssetWriterInput(mediaType:.video,outputSettings:settings)
    input.expectsMediaDataInRealTime=false
    let adaptor=AVAssetWriterInputPixelBufferAdaptor(assetWriterInput:input,sourcePixelBufferAttributes:[kCVPixelBufferPixelFormatTypeKey as String:kCVPixelFormatType_32ARGB,kCVPixelBufferWidthKey as String:width,kCVPixelBufferHeightKey as String:height,kCVPixelBufferCGImageCompatibilityKey as String:true,kCVPixelBufferCGBitmapContextCompatibilityKey as String:true])
    guard writer.canAdd(input) else { fatalError("Unsupported codec") };writer.add(input)
    guard writer.startWriting() else { throw writer.error! };writer.startSession(atSourceTime:.zero)
    for frame in 0..<frames {
        while !input.isReadyForMoreMediaData { if writer.status == .failed { throw writer.error! };Thread.sleep(forTimeInterval:0.002) }
        try autoreleasepool {
            var optional:CVPixelBuffer?
            guard CVPixelBufferPoolCreatePixelBuffer(nil,adaptor.pixelBufferPool!,&optional)==kCVReturnSuccess,let buffer=optional else { fatalError("Pixel buffer allocation failed") }
            CVPixelBufferLockBaseAddress(buffer,[])
            let context=CGContext(data:CVPixelBufferGetBaseAddress(buffer),width:width,height:height,bitsPerComponent:8,bytesPerRow:CVPixelBufferGetBytesPerRow(buffer),space:CGColorSpaceCreateDeviceRGB(),bitmapInfo:CGImageAlphaInfo.noneSkipFirst.rawValue|CGBitmapInfo.byteOrder32Big.rawValue)!
            draw(context,frame)
            if codec == .h264 && [90,270,450].contains(frame) {
                let png=NSBitmapImageRep(cgImage:context.makeImage()!).representation(using:.png,properties:[:])!
                try png.write(to:output.appendingPathComponent("scene-\(frame/180+1).png"))
            }
            CVPixelBufferUnlockBaseAddress(buffer,[])
            guard adaptor.append(buffer,withPresentationTime:CMTime(value:Int64(frame),timescale:fps)) else { throw writer.error! }
        }
    }
    input.markAsFinished();let done=DispatchSemaphore(value:0)
    writer.finishWriting { done.signal() };done.wait()
    guard writer.status == .completed else { throw writer.error! }
    print("CREATED \(name) 1920x1080 30fps 18s no-audio")
}
try render("ARM_style_pilot.mp4",.h264)
try render("ARM_style_pilot_ProRes.mov",.proRes422)
