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
| 3 | espocrm (3).zip | Added Lead defaults preparator handler (client/custom/src/handlers/lead/defaults-preparator.js). |
| 4 | espocrm (4).zip | Added branding assets (logo, favicon), custom calendar themes (Dark, Glass, Hazyblue, Sakura, Violet) and templates. |
| 5 | espocrm (5).zip | Added high-resolution branding asset (cgit-logo-192.png). |
| 6 | espocrm (6).zip | Added MeetingReminders scheduled job, call time/date field views, and Call layout filters. |
| 7 | espocrm (7).zip | Added custom detail and list layouts for Meeting and Account entities. |

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