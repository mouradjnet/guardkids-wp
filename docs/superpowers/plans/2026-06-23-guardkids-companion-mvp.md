# GuardKids Companion MVP — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** App Android nativo que pareia com o GuardKids WP via QR e reporta telemetria (device info + estado real das flags de permissão) por `enroll → sync → heartbeat periódico`, sem enforcement de bloqueio.

**Architecture:** Camada única enxuta em Kotlin/Compose. Unidades puras e testáveis (`QrPayload`, `DeviceState`, `SyncRunner`, `CompanionViewModel`) atrás de interfaces (`SessionStorage`, `SystemInspector`, `CompanionApi`), com implementações Android (EncryptedSharedPreferences, DevicePolicyManager, Retrofit) injetadas. Background via WorkManager.

**Tech Stack:** Kotlin 2.0, Jetpack Compose (Material3), Retrofit + OkHttp + kotlinx-serialization, WorkManager, androidx.security EncryptedSharedPreferences, zxing-android-embedded. Testes: JUnit4 + kotlinx-coroutines-test + MockWebServer.

**Repo alvo:** novo, em `C:\Users\mysho\guardkids-companion` (privado, `mouradjnet`). Pacote `site.guardiaokids.companion`. Backend já em produção (guardkids-wp v1.8.0).

**Convenção de teste/build (rodar da raiz do repo novo):**
- Unit: `./gradlew testDebugUnitTest`
- Lint+build: `./gradlew lintDebug assembleDebug`
- No Windows o wrapper é `./gradlew` no Git Bash (ou `gradlew.bat` no cmd).

---

## File Structure

```
guardkids-companion/
├── settings.gradle.kts
├── build.gradle.kts                      # root (plugins, sem deps)
├── gradle.properties
├── gradle/wrapper/…                       # gradle wrapper
├── .gitignore
├── .github/workflows/ci.yml
├── README.md
└── app/
    ├── build.gradle.kts                   # módulo app + deps
    ├── proguard-rules.pro
    └── src/
        ├── main/
        │   ├── AndroidManifest.xml
        │   └── java/site/guardiaokids/companion/
        │       ├── CompanionApplication.kt
        │       ├── MainActivity.kt
        │       ├── data/
        │       │   ├── QrPayload.kt              # parser do QR (puro)
        │       │   ├── Session.kt                # modelo + SessionStorage interface
        │       │   ├── EncryptedSessionStorage.kt# impl Android
        │       │   ├── DeviceState.kt            # DeviceSnapshot + SystemInspector + collect()
        │       │   ├── AndroidSystemInspector.kt # impl Android
        │       │   └── CompanionApi.kt           # Retrofit interface + DTOs + factory
        │       ├── sync/
        │       │   ├── SyncRunner.kt             # lógica pura do tick periódico
        │       │   ├── HeartbeatWorker.kt        # CoroutineWorker (delega ao SyncRunner)
        │       │   ├── WorkerScheduler.kt        # enqueue/cancel periódico
        │       │   └── BootReceiver.kt           # re-agenda no boot
        │       └── ui/
        │           ├── CompanionViewModel.kt     # state machine
        │           ├── CompanionApp.kt           # navegação por estado
        │           ├── PairingScreen.kt
        │           ├── EnrollingScreen.kt
        │           └── StatusScreen.kt
        ├── test/java/site/guardiaokids/companion/
        │   ├── data/QrPayloadTest.kt
        │   ├── data/DeviceStateTest.kt
        │   ├── data/CompanionApiTest.kt          # MockWebServer (roda na JVM)
        │   ├── data/FakeSessionStorage.kt        # fake compartilhado
        │   ├── sync/SyncRunnerTest.kt
        │   └── ui/CompanionViewModelTest.kt
        └── androidTest/java/site/guardiaokids/companion/
            └── EnrollSyncFlowTest.kt             # instrumentado, MockWebServer
```

**Responsabilidades:** `data/` = modelos + I/O (rede, storage, OS reads). `sync/` = trabalho periódico em background. `ui/` = Compose + ViewModel. Interfaces (`SessionStorage`, `SystemInspector`, `CompanionApi`) isolam o Android das unidades testáveis.

---

## Task 0: Scaffold do projeto Android

**Files:**
- Create: `C:\Users\mysho\guardkids-companion\settings.gradle.kts`
- Create: `build.gradle.kts`, `gradle.properties`, `.gitignore`, `app/build.gradle.kts`, `app/proguard-rules.pro`, `app/src/main/AndroidManifest.xml`, `app/src/main/java/site/guardiaokids/companion/CompanionApplication.kt`

- [ ] **Step 1: Criar o diretório e o git**

```bash
mkdir -p /c/Users/mysho/guardkids-companion
cd /c/Users/mysho/guardkids-companion
git init
```

- [ ] **Step 2: `settings.gradle.kts`**

```kotlin
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = "guardkids-companion"
include(":app")
```

- [ ] **Step 3: root `build.gradle.kts`**

```kotlin
plugins {
    id("com.android.application") version "8.7.3" apply false
    id("org.jetbrains.kotlin.android") version "2.0.21" apply false
    id("org.jetbrains.kotlin.plugin.serialization") version "2.0.21" apply false
    id("org.jetbrains.kotlin.plugin.compose") version "2.0.21" apply false
}
```

- [ ] **Step 4: `gradle.properties`**

```properties
org.gradle.jvmargs=-Xmx2048m -Dfile.encoding=UTF-8
android.useAndroidX=true
kotlin.code.style=official
android.nonTransitiveRClass=true
```

- [ ] **Step 5: `.gitignore`**

```gitignore
*.iml
.gradle/
/local.properties
/.idea/
.DS_Store
/build
/captures
.externalNativeBuild
.cxx
local.properties
app/build/
```

- [ ] **Step 6: `app/build.gradle.kts`**

```kotlin
plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.serialization")
    id("org.jetbrains.kotlin.plugin.compose")
}

android {
    namespace = "site.guardiaokids.companion"
    compileSdk = 35

    defaultConfig {
        applicationId = "site.guardiaokids.companion"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures { compose = true; buildConfig = true }
    testOptions { unitTests.isReturnDefaultValues = true }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2024.12.01")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.activity:activity-compose:1.9.3")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.8.7")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")

    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.7.3")
    implementation("com.squareup.retrofit2:retrofit:2.11.0")
    implementation("com.squareup.retrofit2:converter-kotlinx-serialization:2.11.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")

    implementation("androidx.work:work-runtime-ktx:2.10.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")

    testImplementation("junit:junit:4.13.2")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.9.0")
    testImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")

    androidTestImplementation("androidx.test.ext:junit:1.2.1")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.6.1")
    androidTestImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")
}
```

