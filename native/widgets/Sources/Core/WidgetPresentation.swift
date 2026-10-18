import Foundation

public enum WidgetAppGroupIdentifier {
    // macOS authorizes this namespace from the code signature's Team ID.
    // A group.* identifier without a provisioned group can trigger TCC denial in an extension.
    public static func validated(_ identifier: String?, team: String?) -> String? {
        guard let identifier, let team,
              team.range(of: "^[A-Z0-9]{10}$", options: .regularExpression) != nil,
              identifier == "\(team).pl.szymonpokusinski.recruitflow.widgets" else { return nil }
        return identifier
    }
}

public struct WidgetSelection: Sendable {
    public var onlyNew = true
    public var bestMatches = false
    public var profileId: String? = nil
    public var source: String? = nil
    public var minimumScore: Double = 0
    public var includeCV = false
    public init() {}

    public func match(for offer: WidgetOffer, snapshot: WidgetSnapshot) -> WidgetMatch? {
        let selected = profileId ?? snapshot.activeProfileId
        return offer.matches.first { $0.profileId == selected }
    }

    public func offers(in snapshot: WidgetSnapshot, now: Date = .now) -> [WidgetOffer] {
        snapshot.offers.filter { offer in
            guard offer.active, includeCV || !offer.cvSent, source == nil || source == offer.source else { return false }
            // Re-evaluate relative time from stable firstSeen; a frozen snapshot never keeps yesterday's jobs "new" forever.
            let first = WidgetFormatting.date(offer.firstSeenAt)
            if onlyNew && !(first.map { $0 <= now && now.timeIntervalSince($0) <= 86400 } ?? false) { return false }
            if minimumScore > 0 && (match(for: offer, snapshot: snapshot)?.score ?? -1) < minimumScore { return false }
            return true
        }.sorted { a, b in
            if bestMatches {
                let first = match(for: a, snapshot: snapshot)?.score ?? -1
                let second = match(for: b, snapshot: snapshot)?.score ?? -1
                if first != second { return first > second }
            }
            let first = WidgetFormatting.date(a.firstSeenAt) ?? .distantPast
            let second = WidgetFormatting.date(b.firstSeenAt) ?? .distantPast
            return first != second ? first > second : a.id < b.id
        }
    }
}
public enum WidgetFormatting {
    public static func date(_ value: String?) -> Date? {
        guard let value else { return nil }
        let formatter = ISO8601DateFormatter()
        if let date = formatter.date(from: value) { return date }
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter.date(from: value)
    }
    public static func salary(_ salary: WidgetSalary) -> String? {
        guard salary.min != nil || salary.max != nil else { return nil }
        let formatter = NumberFormatter()
        formatter.locale = Locale(identifier: "pl_PL")
        formatter.maximumFractionDigits = 2
        func number(_ value: Double) -> String { formatter.string(from: NSNumber(value: value)) ?? String(value) }
        let amounts: String
        if let min = salary.min, let max = salary.max, min != max { amounts = "\(number(min))–\(number(max))" }
        else if let min = salary.min { amounts = salary.max == nil ? "od \(number(min))" : number(min) }
        else { amounts = "do \(number(salary.max!))" }
        let period = ["Godzina":"godz.", "Dzień":"dzień", "Miesiąc":"mies.", "Rok":"rok", "Projekt":"projekt"][salary.period] ?? salary.period
        return [amounts, salary.currency, period.isEmpty ? nil : "/ \(period)"].compactMap { $0 }.joined(separator: " ")
    }
    public static func shortDate(_ value: String?) -> String {
        guard let date = date(value) else { return "Brak daty" }
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "pl_PL")
        formatter.dateFormat = "dd.MM HH:mm"
        return formatter.string(from: date)
    }
    public static func isToday(_ value: String?, timezone: String, now: Date) -> Bool {
        guard let date = date(value) else { return false }
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: timezone) ?? .current
        return calendar.isDate(date, inSameDayAs: now)
    }
    public static func safeLink(_ value: String) -> URL? {
        guard let components = URLComponents(string: value), components.scheme == "recruitflow",
              components.user == nil, components.password == nil, components.port == nil,
              components.query == nil, components.fragment == nil else { return nil }
        if components.host == "offers" && components.path == "/new" { return components.url }
        if ["activity", "refresh"].contains(components.host ?? "") && components.path.isEmpty { return components.url }
        if components.host == "offer", UUID(uuidString: String(components.path.dropFirst())) != nil { return components.url }
        return nil
    }
}
