import SwiftUI
import WidgetKit

struct RecruitFlowEntry: TimelineEntry {
    let date: Date
    let snapshot: WidgetSnapshot?
    let configuration: RecruitFlowWidgetIntent
    let error: String?
}
struct RecruitFlowProvider: AppIntentTimelineProvider {
    func placeholder(in context: Context) -> RecruitFlowEntry {
        RecruitFlowEntry(date: .now, snapshot: nil, configuration: RecruitFlowWidgetIntent(), error: nil)
    }
    func snapshot(for configuration: RecruitFlowWidgetIntent, in context: Context) async -> RecruitFlowEntry {
        entry(configuration)
    }
    func timeline(for configuration: RecruitFlowWidgetIntent, in context: Context) async -> Timeline<RecruitFlowEntry> {
        Timeline(entries: [entry(configuration)], policy: .after(Date.now.addingTimeInterval(20 * 60)))
    }
    private func entry(_ configuration: RecruitFlowWidgetIntent) -> RecruitFlowEntry {
        do { return RecruitFlowEntry(date: .now, snapshot: try AppGroupSnapshot.read(), configuration: configuration, error: nil) }
        catch WidgetSnapshotError.unsupportedVersion { return RecruitFlowEntry(date: .now, snapshot: nil, configuration: configuration, error: "Zaktualizuj aplikację widgetów") }
        catch WidgetSharedContainerError.invalidConfiguration { return RecruitFlowEntry(date: .now, snapshot: nil, configuration: configuration, error: "Nieprawidłowe podpisywanie App Group") }
        catch WidgetSharedContainerError.unavailable { return RecruitFlowEntry(date: .now, snapshot: nil, configuration: configuration, error: "Brak dostępu do współdzielonych danych") }
        catch { return RecruitFlowEntry(date: .now, snapshot: nil, configuration: configuration, error: "Połącz lokalne dane w RecruitFlow Widgets") }
    }
}
enum RecruitFlowWidgetKind { case offers, matches, activity }
struct RecruitFlowWidgetView: View {
    @Environment(\.widgetFamily) private var family
    let entry: RecruitFlowEntry
    let kind: RecruitFlowWidgetKind
    private var selection: WidgetSelection {
        var result = entry.configuration.selection
        if kind == .matches || family == .systemSmall { result.bestMatches = true }
        return result
    }