- [ ] **Step 7: `app/proguard-rules.pro`** (vazio com comentário)

```proguard
# Sem regras custom no MVP (minify off no release).
```

- [ ] **Step 8: `app/src/main/AndroidManifest.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.CAMERA" />
    <uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />

    <uses-feature android:name="android.hardware.camera" android:required="false" />

    <application
        android:name=".CompanionApplication"
        android:allowBackup="false"
        android:label="GuardKids Companion"
        android:supportsRtl="true"
        android:theme="@style/Theme.Material3.DayNight.NoActionBar">

        <activity
            android:name=".MainActivity"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>

        <receiver
            android:name=".sync.BootReceiver"
            android:exported="true">
            <intent-filter>
                <action android:name="android.intent.action.BOOT_COMPLETED" />
            </intent-filter>
        </receiver>
    </application>
</manifest>
```

- [ ] **Step 9: `CompanionApplication.kt`** (placeholder mínimo pra app montar)

```kotlin
package site.guardiaokids.companion

import android.app.Application

class CompanionApplication : Application()
```

- [ ] **Step 10: Gerar o gradle wrapper**

Run: `cd /c/Users/mysho/guardkids-companion && gradle wrapper --gradle-version 8.11.1`
(Se `gradle` não estiver no PATH, instalar via `winget install Gradle.Gradle` ou usar o do Android Studio.)
Expected: cria `gradlew`, `gradlew.bat`, `gradle/wrapper/`.

- [ ] **Step 11: Verificar que o build vazio compila**

Run: `./gradlew assembleDebug`
Expected: BUILD SUCCESSFUL (APK vazio gerado em `app/build/outputs/apk/debug/`).

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "chore: scaffold do projeto Android (Gradle, manifest, deps)"
```

---

## Task 1: QrPayload (parser do QR)

O backend gera `qrPayload` = `{"v":1,"type":"gk-companion-pair","child":N,"uuid":"...","tok":"...","api":"https://.../wp-json/guardkids/v1"}`.

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/data/QrPayload.kt`
- Test: `app/src/test/java/site/guardiaokids/companion/data/QrPayloadTest.kt`

- [ ] **Step 1: Escrever o teste que falha**

```kotlin
package site.guardiaokids.companion.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class QrPayloadTest {
    private val valid = """{"v":1,"type":"gk-companion-pair","child":7,"uuid":"abc123","tok":"deadbeef","api":"https://guardiaokids.site/wp-json/guardkids/v1"}"""

    @Test fun parsesValidPayload() {
        val p = QrPayload.parse(valid)!!
        assertEquals(1, p.version)
        assertEquals(7, p.child)
        assertEquals("abc123", p.uuid)
        assertEquals("deadbeef", p.token)
        assertEquals("https://guardiaokids.site/wp-json/guardkids/v1", p.api)
    }

    @Test fun rejectsWrongType() {
        assertNull(QrPayload.parse(valid.replace("gk-companion-pair", "outra-coisa")))
    }

    @Test fun rejectsWrongVersion() {
        assertNull(QrPayload.parse(valid.replace("\"v\":1", "\"v\":2")))
    }

    @Test fun rejectsMalformedJson() {
        assertNull(QrPayload.parse("não é json"))
        assertNull(QrPayload.parse(""))
    }
}
```

- [ ] **Step 2: Rodar pra ver falhar**

Run: `./gradlew testDebugUnitTest --tests "*QrPayloadTest"`
Expected: FAIL (QrPayload não existe / unresolved reference).

- [ ] **Step 3: Implementação mínima**

```kotlin
package site.guardiaokids.companion.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

@Serializable
data class QrPayload(
    @SerialName("v") val version: Int,
    @SerialName("type") val type: String,
    @SerialName("child") val child: Int,
    @SerialName("uuid") val uuid: String,
    @SerialName("tok") val token: String,
    @SerialName("api") val api: String,
) {
    companion object {
        private val json = Json { ignoreUnknownKeys = true }
        private const val EXPECTED_TYPE = "gk-companion-pair"
        private const val EXPECTED_VERSION = 1

        fun parse(raw: String): QrPayload? = try {
            json.decodeFromString<QrPayload>(raw)
                .takeIf { it.type == EXPECTED_TYPE && it.version == EXPECTED_VERSION }
        } catch (_: Exception) {
            null
        }
    }
}
```

- [ ] **Step 4: Rodar pra ver passar**

Run: `./gradlew testDebugUnitTest --tests "*QrPayloadTest"`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/data/QrPayload.kt app/src/test/java/site/guardiaokids/companion/data/QrPayloadTest.kt
git commit -m "feat: parser do QR payload de pareamento"
```

---

## Task 2: Session + SessionStorage (interface + fake + impl Android)

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/data/Session.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/data/EncryptedSessionStorage.kt`
- Create: `app/src/test/java/site/guardiaokids/companion/data/FakeSessionStorage.kt`

`SessionStorage` é a interface (testável); `EncryptedSessionStorage` é a impl Android (smoke só). O fake é usado pelos testes de ViewModel/SyncRunner.

- [ ] **Step 1: `Session.kt` (modelo + interface)**

```kotlin
package site.guardiaokids.companion.data

data class Session(
    val sessionToken: String,
    val deviceUuid: String,
    val apiBaseUrl: String,
    val childId: Int,
)

interface SessionStorage {
    fun read(): Session?
    fun save(session: Session)
    fun clear()
}
```

- [ ] **Step 2: `FakeSessionStorage.kt` (em test/, fake em memória)**

```kotlin
package site.guardiaokids.companion.data

class FakeSessionStorage(private var session: Session? = null) : SessionStorage {
    var clearCount = 0
        private set
    override fun read(): Session? = session
    override fun save(session: Session) { this.session = session }
    override fun clear() { session = null; clearCount++ }
}
```

- [ ] **Step 3: `EncryptedSessionStorage.kt` (impl Android)**

