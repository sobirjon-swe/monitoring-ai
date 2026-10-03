# Monitoring business API

Laravel 13 / PHP 8.5 API foundation. Setup and verified local commands are in
[the local development guide](../docs/operations/local-development.md).

GET /up checks application liveness only; it does not assert database, model,
connector or queue readiness. Authenticated organization/project/role/identity
endpoints are documented in [OpenAPI](../docs/api/openapi.yaml). Local synthetic
analysis contracts do not call an AI provider or retrieve real source artifacts.
Final foundation acceptance is recorded in [E01 evidence](../docs/acceptance/e01.md);
production SSO/OPS, UI and actual model execution remain outside this delivery.
