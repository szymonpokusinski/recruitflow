"""Generate the self-contained two-target Xcode project without third-party tools."""
from pathlib import Path
import hashlib
import json
import os

root = Path(__file__).resolve().parents[1]
project = root / "RecruitFlowWidgets.xcodeproj"
if (project / "project.pbxproj").exists():
    raise SystemExit("Project already exists. Generation is intentionally stopped to preserve Xcode signing and user changes.")
objects = {}
def key(name): return hashlib.sha256(name.encode()).hexdigest()[:24].upper()
def add(object_name, isa, **values):
    identifier = key(object_name)
    objects[identifier] = dict(isa=isa, **values)
    return identifier
def render(value):
    if isinstance(value, dict): return "{\n" + "\n".join(json.dumps(k)+" = "+render(v)+";" for k,v in value.items()) + "\n}"
    if isinstance(value, list): return "(" + ", ".join(render(v) for v in value) + ")"
    if isinstance(value, int): return str(value)
    return json.dumps(value)

shared = sorted((root / "Sources/Core").glob("*.swift")) + sorted((root / "Sources/Shared").glob("*.swift"))
host = shared + sorted((root / "Sources/Host").glob("*.swift"))
widget = shared + sorted((root / "Sources/Widgets").glob("*.swift"))
references = {}
for file in sorted(set(host + widget)):
    path = str(file.relative_to(root))
    references[path] = add(path, "PBXFileReference", lastKnownFileType="sourcecode.swift", path=path, sourceTree="<group>")
host_product = add("host.product", "PBXFileReference", explicitFileType="wrapper.application", path="RecruitFlow Widgets.app", sourceTree="BUILT_PRODUCTS_DIR")
widget_product = add("widget.product", "PBXFileReference", explicitFileType="wrapper.app-extension", path="RecruitFlowWidgetExtension.appex", sourceTree="BUILT_PRODUCTS_DIR")
products = add("products", "PBXGroup", name="Products", children=[host_product,widget_product], sourceTree="<group>")
config_refs = []
for file in sorted((root / "Config").iterdir()):
    config_refs.append(add(str(file.relative_to(root)), "PBXFileReference", lastKnownFileType="text.plist.xml", path=str(file.relative_to(root)), sourceTree="<group>"))
group = add("root", "PBXGroup", children=list(references.values())+config_refs+[products], sourceTree="<group>")
project_id = key("project")
extension_target = key("target.widget")
proxy = add("extension.proxy", "PBXContainerItemProxy", containerPortal=project_id, proxyType=1, remoteGlobalIDString=extension_target, remoteInfo="RecruitFlowWidgetExtension")
dependency = add("extension.dependency", "PBXTargetDependency", target=extension_target, targetProxy=proxy)
embedded = add("extension.embedfile", "PBXBuildFile", fileRef=widget_product, settings={"ATTRIBUTES":["CodeSignOnCopy","RemoveHeadersOnCopy"]})
embed_phase = add("extension.embed", "PBXCopyFilesBuildPhase", buildActionMask=2147483647, dstPath="", dstSubfolderSpec=13, name="Embed Widget Extension", files=[embedded], runOnlyForDeploymentPostprocessing=0)

def configs(name, settings):
    ids=[]
    for configuration in ["Debug","Release"]:
        values = dict(settings)
        values["SWIFT_OPTIMIZATION_LEVEL"] = "-Onone" if configuration=="Debug" else "-O"
        ids.append(add(name+configuration,"XCBuildConfiguration",buildSettings=values,name=configuration))
    return add(name+"configurations","XCConfigurationList",buildConfigurations=ids,defaultConfigurationIsVisible=0,defaultConfigurationName="Release")