```kotlin
package site.guardiaokids.companion.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Persiste a sessão em EncryptedSharedPreferences (Keystore-backed).
 * security-crypto é deprecado mas funcional; fica atrás de SessionStorage,
 * então trocar por DataStore+Keystore depois é local a esta classe.
 */
class EncryptedSessionStorage(context: Context) : SessionStorage {
    private val prefs = run {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context,
            "companion_session",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    override fun read(): Session? {
        val token = prefs.getString(KEY_TOKEN, null) ?: return null
        val uuid = prefs.getString(KEY_UUID, null) ?: return null
        val api = prefs.getString(KEY_API, null) ?: return null
        val child = prefs.getInt(KEY_CHILD, 0)
        return Session(token, uuid, api, child)
    }

    override fun save(session: Session) {
        prefs.edit()
            .putString(KEY_TOKEN, session.sessionToken)
            .putString(KEY_UUID, session.deviceUuid)
            .putString(KEY_API, session.apiBaseUrl)
            .putInt(KEY_CHILD, session.childId)
            .apply()
    }

    override fun clear() {
        prefs.edit().clear().apply()
    }

    private companion object {
        const val KEY_TOKEN = "session_token"
        const val KEY_UUID = "device_uuid"
        const val KEY_API = "api_base_url"
        const val KEY_CHILD = "child_id"
    }
}
```

- [ ] **Step 4: Compilar (sem teste novo de unidade — interface trivial; fake validado nos testes seguintes)**

Run: `./gradlew compileDebugKotlin compileDebugUnitTestKotlin`
Expected: BUILD SUCCESSFUL.

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/data/Session.kt app/src/main/java/site/guardiaokids/companion/data/EncryptedSessionStorage.kt app/src/test/java/site/guardiaokids/companion/data/FakeSessionStorage.kt
git commit -m "feat: SessionStorage (interface + fake + impl EncryptedSharedPreferences)"
```

---

## Task 3: DeviceState (snapshot das flags de permissão)

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/data/DeviceState.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/data/AndroidSystemInspector.kt`
- Test: `app/src/test/java/site/guardiaokids/companion/data/DeviceStateTest.kt`

`SystemInspector` expõe os reads brutos do OS (mockáveis); `DeviceState.collect()` mapeia pro `DeviceSnapshot`. No MVP, `deviceAdmin`/`accessibility` ainda são `false` (sem componente provisionado), mas o código lê o estado real, então flipam sozinhos na fase de enforcement.

- [ ] **Step 1: Escrever o teste que falha**

```kotlin
package site.guardiaokids.companion.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class DeviceStateTest {
    private class FakeInspector(
        val accessibility: Boolean = false,
        val admin: Boolean = false,
        val owner: Boolean = false,
        val playStore: Boolean = true,
    ) : SystemInspector {
        override fun isAccessibilityServiceEnabled() = accessibility
        override fun isDeviceAdminActive() = admin
        override fun isDeviceOwner() = owner
        override fun isPlayStoreEnabled() = playStore
    }

    @Test fun mapsAllFlags() {
        val snap = DeviceState.collect(
            inspector = FakeInspector(accessibility = true, admin = true, owner = true, playStore = false),
            androidVersion = "14",
            companionVersion = "0.1.0",
        )
        assertEquals("14", snap.androidVersion)
        assertEquals("0.1.0", snap.companionVersion)
        assertTrue(snap.accessibilityEnabled)
        assertTrue(snap.deviceAdminEnabled)
        assertTrue(snap.deviceOwnerEnabled)
        assertFalse(snap.playStoreEnabled)
    }

    @Test fun defaultsUnprovisionedFlagsToFalse() {
        val snap = DeviceState.collect(FakeInspector(), "12", "0.1.0")
        assertFalse(snap.accessibilityEnabled)
        assertFalse(snap.deviceAdminEnabled)
        assertFalse(snap.deviceOwnerEnabled)
        assertTrue(snap.playStoreEnabled)
    }
}
```

- [ ] **Step 2: Rodar pra ver falhar**

Run: `./gradlew testDebugUnitTest --tests "*DeviceStateTest"`
Expected: FAIL (DeviceState/SystemInspector/DeviceSnapshot não existem).

- [ ] **Step 3: `DeviceState.kt` (modelo + interface + collect puro)**

```kotlin
package site.guardiaokids.companion.data

data class DeviceSnapshot(
    val androidVersion: String,
    val companionVersion: String,
    val accessibilityEnabled: Boolean,
    val deviceAdminEnabled: Boolean,
    val deviceOwnerEnabled: Boolean,
    val playStoreEnabled: Boolean,
)

interface SystemInspector {
    fun isAccessibilityServiceEnabled(): Boolean
    fun isDeviceAdminActive(): Boolean
    fun isDeviceOwner(): Boolean
    fun isPlayStoreEnabled(): Boolean
}

object DeviceState {
    fun collect(
        inspector: SystemInspector,
        androidVersion: String,
        companionVersion: String,
    ): DeviceSnapshot = DeviceSnapshot(
        androidVersion = androidVersion,
        companionVersion = companionVersion,
        accessibilityEnabled = inspector.isAccessibilityServiceEnabled(),
        deviceAdminEnabled = inspector.isDeviceAdminActive(),
        deviceOwnerEnabled = inspector.isDeviceOwner(),
        playStoreEnabled = inspector.isPlayStoreEnabled(),
    )
}
```

- [ ] **Step 4: `AndroidSystemInspector.kt` (impl Android, smoke só)**

```kotlin
package site.guardiaokids.companion.data

import android.app.admin.DevicePolicyManager
import android.content.Context
import android.content.pm.PackageManager

/**
 * Reads reais do OS. No MVP não há serviço de Accessibility nem
 * DeviceAdminReceiver declarado, então essas duas reportam false até a fase
 * de enforcement adicionar os componentes. isDeviceOwner/playStore já são reais.
 */
class AndroidSystemInspector(private val context: Context) : SystemInspector {
    private val dpm get() = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager

    override fun isAccessibilityServiceEnabled(): Boolean = false // sem serviço no MVP

    override fun isDeviceAdminActive(): Boolean = dpm.activeAdmins?.any {
        it.packageName == context.packageName
    } ?: false

    override fun isDeviceOwner(): Boolean = dpm.isDeviceOwnerApp(context.packageName)

    override fun isPlayStoreEnabled(): Boolean = try {
        context.packageManager.getApplicationInfo("com.android.vending", 0).enabled
    } catch (_: PackageManager.NameNotFoundException) {
        false
    }
}
```

- [ ] **Step 5: Rodar pra ver passar**

Run: `./gradlew testDebugUnitTest --tests "*DeviceStateTest"`
Expected: PASS (2 testes).

