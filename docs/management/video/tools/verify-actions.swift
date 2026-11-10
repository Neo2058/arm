import AVFoundation
import Foundation
let url=URL(fileURLWithPath:CommandLine.arguments[1]),asset=AVURLAsset(url:URL(fileURLWithPath:CommandLine.arguments[1]))
let tracks=try await asset.loadTracks(withMediaType:.video),audio=try await asset.loadTracks(withMediaType:.audio),duration=try await asset.load(.duration)
guard tracks.count==1,audio.isEmpty else {fatalError("Unexpected tracks")}
let size=try await tracks[0].load(.naturalSize),fps=try await tracks[0].load(.nominalFrameRate)
guard size.width==1920,size.height==1080,abs(fps-30)<0.01 else {fatalError("Unexpected size/fps")}
for fraction in [0.1,0.5,0.9] {
 let reader=try AVAssetReader(asset:asset);reader.timeRange=CMTimeRange(start:CMTime(seconds:duration.seconds*fraction,preferredTimescale:600),duration:CMTime(seconds:0.1,preferredTimescale:600))
 let output=AVAssetReaderTrackOutput(track:tracks[0],outputSettings:[kCVPixelBufferPixelFormatTypeKey as String:kCVPixelFormatType_32BGRA]);reader.add(output)
 guard reader.startReading(),output.copyNextSampleBuffer() != nil else {fatalError("Frame decode failed")};reader.cancelReading()
}
let result:[String:Any]=["file":url.lastPathComponent,"duration_seconds":duration.seconds,"fps":fps,"width":size.width,"height":size.height,"audio_tracks":0,"decode_checks":"10%,50%,90% passed"]
let data=try JSONSerialization.data(withJSONObject:result,options:[.prettyPrinted,.sortedKeys]);try data.write(to:url.appendingPathExtension("verification.json"));print(String(data:data,encoding:.utf8)!)