targets=[]
for name, files, product, suffix in [("host",host,host_product,"application"),("widget",widget,widget_product,"app-extension")]:
    build_files=[add(name+str(file),"PBXBuildFile",fileRef=references[str(file.relative_to(root))]) for file in files]
    sources=add(name+"sources","PBXSourcesBuildPhase",buildActionMask=2147483647,files=build_files,runOnlyForDeploymentPostprocessing=0)
    frameworks=add(name+"frameworks","PBXFrameworksBuildPhase",buildActionMask=2147483647,files=[],runOnlyForDeploymentPostprocessing=0)
    settings={"PRODUCT_BUNDLE_IDENTIFIER":"pl.szymonpokusinski.recruitflow.widgets"+(".extension" if name=="widget" else ""),
        "PRODUCT_NAME":"RecruitFlowWidgetExtension" if name=="widget" else "RecruitFlow Widgets",
        "INFOPLIST_FILE":"Config/Widget-Info.plist" if name=="widget" else "Config/Host-Info.plist",
        "GENERATE_INFOPLIST_FILE":"NO", "CODE_SIGN_ENTITLEMENTS":"Config/Widget.entitlements" if name=="widget" else "Config/Host.entitlements",
        "CODE_SIGN_STYLE":"Automatic", "DEVELOPMENT_TEAM":os.environ.get("RECRUITFLOW_DEVELOPMENT_TEAM", ""), "ENABLE_HARDENED_RUNTIME":"YES",
        "ALWAYS_SEARCH_USER_PATHS":"NO",
        "MACOSX_DEPLOYMENT_TARGET":"14.0", "SDKROOT":"macosx", "SWIFT_VERSION":"5.0",
        "SWIFT_EMIT_LOC_STRINGS":"YES", "COMBINE_HIDPI_IMAGES":"YES"}
    if name=="widget": settings.update({"APPLICATION_EXTENSION_API_ONLY":"YES","SKIP_INSTALL":"YES"})
    target=add("target."+name,"PBXNativeTarget", name="RecruitFlowWidgetExtension" if name=="widget" else "RecruitFlow Widgets",
        buildConfigurationList=configs(name,settings),buildPhases=[sources,frameworks]+([embed_phase] if name=="host" else []),
        buildRules=[],dependencies=[dependency] if name=="host" else [],productName=settings["PRODUCT_NAME"],productReference=product,
        productType="com.apple.product-type."+suffix)
    targets.append(target)
add("project","PBXProject",attributes={"LastUpgradeCheck":"2600","TargetAttributes":{target:{"ProvisioningStyle":"Automatic"} for target in targets}},
    buildConfigurationList=configs("project",{}), compatibilityVersion="Xcode 14.0",developmentRegion="pl",hasScannedForEncodings=0,
    knownRegions=["pl","en","Base"],mainGroup=group,productRefGroup=products,projectDirPath="",projectRoot="",targets=targets)
project=root / "RecruitFlowWidgets.xcodeproj"
project.mkdir(exist_ok=True)
(project / "project.pbxproj").write_text("// !$*UTF8*$!\n"+render({"archiveVersion":1,"classes":{},"objectVersion":56,"objects":objects,"rootObject":project_id})+"\n")
scheme=project / "xcshareddata/xcschemes"
scheme.mkdir(parents=True,exist_ok=True)
host_target=key("target.host")
reference=f'<BuildableReference BuildableIdentifier="primary" BlueprintIdentifier="{host_target}" BuildableName="RecruitFlow Widgets.app" BlueprintName="RecruitFlow Widgets" ReferencedContainer="container:RecruitFlowWidgets.xcodeproj"/>'
(scheme / "RecruitFlow Widgets.xcscheme").write_text(f'''<?xml version="1.0" encoding="UTF-8"?>
<Scheme LastUpgradeVersion="2600" version="1.3">
<BuildAction parallelizeBuildables="YES" buildImplicitDependencies="YES"><BuildActionEntries><BuildActionEntry buildForTesting="YES" buildForRunning="YES" buildForProfiling="YES" buildForArchiving="YES" buildForAnalyzing="YES">{reference}</BuildActionEntry></BuildActionEntries></BuildAction>
<LaunchAction buildConfiguration="Debug" selectedDebuggerIdentifier="Xcode.DebuggerFoundation.Debugger.LLDB" selectedLauncherIdentifier="Xcode.IDEFoundation.Launcher.LLDB" launchStyle="0" useCustomWorkingDirectory="NO" ignoresPersistentStateOnLaunch="NO" debugDocumentVersioning="YES" allowLocationSimulation="YES"><BuildableProductRunnable runnableDebuggingMode="0">{reference}</BuildableProductRunnable></LaunchAction>
<ArchiveAction buildConfiguration="Release" revealArchiveInOrganizer="YES"/>
</Scheme>''')
print(project)
