import AppIntents

enum OfferScope: String, AppEnum {
    case new, all
    static var typeDisplayRepresentation: TypeDisplayRepresentation = "Oferty"
    static var caseDisplayRepresentations: [OfferScope: DisplayRepresentation] = [.new: "Tylko nowe", .all: "Wszystkie dostępne"]
}
enum OfferRanking: String, AppEnum {
    case newest, best
    static var typeDisplayRepresentation: TypeDisplayRepresentation = "Sortowanie"
    static var caseDisplayRepresentations: [OfferRanking: DisplayRepresentation] = [.newest: "Najnowsze", .best: "Najlepiej dopasowane"]
}
struct WidgetProfileEntity: AppEntity {
    static var typeDisplayRepresentation: TypeDisplayRepresentation = "Profil RecruitFlow"
    static var defaultQuery = WidgetProfileQuery()
    let id: String
    let name: String
    var displayRepresentation: DisplayRepresentation { DisplayRepresentation(title: "\(name)") }
}
struct WidgetProfileQuery: EntityQuery {
    func entities(for identifiers: [String]) async throws -> [WidgetProfileEntity] {
        try await suggestedEntities().filter { identifiers.contains($0.id) }
    }
    func suggestedEntities() async throws -> [WidgetProfileEntity] {
        (try? AppGroupSnapshot.read().profiles.map { WidgetProfileEntity(id: $0.id, name: $0.name) }) ?? []
    }
}
struct WidgetSourceEntity: AppEntity {
    static var typeDisplayRepresentation: TypeDisplayRepresentation = "Źródło"
    static var defaultQuery = WidgetSourceQuery()
    let id: String
    var displayRepresentation: DisplayRepresentation { DisplayRepresentation(title: "\(id)") }
}
struct WidgetSourceQuery: EntityQuery {
    func entities(for identifiers: [String]) async throws -> [WidgetSourceEntity] {
        try await suggestedEntities().filter { identifiers.contains($0.id) }
    }
    func suggestedEntities() async throws -> [WidgetSourceEntity] {
        (try? AppGroupSnapshot.read().sources.map { WidgetSourceEntity(id: $0) }) ?? []
    }
}
struct RecruitFlowWidgetIntent: WidgetConfigurationIntent {
    static var title: LocalizedStringResource = "RecruitFlow"
    static var description = IntentDescription("Wybierz oferty i sposób prezentacji tej instancji widgetu.")
    @Parameter(title: "Zakres", default: .new) var scope: OfferScope
    @Parameter(title: "Sortowanie", default: .newest) var ranking: OfferRanking
    @Parameter(title: "Profil (puste: aktywny w RecruitFlow)") var profile: WidgetProfileEntity?
    @Parameter(title: "Źródło (puste: wszystkie)") var source: WidgetSourceEntity?
    @Parameter(title: "Minimalne dopasowanie (0: dowolne)", default: 0) var minimumScore: Int
    @Parameter(title: "Uwzględnij wysłane CV", default: false) var includeCV: Bool
    @Parameter(title: "Pokaż wynagrodzenia", default: true) var showSalary: Bool
    @Parameter(title: "Pokaż aktywność HR", default: true) var showHR: Bool
    var selection: WidgetSelection {
        var result = WidgetSelection()
        result.onlyNew = scope == .new
        result.bestMatches = ranking == .best
        result.profileId = profile?.id
        result.source = source?.id
        result.minimumScore = Double(max(0, min(100, minimumScore)))
        result.includeCV = includeCV
        return result
    }
}