- [ ] **Step 6: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/data/DeviceState.kt app/src/main/java/site/guardiaokids/companion/data/AndroidSystemInspector.kt app/src/test/java/site/guardiaokids/companion/data/DeviceStateTest.kt
git commit -m "feat: DeviceState snapshot das flags de permissão"
```

---

## Task 4: CompanionApi (Retrofit interface + DTOs + factory)

O backend espera os campos do `sync` em snake_case e devolve `sessionToken`/`deviceUuid` no enroll. Auth via header `X-GuardKids-Companion-Token`.

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/data/CompanionApi.kt`
- Test: `app/src/test/java/site/guardiaokids/companion/data/CompanionApiTest.kt`

- [ ] **Step 1: Escrever o teste que falha (MockWebServer roda na JVM)**

```kotlin
package site.guardiaokids.companion.data

import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class CompanionApiTest {
    private lateinit var server: MockWebServer
    private lateinit var api: CompanionApi

    @Before fun setUp() {
        server = MockWebServer()
        server.start()
        api = CompanionApi.create(server.url("/wp-json/guardkids/v1").toString())
    }

    @After fun tearDown() { server.shutdown() }

    @Test fun enrollSendsTokenHeaderAndParsesResponse() = runBlocking {
        server.enqueue(MockResponse().setResponseCode(201)
            .setBody("""{"sessionToken":"sess123","deviceUuid":"uuid9"}"""))

        val res = api.enroll("pair-token-abc")

        val req = server.takeRequest()
        assertEquals("/wp-json/guardkids/v1/companion/enroll", req.path)
        assertEquals("pair-token-abc", req.getHeader("X-GuardKids-Companion-Token"))
        assertEquals("sess123", res.sessionToken)
        assertEquals("uuid9", res.deviceUuid)
    }

    @Test fun syncSerializesSnakeCaseBody() = runBlocking {
        server.enqueue(MockResponse().setResponseCode(200)
            .setBody("""{"paired":true,"status":"active"}"""))

        api.sync("sess123", SyncRequest(
            deviceName = "Galaxy",
            androidVersion = "14",
            companionVersion = "0.1.0",
            deviceOwnerEnabled = false,
            accessibilityEnabled = false,
            deviceAdminEnabled = false,
            playStoreEnabled = true,
        ))

        val body = server.takeRequest().body.readUtf8()
        assertTrue(body.contains("\"android_version\":\"14\""))
        assertTrue(body.contains("\"play_store_enabled\":true"))
        assertTrue(body.contains("\"device_name\":\"Galaxy\""))
    }
}
```

- [ ] **Step 2: Rodar pra ver falhar**

Run: `./gradlew testDebugUnitTest --tests "*CompanionApiTest"`
Expected: FAIL (CompanionApi/SyncRequest não existem).

- [ ] **Step 3: `CompanionApi.kt` (interface + DTOs + factory)**

```kotlin
package site.guardiaokids.companion.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.kotlinx.serialization.asConverterFactory
import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST
import java.util.concurrent.TimeUnit

@Serializable
data class EnrollResponse(
    val sessionToken: String,
    val deviceUuid: String,
)

@Serializable
data class SyncRequest(
    @SerialName("device_name") val deviceName: String?,
    @SerialName("android_version") val androidVersion: String,
    @SerialName("companion_version") val companionVersion: String,
    @SerialName("device_owner_enabled") val deviceOwnerEnabled: Boolean,
    @SerialName("accessibility_enabled") val accessibilityEnabled: Boolean,
    @SerialName("device_admin_enabled") val deviceAdminEnabled: Boolean,
    @SerialName("play_store_enabled") val playStoreEnabled: Boolean,
)

@Serializable
data class SyncResponse(
    val paired: Boolean = false,
    val status: String = "pending",
)

@Serializable
data class HeartbeatResponse(
    val ok: Boolean = false,
)

interface CompanionApi {
    @POST("companion/enroll")
    suspend fun enroll(@Header("X-GuardKids-Companion-Token") token: String): EnrollResponse

    @POST("companion/sync")
    suspend fun sync(
        @Header("X-GuardKids-Companion-Token") token: String,
        @Body body: SyncRequest,
    ): SyncResponse

    @POST("companion/heartbeat")
    suspend fun heartbeat(@Header("X-GuardKids-Companion-Token") token: String): HeartbeatResponse

    companion object {
        private val json = Json { ignoreUnknownKeys = true }

        /** baseUrl ex.: "https://site/wp-json/guardkids/v1" (sem barra final). */
        fun create(baseUrl: String): CompanionApi {
            val client = OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(20, TimeUnit.SECONDS)
                .build()
            return Retrofit.Builder()
                .baseUrl(baseUrl.trimEnd('/') + "/")
                .client(client)
                .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
                .build()
                .create(CompanionApi::class.java)
        }
    }
}
```

- [ ] **Step 4: Rodar pra ver passar**

Run: `./gradlew testDebugUnitTest --tests "*CompanionApiTest"`
Expected: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/data/CompanionApi.kt app/src/test/java/site/guardiaokids/companion/data/CompanionApiTest.kt
git commit -m "feat: CompanionApi Retrofit (enroll/sync/heartbeat)"
```

---

## Task 5: SyncRunner (lógica pura do tick periódico)

`SyncRunner` é o miolo testável do worker: lê a sessão, coleta o snapshot, faz `sync`, e classifica o resultado. No MVP o tick periódico chama `sync` (idempotente, atualiza `last_sync` + flags) — o endpoint `/heartbeat` fica reservado pra um ping leve futuro.

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/sync/SyncRunner.kt`
- Test: `app/src/test/java/site/guardiaokids/companion/sync/SyncRunnerTest.kt`

- [ ] **Step 1: Escrever o teste que falha**

