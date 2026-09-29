# =============================================================================
# Checkmate release shrink rules (R8).
#
# Deliberately conservative: shrink only (remove unused library code, e.g.
# unused kotlin-stdlib classes), no renaming, no reordering. The app cannot be
# executed in this build environment (no device/emulator), so obfuscation is
# withheld to avoid untestable risk. Enabling it requires an on-device test.
# =============================================================================

# Keep the entire application package and the JS bridge surface.
-keep class com.umaiz.checkmate.** { *; }
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}

# Kotlin/JVM metadata needed for reflection-free operation.
-keepattributes RuntimeVisibleAnnotations,AnnotationDefault,Signature,InnerClasses,EnclosingMethod,SourceFile,LineNumberTable

# No obfuscation / no optimization (see header comment).
-dontobfuscate
-dontoptimize

# Compile-time-only annotations and optional references.
-dontwarn org.jetbrains.annotations.**
-dontwarn kotlinx.coroutines.**
-dontwarn kotlin.**
-dontwarn android.**
