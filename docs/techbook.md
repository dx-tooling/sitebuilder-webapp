#  Techbook

What are general tech stack and tooling choices for this application?


## Dependency Management

The application pulls in and manages external dependecies in three different areas:


### PHP

PHP dependencies are managed through Composer, and as always, are stored at vendor/.


### Node.js
Command Line Node.js dependencies are managed through NPM, and as always, are stored at node_modules/. This includes tooling required during development and testing, like ESLint and Prettier, but also an additional TailwindCSS installation due to https://youtrack.jetbrains.com/issue/WEB-55647/Support-Tailwind-css-autocompletion-using-standalone-tailwind-CLI — "additional" means: in addition to the actually used TailwindCSS installation through the AssetMapper & symfonycasts/tailwind-bundle)


### AssetMapper
Frontend-only JavaScript dependencies are managed through the Symfony AssetMapper system (via importmaps), and as always, are stored at assets/vendor/.
