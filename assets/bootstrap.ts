// @ts-expect-error "@symfony/stimulus-bundle is JS code without a types definition"
import { startStimulusApp } from "@symfony/stimulus-bundle";
// @ts-expect-error "@enterprise-tooling-for-symfony/webui is JS code without a types definition"
import { webuiBootstrap } from "@enterprise-tooling-for-symfony/webui";
import ChatBasedContentEditorController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts";
import ConversationHeartbeatController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/conversation_heartbeat_controller.ts";
import DistFilesController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/dist_files_controller.ts";
import MarkdownController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/markdown_controller.ts";
import WorkspaceSetupController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/workspace_setup_controller.ts";
import LlmKeyVerificationController from "../src/ProjectMgmt/Presentation/Resources/assets/controllers/llm_key_verification_controller.ts";
import ManifestUrlsController from "../src/ProjectMgmt/Presentation/Resources/assets/controllers/manifest_urls_controller.ts";
import S3CredentialsController from "../src/ProjectMgmt/Presentation/Resources/assets/controllers/s3_credentials_controller.ts";
import RemoteAssetBrowserController from "../src/RemoteContentAssets/Presentation/Resources/assets/controllers/remote_asset_browser_controller.ts";

const app = startStimulusApp();

app.register("chat-based-content-editor", ChatBasedContentEditorController);
app.register("conversation-heartbeat", ConversationHeartbeatController);
app.register("dist-files", DistFilesController);
app.register("markdown", MarkdownController);
app.register("workspace-setup", WorkspaceSetupController);
app.register("llm-key-verification", LlmKeyVerificationController);
app.register("manifest-urls", ManifestUrlsController);
app.register("s3-credentials", S3CredentialsController);
app.register("remote-asset-browser", RemoteAssetBrowserController);

webuiBootstrap(app);
