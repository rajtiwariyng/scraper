# Changelog

This file is a placeholder. The project does not yet follow a formal
release cadence; commits land directly on `main`. Until tags are
introduced, treat the `git log` as the authoritative history.

```bash
git log --oneline --decorate
```

## Conventions (proposed)

When the project starts cutting releases, this file should follow
[Keep a Changelog](https://keepachangelog.com/) and
[Semantic Versioning](https://semver.org/):

```
## [Unreleased]
### Added
### Changed
### Deprecated
### Removed
### Fixed
### Security

## [1.0.0] — YYYY-MM-DD
### Added
- Initial public release.
```

## Recent notable changes (free-form, until 1.0.0)

These are pulled from `git log` at the time of writing; the live commit log
is the source of truth.

- **2026-04** — Working state across Flipkart PDP, Amazon PDP+reviews,
  ranking scrapers; Zepto scraper added; nine platforms total.
- **2025-12** — Browsershot integration stabilised; pagination handling
  rewritten to track total pages from the SERP itself.
- **2025-09** — `scraping_urls` queue added; admin UI for keyword and
  scraper-run management.
- **2024-01** — Initial schema (16 migrations) covering products, reviews,
  product_rankings, scraping_logs, scraper_configurations, scraper_runs.
