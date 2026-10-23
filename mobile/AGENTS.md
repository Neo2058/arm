# Expo HAS CHANGED

Read the exact versioned docs at https://docs.expo.dev/versions/v57.0.0/ before writing any code.

Playback uses expo-video/expo-audio matching SDK 57, not expo-av 16 (native JSI crash on RN 0.86).
Keep expo-asset in the SDK 57 range: unconstrained peer installation can pull SDK 58.
Native dependency changes require a new APK and an install/start smoke test; JS export alone is insufficient.
Preserve local native signing configuration when regenerating ignored android/ios directories.
