package ru.tch15.arm.push

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

class ArmNotificationBootReceiver : BroadcastReceiver() {
  override fun onReceive(context: Context, intent: Intent?) {
    val action = intent?.action ?: return
    if (
      action != Intent.ACTION_BOOT_COMPLETED &&
      action != Intent.ACTION_MY_PACKAGE_REPLACED
    ) {
      return
    }
    if (context.packageName != "ru.tch15.arm") return
    if (!ArmNotificationService.hasSession(context)) return
    val prefs = context.getSharedPreferences("arm_notify", Context.MODE_PRIVATE)
    val token = prefs.getString("token", "") ?: return
    val apiUrl = prefs.getString("apiUrl", "") ?: return
    ArmNotificationService.start(context, token, apiUrl)
  }
}
