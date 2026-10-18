import AppKit
import SwiftUI
import WidgetKit
import CryptoKit
import OSLog

@MainActor
final class SnapshotBridge: ObservableObject {
    static let shared = SnapshotBridge()
    @Published var status = "Wybierz folder snapshotu przygotowany przez RecruitFlow."
    @Published var updatedAt: String?
    private var folder: URL?
    private var folderScoped = false
    private var watcher: DispatchSourceFileSystemObject?
    private var lastDigest: String?
    private let logger = Logger(subsystem: "pl.szymonpokusinski.recruitflow.widgets", category: "bridge")

    func restore() {
        // The installed desktop publishes directly to the signed App Group.
        // Prefer it over an old developer-folder bookmark after installation.
        if let directory = try? AppGroupSnapshot.directory(),
           FileManager.default.fileExists(atPath: directory.appendingPathComponent(".desktop-publisher").path) {
            do { try connect(directory, scoped: false); return }
            catch { status = "Nie można połączyć lokalnych danych zainstalowanej aplikacji." }
        }
        guard let bookmark = UserDefaults.standard.data(forKey: "RecruitFlowSnapshotFolder") else { return }
        do {
            var stale = false
            let url = try URL(resolvingBookmarkData: bookmark, options: .withSecurityScope, relativeTo: nil, bookmarkDataIsStale: &stale)
            if stale { UserDefaults.standard.removeObject(forKey: "RecruitFlowSnapshotFolder"); status = "Uprawnienie do folderu wygasło. Wybierz go ponownie."; return }
            try connect(url)
        } catch { status = "Nie udało się odtworzyć dostępu do lokalnego folderu." }
    }
    func chooseFolder() {
        let panel = NSOpenPanel()
        panel.canChooseDirectories = true
        panel.canChooseFiles = false
        panel.allowsMultipleSelection = false
        panel.message = "Wybierz folder widgets wskazany przez php artisan widgets:publish."
        guard panel.runModal() == .OK, let url = panel.url else { return }
        do {
            let bookmark = try url.bookmarkData(options: [.withSecurityScope, .securityScopeAllowOnlyReadAccess], includingResourceValuesForKeys: nil, relativeTo: nil)
            try connect(url)
            UserDefaults.standard.set(bookmark, forKey: "RecruitFlowSnapshotFolder")
        } catch { status = "Brak dostępu do folderu snapshotu. Poprzednie dane widgetów zachowano." }
    }
    private func connect(_ url: URL, scoped: Bool = true) throws {
        if scoped && !url.startAccessingSecurityScopedResource() { throw CocoaError(.fileReadNoPermission) }
        let descriptor = open(url.path, O_EVTONLY)
        guard descriptor >= 0 else {
            if scoped { url.stopAccessingSecurityScopedResource() }
            throw CocoaError(.fileReadNoPermission)
        }
        watcher?.cancel()
        if folderScoped { folder?.stopAccessingSecurityScopedResource() }
        folder = url
        folderScoped = scoped
        let source = DispatchSource.makeFileSystemObjectSource(fileDescriptor: descriptor, eventMask: [.write, .rename, .delete], queue: .main)
        source.setEventHandler { [weak self] in Task { @MainActor in self?.refresh() } }
        source.setCancelHandler { close(descriptor) }
        watcher = source
        source.resume()
        refresh()
    }
    func refresh(forceReload: Bool = false) {
        guard let folder else { status = "Najpierw wybierz folder lokalnego snapshotu."; return }
        do {
            let url = folder.appendingPathComponent(AppGroupSnapshot.fileName)
            let size = try url.resourceValues(forKeys: [.fileSizeKey]).fileSize ?? 0
            guard size <= 2_000_000 else { throw WidgetSnapshotError.oversized }
            let data = try Data(contentsOf: url)
            let snapshot = try WidgetSnapshotCodec.decode(data)
            let digest = SHA256.hash(data: data).map { String(format: "%02x", $0) }.joined()
            if digest != lastDigest {
                if folderScoped { try AppGroupSnapshot.write(data) }
                lastDigest = digest
                WidgetCenter.shared.reloadAllTimelines()
            } else if forceReload {
                WidgetCenter.shared.reloadAllTimelines()
            }
            if forceReload {
                WidgetCenter.shared.getCurrentConfigurations { [logger] result in
                    switch result {
                    case .success(let widgets): logger.notice("Installed RecruitFlow widget configurations: \(widgets.count, privacy: .public)")
                    case .failure(let error): logger.error("Widget configuration query failed: code=\((error as NSError).code, privacy: .public)")
                    }
                }
            }
            updatedAt = snapshot.generatedAt
            status = "Połączono · \(snapshot.totalOffers) ofert · \(WidgetFormatting.shortDate(snapshot.generatedAt))"
        } catch WidgetSnapshotError.unsupportedVersion {
            status = "Nowszy format snapshotu. Zaktualizuj aplikację widgetów. Poprzednie dane zachowano."
        } catch {
            status = "Nie można odczytać aktualnego snapshotu. Poprzednie dane widgetów zachowano."
        }
    }
    func openRecruitFlow(_ url: URL) {
        guard WidgetFormatting.safeLink(url.absoluteString) != nil else { return }
        let candidates = [URL(fileURLWithPath: "/Applications/RecruitFlow.app"),
            FileManager.default.homeDirectoryForCurrentUser.appendingPathComponent("Applications/RecruitFlow.app")]
        guard let application = candidates.first(where: {
            Bundle(url: $0)?.bundleIdentifier == "pl.szymonpokusinski.recruitflow.desktop"
        }) else {
            status = "Zainstaluj RecruitFlow.app w folderze Aplikacje."
            WidgetAppDelegate.showPanel()
            return
        }
        NSWorkspace.shared.open([url], withApplicationAt: application, configuration: .init()) { _, error in
            if error != nil {
                Task { @MainActor in
                    self.status = "Nie udało się otworzyć RecruitFlow. Otwórz aplikację z folderu Aplikacje."
                    WidgetAppDelegate.showPanel()
                }
            }
        }
    }
    func handle(_ url: URL) {
        if WidgetFormatting.safeLink(url.absoluteString) != nil { openRecruitFlow(url); return }
        guard url.scheme == "recruitflow-widgets", url.host == "refresh", url.path.isEmpty,
              url.query == nil, url.fragment == nil, url.user == nil, url.password == nil, url.port == nil else { return }
        if let directory = try? AppGroupSnapshot.directory(),
           FileManager.default.fileExists(atPath: directory.appendingPathComponent(".desktop-publisher").path), folderScoped || folder == nil {
            try? connect(directory, scoped: false)
        }
        refresh(forceReload: true)
    }
}
@main
struct RecruitFlowWidgetsApp: App {
    @NSApplicationDelegateAdaptor(WidgetAppDelegate.self) private var delegate
    @StateObject private var bridge = SnapshotBridge.shared
    var body: some Scene {
        MenuBarExtra("RecruitFlow Widgets", systemImage: "briefcase") {
            Text(bridge.status)
            Button("Panel widgetów…") { WidgetAppDelegate.showPanel() }
            Button("Wybierz lokalny folder snapshotu…") { bridge.chooseFolder() }
            Button("Odśwież widgety") { bridge.refresh(forceReload: true) }
            Divider()
            Button("Otwórz RecruitFlow") {
                if let url = WidgetFormatting.safeLink("recruitflow://offers/new") { bridge.openRecruitFlow(url) }
            }
            Button("Zakończ aplikację pomocniczą") { NSApplication.shared.terminate(nil) }
        }
    }
}
struct WidgetBridgePanel: View {
    @ObservedObject var bridge = SnapshotBridge.shared
    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("RecruitFlow Widgets").font(.title)
            Text(bridge.status)
            Text("Widgety czytają tylko ostatni snapshot. Zamknięcie RecruitFlow nie usuwa danych; nowe dane wymagają działającej aplikacji i mostu.")
                .font(.caption).foregroundStyle(.secondary)
            Button("Wybierz folder snapshotu…") { bridge.chooseFolder() }
            Button("Odśwież") { bridge.refresh(forceReload: true) }
        }.padding(24).frame(width: 460)
    }
}
@MainActor
final class WidgetAppDelegate: NSObject, NSApplicationDelegate {
    private static var panel: NSWindow?
    static func showPanel() {
        if panel == nil {
            let window = NSWindow(contentRect: NSRect(x: 0, y: 0, width: 508, height: 260),
                styleMask: [.titled, .closable], backing: .buffered, defer: false)
            window.title = "RecruitFlow Widgets"
            window.isReleasedWhenClosed = false
            window.contentView = NSHostingView(rootView: WidgetBridgePanel())
            window.center()
            panel = window
        }
        panel?.makeKeyAndOrderFront(nil)
        NSApplication.shared.activate(ignoringOtherApps: true)
    }
    func applicationDidFinishLaunching(_ notification: Notification) {
        SnapshotBridge.shared.restore()
        if SnapshotBridge.shared.updatedAt == nil { Self.showPanel() }
    }
    func applicationShouldHandleReopen(_ sender: NSApplication, hasVisibleWindows flag: Bool) -> Bool {
        Self.showPanel()
        return true
    }
    func application(_ application: NSApplication, open urls: [URL]) {
        for url in urls { SnapshotBridge.shared.handle(url) }
    }
}
