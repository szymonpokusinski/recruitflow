import Foundation

enum CheckFailure: Error { case failed(String) }
func check(_ condition: Bool, _ message: String) throws { if !condition { throw CheckFailure.failed(message) } }
func row(_ number: Int, score: Double? = nil, first: String? = "2026-10-13T08:00:00+00:00", active: Bool = true, cv: Bool = false) -> [String: Any] {
    let id = String(format:"00000000-0000-4000-8000-%012d",number)
    var value: [String: Any] = ["id":id,"company":"Firma testowa","title":String(repeating:"Długie stanowisko ",count:10),
        "location":"Warszawa","workMode":"Zdalnie","salary":["currency":"PLN","period":"Godzina","basis":""],
        "matches":[],"priority":"","source":"Ręcznie","status":"Nie aplikowano","cvSent":cv,"active":active,
        "isNewToday":true,"isNew24Hours":true,"deepLink":"recruitflow://offer/\(id)"]
    if let first { value["firstSeenAt"] = first }
    if let score { let match: [String: Any] = ["score":score,"source":"Zapisana ocena"];value["match"]=match;value["matches"]=[match] }
    return value
}
func fixture(_ offers: [[String: Any]] = [], version: Int = 1) throws -> Data {
    try JSONSerialization.data(withJSONObject:["schema_version":version,"generatedAt":"2026-10-13T10:00:00+00:00",
        "timezone":"Europe/Warsaw","staleAfterMinutes":60,"statistics":["newToday":offers.count,"new24Hours":offers.count,
        "highMatches":0,"cvSent":0,"activeProcesses":0,"newHRReplies":0,"upcomingInterviews":0],
        "offers":offers,"totalOffers":offers.count,"truncated":false,"deduplicatedCount":0,"profiles":[],"sources":["Ręcznie"],"events":[]])
}
func rejects(_ data: Data, expected: WidgetSnapshotError) throws {
    do { _ = try WidgetSnapshotCodec.decode(data); throw CheckFailure.failed("Expected \(expected)") }
    catch let error as WidgetSnapshotError { try check(error == expected,"Wrong error") }
}
let tests: [(String, () throws -> Void)] = [
    ("empty / one / unknown values", {
        try check(try WidgetSnapshotCodec.decode(fixture()).offers.isEmpty,"Empty data")
        let snapshot = try WidgetSnapshotCodec.decode(fixture([row(1)]))
        try check(snapshot.offers.count == 1 && snapshot.offers[0].match == nil,"Single offer")
        try check(WidgetFormatting.salary(snapshot.offers[0].salary) == nil,"Missing salary")
    }),
    ("ranking / zero / profile / minimum", {
        let snapshot = try WidgetSnapshotCodec.decode(fixture([row(1),row(2,score:0),row(3,score:87)]))
        var selection=WidgetSelection();selection.onlyNew=false;selection.bestMatches=true
        try check(selection.offers(in:snapshot).map(\.id)==[snapshot.offers[2].id,snapshot.offers[1].id,snapshot.offers[0].id],"Numeric order")
        selection.minimumScore=85;try check(selection.offers(in:snapshot).count==1,"Minimum")
        selection.profileId="other";try check(selection.match(for:snapshot.offers[2],snapshot:snapshot)==nil,"Wrong profile")
    }),
    ("stable first seen / inactive / CV / stale snapshot", {
        let snapshot=try WidgetSnapshotCodec.decode(fixture([row(1,first:"2026-10-11T10:00:00+00:00"),row(2),row(3,active:false),row(4,cv:true)]))
        let now=WidgetFormatting.date("2026-10-13T12:00:00+00:00")!
        try check(WidgetSelection().offers(in:snapshot,now:now).map(\.id)==[snapshot.offers[1].id],"Available new offers")
        try check(snapshot.isStale(at:now),"Stale data")
        try check(!WidgetFormatting.isToday("2026-10-12T12:00:00+00:00",timezone:"Europe/Warsaw",now:now),"Calendar day")
    }),
    ("invalid / old format / duplicate / size limit", {
        try rejects(Data("broken".utf8),expected:.invalidFormat)
        try rejects(fixture(version:0),expected:.unsupportedVersion)
        try rejects(fixture([row(1),row(1)]),expected:.invalidIdentity)
        try rejects(Data(repeating:32,count:2_000_001),expected:.oversized)
    }),
    ("safe deep links / score bounds", {
        var bad=row(1);bad["deepLink"]="https://evil.example";try rejects(fixture([bad]),expected:.invalidIdentity)
        try rejects(fixture([row(1,score:101)]),expected:.invalidIdentity)
        try check(WidgetFormatting.safeLink("recruitflow://offer/../../.env")==nil,"Traversal")
        try check(WidgetFormatting.safeLink("recruitflow://activity?url=x")==nil,"Query")
        try check(WidgetFormatting.safeLink("recruitflow://offers/new") != nil,"New offers")
    }),
    ("salary units / null / Unicode", {
        var value=row(1);value["salary"]=["min":35,"max":45,"currency":"PLN","period":"Godzina","basis":"Brutto"]
        let snapshot=try WidgetSnapshotCodec.decode(fixture([value]))
        try check(WidgetFormatting.salary(snapshot.offers[0].salary)=="35–45 PLN / godz.","Hourly amount")
        try check(snapshot.offers[0].title.contains("Długie"),"Unicode")
    }),
    ("independent source filter / unknown date", {
        var value=row(2);value["source"]="Gemini"
        let snapshot=try WidgetSnapshotCodec.decode(fixture([row(1,first:nil),value]))
        var selection=WidgetSelection();selection.onlyNew=false;selection.source="Gemini"
        try check(selection.offers(in:snapshot).map(\.id)==[snapshot.offers[1].id],"Source")
    }),
    ("100 records / durable restart", {
        let url=FileManager.default.temporaryDirectory.appendingPathComponent("recruitflow-check-\(UUID()).json")
        defer {try? FileManager.default.removeItem(at:url)}
        try fixture((1...100).map{row($0)}).write(to:url,options:.atomic)
        try check(try LocalWidgetSnapshotSource(url:url).load().offers.count==100,"First process")
        try check(try LocalWidgetSnapshotSource(url:url).load().offers.count==100,"Restart")
    })
]
var failures=0
for (name, test) in tests {
    do {try test();print("PASS \(name)")} catch {failures+=1;print("FAIL \(name): \(error)")}
}
if CommandLine.arguments.count>1 {
    do {let snapshot=try LocalWidgetSnapshotSource(url:URL(fileURLWithPath:CommandLine.arguments[1])).load();print("PASS Laravel JSON contract: \(snapshot.offers.count) offers")}
    catch {failures+=1;print("FAIL Laravel contract: \(error)")}
}
exit(failures==0 ? 0 : 1)
