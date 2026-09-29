package com.umaiz.checkmate

import android.annotation.SuppressLint
import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.VibrationEffect
import android.os.Vibrator
import android.util.Log
import android.webkit.ConsoleMessage
import android.webkit.JavascriptInterface
import android.webkit.JsPromptResult
import android.webkit.JsResult
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import java.io.ByteArrayInputStream
import java.io.FileNotFoundException
import java.util.Locale

/**
 * Checkmate — minimal Android shell.
 *
 * The product itself is the bundled HTML/CSS/JS/SVG client in `assets/app/`.
 * Assets are served through a synthetic, stable HTTPS origin
 * (https://appassets.androidplatform.net/) using WebViewClient#shouldInterceptRequest
 * instead of a `file://` URL:
 *
 *  - localStorage / IndexedDB behave normally on a stable origin
 *    (file:// storage is unreliable across Android versions),
 *  - navigation can be locked to that one host,
 *  - no cleartext, no third-party requests, no remote JavaScript.
 *
 * Security posture:
 *  - file and content access disabled,
 *  - cleartext blocked by network_security_config (HTTPS only, with a
 *    loopback exception for emulator-based backend development),
 *  - external http(s) links leave the app through ACTION_VIEW,
 *  - unknown / intent: schemes are dropped,
 *  - the only JS bridge exposes keep-screen-on, haptics, version and platform.
 */
class MainActivity : Activity() {

    companion object {
        private const val TAG = "Checkmate"
        private const val ASSET_HOST = "appassets.androidplatform.net"
        private const val ASSET_PREFIX = "https://$ASSET_HOST/"
        private const val ASSET_ROOT = "app"
        private const val STATE_WEBVIEW = "state_webview"
        private const val MAX_VIBRATE_MS = 1000
        private val WINDOW_BACKGROUND = 0xFF151515.toInt()
    }

    private var webView: WebView? = null

    // --------------------------------------------------------------- lifecycle

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val wv = WebView(this)
        webView = wv
        applyCheckmateSettings(wv)

        window.statusBarColor = WINDOW_BACKGROUND
        window.navigationBarColor = WINDOW_BACKGROUND

        wv.webViewClient = CheckmateWebViewClient()
        wv.webChromeClient = CheckmateChromeClient()
        wv.addJavascriptInterface(NativeBridge(), "CheckmateNative")

        setContentView(wv)

        if (savedInstanceState != null) {
            @Suppress("DEPRECATION")
            val saved = savedInstanceState.getBundle(STATE_WEBVIEW)
            if (saved != null && wv.restoreState(saved) != null) {
                return
            }
        }
        wv.loadUrl(ASSET_PREFIX)
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun applyCheckmateSettings(wv: WebView) {
        with(wv.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true          // localStorage: settings, session
            databaseEnabled = true            // IndexedDB: games, puzzles, history
            allowFileAccess = false           // assets are served over the synthetic origin
            allowContentAccess = false
            @Suppress("DEPRECATION")
            allowFileAccessFromFileURLs = false
            @Suppress("DEPRECATION")
            allowUniversalAccessFromFileURLs = false
            setSupportZoom(false)
            builtInZoomControls = false
            displayZoomControls = false
            mediaPlaybackRequiresUserGesture = true
            textZoom = 100                    // layout stays predictable; scaling lives in CSS
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            setRenderPriority(WebSettings.RenderPriority.HIGH)
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView?.let {
            val b = Bundle()
            @Suppress("DEPRECATION")
            it.saveState(b)
            outState.putBundle(STATE_WEBVIEW, b)
        }
    }

    override fun onResume() {
        super.onResume()
        webView?.onResume()
    }

    override fun onPause() {
        webView?.onPause()
        super.onPause()
    }

    override fun onDestroy() {
        webView?.let {
            it.stopLoading()
            it.destroy()
        }
        webView = null
        super.onDestroy()
    }

    override fun onBackPressed() {
        val wv = webView
        if (wv != null && wv.canGoBack()) {
            wv.goBack()
        } else {
            // At app root: keep the process (and any in-progress local game) warm.
            moveTaskToBack(true)
        }
    }

    // ----------------------------------------------------------------- bridge

    /**
     * Narrow, non-sensitive bridge exposed to the bundled page only.
     * No filesystem, no network, no account data, no token material.
     */
    inner class NativeBridge {
        @JavascriptInterface
        fun setKeepScreenOn(enabled: Boolean) {
            runOnUiThread { window.decorView.keepScreenOn = enabled }
        }

        @JavascriptInterface
        fun vibrate(milliseconds: Int) {
            if (milliseconds <= 0) return
            val clamped = milliseconds.coerceAtMost(MAX_VIBRATE_MS)
            runOnUiThread {
                val vibrator = getSystemService(VIBRATOR_SERVICE) as? Vibrator ?: return@runOnUiThread
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    vibrator.vibrate(
                        VibrationEffect.createOneShot(clamped.toLong(), VibrationEffect.DEFAULT_AMPLITUDE)
                    )
                } else {
                    @Suppress("DEPRECATION")
                    vibrator.vibrate(clamped.toLong())
                }
            }
        }

        @JavascriptInterface
        fun versionName(): String = try {
            @Suppress("DEPRECATION")
            packageManager.getPackageInfo(packageName, 0).versionName ?: "1.0.0"
        } catch (e: Exception) {
            "1.0.0"
        }

        @JavascriptInterface
        fun platform(): String = "android-${Build.VERSION.SDK_INT}"
    }

    // ---------------------------------------------------------- web plumbing

    private inner class CheckmateWebViewClient : WebViewClient() {

        // Main-frame navigation policy (API 24+).
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            return handleNavigation(request.url.toString())
        }

