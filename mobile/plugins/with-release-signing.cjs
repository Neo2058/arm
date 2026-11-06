const { withAppBuildGradle } = require('expo/config-plugins');
module.exports = config => withAppBuildGradle(config, result => {
  const marker = '// ARM private release signing';
  const signing = `${marker}
        release {
            def ks = new File(System.getProperty("user.home"), ".tch15-arm/keystore.properties")
            def props = new Properties()
            ks.withInputStream { props.load(it) }
            storeFile file(props['storeFile'])
            storePassword props['storePassword']
            keyAlias props['keyAlias']
            keyPassword props['keyPassword']
        }
`;
  if (!result.modResults.contents.includes(marker)) {
    result.modResults.contents = result.modResults.contents.replace('signingConfigs {', 'signingConfigs {\n' + signing);
  }
  result.modResults.contents = result.modResults.contents.replace(/(buildTypes\s*\{[\s\S]*?release\s*\{[\s\S]*?)signingConfig signingConfigs\.debug/, '$1signingConfig signingConfigs.release');
  if (!/buildTypes[\s\S]*?release\s*\{[\s\S]*?signingConfig signingConfigs\.release/.test(result.modResults.contents)) throw new Error('Release signing was not configured');
  return result;
});
