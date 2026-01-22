// @ts-expect-error "@symfony/stimulus-bundle is JS code without a types definition"
import { startStimulusApp } from "@symfony/stimulus-bundle";
// @ts-expect-error "@enterprise-tooling-for-symfony/webui is JS code without a types definition"
import { webuiBootstrap } from "@enterprise-tooling-for-symfony/webui";
import ChatBasedContentEditorController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/chat_based_content_editor_controller.ts";
import DistFilesController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/dist_files_controller.ts";
import WorkspaceSetupController from "../src/ChatBasedContentEditor/Presentation/Resources/assets/controllers/workspace_setup_controller.ts";

const app = startStimulusApp();

app.register("chat-based-content-editor", ChatBasedContentEditorController);
app.register("dist-files", DistFilesController);
app.register("workspace-setup", WorkspaceSetupController);

webuiBootstrap(app);
