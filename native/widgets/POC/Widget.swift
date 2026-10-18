import SwiftUI
import WidgetKit

struct POCEntry: TimelineEntry { let date: Date }
struct POCProvider: TimelineProvider {
    func placeholder(in context: Context) -> POCEntry { POCEntry(date: .now) }
    func getSnapshot(in context: Context, completion: @escaping (POCEntry) -> Void) {
        completion(POCEntry(date: .now))
    }
    func getTimeline(in context: Context, completion: @escaping (Timeline<POCEntry>) -> Void) {
        completion(Timeline(entries: [POCEntry(date: .now)], policy: .never))
    }
}
@main
struct RecruitFlowPOCWidget: Widget {
    var body: some WidgetConfiguration {
        StaticConfiguration(kind: "RecruitFlow.POC", provider: POCProvider()) { _ in
            VStack(alignment: .leading, spacing: 12) {
                Label("RecruitFlow", systemImage: "briefcase.fill").font(.headline)
                Text("WidgetKit PoC").font(.title3)
                Text("Rejestracja systemowa").font(.caption)
            }.containerBackground(.background, for: .widget)
        }
        .configurationDisplayName("RecruitFlow — PoC")
        .description("Weryfikacja prawdziwego rozszerzenia WidgetKit.")
        .supportedFamilies([.systemSmall])
    }
}
