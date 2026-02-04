# Preprod deployment

Preprod is a staging environment for testing before production. Deployment steps and server-specific details (compose, scripts, env) are **not** in this repository. Use your organization's **hosting repository** (e.g. `sitebuilder-webapp-hosting-*`) and its integration script to link in scripts, compose, and docs.

- Generic preprod compose and nginx config patterns may exist in this repo (e.g. `docker/nginx/default.conf.preprod`); the actual deploy script and company-specific `docker-compose.preprod.yml` come from the hosting repo.
- For Joboo-specific preprod deployment, see [deployment-preprod-joboo.md](deployment-preprod-joboo.md) (available after integrating the `sitebuilder-webapp-hosting-joboo` repo).
