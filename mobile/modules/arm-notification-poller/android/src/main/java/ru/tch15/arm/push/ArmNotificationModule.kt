package ru.tch15.arm.push

import expo.modules.kotlin.exception.Exceptions
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition

class ArmNotificationModule : Module() {
  override fun definition() = ModuleDefinition {
    Name("ArmNotificationPoller")

    AsyncFunction("start") { token: String, apiUrl: String ->
      ArmNotificationService.start(requireAppContext(), token, apiUrl)
    }

    AsyncFunction("stop") {
      ArmNotificationService.stop(requireAppContext())
    }

    AsyncFunction("setForeground") { value: Boolean ->
      ArmNotificationService.setForeground(requireAppContext(), value)
    }

    AsyncFunction("sha256File") { uri: String ->
      FileDigest.sha256(requireAppContext(), uri)
    }

    AsyncFunction("inspectApk") { uri: String ->
      val info = ApkInspector.inspect(requireAppContext(), uri)
      mapOf(
        "package" to info.packageName,
        "versionCode" to info.versionCode.toInt(),
        "certificateSha256" to info.certificateSha256,
      )
    }

    AsyncFunction("verifyReleaseSignature") { message: String, signatureHex: String ->
      ReleaseMetadata.verify(message, signatureHex)
    }
  }

  private fun requireAppContext() =
    appContext.reactContext?.applicationContext ?: throw Exceptions.AppContextLost()
}
