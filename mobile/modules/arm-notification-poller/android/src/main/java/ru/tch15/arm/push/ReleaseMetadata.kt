package ru.tch15.arm.push

import java.security.KeyFactory
import java.security.Signature
import java.security.spec.X509EncodedKeySpec

internal object ReleaseMetadata {
  const val PUBLIC_KEY_HEX = "3059301306072a8648ce3d020106082a8648ce3d03010703420004ba84876f20b70a1e138dcd3eade2a51f6a9a188b5056e3cc39a5e0503fb2602f42da6da823b7533a9fe20b2ed6fb64725ade829cac49975836dfa19e4f402848"

  fun verify(message: String, signatureHex: String): Boolean {
    val signature = hex(signatureHex) ?: return false
    val der = hex(PUBLIC_KEY_HEX) ?: return false
    if (message.isEmpty() || signature.size < 32 || der.size < 50) {
      return false
    }
    return try {
      val key = KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(der))
      val verifier = Signature.getInstance("SHA256withECDSA")
      verifier.initVerify(key)
      verifier.update(message.toByteArray(Charsets.UTF_8))
      verifier.verify(signature)
    } catch (_: Exception) {
      false
    }
  }

  private fun hex(value: String): ByteArray? {
    val text = value.trim().lowercase()
    if (text.length < 64 || text.length % 2 != 0 || text.any { it !in "0123456789abcdef" }) {
      return null
    }
    return ByteArray(text.length / 2) { index ->
      text.substring(index * 2, index * 2 + 2).toInt(16).toByte()
    }
  }
}
