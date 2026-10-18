import Foundation
import Testing
@testable import RecruitFlowWidgetCore

private let now = Date(timeIntervalSince1970: 1_791_889_200) // 2026-10-13; tests use ISO comparisons below.
@Test func macOSAppGroupMustMatchSigningTeam() {
    #expect(WidgetAppGroupIdentifier.validated("TESTTEAM01.pl.szymonpokusinski.recruitflow.widgets", team: "TESTTEAM01") != nil)
    #expect(WidgetAppGroupIdentifier.validated("group.pl.szymonpokusinski.recruitflow.widgets", team: "TESTTEAM01") == nil)
    #expect(WidgetAppGroupIdentifier.validated("OTHERTEAM1.pl.szymonpokusinski.recruitflow.widgets", team: "TESTTEAM01") == nil)
    #expect(WidgetAppGroupIdentifier.validated(nil, team: nil) == nil)
    #expect(WidgetAppGroupIdentifier.validated(".pl.szymonpokusinski.recruitflow.widgets", team: "") == nil)
}
private func data(offers: [[String: Any]] = [], version: Int = 1) throws -> Data {
    try JSONSerialization.data(withJSONObject: ["schema_version": version, "generatedAt": "2026-10-13T10:00:00+00:00",
        "timezone": "Europe/Warsaw", "staleAfterMinutes": 60, "statistics": ["newToday": offers.count, "new24Hours": offers.count,
        "highMatches": 0, "cvSent": 0, "activeProcesses": 0, "newHRReplies": 0, "upcomingInterviews": 0],
        "offers": offers, "totalOffers": offers.count, "truncated": false, "deduplicatedCount": 0,
        "profiles": [], "sources": ["Ręcznie"], "events": []])
}
private func offer(_ number: Int, score: Double? = nil, first: String? = "2026-10-13T08:00:00+00:00", active: Bool = true, cv: Bool = false) -> [String: Any] {
    let id = String(format: "00000000-0000-4000-8000-%012d", number)
    var result: [String: Any] = ["id": id, "company": "Firma testowa", "title": String(repeating: "Długie stanowisko ", count: 10),
        "location": "Warszawa", "workMode": "Zdalnie", "salary": ["currency":"PLN", "period":"Godzina", "basis":""],
        "matches": [], "priority":"", "source":"Ręcznie", "status":"Nie aplikowano", "cvSent":cv, "active":active,
        "isNewToday":true, "isNew24Hours":true, "deepLink":"recruitflow://offer/\(id)"]
    if let first { result["firstSeenAt"] = first }
    if let score {
        let match: [String: Any] = ["score":score,"source":"Zapisana ocena"]
        result["match"] = match; result["matches"] = [match]
    }
    return result
}
@Test func emptyAndSingleOfferDecode() throws {
    #expect(try WidgetSnapshotCodec.decode(data()).offers.isEmpty)
    let snapshot = try WidgetSnapshotCodec.decode(data(offers: [offer(1)]))
    #expect(snapshot.offers.count == 1)
    #expect(snapshot.offers[0].match == nil)
    #expect(WidgetFormatting.salary(snapshot.offers[0].salary) == nil)
}
@Test func scoresSortAndFilterWithoutInventingMissingValues() throws {
    let snapshot = try WidgetSnapshotCodec.decode(data(offers:[offer(1),offer(2,score:0),offer(3,score:87)]))
    var selection = WidgetSelection(); selection.bestMatches = true; selection.onlyNew = false
    #expect(selection.offers(in:snapshot).map(\.id) == [snapshot.offers[2].id,snapshot.offers[1].id,snapshot.offers[0].id])
    selection.minimumScore = 85
    #expect(selection.offers(in:snapshot).count == 1)
    selection.minimumScore = 0; selection.profileId = "different-profile"
    #expect(selection.match(for:snapshot.offers[2],snapshot:snapshot) == nil)
}
@Test func frozenSnapshotDoesNotKeepOldOffersNew() throws {
    let snapshot = try WidgetSnapshotCodec.decode(data(offers:[offer(1,first:"2026-10-11T10:00:00+00:00"),offer(2),offer(3,active:false),offer(4,cv:true)]))
    let date = WidgetFormatting.date("2026-10-13T12:00:00+00:00")!
    let selection = WidgetSelection()
    #expect(selection.offers(in:snapshot,now:date).map(\.id) == [snapshot.offers[1].id])
    #expect(snapshot.isStale(at:date))
    #expect(!snapshot.isStale(at:WidgetFormatting.date("2026-10-13T10:30:00+00:00")!))
}
@Test func corruptedOldAndDuplicateSnapshotsAreRejected() throws {
    #expect(throws:WidgetSnapshotError.invalidFormat) { try WidgetSnapshotCodec.decode(Data("broken".utf8)) }
    #expect(throws:WidgetSnapshotError.unsupportedVersion) { try WidgetSnapshotCodec.decode(data(version:0)) }
    #expect(throws:WidgetSnapshotError.invalidIdentity) { try WidgetSnapshotCodec.decode(data(offers:[offer(1),offer(1)])) }
    #expect(throws:WidgetSnapshotError.oversized) { try WidgetSnapshotCodec.decode(Data(repeating:32,count:2_000_001)) }
}
@Test func externalLinksAndInvalidScoresAreRejected() throws {
    var value = offer(1); value["deepLink"] = "https://evil.example"
    #expect(throws:WidgetSnapshotError.invalidIdentity) { try WidgetSnapshotCodec.decode(data(offers:[value])) }
    #expect(throws:WidgetSnapshotError.invalidIdentity) { try WidgetSnapshotCodec.decode(data(offers:[offer(1,score:101)])) }
    #expect(WidgetFormatting.safeLink("recruitflow://offer/../../.env") == nil)
    #expect(WidgetFormatting.safeLink("recruitflow://activity?url=x") == nil)
    #expect(WidgetFormatting.safeLink("recruitflow://offers/new") != nil)
    #expect(WidgetFormatting.safeLink("recruitflow://refresh") != nil)
    #expect(WidgetFormatting.safeLink("recruitflow://refresh?token=x") == nil)
}
@Test func hourlySalaryIsNeverConvertedIntoMonthlySalary() throws {
    var value = offer(1)
    value["salary"] = ["min":35,"max":45,"currency":"PLN","period":"Godzina","basis":"Brutto"]
    let snapshot = try WidgetSnapshotCodec.decode(data(offers:[value]))
    #expect(WidgetFormatting.salary(snapshot.offers[0].salary) == "35–45 PLN / godz.")
}
@Test func sourceAndUnknownFirstSeenFiltersAreIndependent() throws {
    var other = offer(2); other["source"] = "Gemini"
    let snapshot = try WidgetSnapshotCodec.decode(data(offers:[offer(1,first:nil),other]))
    var selection = WidgetSelection(); selection.onlyNew = false; selection.source = "Gemini"
    #expect(selection.offers(in:snapshot).map(\.id) == [snapshot.offers[1].id])
}
@Test func manyOffersAndRestartReadFromDurableFile() throws {
    let url = FileManager.default.temporaryDirectory.appendingPathComponent("recruitflow-swift-test-\(UUID().uuidString).json")
    defer { try? FileManager.default.removeItem(at:url) }
    try data(offers:(1...100).map { offer($0) }).write(to:url,options:.atomic)
    #expect(try LocalWidgetSnapshotSource(url:url).load().offers.count == 100)
    #expect(try LocalWidgetSnapshotSource(url:url).load().offers.count == 100)
}
