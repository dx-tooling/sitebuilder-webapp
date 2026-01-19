# Development Workflow

**Reference**: See `docs/devbook.md` for common development tasks.

## Quality Checks

- **Always** run `mise quality` before committing code
- This runs: Doctrine schema validation, PHP CS Fixer, Prettier, ESLint, TypeScript checks, PHPStan
- Fix all issues before proceeding

## Common Tasks

### Building Frontend
- `mise run frontend` - Build frontend assets

### Updating Dependencies
- `mise run composer update --with-dependencies` - Update PHP dependencies
- `mise run npm update` - Update Node.js dependencies
- `mise run console importmap:update` - Update AssetMapper importmaps

### Database Migrations
- Edit entities
- Run `mise run console make:migration` to generate migration
- Run `mise run console doctrine:migrations:migrate --no-interaction` to apply

### Database Connection
- `mise run db` - Connect to local database

## Testing

- Run `mise run tests` to execute test suite
- Architecture tests enforce vertical boundaries
- Unit tests should be in `tests/Unit/`
- Integration tests should be in `tests/Integration/`

## Before Committing

1. Run `mise quality` and fix all issues
2. Run `mise run tests` and ensure all pass
3. Verify architecture boundaries are respected
4. Check that all type annotations are correct
