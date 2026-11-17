package ru.tch15.arm.push

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.HandlerThread
import android.os.IBinder
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import org.json.JSONObject
import java.net.HttpURLConnection
import java.util.LinkedHashSet
import java.net.URL
import java.nio.charset.StandardCharsets

class ArmNotificationService : Service() {
  private var worker: HandlerThread? = null
  private var handler: Handler? = null
  private var pollTask: Runnable? = null

  override fun onBind(intent: Intent?): IBinder? = null

  override fun onCreate() {
    super.onCreate()
    val thread = HandlerThread("arm-notify").also { it.start() }
    worker = thread
    handler = Handler(thread.looper)
  }

  override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
    if (packageName != MAIN_PACKAGE || intent?.action == ACTION_STOP) {
      stopInternal()
      return START_NOT_STICKY
    }
    val prefs = prefs(this)
    if (prefs.getString(KEY_TOKEN, "").isNullOrBlank() || prefs.getString(KEY_API, "").isNullOrBlank()) {
      stopInternal()
      return START_NOT_STICKY
    }
    ensureChannels()
    try {
      ServiceCompat.startForeground(
        this,
        WATCH_ID,
        watchNotification(),
        ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC,
      )
    } catch (_: Exception) {
      stopInternal()
      return START_NOT_STICKY
    }
    schedule(0L)
    return START_STICKY
  }

  override fun onDestroy() {
    pollTask?.let { handler?.removeCallbacks(it) }
    worker?.quitSafely()
    worker = null
    handler = null
    super.onDestroy()
  }

  private fun stopInternal() {
    pollTask?.let { handler?.removeCallbacks(it) }
    clearSession(this)
    stopForeground(STOP_FOREGROUND_REMOVE)
    stopSelf()
  }

  private fun schedule(delayMs: Long) {
    val task = pollTask ?: Runnable { pollOnce() }.also { pollTask = it }
    handler?.removeCallbacks(task)
    handler?.postDelayed(task, delayMs)
  }

  private fun pollOnce() {
    val prefs = prefs(this)
    val token = prefs.getString(KEY_TOKEN, "") ?: ""
    val apiUrl = (prefs.getString(KEY_API, "") ?: "").trimEnd('/')
    if (token.isBlank() || apiUrl.isBlank()) {
      stopInternal()
      return
    }
    val wake = (getSystemService(POWER_SERVICE) as? PowerManager)
      ?.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "arm:notify")
    try {
      wake?.acquire(20_000L)
      val payload = fetchInbox(apiUrl, token)
      if (payload == STOP) {
        stopInternal()
        return
      }
      if (payload != null) {
        publishEvents(payload)
      }
    } catch (_: Exception) {
      // next tick retries
    } finally {
      if (wake?.isHeld == true) wake.release()
      schedule(POLL_MS)
    }
  }

  private fun fetchInbox(apiUrl: String, token: String): JSONObject? {
    val url = URL("$apiUrl/notifications?unread=1")
    val conn = (url.openConnection() as HttpURLConnection).apply {
      requestMethod = "GET"
      connectTimeout = 15_000
      readTimeout = 15_000
      instanceFollowRedirects = false
      setRequestProperty("Accept", "application/json")
      setRequestProperty("Authorization", "Bearer $token")
      setRequestProperty("X-TCH15-Client", "ru.tch15.arm/1.1.11-notify")
    }
    return try {
      val code = conn.responseCode
      if (code == 401 || code == 403) {
        return STOP
      }
      if (code != 200) {
        return null
      }
      val length = conn.contentLength
      if (length > MAX_BODY) {
        return null
      }
      val bytes = conn.inputStream.use { stream ->
        val buf = ByteArray(MAX_BODY + 1)
        var n = 0
        while (n <= MAX_BODY) {
          val r = stream.read(buf, n, buf.size - n)
          if (r <= 0) break
          n += r
        }
        if (n > MAX_BODY) return null
        buf.copyOf(n)
      }
      JSONObject(String(bytes, StandardCharsets.UTF_8))
    } finally {
      conn.disconnect()
    }
  }

  private fun publishEvents(payload: JSONObject) {
    val items = payload.optJSONArray("items") ?: return
    val fgAt = prefs(this).getLong(KEY_FG, 0L)
    val inForeground = fgAt > 0L && System.currentTimeMillis() - fgAt < FOREGROUND_MS
    if (inForeground) {
      return
    }
    val shown = shownSet()
    val next = LinkedHashSet<String>()
    for (i in 0 until items.length()) {
      val item = items.optJSONObject(i) ?: continue
      val id = item.optInt("id", 0)
      val title = item.optString("title").trim()
      if (id <= 0 || title.isEmpty()) continue
      val signature = "$id:${item.optInt("count", 1)}:${item.optString("updated_at")}"
      next.add(signature)
      if (shown.contains(signature)) continue
      postEvent(id, title)
    }
    saveShown(next)
  }

  private fun postEvent(id: Int, title: String) {
    val launch = Intent(Intent.ACTION_VIEW, Uri.parse("tch15://notifications/$id")).apply {
      setPackage(packageName)
      addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
    }
    val pi = PendingIntent.getActivity(
      this,
      id,
      launch,
      PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
    )
    val notification = NotificationCompat.Builder(this, CHANNEL_EVENTS)
      .setSmallIcon(applicationInfo.icon)
      .setContentTitle(title)
      .setContentText("Откройте приложение ТЧ-15")
      .setAutoCancel(true)
      .setPriority(NotificationCompat.PRIORITY_HIGH)
      .setContentIntent(pi)
      .build()
    (getSystemService(NOTIFICATION_SERVICE) as NotificationManager).notify(EVENT_BASE + id, notification)
  }

  private fun watchNotification(): Notification {
    return NotificationCompat.Builder(this, CHANNEL_WATCH)
      .setSmallIcon(applicationInfo.icon)
      .setContentTitle("АРМ: уведомления включены")
      .setContentText("Приложение проверяет новые сообщения, даже когда свёрнуто")
      .setOngoing(true)
      .setSilent(true)
      .setPriority(NotificationCompat.PRIORITY_LOW)
      .setContentIntent(openAppIntent())
      .build()
  }

  private fun openAppIntent(): PendingIntent {
    val launch = packageManager.getLaunchIntentForPackage(packageName) ?: Intent()
    launch.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
    return PendingIntent.getActivity(
      this,
      1,
      launch,
      PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
    )
  }

  private fun ensureChannels() {
    if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
    val manager = getSystemService(NOTIFICATION_SERVICE) as NotificationManager
    manager.createNotificationChannel(
      NotificationChannel(CHANNEL_WATCH, "Фон АРМ", NotificationManager.IMPORTANCE_LOW),
    )
    manager.createNotificationChannel(
      NotificationChannel(CHANNEL_EVENTS, "События АРМ", NotificationManager.IMPORTANCE_HIGH),
    )
  }

  private fun shownSet(): MutableSet<String> {
    val raw = prefs(this).getString(KEY_SHOWN, "") ?: ""
    return raw.split('\n').filter { it.isNotBlank() }.toMutableSet()
  }

  private fun saveShown(values: Set<String>) {
    prefs(this).edit().putString(KEY_SHOWN, values.take(80).joinToString("\n")).apply()
  }

  companion object {
    const val ACTION_START = "ru.tch15.arm.push.START"
    const val ACTION_STOP = "ru.tch15.arm.push.STOP"
    private const val MAIN_PACKAGE = "ru.tch15.arm"
    private const val PREFS = "arm_notify"
    private const val KEY_TOKEN = "token"
    private const val KEY_API = "apiUrl"
    private const val KEY_FG = "fgAt"
    private const val KEY_SHOWN = "shown"
    private const val CHANNEL_WATCH = "arm_watch"
    private const val CHANNEL_EVENTS = "arm_events"
    private const val WATCH_ID = 15015
    private const val EVENT_BASE = 16000
    private const val POLL_MS = 30_000L
    private const val FOREGROUND_MS = 45_000L
    private const val MAX_BODY = 1_000_000
    private val STOP = JSONObject().put("_stop", true)

    fun start(context: Context, token: String, apiUrl: String) {
      if (context.packageName != MAIN_PACKAGE) return
      if (token.isBlank() || apiUrl.isBlank()) return
      prefs(context).edit()
        .putString(KEY_TOKEN, token)
        .putString(KEY_API, apiUrl)
        .putLong(KEY_FG, System.currentTimeMillis())
        .apply()
      val intent = Intent(context, ArmNotificationService::class.java).setAction(ACTION_START)
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
        context.startForegroundService(intent)
      } else {
        context.startService(intent)
      }
    }

    fun stop(context: Context) {
      clearSession(context)
      context.stopService(Intent(context, ArmNotificationService::class.java).setAction(ACTION_STOP))
    }

    fun setForeground(context: Context, value: Boolean) {
      prefs(context).edit().putLong(KEY_FG, if (value) System.currentTimeMillis() else 0L).apply()
    }

    fun hasSession(context: Context): Boolean {
      val prefs = prefs(context)
      return !prefs.getString(KEY_TOKEN, "").isNullOrBlank() && !prefs.getString(KEY_API, "").isNullOrBlank()
    }

    private fun clearSession(context: Context) {
      prefs(context).edit().remove(KEY_TOKEN).remove(KEY_API).remove(KEY_SHOWN).putLong(KEY_FG, 0L).apply()
    }

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
  }
}
