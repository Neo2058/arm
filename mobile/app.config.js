const apiUrl =
  process.env.EXPO_PUBLIC_API_URL || 'https://xn---15-7edz.xn--p1ai/api/mobile';

const isHttp = apiUrl.startsWith('http://');

export default {
  expo: {
    name: 'ТЧ-15',
    slug: 'arm-mobile',
    version: '1.0.0',
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
      buildNumber: '1',
      infoPlist: {
        NSAppTransportSecurity: isHttp
          ? { NSAllowsLocalNetworking: true, NSAllowsArbitraryLoads: true }
          : { NSAllowsLocalNetworking: true },
        ITSAppUsesNonExemptEncryption: false,
      },
    },
    android: {
      package: 'ru.tch15.arm',
      versionCode: 1,
      usesCleartextTraffic: isHttp,
      adaptiveIcon: {
        backgroundColor: '#0b1018',
        foregroundImage: './assets/android-icon-foreground.png',
        backgroundImage: './assets/android-icon-background.png',
        monochromeImage: './assets/android-icon-monochrome.png',
      },
    },
    plugins: ['expo-secure-store', 'expo-av'],
    extra: {
      apiUrl,
      ...(process.env.EAS_PROJECT_ID
        ? { eas: { projectId: process.env.EAS_PROJECT_ID } }
        : {}),
    },
  },
};
