// swift-tools-version: 6.0
import PackageDescription

let package = Package(
    name: "RecruitFlowWidgetCore",
    platforms: [.macOS(.v14), .iOS(.v17)],
    products: [.library(name: "RecruitFlowWidgetCore", targets: ["RecruitFlowWidgetCore"])],
    targets: [
        .target(name: "RecruitFlowWidgetCore", path: "Sources/Core"),
        .testTarget(name: "RecruitFlowWidgetCoreTests", dependencies: ["RecruitFlowWidgetCore"], path: "Tests")
    ]
)
