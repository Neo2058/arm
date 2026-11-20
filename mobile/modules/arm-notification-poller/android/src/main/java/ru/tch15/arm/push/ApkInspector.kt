package ru.tch15.arm.push

import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import java.io.File
import java.security.MessageDigest
import java.security.cert.CertificateFactory
import java.util.zip.ZipFile

internal object ApkInspector {
  data class Info(val packageName: String, val versionCode: Long, val certificateSha256: String)

  fun inspect(context: Context, uri: String): Info {
    val file = FileDigest.cacheFile(context, uri)
    if (!file.name.endsWith(".apk")) {
      throw Exception("Not an APK")
    }
    val parsed = context.packageManager.getPackageArchiveInfo(file.path, 0)
      ?: throw Exception("Not a valid Android package")
    parsed.applicationInfo?.sourceDir = file.path
    parsed.applicationInfo?.publicSourceDir = file.path
    val packageName = parsed.packageName ?: throw Exception("Missing package name")
    val versionCode = packageVersionCode(parsed)
    if (versionCode < 1L) {
      throw Exception("Missing versionCode")
    }
    val certs = signingCerts(context.packageManager, file)
    if (certs.size != 1) {
      throw Exception("Unexpected signer count")
    }
    val digest = MessageDigest.getInstance("SHA-256").digest(certs[0])
    val hex = digest.joinToString("") { b -> "%02x".format(b.toInt() and 0xff) }
    return Info(packageName, versionCode, hex)
  }

  private fun packageVersionCode(info: android.content.pm.PackageInfo): Long {
    return if (Build.VERSION.SDK_INT >= 28) info.longVersionCode else {
      @Suppress("DEPRECATION")
      info.versionCode.toLong()
    }
  }

  private fun signingCerts(pm: PackageManager, file: File): List<ByteArray> {
    val fromPackage = packageCerts(pm, file)
    if (fromPackage.size == 1) {
      return fromPackage
    }
    return zipCerts(file)
  }

  private fun packageCerts(pm: PackageManager, file: File): List<ByteArray> {
    return try {
      if (Build.VERSION.SDK_INT >= 28) {
        val info = pm.getPackageArchiveInfo(file.path, PackageManager.GET_SIGNING_CERTIFICATES)
        info?.applicationInfo?.sourceDir = file.path
        info?.applicationInfo?.publicSourceDir = file.path
        val signers = info?.signingInfo?.apkContentsSigners ?: emptyArray()
        signers.map { it.toByteArray() }
      } else {
        @Suppress("DEPRECATION")
        val info = pm.getPackageArchiveInfo(file.path, PackageManager.GET_SIGNATURES)
        @Suppress("DEPRECATION")
        val signers = info?.signatures ?: emptyArray()
        signers.map { it.toByteArray() }
      }
    } catch (_: Exception) {
      emptyList()
    }
  }

  private fun zipCerts(file: File): List<ByteArray> {
    return try {
      ZipFile(file).use { zip ->
        val entries = zip.entries().toList().filter { entry ->
          val name = entry.name.uppercase()
          name.startsWith("META-INF/") && (name.endsWith(".RSA") || name.endsWith(".EC") || name.endsWith(".DSA"))
        }
        if (entries.size != 1) {
          return emptyList()
        }
        zip.getInputStream(entries[0]).use { input ->
          CertificateFactory.getInstance("X.509").generateCertificates(input).map { it.encoded }
        }
      }
    } catch (_: Exception) {
      emptyList()
    }
  }
}