```kotlin
package site.guardiaokids.companion.sync

import kotlinx.coroutines.runBlocking
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.Assert.assertEquals
import org.junit.Test
import retrofit2.HttpException
import retrofit2.Response
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.DeviceSnapshot
import site.guardiaokids.companion.data.EnrollResponse
import site.guardiaokids.companion.data.FakeSessionStorage
import site.guardiaokids.companion.data.HeartbeatResponse
import site.guardiaokids.companion.data.Session
import site.guardiaokids.companion.data.SyncRequest
import site.guardiaokids.companion.data.SyncResponse

class SyncRunnerTest {
    private val snapshot = DeviceSnapshot("14", "0.1.0", false, false, false, true)
    private fun snapshotProvider(): DeviceSnapshot = snapshot

    private class FakeApi(
        val onSync: suspend () -> SyncResponse = { SyncResponse(paired = true, status = "active") },
    ) : CompanionApi {
        var syncCalls = 0
        override suspend fun enroll(token: String) = EnrollResponse("x", "y")
        override suspend fun sync(token: String, body: SyncRequest): SyncResponse {
            syncCalls++; return onSync()
        }
        override suspend fun heartbeat(token: String) = HeartbeatResponse(true)
    }

    private fun http401(): HttpException =
        HttpException(Response.error<Any>(401, "".toResponseBody(null)))

    @Test fun noSessionReturnsSuccessWithoutCalling() = runBlocking {
        val storage = FakeSessionStorage(null)
        val api = FakeApi()
        val result = SyncRunner.run(storage, { api }, ::snapshotProvider)
        assertEquals(SyncResult.SUCCESS, result)
        assertEquals(0, api.syncCalls)
    }

    @Test fun validSessionSyncsAndSucceeds() = runBlocking {
        val storage = FakeSessionStorage(Session("sess", "uuid", "https://x/wp-json/guardkids/v1", 7))
        val api = FakeApi()
        val result = SyncRunner.run(storage, { api }, ::snapshotProvider)
        assertEquals(SyncResult.SUCCESS, result)
        assertEquals(1, api.syncCalls)
    }

    @Test fun unauthorizedClearsSessionAndReportsRevoked() = runBlocking {
        val storage = FakeSessionStorage(Session("sess", "uuid", "https://x/wp-json/guardkids/v1", 7))
        val api = FakeApi(onSync = { throw http401() })
        val result = SyncRunner.run(storage, { api }, ::snapshotProvider)
        assertEquals(SyncResult.REVOKED, result)
        assertEquals(1, storage.clearCount)
    }

    @Test fun networkErrorReturnsRetry() = runBlocking {
        val storage = FakeSessionStorage(Session("sess", "uuid", "https://x/wp-json/guardkids/v1", 7))
        val api = FakeApi(onSync = { throw java.io.IOException("offline") })
        val result = SyncRunner.run(storage, { api }, ::snapshotProvider)
        assertEquals(SyncResult.RETRY, result)
        assertEquals(0, storage.clearCount)
    }
}
```

- [ ] **Step 2: Rodar pra ver falhar**

Run: `./gradlew testDebugUnitTest --tests "*SyncRunnerTest"`
Expected: FAIL (SyncRunner/SyncResult não existem).

- [ ] **Step 3: Implementação**

```kotlin
package site.guardiaokids.companion.sync

import retrofit2.HttpException
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.DeviceSnapshot
import site.guardiaokids.companion.data.SessionStorage
import site.guardiaokids.companion.data.SyncRequest
import java.io.IOException

enum class SyncResult { SUCCESS, RETRY, REVOKED }

object SyncRunner {
    /**
     * @param apiFactory cria a CompanionApi pra base URL da sessão.
     * @param snapshotProvider coleta o DeviceSnapshot atual.
     */
    suspend fun run(
        storage: SessionStorage,
        apiFactory: (String) -> CompanionApi,
        snapshotProvider: () -> DeviceSnapshot,
    ): SyncResult {
        val session = storage.read() ?: return SyncResult.SUCCESS
        val snap = snapshotProvider()
        return try {
            apiFactory(session.apiBaseUrl).sync(
                session.sessionToken,
                SyncRequest(
                    deviceName = null,
                    androidVersion = snap.androidVersion,
                    companionVersion = snap.companionVersion,
                    deviceOwnerEnabled = snap.deviceOwnerEnabled,
                    accessibilityEnabled = snap.accessibilityEnabled,
                    deviceAdminEnabled = snap.deviceAdminEnabled,
                    playStoreEnabled = snap.playStoreEnabled,
                ),
            )
            SyncResult.SUCCESS
        } catch (e: HttpException) {
            if (e.code() == 401) {
                storage.clear()
                SyncResult.REVOKED
            } else {
                SyncResult.RETRY
            }
        } catch (_: IOException) {
            SyncResult.RETRY
        }
    }
}
```

- [ ] **Step 4: Rodar pra ver passar**

Run: `./gradlew testDebugUnitTest --tests "*SyncRunnerTest"`
Expected: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/sync/SyncRunner.kt app/src/test/java/site/guardiaokids/companion/sync/SyncRunnerTest.kt
git commit -m "feat: SyncRunner (lógica pura do tick periódico)"
```

---

## Task 6: HeartbeatWorker + WorkerScheduler + BootReceiver

Wiring Android em volta do `SyncRunner` (smoke; sem unit test — dependem do framework).

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/sync/HeartbeatWorker.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/sync/WorkerScheduler.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/sync/BootReceiver.kt`

- [ ] **Step 1: `WorkerScheduler.kt`**

```kotlin
package site.guardiaokids.companion.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

object WorkerScheduler {
    private const val WORK_NAME = "companion_heartbeat"

    fun schedule(context: Context) {
        val request = PeriodicWorkRequestBuilder<HeartbeatWorker>(30, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 5, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            WORK_NAME,
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    fun cancel(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_NAME)
    }
}
```

- [ ] **Step 2: `HeartbeatWorker.kt`**

```kotlin
package site.guardiaokids.companion.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import site.guardiaokids.companion.data.AndroidSystemInspector
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.DeviceState
import site.guardiaokids.companion.data.EncryptedSessionStorage

class HeartbeatWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val storage = EncryptedSessionStorage(applicationContext)
        val inspector = AndroidSystemInspector(applicationContext)
        val version = applicationContext.packageManager
            .getPackageInfo(applicationContext.packageName, 0).versionName ?: "0"

        val result = SyncRunner.run(
            storage = storage,
            apiFactory = { base -> CompanionApi.create(base) },
            snapshotProvider = {
                DeviceState.collect(inspector, android.os.Build.VERSION.RELEASE, version)
            },
        )
        return when (result) {
            SyncResult.SUCCESS, SyncResult.REVOKED -> Result.success()
            SyncResult.RETRY -> Result.retry()
        }
    }
}
```

- [ ] **Step 3: `BootReceiver.kt`**

```kotlin
package site.guardiaokids.companion.sync

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import site.guardiaokids.companion.data.EncryptedSessionStorage

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        // Só re-agenda se há sessão (device pareado).
        if (EncryptedSessionStorage(context).read() != null) {
            WorkerScheduler.schedule(context)
        }
    }
}
```

- [ ] **Step 4: Compilar**