        // Legacy variant for API 21-23.
        @Suppress("DEPRECATION")
        override fun shouldOverrideUrlLoading(view: WebView, url: String): Boolean {
            return handleNavigation(url)
        }

        private fun handleNavigation(url: String): Boolean {
            val lower = url.lowercase(Locale.US)
            return when {
                lower.startsWith(ASSET_PREFIX) -> false          // inside the app
                lower == "about:blank" -> false
                lower.startsWith("data:") || lower.startsWith("blob:") -> false
                lower.startsWith("http://") || lower.startsWith("https://") -> {
                    // Untrusted/external link: leave the app context.
                    openExternally(url)
                    true
                }
                lower.startsWith("intent:") -> true              // dropped
                lower.startsWith("javascript:") -> true          // never navigate to JS URLs
                else -> {
                    // tel:, mailto:, market:, ... -> hand to the system.
                    try {
                        openExternally(url)
                    } catch (e: Exception) {
                        Log.i(TAG, "blocked navigation to unsupported scheme")
                    }
                    true
                }
            }
        }

        private fun openExternally(url: String) {
            try {
                val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                startActivity(intent)
            } catch (e: Exception) {
                Log.i(TAG, "no handler for external url")
            }
        }

        // Serve bundled assets for the synthetic origin.
        override fun shouldInterceptRequest(
            view: WebView,
            request: WebResourceRequest
        ): WebResourceResponse? {
            val url = request.url
            if (!url.host.equals(ASSET_HOST, ignoreCase = true)) return null
            if (url.scheme != "https") return null

            var path = url.path ?: "/"
            if (path.isEmpty()) path = "/"
            if (path.endsWith("/")) path += "index.html"

            serveAsset(path)?.let { return it }

            // SPA fallback: extension-less routes render the app shell.
            if (request.isForMainFrame) {
                return serveAsset("/index.html") ?: notFoundResponse()
            }
            return notFoundResponse()
        }

        private fun serveAsset(path: String): WebResourceResponse? {
            val normalized = if (path.startsWith("/")) path.substring(1) else path
            if (normalized.isEmpty() || normalized.contains("..")) return null
            return try {
                val stream = assets.open("$ASSET_ROOT/$normalized")
                val mime = mimeTypeFor(normalized)
                if (isTextMime(mime)) {
                    WebResourceResponse(mime, "UTF-8", stream)
                } else {
                    WebResourceResponse(mime, null, stream)
                }
            } catch (e: FileNotFoundException) {
                null
            } catch (e: Exception) {
                Log.w(TAG, "asset read failed")
                null
            }
        }

        private fun notFoundResponse(): WebResourceResponse =
            WebResourceResponse(
                "text/plain",
                "UTF-8",
                404,
                "Not found",
                emptyMap(),
                ByteArrayInputStream(ByteArray(0))
            )

        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            if (request.isForMainFrame) {
                // Local assets failed to load (corrupted install).
                Log.e(TAG, "main frame load error: ${error.description}")
            }
        }

        override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail?): Boolean {
            Log.e(TAG, "renderer gone; restarting")
            webView?.let {
                it.stopLoading()
                it.destroy()
            }
            webView = null
            finish()
            return true
        }
    }

    private inner class CheckmateChromeClient : WebChromeClient() {
        override fun onConsoleMessage(consoleMessage: ConsoleMessage?): Boolean {
            consoleMessage?.let { Log.d(TAG, "js:${it.messageLevel()} ${it.message()}") }
            return true
        }

        override fun onJsAlert(view: WebView?, url: String?, message: String?, result: JsResult?): Boolean {
            Log.i(TAG, "js-alert: $message")
            result?.confirm()
            return true
        }

        override fun onJsConfirm(view: WebView?, url: String?, message: String?, result: JsResult?): Boolean {
            result?.confirm()
            return true
        }

        override fun onJsPrompt(
            view: WebView?,
            url: String?,
            message: String?,
            defaultValue: String?,
            result: JsPromptResult?
        ): Boolean {
            result?.confirm(defaultValue ?: "")
            return true
        }

        override fun onPermissionRequest(request: PermissionRequest?) {
            // Camera/microphone are not part of the product; deny everything.
            request?.deny()
        }

        override fun onShowFileChooser(
            view: WebView?,
            filePathCallback: ValueCallback<Array<Uri>>?,
            fileChooserParams: WebChromeClient.FileChooserParams?
        ): Boolean {
            filePathCallback?.onReceiveValue(null)
            return true
        }
    }

    // ---------------------------------------------------------------- helpers

    private fun mimeTypeFor(path: String): String {
        val ext = path.substringAfterLast('.', "").lowercase(Locale.US)
        return when (ext) {
            "html", "htm" -> "text/html"
            "css" -> "text/css"
            "js", "mjs" -> "application/javascript"
            "json", "map" -> "application/json"
            "svg" -> "image/svg+xml"
            "png" -> "image/png"
            "jpg", "jpeg" -> "image/jpeg"
            "gif" -> "image/gif"
            "webp" -> "image/webp"
            "ico" -> "image/x-icon"
            "woff" -> "font/woff"
            "woff2" -> "font/woff2"
            "ttf" -> "font/ttf"
            "txt" -> "text/plain"
            "webmanifest" -> "application/manifest+json"
            "xml" -> "application/xml"
            else -> "application/octet-stream"
        }
    }

    private fun isTextMime(mime: String): Boolean =
        mime.startsWith("text/") ||
            mime == "application/javascript" ||
            mime == "application/json" ||
            mime == "image/svg+xml" ||
            mime == "application/xml" ||
            mime == "application/manifest+json"
}
