import SwiftUI
import WidgetKit

@main
struct RecruitFlowWidgetPOC: App {
    var body: some Scene {
        WindowGroup("RecruitFlow Widgets — PoC") {
            VStack(spacing: 16) {
                Image(systemName: "briefcase.fill").font(.largeTitle)
                Text("RecruitFlow WidgetKit PoC").font(.title2)
                Text("Otwórz galerię widgetów macOS i wyszukaj RecruitFlow.")
                Text("Ten etap sprawdza rejestrację rozszerzenia, jeszcze bez danych ofert.")
                    .font(.caption).foregroundStyle(.secondary)
            }
            .padding(32).frame(width: 420, height: 220)
            .onAppear { WidgetCenter.shared.reloadAllTimelines() }
        }
    }
}
