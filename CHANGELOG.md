# Changelog

## 5.2.5

- Added a "Validate players" button under the "Registrations" tab (next to "Pairing export") when Tournament Manager is active. It checks every registered player's USCF membership status against the USCF ratings API through the event's end date, with results shown right below the Registrations tab. This release just adds the button, and USCF validation is all in Tournament Manager.

## 5.2.4

- Support for optional Tournament Manager: added an "Import to Tournament Manager" button (next to "Pairing export") when Tournament Manager is active.
- Enhanced tab functionality: tabs now have permalinks, and other plugins can add their own tab to the event page next to "Tournament Details" and "Registrations" (e.g. Tournament Manager's Standings, Wall chart, and Round entry tabs).
- Added a "Show profile pictures" per-event toggle (next to the Registrations tab toggle on the event page's meta box) that adds a photo column to the registrations tables, the CSV/pairing exports' underlying data model (`build_sections()`), and the Print wall sheet.
- Added "Demo mode" to Settings: give this plugin a whirl and add up to 50 famous chess players as test registrants to a chosen event. Test registrants appear in the Registrations tab, exports, and photo column, all without needing a real ticket sale via Event Tickets. A "Remove all test registrants" button clears them from the selected event. Adding test registrants requires an extra confirmation checkbox on sites WordPress report are production sites. Note that profile pictures are saved in the media library, which makes them reusable, e.g. in tournament recap blog posts.

## 5.2.3

- Private test release.

## 5.2.2

- Added an "Edit registration details" button to each player's card, linking to their ETECF registration form. 
- USCF IDs now link to ratings.uschess.org.
- Added a setting to show / hide the registration count badge (on by default), with help text about page caching.
- Hardening: the editor-only player cards (registrant PII + tokenized edit links) now set `DONOTCACHEPAGE`, so a page cache can never store that view and serve it to anonymous visitors.

## 5.2.1

- Updating README "Versions" section with known versions of ETECF that ETR is known to work with.

## 5.2

- First release.