    var body: some View {
        VStack(alignment: .leading, spacing: family == .systemSmall ? 8 : 12) {
            HStack {
                Label(kind == .activity ? "Rekrutacje" : "RecruitFlow", systemImage: "briefcase.fill")
                    .font(.headline).foregroundStyle(.primary)
                Spacer(minLength: 4)
                Link(destination: WidgetFormatting.safeLink("recruitflow://refresh")!) {
                    Image(systemName: "arrow.clockwise").font(.body.weight(.semibold))
                        .padding(5)
                }.buttonStyle(.plain).accessibilityLabel("Odśwież dane RecruitFlow")
            }
            if let snapshot = entry.snapshot {
                content(snapshot)
                Spacer(minLength: 0)
                HStack(spacing: 4) {
                    Image(systemName: snapshot.isStale(at: entry.date) ? "clock.badge.exclamationmark" : "clock")
                    Text("\(snapshot.isStale(at: entry.date) ? "Starsze dane · " : "")\(WidgetFormatting.shortDate(snapshot.dataUpdatedAt ?? snapshot.generatedAt))")
                }.font(.caption2).foregroundStyle(.secondary).lineLimit(1)
                if let refresh = snapshot.refresh {
                    if refresh.status == "running", let at = WidgetFormatting.date(refresh.at), entry.date.timeIntervalSince(at) < 360 {
                        Text("Odświeżanie danych…").font(.caption2).foregroundStyle(.secondary)
                    } else if refresh.status == "failed" {
                        Text("Błąd odczytu · sprawdź Integracje").font(.caption2).foregroundStyle(.orange)
                    }
                }
                if family != .systemSmall {
                    Text(snapshot.hasRemoteSources == true ? "Synchronizacja: \(WidgetFormatting.shortDate(snapshot.lastSuccessfulSyncAt))" : "Tryb lokalny")
                        .font(.caption2).foregroundStyle(.secondary).lineLimit(1)
                }
            } else {
                Image(systemName: "tray").font(.title2).foregroundStyle(.secondary)
                Text(entry.error ?? "Oczekiwanie na lokalne dane").font(.caption)
                Spacer(minLength: 0)
            }
        }
        .containerBackground(.background, for: .widget)
        .widgetURL(WidgetFormatting.safeLink(kind == .activity ? "recruitflow://activity" : "recruitflow://offers/new"))
        .privacySensitive()
    }
    @ViewBuilder private func content(_ snapshot: WidgetSnapshot) -> some View {
        let rows = selection.offers(in: snapshot, now: entry.date)
        if family == .systemSmall {
            Text("\(rows.filter { WidgetFormatting.isToday($0.firstSeenAt, timezone: snapshot.timezone, now: entry.date) }.count)").font(.system(.largeTitle, design: .rounded).bold())
            Text("nowe oferty dzisiaj").font(.caption).foregroundStyle(.secondary)
            if let offer = rows.first { offerRow(offer, snapshot: snapshot, compact: true) }
            else { Text("Brak nowych propozycji").font(.caption) }
        } else if kind == .activity {
            statistics(snapshot)
            events(snapshot, maximum: family == .systemMedium ? 2 : 5)
        } else {
            if family == .systemLarge || family == .systemExtraLarge { statistics(snapshot) }
            else { Text("\(rows.count) \(selection.onlyNew ? "nowych ofert" : "ofert")").font(.subheadline.weight(.semibold)) }
            if rows.isEmpty { Text("Brak ofert dla tych kryteriów").font(.subheadline).foregroundStyle(.secondary) }
            let maximum = family == .systemMedium ? 3 : (family == .systemLarge ? 5 : 9)
            if family == .systemExtraLarge {
                let split = min(5, rows.count)
                HStack(alignment: .top, spacing: 20) {
                    VStack(alignment: .leading, spacing: 10) { ForEach(Array(rows.prefix(split))) { offerRow($0, snapshot: snapshot) } }
                    VStack(alignment: .leading, spacing: 10) { ForEach(Array(rows.dropFirst(split).prefix(maximum-split))) { offerRow($0, snapshot: snapshot) } }
                }
            } else {
                ForEach(Array(rows.prefix(maximum))) { offerRow($0, snapshot: snapshot) }
            }
            if entry.configuration.showHR && family != .systemMedium { events(snapshot, maximum: 1) }
        }
    }
    private func offerRow(_ offer: WidgetOffer, snapshot: WidgetSnapshot, compact: Bool = false) -> some View {
        let match = entry.configuration.selection.match(for: offer, snapshot: snapshot)
        return Link(destination: WidgetFormatting.safeLink(offer.deepLink)!) {
            VStack(alignment: .leading, spacing: 2) {
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text(offer.title).font(compact ? .caption.weight(.semibold) : .subheadline.weight(.semibold)).lineLimit(compact ? 2 : 1)
                    Spacer(minLength: 0)
                    if let match { Text("\(Int(match.score))/100").font(.caption.weight(.semibold)).foregroundStyle(match.score >= 85 ? .green : .primary).accessibilityLabel("Dopasowanie \(Int(match.score)) na 100; \(match.source)") }
                }
                if !compact {
                    HStack(spacing: 4) {
                        Text(offer.company.isEmpty ? "Pracodawca niepodany" : offer.company)
                        if !offer.workMode.isEmpty { Text("· \(offer.workMode)") }
                        else if !offer.location.isEmpty { Text("· \(offer.location)") }
                    }.font(.caption).foregroundStyle(.secondary).lineLimit(1)
                    if entry.configuration.showSalary, let salary = WidgetFormatting.salary(offer.salary) { Text(salary).font(.caption).lineLimit(1) }
                    if family == .systemExtraLarge { Text("\(offer.source) · \(offer.cvSent ? "CV wysłane" : offer.status)").font(.caption2).foregroundStyle(.secondary).lineLimit(1) }
                }
            }
        }.buttonStyle(.plain)
    }
    private func statistics(_ snapshot: WidgetSnapshot) -> some View {
        var recent = WidgetSelection()
        recent.onlyNew = true
        let replies = snapshot.events.filter { event in
            event.isReply && WidgetFormatting.date(event.occurredAt).map {
                $0 <= entry.date && entry.date.timeIntervalSince($0) <= 86400
            } == true
        }.count
        return HStack(spacing: 16) {
            metric("Nowe 24 h · łącznie", recent.offers(in: snapshot, now: entry.date).count)
            metric("Rekrutacje", snapshot.statistics.activeProcesses)
            metric("Odpowiedzi HR · 24 h", replies)
        }
    }
    private func metric(_ label: String, _ value: Int) -> some View {
        VStack(alignment: .leading, spacing: 2) { Text("\(value)").font(.title3.bold()); Text(label).font(.caption2).foregroundStyle(.secondary) }
    }
    @ViewBuilder private func events(_ snapshot: WidgetSnapshot, maximum: Int) -> some View {
        let future = snapshot.events.filter { event in
            let upcoming = WidgetFormatting.date(event.deadlineAt).map { $0 >= entry.date } ?? false
            let recentReply = event.isReply && WidgetFormatting.date(event.occurredAt).map {
                $0 <= entry.date && entry.date.timeIntervalSince($0) <= 86400
            } == true
            return upcoming || recentReply
        }
        ForEach(Array(future.prefix(maximum))) { event in
            Link(destination: WidgetFormatting.safeLink(event.deepLink)!) {
                Label("\(event.type) · \(WidgetFormatting.shortDate(event.deadlineAt ?? event.occurredAt))", systemImage: event.isInterview ? "calendar" : "envelope")
                    .font(.caption).lineLimit(1)
            }.buttonStyle(.plain)
        }
    }
}
struct NewOffersWidget: Widget {
    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: "RecruitFlow.NewOffers", intent: RecruitFlowWidgetIntent.self, provider: RecruitFlowProvider()) { RecruitFlowWidgetView(entry: $0, kind: .offers) }
            .configurationDisplayName("RecruitFlow — New Offers").description("Najnowsze lokalne oferty pracy.")
            .supportedFamilies([.systemSmall, .systemMedium, .systemLarge, .systemExtraLarge])
    }
}
struct BestMatchesWidget: Widget {
    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: "RecruitFlow.BestMatches", intent: RecruitFlowWidgetIntent.self, provider: RecruitFlowProvider()) { RecruitFlowWidgetView(entry: $0, kind: .matches) }
            .configurationDisplayName("RecruitFlow — Best Matches").description("Zapisane oceny dopasowania do profilu.")
            .supportedFamilies([.systemSmall, .systemMedium, .systemLarge, .systemExtraLarge])
    }
}
struct RecruitmentActivityWidget: Widget {
    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: "RecruitFlow.Activity", intent: RecruitFlowWidgetIntent.self, provider: RecruitFlowProvider()) { RecruitFlowWidgetView(entry: $0, kind: .activity) }
            .configurationDisplayName("RecruitFlow — Recruitment Activity").description("Procesy i najbliższe terminy bez treści prywatnych wiadomości.")
            .supportedFamilies([.systemMedium, .systemLarge, .systemExtraLarge])
    }
}
@main struct RecruitFlowWidgetBundle: WidgetBundle {
    var body: some Widget { NewOffersWidget(); BestMatchesWidget(); RecruitmentActivityWidget() }
}
