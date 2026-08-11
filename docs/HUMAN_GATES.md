# Human Gates

Codex should continue autonomously until one of these occurs.

## Login

Output `LOGIN REQUIRED` when a browser/session requires the user to authenticate.

## Business decision

Output `BUSINESS DECISION REQUIRED` and present concise options when two legitimate business rules produce materially different behavior.

## Production migration

Output `READY FOR PRODUCTION MIGRATION` with:
- affected tenants
- exact migrations
- backup
- risk
- rollback/forward fix

## Production deployment

Output `READY FOR PRODUCTION DEPLOYMENT` with:
- code files
- migration state
- validation
- smoke test
- rollback

## Destructive production action

Output `DESTRUCTIVE PRODUCTION ACTION REQUIRED` before any production deletion, destructive SQL, or irreversible financial mutation.

## Server configuration

Output `SERVER CONFIG CHANGE REQUIRED` before changing production Nginx/WAF/firewall/PHP-FPM configuration or reloading/restarting a shared production service.

## Pipeline rule

Before a production gate, relevant local tests, local Playwright QA, and diff review must pass. Production is never the ordinary development or browser-test environment.

## Architecture conflict

Output `ARCHITECTURE DECISION REQUIRED` when the real codebase invalidates the planned approach and the fix would materially broaden scope.
