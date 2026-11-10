// Compose real recorded PNG frames into a constant-rate, silent video.
import AppKit
import AVFoundation
import CoreVideo
import Foundation
let folder=URL(fileURLWithPath:CommandLine.arguments[1]),out=URL(fileURLWithPath:CommandLine.arguments[2])
let manifest=try JSONSerialization.jsonObject(with:Data(contentsOf:folder.appendingPathComponent("manifest.json"))) as! [String:Any]
let frames=manifest["frames"] as! [[String:Any]], scenes=manifest["scenes"] as! [[String:Any]]
let duration=manifest["duration_seconds"] as! Double
let samples=frames.map { ($0["time_seconds"] as! Double,$0["file"] as! String) }
try FileManager.default.createDirectory(at:out,withIntermediateDirectories:true)
let name=CommandLine.arguments.count>3 ? CommandLine.arguments[3] : "ARM_web_actions_draft.mp4"
let file=out.appendingPathComponent(name)
if FileManager.default.fileExists(atPath:file.path) {try FileManager.default.removeItem(at:file)}
let writer=try AVAssetWriter(outputURL:file,fileType:.mp4)
let input=AVAssetWriterInput(mediaType:.video,outputSettings:[AVVideoCodecKey:AVVideoCodecType.h264,AVVideoWidthKey:1920,AVVideoHeightKey:1080,AVVideoCompressionPropertiesKey:[AVVideoAverageBitRateKey:10_000_000,AVVideoExpectedSourceFrameRateKey:30,AVVideoMaxKeyFrameIntervalKey:30]])
let adaptor=AVAssetWriterInputPixelBufferAdaptor(assetWriterInput:input,sourcePixelBufferAttributes:[kCVPixelBufferPixelFormatTypeKey as String:kCVPixelFormatType_32ARGB,kCVPixelBufferWidthKey as String:1920,kCVPixelBufferHeightKey as String:1080,kCVPixelBufferCGImageCompatibilityKey as String:true,kCVPixelBufferCGBitmapContextCompatibilityKey as String:true])
writer.add(input);guard writer.startWriting() else {throw writer.error!};writer.startSession(atSourceTime:.zero)
var index=0,previous="",image:NSImage?
func text(_ s:String,_ x:CGFloat,_ y:CGFloat,_ size:CGFloat,_ color:NSColor) { (s as NSString).draw(at:NSPoint(x:x,y:y),withAttributes:[.font:NSFont.systemFont(ofSize:size,weight:.medium),.foregroundColor:color]) }
for f in 0..<Int(ceil(duration*30)) {
 while !input.isReadyForMoreMediaData {if writer.status == .failed {throw writer.error!};Thread.sleep(forTimeInterval:0.002)}
 try autoreleasepool {
  let t=Double(f)/30
  while index+1<samples.count && samples[index+1].0<=t {index+=1}
  if previous != samples[index].1 {previous=samples[index].1;image=NSImage(contentsOf:folder.appendingPathComponent(previous))!}
  var optional:CVPixelBuffer?;guard CVPixelBufferPoolCreatePixelBuffer(nil,adaptor.pixelBufferPool!,&optional)==kCVReturnSuccess,let buffer=optional else {fatalError("Buffer allocation failed")}
  CVPixelBufferLockBaseAddress(buffer,[])
  let ctx=CGContext(data:CVPixelBufferGetBaseAddress(buffer),width:1920,height:1080,bitsPerComponent:8,bytesPerRow:CVPixelBufferGetBytesPerRow(buffer),space:CGColorSpaceCreateDeviceRGB(),bitmapInfo:CGImageAlphaInfo.noneSkipFirst.rawValue|CGBitmapInfo.byteOrder32Big.rawValue)!
  ctx.setFillColor(NSColor(srgbRed:0.035,green:0.055,blue:0.086,alpha:1).cgColor);ctx.fill(CGRect(x:0,y:0,width:1920,height:1080))
  NSGraphicsContext.saveGraphicsState();NSGraphicsContext.current=NSGraphicsContext(cgContext:ctx,flipped:false)
  let scene=scenes.last(where:{($0["start_seconds"] as! Double)<=t}) ?? scenes.first!
  text(scene["title"] as! String,128,1033,23,NSColor(srgbRed:1,green:0.62,blue:0.30,alpha:1))
  let scale=min(1664/image!.size.width,936/image!.size.height)
  let iw=image!.size.width*scale,ih=image!.size.height*scale
  image!.draw(in:NSRect(x:960-iw/2,y:540-ih/2,width:iw,height:ih))
  text("УЧЕБНЫЙ СТЕНД · Реальные действия в локальном приложении",128,28,17,.lightGray)
  text("Черновая запись · без озвучки",1420,28,17,.lightGray)
  NSGraphicsContext.restoreGraphicsState();CVPixelBufferUnlockBaseAddress(buffer,[])
  guard adaptor.append(buffer,withPresentationTime:CMTime(value:Int64(f),timescale:30)) else {throw writer.error!}
 }
}
input.markAsFinished();let sem=DispatchSemaphore(value:0);writer.finishWriting{sem.signal()};sem.wait()
guard writer.status == .completed else {throw writer.error!}
print("REAL_ACTIONS_VIDEO_CREATED seconds=\(duration) source_frames=\(frames.count) output=\(file.path)")