Run: `./gradlew compileDebugKotlin`
Expected: BUILD SUCCESSFUL.

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/sync/HeartbeatWorker.kt app/src/main/java/site/guardiaokids/companion/sync/WorkerScheduler.kt app/src/main/java/site/guardiaokids/companion/sync/BootReceiver.kt
git commit -m "feat: HeartbeatWorker + scheduler + boot receiver"
```

---

## Task 7: CompanionViewModel (state machine)

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/ui/CompanionViewModel.kt`
- Test: `app/src/test/java/site/guardiaokids/companion/ui/CompanionViewModelTest.kt`

- [ ] **Step 1: Escrever o teste que falha**

```kotlin
package site.guardiaokids.companion.ui

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.resetMain
import kotlinx.coroutines.test.runTest
import kotlinx.coroutines.test.setMain
import okhttp3.ResponseBody.Companion.toResponseBody
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import retrofit2.HttpException
import retrofit2.Response
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.DeviceSnapshot
import site.guardiaokids.companion.data.EnrollResponse
import site.guardiaokids.companion.data.FakeSessionStorage
import site.guardiaokids.companion.data.HeartbeatResponse
import site.guardiaokids.companion.data.Session
import site.guardiaokids.companion.data.SyncRequest
import site.guardiaokids.companion.data.SyncResponse

@OptIn(ExperimentalCoroutinesApi::class)
class CompanionViewModelTest {
    private val dispatcher = StandardTestDispatcher()
    private val snapshot = DeviceSnapshot("14", "0.1.0", false, false, false, true)
    private val validQr = """{"v":1,"type":"gk-companion-pair","child":7,"uuid":"u9","tok":"pairtok","api":"https://x/wp-json/guardkids/v1"}"""

    private class FakeApi(
        val enrollImpl: suspend () -> EnrollResponse = { EnrollResponse("sess9", "u9") },
    ) : CompanionApi {
        override suspend fun enroll(token: String) = enrollImpl()
        override suspend fun sync(token: String, body: SyncRequest) = SyncResponse(true, "active")
        override suspend fun heartbeat(token: String) = HeartbeatResponse(true)
    }

    @Before fun setUp() { Dispatchers.setMain(dispatcher) }
    @After fun tearDown() { Dispatchers.resetMain() }

    private fun viewModel(storage: FakeSessionStorage, api: CompanionApi) = CompanionViewModel(
        storage = storage,
        apiFactory = { api },
        snapshotProvider = { snapshot },
        onScheduleWork = {},
        onCancelWork = {},
    )

    @Test fun startsUnpairedWhenNoSession() {
        val vm = viewModel(FakeSessionStorage(null), FakeApi())
        assertTrue(vm.state.value is UiState.Unpaired)
    }

    @Test fun startsPairedWhenSessionExists() {
        val vm = viewModel(
            FakeSessionStorage(Session("sess", "u9", "https://x/wp-json/guardkids/v1", 7)),
            FakeApi(),
        )
        assertTrue(vm.state.value is UiState.Paired)
    }

    @Test fun scanEnrollsSyncsAndBecomesPaired() = runTest {
        val storage = FakeSessionStorage(null)
        val vm = viewModel(storage, FakeApi())
        vm.onQrScanned(validQr)
        advanceUntilIdle()
        assertTrue(vm.state.value is UiState.Paired)
        assertEquals("sess9", storage.read()!!.sessionToken)
    }

    @Test fun invalidQrGoesToError() = runTest {
        val vm = viewModel(FakeSessionStorage(null), FakeApi())
        vm.onQrScanned("lixo")
        advanceUntilIdle()
        assertTrue(vm.state.value is UiState.Error)
    }

    @Test fun enrollFailureGoesToError() = runTest {
        val api = FakeApi(enrollImpl = {
            throw HttpException(Response.error<Any>(401, "".toResponseBody(null)))
        })
        val vm = viewModel(FakeSessionStorage(null), api)
        vm.onQrScanned(validQr)
        advanceUntilIdle()
        assertTrue(vm.state.value is UiState.Error)
    }

    @Test fun unpairClearsAndReturnsToUnpaired() {
        val storage = FakeSessionStorage(Session("sess", "u9", "https://x/wp-json/guardkids/v1", 7))
        val vm = viewModel(storage, FakeApi())
        vm.onUnpair()
        assertTrue(vm.state.value is UiState.Unpaired)
        assertEquals(1, storage.clearCount)
    }
}
```

- [ ] **Step 2: Rodar pra ver falhar**

Run: `./gradlew testDebugUnitTest --tests "*CompanionViewModelTest"`
Expected: FAIL (CompanionViewModel/UiState não existem).

- [ ] **Step 3: Implementação**

```kotlin
package site.guardiaokids.companion.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.DeviceSnapshot
import site.guardiaokids.companion.data.QrPayload
import site.guardiaokids.companion.data.Session
import site.guardiaokids.companion.data.SessionStorage
import site.guardiaokids.companion.data.SyncRequest

sealed interface UiState {
    data object Unpaired : UiState
    data object Enrolling : UiState
    data class Paired(val snapshot: DeviceSnapshot, val childId: Int) : UiState
    data class Error(val message: String) : UiState
}

class CompanionViewModel(
    private val storage: SessionStorage,
    private val apiFactory: (String) -> CompanionApi,
    private val snapshotProvider: () -> DeviceSnapshot,
    private val onScheduleWork: () -> Unit,
    private val onCancelWork: () -> Unit,
) : ViewModel() {

    private val _state = MutableStateFlow<UiState>(initialState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    private fun initialState(): UiState {
        val s = storage.read() ?: return UiState.Unpaired
        return UiState.Paired(snapshotProvider(), s.childId)
    }

    fun onQrScanned(raw: String) {
        val payload = QrPayload.parse(raw)
        if (payload == null) {
            _state.value = UiState.Error("QR inválido. Gere um novo no painel.")
            return
        }
        _state.value = UiState.Enrolling
        viewModelScope.launch {
            try {
                val api = apiFactory(payload.api)
                val enroll = api.enroll(payload.token)
                val session = Session(enroll.sessionToken, enroll.deviceUuid, payload.api, payload.child)
                val snap = snapshotProvider()
                api.sync(session.sessionToken, SyncRequest(
                    deviceName = null,
                    androidVersion = snap.androidVersion,
                    companionVersion = snap.companionVersion,
                    deviceOwnerEnabled = snap.deviceOwnerEnabled,
                    accessibilityEnabled = snap.accessibilityEnabled,
                    deviceAdminEnabled = snap.deviceAdminEnabled,
                    playStoreEnabled = snap.playStoreEnabled,
                ))
                storage.save(session)
                onScheduleWork()
                _state.value = UiState.Paired(snap, session.childId)
            } catch (e: Exception) {
                _state.value = UiState.Error("Falha ao parear: ${e.message ?: "erro de rede"}")
            }
        }
    }

    fun onUnpair() {
        storage.clear()
        onCancelWork()
        _state.value = UiState.Unpaired
    }

    fun onDismissError() {
        _state.value = if (storage.read() != null) {
            UiState.Paired(snapshotProvider(), storage.read()!!.childId)
        } else {
            UiState.Unpaired
        }
    }
}
```

