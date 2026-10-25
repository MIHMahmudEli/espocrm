# EspoCRM Project History

This repository tracks the chronological development snapshots and version history of the EspoCRM project and its custom extensions.

## Repository Overview

- **Source of Truth**: Git commits and tags represent the progressive evolution across project versions.
- **Root Directory**: Contains the sanitized EspoCRM application snapshot.
- **Documentation**: Detailed technical documentation for custom modules is maintained in the companion repository [CGIT-espocrm-docs](https://github.com/MIHMahmudEli/CGIT-espocrm-docs).
- **Security**: Local credentials, database connection strings, runtime logs, and cache artifacts are excluded via .gitignore. See .env.example for environment configuration.

## Version History & Git Tags

| Tag | Snapshot Source | Description |
| :--- | :--- | :--- |
| 1 | espocrm.zip | Initial project snapshot containing baseline EspoCRM installation and core custom modules. |
| 2 | espocrm (2).zip | Added API probe endpoint (public/api/v1/probe.php). |

## Documentation & Diagnostics

- **Custom Documentation**: Full specifications, architecture maps, and tests for WebPush notifications and UI enhancements reside in [CGIT-espocrm-docs](https://github.com/MIHMahmudEli/CGIT-espocrm-docs).
- **Local Diagnostics**: Standalone server verification scripts such as phpcheck.php are intentionally kept outside version control to maintain repository hygiene.

## Setup & Configuration

1. Clone or checkout the desired version:
   `ash
   git checkout latest
   `
2. Configure environment credentials using .env.example:
   `ash
   cp .env.example .env
   `
3. Ensure web server permissions are set for data/ and custom/.