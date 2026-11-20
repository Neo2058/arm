package ru.tch15.arm.push

import android.content.Context
import android.net.Uri
import java.io.File
import java.security.MessageDigest

internal object FileDigest {
  private const val MAX_BYTES = 200L * 1024 * 1024
  private const val BUFFER = 65536

  fun cacheFile(context: Context, uri: String): File {
    val file = resolve(uri).canonicalFile
    val cache = context.cacheDir.canonicalFile
    if (!file.path.startsWith(cache.path + File.separator)) {
      throw Exception("Digest is limited to the app cache")
    }
    val length = file.length()
    if (length < 1L || length > MAX_BYTES) {
      throw Exception("Installer size is outside the allowed range")
    }
    return file
  }

  fun sha256(context: Context, uri: String): String {
    val file = cacheFile(context, uri)
    val digest = MessageDigest.getInstance("SHA-256")
    file.inputStream().use { input ->
      val buf = ByteArray(BUFFER)
      while (true) {
        val n = input.read(buf)
        if (n <= 0) break
        digest.update(buf, 0, n)
      }
    }
    return digest.digest().joinToString("") { b -> "%02x".format(b.toInt() and 0xff) }
  }

  private fun resolve(uri: String): File {
    val value = uri.trim()
    if (value.isEmpty()) {
      throw Exception("File missing")
    }
    if (value.startsWith("file:")) {
      return File(Uri.parse(value).path ?: "")
    }
    return File(value)
  }
}