- [ ] **Step 4: Rodar pra ver passar**

Run: `./gradlew testDebugUnitTest --tests "*CompanionViewModelTest"`
Expected: PASS (6 testes).

- [ ] **Step 5: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/ui/CompanionViewModel.kt app/src/test/java/site/guardiaokids/companion/ui/CompanionViewModelTest.kt
git commit -m "feat: CompanionViewModel state machine"
```

---

## Task 8: UI Compose (telas + navegação + MainActivity)

Sem unit test (UI; coberta por smoke manual). `PairingScreen` usa o scanner do zxing via `ActivityResultContracts`.

**Files:**
- Create: `app/src/main/java/site/guardiaokids/companion/ui/PairingScreen.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/ui/EnrollingScreen.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/ui/StatusScreen.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/ui/CompanionApp.kt`
- Create: `app/src/main/java/site/guardiaokids/companion/MainActivity.kt`

- [ ] **Step 1: `PairingScreen.kt`**

```kotlin
package site.guardiaokids.companion.ui

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions

@Composable
fun PairingScreen(onScanned: (String) -> Unit) {
    val launcher = rememberLauncherForActivityResult(ScanContract()) { result ->
        result.contents?.let(onScanned)
    }
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("GuardKids Companion", textAlign = TextAlign.Center)
        Spacer(Modifier.height(12.dp))
        Text(
            "Abra o painel dos pais, gere o QR de pareamento e escaneie aqui.",
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(24.dp))
        Button(onClick = {
            launcher.launch(ScanOptions().apply {
                setDesiredBarcodeFormats(ScanOptions.QR_CODE)
                setPrompt("Aponte para o QR do painel")
                setBeepEnabled(false)
                setOrientationLocked(true)
            })
        }) {
            Text("Escanear QR Code")
        }
    }
}
```

- [ ] **Step 2: `EnrollingScreen.kt`**

```kotlin
package site.guardiaokids.companion.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp

@Composable
fun EnrollingScreen() {
    Column(
        modifier = Modifier.fillMaxSize(),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        CircularProgressIndicator()
        Spacer(Modifier.height(16.dp))
        Text("Pareando dispositivo…")
    }
}
```

- [ ] **Step 3: `StatusScreen.kt`**

```kotlin
package site.guardiaokids.companion.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import site.guardiaokids.companion.data.DeviceSnapshot

@Composable
fun StatusScreen(snapshot: DeviceSnapshot, childId: Int, onUnpair: () -> Unit) {
    Column(modifier = Modifier.fillMaxWidth().padding(24.dp)) {
        Text("Dispositivo pareado (filho #$childId)")
        Spacer(Modifier.height(8.dp))
        Text("Android ${snapshot.androidVersion} · Companion ${snapshot.companionVersion}")
        Spacer(Modifier.height(16.dp))
        FlagRow("Acessibilidade", snapshot.accessibilityEnabled)
        FlagRow("Device Admin", snapshot.deviceAdminEnabled)
        FlagRow("Device Owner", snapshot.deviceOwnerEnabled)
        FlagRow("Play Store", snapshot.playStoreEnabled)
        Spacer(Modifier.height(24.dp))
        Button(onClick = onUnpair) { Text("Desparear este dispositivo") }
    }
}

@Composable
private fun FlagRow(label: String, on: Boolean) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(label)
        Text(if (on) "✓" else "—")
    }
}
```

- [ ] **Step 4: `CompanionApp.kt` (navegação por estado)**

```kotlin
package site.guardiaokids.companion.ui

import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.foundation.layout.padding

@Composable
fun CompanionApp(viewModel: CompanionViewModel) {
    val state by viewModel.state.collectAsState()
    Scaffold { padding ->
        when (val s = state) {
            is UiState.Unpaired -> PairingScreen(onScanned = viewModel::onQrScanned)
            is UiState.Enrolling -> EnrollingScreen()
            is UiState.Paired -> androidx.compose.foundation.layout.Box(Modifier.padding(padding)) {
                StatusScreen(s.snapshot, s.childId, onUnpair = viewModel::onUnpair)
            }
            is UiState.Error -> {
                PairingScreen(onScanned = viewModel::onQrScanned)
                AlertDialog(
                    onDismissRequest = viewModel::onDismissError,
                    confirmButton = { TextButton(onClick = viewModel::onDismissError) { Text("OK") } },
                    title = { Text("Erro") },
                    text = { Text(s.message) },
                )
            }
        }
    }
}
```

- [ ] **Step 5: `MainActivity.kt` (monta o ViewModel com as deps Android)**

```kotlin
package site.guardiaokids.companion

import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import site.guardiaokids.companion.data.AndroidSystemInspector
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.DeviceState
import site.guardiaokids.companion.data.EncryptedSessionStorage
import site.guardiaokids.companion.sync.WorkerScheduler
import site.guardiaokids.companion.ui.CompanionApp
import site.guardiaokids.companion.ui.CompanionViewModel

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val appContext = applicationContext
        val version = packageManager.getPackageInfo(packageName, 0).versionName ?: "0"

        setContent {
            val vm: CompanionViewModel = viewModel(factory = viewModelFactory {
                initializer {
                    CompanionViewModel(
                        storage = EncryptedSessionStorage(appContext),
                        apiFactory = { base -> CompanionApi.create(base) },
                        snapshotProvider = {
                            DeviceState.collect(
                                AndroidSystemInspector(appContext),
                                Build.VERSION.RELEASE,
                                version,
                            )
                        },
                        onScheduleWork = { WorkerScheduler.schedule(appContext) },
                        onCancelWork = { WorkerScheduler.cancel(appContext) },
                    )
                }
            })
            CompanionApp(vm)
        }
    }
}
```

- [ ] **Step 6: Build completo + lint**

Run: `./gradlew lintDebug assembleDebug`
Expected: BUILD SUCCESSFUL.

- [ ] **Step 7: Commit**

```bash
git add app/src/main/java/site/guardiaokids/companion/ui/ app/src/main/java/site/guardiaokids/companion/MainActivity.kt
git commit -m "feat: UI Compose (Pairing/Enrolling/Status) + navegação"
```

---

## Task 9: Teste instrumentado enroll→sync + smoke manual

**Files:**
- Create: `app/src/androidTest/java/site/guardiaokids/companion/EnrollSyncFlowTest.kt`

- [ ] **Step 1: Teste instrumentado (valida CompanionApi real contra MockWebServer no device)**

```kotlin
package site.guardiaokids.companion

