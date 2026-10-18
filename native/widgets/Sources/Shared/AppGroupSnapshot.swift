import Foundation
import OSLog

enum WidgetSharedContainerError: Error { case invalidConfiguration, unavailable }

enum AppGroupSnapshot {
    private static let logger = Logger(subsystem: "pl.szymonpokusinski.recruitflow.widgets", category: "snapshot")
    static let fileName = "dashboard-v1.json"
    static func directory() throws -> URL {
        guard let identifier = WidgetAppGroupIdentifier.validated(
            Bundle.main.object(forInfoDictionaryKey: "RecruitFlowAppGroupIdentifier") as? String,
            team: Bundle.main.object(forInfoDictionaryKey: "RecruitFlowSigningTeam") as? String) else {
            throw WidgetSharedContainerError.invalidConfiguration
        }
        guard let url = FileManager.default.containerURL(forSecurityApplicationGroupIdentifier: identifier) else {
            throw WidgetSharedContainerError.unavailable
        }
        return url
    }
    static func read() throws -> WidgetSnapshot {
        do {
            let snapshot = try LocalWidgetSnapshotSource(url: directory().appendingPathComponent(fileName)).load()
            logger.notice("Widget snapshot read: \(snapshot.offers.count, privacy: .public) offers; schema \(snapshot.schema_version, privacy: .public)")
            return snapshot
        } catch {
            logger.error("Widget snapshot read failed: domain=\((error as NSError).domain, privacy: .public) code=\((error as NSError).code, privacy: .public)")
            throw error
        }
    }
    static func write(_ data: Data) throws {
        _ = try WidgetSnapshotCodec.decode(data)
        let url = try directory().appendingPathComponent(fileName)
        try data.write(to: url, options: .atomic)
        try FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: url.path)
    }
}
