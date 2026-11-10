import AVFoundation
import Foundation
let root=URL(fileURLWithPath:CommandLine.arguments[1])
var results:[[String:Any]]=[]
for filename in ["ARM_style_pilot.mp4","ARM_style_pilot_ProRes.mov"] {
    let asset=AVURLAsset(url:root.appendingPathComponent(filename))
    let duration=try await asset.load(.duration)
    let tracks=try await asset.loadTracks(withMediaType:.video)
    let audio=try await asset.loadTracks(withMediaType:.audio)
    guard tracks.count==1,audio.isEmpty else { fatalError("Unexpected tracks") }
    let size=try await tracks[0].load(.naturalSize)
    let fps=try await tracks[0].load(.nominalFrameRate)
    let descriptions=try await tracks[0].load(.formatDescriptions)
    let subtype=CMFormatDescriptionGetMediaSubType(descriptions[0])
    let codec=String(bytes:[UInt8((subtype>>24)&255),UInt8((subtype>>16)&255),UInt8((subtype>>8)&255),UInt8(subtype&255)],encoding:.ascii)!
    guard size.width==1920,size.height==1080,fps==30,abs(duration.seconds-18)<0.05 else { fatalError("Unexpected video format") }
    for second in [3,9,15] {
        let reader=try AVAssetReader(asset:asset)
        reader.timeRange=CMTimeRange(start:CMTime(seconds:Double(second),preferredTimescale:30),duration:CMTime(seconds:0.1,preferredTimescale:30))
        let output=AVAssetReaderTrackOutput(track:tracks[0],outputSettings:[kCVPixelBufferPixelFormatTypeKey as String:kCVPixelFormatType_32BGRA])
        reader.add(output);guard reader.startReading(),output.copyNextSampleBuffer() != nil else { fatalError("Frame decode failed") };reader.cancelReading()
    }
    results.append(["file":filename,"width":Int(size.width),"height":Int(size.height),"fps":fps,"duration_seconds":duration.seconds,"codec":codec,"audio_tracks":audio.count,"decoded_seconds":[3,9,15]])
}
let data=try JSONSerialization.data(withJSONObject:results,options:[.prettyPrinted,.sortedKeys])
try data.write(to:root.appendingPathComponent("verification.json"))
print(String(data:data,encoding:.utf8)!)
