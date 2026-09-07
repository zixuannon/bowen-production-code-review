# eSchool Production Baseline

This file is the human-readable companion to `config/production-baseline.json`.
The JSON contract and every release's `.release-manifest.json` are authoritative for automated checks.

- Current accepted Production SHA: `79f56c8337785a5672b54800615e02b4a58098b7`
- Current GitHub main SHA at guardrail creation: `6b9ec7feec56e2b96907558a9d5e984fc60a0e21`
- Latest completed phase: Phase 5.5A
- Required baseline for Phase 5.5B: `79f56c8337785a5672b54800615e02b4a58098b7` or a descendant containing it
- Production host: `43.160.241.126`
- PHP: `/usr/bin/php83`
- PHP-FPM socket: `/tmp/php-cgi-83.sock`
- Release root: `/www/wwwroot/releases`

Updates to the accepted SHA and phase are allowed only after formal Production acceptance. A guard failure is fail-closed: do not deploy, migrate, or switch traffic.
