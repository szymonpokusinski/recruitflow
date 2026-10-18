import Foundation

public struct WidgetSalary: Codable, Sendable, Equatable {
    public let min: Double?
    public let max: Double?
    public let currency: String
    public let period: String
    public let basis: String
}
public struct WidgetMatch: Codable, Sendable, Equatable {
    public let profileId: String?
    public let score: Double
    public let source: String
    public let evaluatedAt: String?
}
public struct WidgetOffer: Codable, Identifiable, Sendable {
    public let id: String
    public let company: String
    public let title: String
    public let location: String
    public let workMode: String
    public let salary: WidgetSalary
    public let match: WidgetMatch?
    public let matches: [WidgetMatch]
    public let profileId: String?
    public let priority: String
    public let source: String
    public let firstSeenAt: String?
    public let status: String
    public let cvSent: Bool
    public let active: Bool
    public let isNewToday: Bool
    public let isNew24Hours: Bool
    public let unreviewed: Bool?
    public let deepLink: String
}
public struct WidgetEvent: Codable, Identifiable, Sendable {
    public let id: String
    public let offerId: String
    public let type: String
    public let occurredAt: String?
    public let deadlineAt: String?
    public let isReply: Bool
    public let isInterview: Bool
    public let deepLink: String
}
public struct WidgetProfile: Codable, Identifiable, Sendable {
    public let id: String
    public let name: String
}
public struct WidgetStatistics: Codable, Sendable {
    public let newToday: Int
    public let new24Hours: Int
    public let unreviewed: Int?
    public let highMatches: Int
    public let cvSent: Int
    public let activeProcesses: Int
    public let newHRReplies: Int
    public let upcomingInterviews: Int
}
public struct WidgetRefresh: Codable, Sendable {
    public let status: String
    public let at: String
}
public struct WidgetSnapshot: Codable, Sendable {
    public let refresh: WidgetRefresh?
    public let schema_version: Int
    public let generatedAt: String
    public let dataUpdatedAt: String?
    public let hasRemoteSources: Bool?
    public let timezone: String
    public let staleAfterMinutes: Int
    public let lastSuccessfulSyncAt: String?
    public let lastReviewedAt: String?
    public let activeProfileId: String?
    public let statistics: WidgetStatistics
    public let offers: [WidgetOffer]
    public let totalOffers: Int
    public let truncated: Bool
    public let deduplicatedCount: Int
    public let profiles: [WidgetProfile]
    public let sources: [String]
    public let events: [WidgetEvent]

    public func isStale(at now: Date = .now) -> Bool {
        guard let generated = WidgetFormatting.date(dataUpdatedAt ?? generatedAt) else { return true }
        return now.timeIntervalSince(generated) > Double(staleAfterMinutes) * 60
    }
}
public enum WidgetSnapshotError: Error, Equatable {
    case oversized, unsupportedVersion, invalidFormat, invalidIdentity
}
public protocol WidgetSnapshotSource: Sendable {
    func load() throws -> WidgetSnapshot
}
public enum WidgetSnapshotCodec {
    public static func decode(_ data: Data) throws -> WidgetSnapshot {
        guard data.count <= 2_000_000 else { throw WidgetSnapshotError.oversized }
        let version = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
        guard let version, let number = version["schema_version"] as? Int else { throw WidgetSnapshotError.invalidFormat }
        guard number == 1 else { throw WidgetSnapshotError.unsupportedVersion }
        guard let snapshot = try? JSONDecoder().decode(WidgetSnapshot.self, from: data),
              WidgetFormatting.date(snapshot.generatedAt) != nil,
              snapshot.staleAfterMinutes > 0, snapshot.offers.count <= 500,
              snapshot.totalOffers >= snapshot.offers.count else { throw WidgetSnapshotError.invalidFormat }
        var ids = Set<String>()
        for offer in snapshot.offers {
            guard UUID(uuidString: offer.id) != nil, ids.insert(offer.id).inserted,
                  offer.deepLink == "recruitflow://offer/\(offer.id)",
                  offer.title.count <= 300, offer.company.count <= 300,
                  offer.matches.allSatisfy({ $0.score.isFinite && (0...100).contains($0.score) }),
                  offer.match.map({ $0.score.isFinite && (0...100).contains($0.score) }) ?? true,
                  [offer.salary.min, offer.salary.max].allSatisfy({ $0.map({ $0.isFinite && $0 >= 0 }) ?? true })
            else { throw WidgetSnapshotError.invalidIdentity }
        }
        guard snapshot.events.allSatisfy({ UUID(uuidString: $0.id) != nil && UUID(uuidString: $0.offerId) != nil && $0.deepLink == "recruitflow://offer/\($0.offerId)" }) else {
            throw WidgetSnapshotError.invalidIdentity
        }
        return snapshot
    }
}
public struct LocalWidgetSnapshotSource: WidgetSnapshotSource {
    public let url: URL
    public init(url: URL) { self.url = url }
    public func load() throws -> WidgetSnapshot {
        let size = try url.resourceValues(forKeys: [.fileSizeKey]).fileSize ?? 0
        guard size <= 2_000_000 else { throw WidgetSnapshotError.oversized }
        return try WidgetSnapshotCodec.decode(Data(contentsOf: url))
    }
}
