// @ts-expect-error "@symfony/stimulus-bundle is JS code without a types definition"
import { startStimulusApp } from "@symfony/stimulus-bundle";
// @ts-expect-error "@enterprise-tooling-for-symfony/webui is JS code without a types definition"
import { webuiBootstrap } from "@enterprise-tooling-for-symfony/webui";

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);

webuiBootstrap(app);
