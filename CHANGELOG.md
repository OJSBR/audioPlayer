# Changelog

All notable changes to this plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
version numbers follow the PKP four-part scheme used in `version.xml`.

## [Unreleased]

## [1.0.1.1] - 2026-08-30

### Added
- Tests proving the plugin cannot become a way around access control: the set of
  hooks it registers is asserted exactly (all of them fire after
  `CatalogBookHandler::download` has run the access policy), it registers no
  route of its own, and it declines the hook when no file is present.
  Confirmed on a live server as well — requesting the same MP3 with
  `?audioStream=1` returns 206 when the file is open, and 404 when the file has
  no direct sales price, when the publication format is unavailable, and when
  the publication is not published: identical to the ordinary download.
- Cypress specs (`cypress/tests/functional/AudioPlayer.cy.js`): enabling the plugin
  and saving its settings, building the player from the audio publication format,
  a `Range` request answering `206` and an unsatisfiable one answering `416`, and a
  file that does not belong to the format being refused with exactly the same status
  with and without `?audioStream=1`.
- A live demo link in the README.

## [1.0.1.0] - 2026-08-30

### Added
- First public release. Streams audio publication formats in an in-page player
  instead of forcing a download, with HTTP Range support (RFC 9110 section 14.1)
  so seeking works, a track list built from the publication formats, and
  settings for autoplay and playback speed.

### Notes
- On servers where a reverse proxy caches responses, `Range` may be stripped
  before it reaches PHP, which turns every seek into a full re-download. The
  README documents the nginx snippet that forwards `Range` for this route.

[Unreleased]: https://github.com/OJSBR/audioPlayerOmp/compare/1.0.1.1-omp3.5...stable-3_5_0
[1.0.1.1]: https://github.com/OJSBR/audioPlayerOmp/releases/tag/1.0.1.1-omp3.5
[1.0.1.0]: https://github.com/OJSBR/audioPlayerOmp/releases/tag/1.0.1.0-omp3.5
