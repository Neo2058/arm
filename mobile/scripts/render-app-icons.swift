// macOS renderer for the editable SVG icon sources. Run from any directory:
// swift mobile/scripts/render-app-icons.swift /absolute/path/to/mobile/assets
import AppKit
import Foundation

let assets = URL(fileURLWithPath: CommandLine.arguments[1])
let source = try String(contentsOf: assets.appendingPathComponent("app-symbol.svg"), encoding: .utf8)
let monochrome = try String(contentsOf: assets.appendingPathComponent("app-symbol-monochrome.svg"), encoding: .utf8)
func render(_ svg: String, _ filename: String, _ size: Int = 1024) throws {
    let opaque = filename == "icon.png" || filename == "favicon.png" || filename.contains("background") || filename.hasSuffix("/ic_launcher.png")
    guard let image = NSImage(data: Data(svg.utf8)),
          let bitmap = NSBitmapImageRep(bitmapDataPlanes: nil, pixelsWide: size, pixelsHigh: size,
            bitsPerSample: 8, samplesPerPixel: opaque ? 3 : 4, hasAlpha: !opaque, isPlanar: false,
            colorSpaceName: .deviceRGB, bytesPerRow: 0, bitsPerPixel: 0) else {
        fatalError("Cannot render SVG")
    }
    NSGraphicsContext.saveGraphicsState()
    NSGraphicsContext.current = NSGraphicsContext(bitmapImageRep: bitmap)
    if filename.contains("ic_launcher_round") {
        NSBezierPath(ovalIn: NSRect(x: 0, y: 0, width: size, height: size)).addClip()
    }
    image.draw(in: NSRect(x: 0, y: 0, width: size, height: size))
    NSGraphicsContext.restoreGraphicsState()
    try bitmap.representation(using: .png, properties: [:])!.write(to: assets.appendingPathComponent(filename))
}
try render(source, "icon.png")
let foreground = source
    .replacingOccurrences(of: "<rect width=\"1024\" height=\"1024\" fill=\"url(#bg)\"/>", with: "")
    .replacingOccurrences(of: "<circle cx=\"512\" cy=\"478\" r=\"320\" fill=\"none\" stroke=\"#263c54\" stroke-width=\"2\"/>", with: "")
try render(foreground, "android-icon-foreground.png")
try render("<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1024\" height=\"1024\"><rect width=\"1024\" height=\"1024\" fill=\"#0b1018\"/></svg>", "android-icon-background.png")
try render(monochrome, "android-icon-monochrome.png")
try render(foreground, "splash-icon.png")
try render(source, "favicon.png", 64)
print("Rendered app, adaptive, monochrome, splash and favicon assets")
if CommandLine.arguments.contains("--android-res") {
    let resources = assets.deletingLastPathComponent().appendingPathComponent("android/app/src/main/res")
    for (density, scale) in [("mdpi", 1.0), ("hdpi", 1.5), ("xhdpi", 2.0), ("xxhdpi", 3.0), ("xxxhdpi", 4.0)] {
        for (name, svg, base) in [
            ("ic_launcher", source, 48), ("ic_launcher_round", source, 48),
            ("ic_launcher_foreground", foreground, 108), ("ic_launcher_background", "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"1024\" height=\"1024\"><rect width=\"1024\" height=\"1024\" fill=\"#0b1018\"/></svg>", 108),
            ("ic_launcher_monochrome", monochrome, 108)
        ] {
            let relative = "../android/app/src/main/res/mipmap-\(density)/\(name)"
            try render(svg, relative + ".png", Int(Double(base) * scale))
            let old = resources.appendingPathComponent("mipmap-\(density)/\(name).webp")
            if FileManager.default.fileExists(atPath: old.path) { try FileManager.default.removeItem(at: old) }
        }
        try render(source, "../android/app/src/main/res/drawable-\(density)/splashscreen_logo.png", Int(288 * scale))
    }
    print("Updated existing Android launcher resources; signing configuration preserved")
}