import androidx.test.ext.junit.runners.AndroidJUnit4
import kotlinx.coroutines.runBlocking
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import site.guardiaokids.companion.data.CompanionApi
import site.guardiaokids.companion.data.SyncRequest

@RunWith(AndroidJUnit4::class)
class EnrollSyncFlowTest {
    private lateinit var server: MockWebServer

    @Before fun setUp() { server = MockWebServer(); server.start() }
    @After fun tearDown() { server.shutdown() }

    @Test fun enrollThenSyncRoundTrip() = runBlocking {
        server.enqueue(MockResponse().setResponseCode(201).setBody("""{"sessionToken":"s","deviceUuid":"u"}"""))
        server.enqueue(MockResponse().setResponseCode(200).setBody("""{"paired":true,"status":"active"}"""))

        val api = CompanionApi.create(server.url("/wp-json/guardkids/v1").toString())
        val enroll = api.enroll("pair")
        val sync = api.sync(enroll.sessionToken, SyncRequest(null, "14", "0.1.0", false, false, false, true))

        assertEquals("s", enroll.sessionToken)
        assertEquals("active", sync.status)
        assertEquals("/wp-json/guardkids/v1/companion/enroll", server.takeRequest().path)
        assertEquals("/wp-json/guardkids/v1/companion/sync", server.takeRequest().path)
    }
}
```

- [ ] **Step 2: Rodar instrumentado (precisa emulador/device conectado)**

Run: `./gradlew connectedDebugAndroidTest`
Expected: PASS (1 teste). Se não houver device, pular e marcar no commit.

- [ ] **Step 3: Smoke manual contra backend real (LocalWP ou prod)**

Checklist (executar manualmente, registrar resultado):
1. No painel dos pais → Modo de Proteção → wizard do Companion → gerar QR pra um filho.
2. Abrir o app no emulador/device → "Escanear QR Code" → apontar pro QR.
3. Confirmar: app vai pra tela de Status com Android/versão + flags.
4. No painel: `CompanionStatusCard` do filho mostra o device `active` + última sync.
5. Forçar o worker: `adb shell cmd jobscheduler run -f site.guardiaokids.companion <jobId>` OU aguardar ~30 min → `last_sync` atualiza.
6. Re-parear no painel (gera QR novo) → no próximo tick o app cai pra tela de Pairing (401 tratado).

- [ ] **Step 4: Commit**

```bash
git add app/src/androidTest/
git commit -m "test: fluxo instrumentado enroll->sync + checklist de smoke"
```

---

## Task 10: CI + README

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `README.md`

- [ ] **Step 1: `.github/workflows/ci.yml`**

```yaml
name: CI
on:
  push:
    branches: [ master ]
  pull_request:

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-java@v4
        with:
          distribution: temurin
          java-version: '17'
      - name: Gradle build + unit tests + lint
        run: ./gradlew testDebugUnitTest lintDebug assembleDebug --stacktrace
```

- [ ] **Step 2: `README.md`**

```markdown
# GuardKids Companion

App Android (telemetria) do GuardKids WP. Pareia via QR, troca o pairing token
por um session token persistente e reporta device info + flags de permissão
via `guardkids/v1` (`enroll`/`sync`/`heartbeat`).

**Status:** MVP telemetria-only. Sem enforcement de bloqueio (fase seguinte).

## Build
- `./gradlew assembleDebug` — APK debug
- `./gradlew testDebugUnitTest` — testes unit
- `./gradlew lintDebug` — lint

Requisitos: JDK 17, Android SDK (compileSdk 35), minSdk 26.

## Backend
Consome o GuardKids WP (plugin) ≥ v1.8.0. Endpoints em `wp-json/guardkids/v1/companion/*`.
```

- [ ] **Step 3: Build final completo**

Run: `./gradlew testDebugUnitTest lintDebug assembleDebug`
Expected: BUILD SUCCESSFUL, todos os testes unit verdes.

- [ ] **Step 4: Criar o repo remoto + push**

```bash
cd /c/Users/mysho/guardkids-companion
gh repo create mouradjnet/guardkids-companion --private --source=. --remote=origin
git add .github/workflows/ci.yml README.md
git commit -m "ci: GitHub Actions (test + lint + build) + README"
git branch -M master
git push -u origin master
```

- [ ] **Step 5: Confirmar CI verde**

Run: `gh run watch` (na raiz do repo novo)
Expected: job `build` verde.

---

## Self-Review (preenchido)

**1. Spec coverage:**
- Pairing/enroll/sync/heartbeat → Tasks 1,4,5,7. ✓
- Session token persistente seguro → Task 2 (EncryptedSharedPreferences). ✓
- Status real das flags → Task 3 (DeviceState). ✓
- WorkManager periódico + boot → Task 6. ✓
- State machine Unpaired→Enrolling→Paired/Error → Task 7. ✓
- 3 telas Compose → Task 8. ✓
- Testes unit + instrumentado + smoke → Tasks 1-9. ✓
- Repo novo + CI → Tasks 0,10. ✓
- Critérios de aceite do spec (1-5) → cobertos pelo smoke da Task 9 + unit suites. ✓

**2. Placeholder scan:** Sem TBD/TODO; todo código presente. ✓ (proguard-rules.pro intencionalmente vazio com comentário.)

**3. Type consistency:** `CompanionApi` (enroll/sync/heartbeat), `SyncRequest`/`EnrollResponse`/`SyncResponse`/`HeartbeatResponse`, `Session`, `SessionStorage`, `DeviceSnapshot`/`SystemInspector`, `SyncResult`/`SyncRunner`, `UiState`/`CompanionViewModel` — assinaturas batem entre tasks 4,5,7 e os fakes dos testes. `apiFactory: (String) -> CompanionApi`, `snapshotProvider: () -> DeviceSnapshot` consistentes em SyncRunner e ViewModel. ✓

**Nota de ambiente:** desenvolvimento exige Android SDK + JDK 17 na máquina (Android Studio). O backend já está em produção; o smoke pode usar prod ou LocalWP.
