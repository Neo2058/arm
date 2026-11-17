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
  }

  private fun requireAppContext() =
    appContext.reactContext?.applicationContext ?: throw Exceptions.AppContextLost()
}
