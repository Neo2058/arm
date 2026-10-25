const { resolveApiUrl } = require('./api-config.cjs');
const apiUrl = resolveApiUrl(process.env.EXPO_PUBLIC_API_URL);

const isHttp = apiUrl.startsWith('http://');

export default {
  expo: {
    name: 'ТЧ-15',
    slug: 'arm-mobile',
    version: '1.1.0',
    orientation: 'portrait',
    icon: './assets/icon.png',
    userInterfaceStyle: 'automatic',
    scheme: 'tch15',
    splash: {
      image: './assets/icon.png',
      resizeMode: 'contain',
      backgroundColor: '#0b1018',
    },
    ios: {
      supportsTablet: true,
      bundleIdentifier: 'ru.tch15.arm',
      buildNumber: '12',
      infoPlist: {
        NSAppTransportSecurity: isHttp
          ? { NSAllowsLocalNetworking: true, NSAllowsArbitraryLoads: true }
          : { NSAllowsLocalNetworking: true },
        ITSAppUsesNonExemptEncryption: false,
      },
    },
    android: {
      package: 'ru.tch15.arm',
      allowBackup: false,
      versionCode: 12,
      usesCleartextTraffic: isHttp,
      adaptiveIcon: {
        backgroundColor: '#0b1018',
        foregroundImage: './assets/android-icon-foreground.png',
        backgroundImage: './assets/android-icon-background.png',
        monochromeImage: './assets/android-icon-monochrome.png',
      },
    },
    plugins: [
      'expo-secure-store',
      'expo-video',
      ['expo-audio', { microphonePermission: false, recordAudioAndroid: false }],
      [
        'expo-image-picker',
        {
          photosPermission: 'ТЧ-15 использует фото, чтобы отправить снимок в рабочий чат колонны.',
          cameraPermission: false,
          microphonePermission: false,
        },
      ],
      'expo-document-picker',
      'expo-file-system',
    ],
    extra: {
      apiUrl,
      ...(process.env.EAS_PROJECT_ID
        ? { eas: { projectId: process.env.EAS_PROJECT_ID } }
        : {}),
    },
  },
};
