# Event Tickets Registrations (ETR)

Adds a rename-able "Registrations" tab to event pages created by The Events Calendar (TEC) that are configured to use Event Tickets (ET) and further enhanced by [Event Tickets Extra Custom Fields](https://github.com/christefano/wp-etecf) (ETECF). Registrants are neatly grouped in a table on the "Registrations" tab by section previously declared by ETECF. Sections, the section options, and the attendee fields themselves are all managed by [ETECF](https://github.com/christefano/wp-etecf).

ETR was built for the McMinnville Chess Club, but it has been generalized to be used for any WordPress site using The Events Calendar (TEC), Event Tickets (ET), and Event Tickets Extra Custom Fields (ETECF) for chess tournaments or really any type of event. Please note that it hasn't been tested with Event Tickets' RSVP feature or optional WooCommerce integration.

If you find this plugin useful, consider [making a donation](https://macchess.org/donate) to the McMinnville Chess Club!

## Requirements

- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/)
- [Event Tickets](https://wordpress.org/plugins/event-tickets/)
- [Event Tickets Extra Custom Fields](https://github.com/christefano/wp-etecf)

## Features

- **Per-event toggle.**
The "Event Registrations" meta box on the event edit screen turns on the "Registrations" tab and can hide individual sections for that event.

- **Display.**
On a single event with Event Registrations enabled, the event info splits into rename-able "Tournament Details" and "Registrations" tabs. Sections follow the ETECF option order and empty sections are skipped. Rows are sorted by descending rating (anything blank, e.g. an unrated player, is displayed last) and then by name. The rename-able "Registrations" tab shows a live count (turn on or off in Settings), and the total number of current registrations is shown at the bottom.

- **Shortcode.**
`[etr_registrations event="123"]` embeds a table anywhere (defaults to the current event, or the most recently created event when used somewhere other than on an event page).

- **Editor tools.**
Users who can edit the event get extras that stay hidden from the public: click any name to open a card showing all of that attendee's fields, and from that card mark (or clear) the player as a no-show. No-shows sink to the bottom of their section, are struck through, and don't get a pairing number (making pairing a bit easier).

- **Exports.**
A "Print" button opens a clean, print-ready wall sheet (one section per page for now) and is available to everyone. Users who can edit the event also get "Download CSV" and "Pairing export" buttons. The "Pairing export" botton formats one player per row (last name, first name, USCF ID, rating, section) for import into pairing software such as SwissSys or WinTD. Both exports end with a Status column that flags no-shows.

- **Tournament Manager import.**
When the [Tournament Manager](https://github.com/christefano/wp-tournament-manager) plugin is active, TDs see an "Import to Tournament Manager" button next to "Pairing export". It creates (or reuses) the tournament linked to the event and hands the roster straight to Tournament Manager's import preview screen so no CSV download / upload round trip needed. A "Validate players" button also appears, and this checks every registered player's USCF membership status against the USCF ratings API through the event's end date, and shows the results (name, USCF ID, status, expiration, and verdict). ETR just adds the button, and USCF validation is in Tournament Manager.

- **Tab extensibility (developers).**
The event page's tab UI is extensible via the `etr_event_tabs` filter, applied identically from the main two-tab (`the_content`) render path and from the TEC block-template fallback, so a plugin only has to hook once to appear on both. The filter receives `( $tabs, $event_id )` and must return the (possibly modified) `$tabs` array; each tab is `array( 'id' => 'my-tab', 'label' => 'My Tab', 'html' => '...' )`. `label` is plain text (escaped when rendered); `html` is emitted unescaped, so it is the callback's responsibility to output only already-safe markup, the same contract `the_content` itself uses. Core tabs ("Tournament Details" and "Registrations") are always first; filtered-in tabs append after them in the order returned. Each tab also gets a permanent URL fragment, `#tab-{id}`, that opens it directly on load and can be bookmarked, linked to, or put on a QR code (the older `#etr-tab-{id}` form, and the original bare `#registrations`, still open the matching tab on load too, for links written before `#tab-{id}` became canonical) - see Tournament Manager's `WPMTM_Frontend::filter_etr_event_tabs()` for a real-world consumer (its Standings / Wall chart / Round entry tabs). On a single event's tab UI, only the "Details" tab (`id` `details`) shows the TEC "Add to calendar" widget and the Event Tickets "Get Tickets" form, which both render outside the tab panels themselves; every other tab hides them via a `document.body[data-etr-active-tab]` attribute that `assets/etr-tabs.js` keeps in sync (see the CSS rules in `assets/etr-registrations.css` for the exact selectors, which are theme/TEC-version dependent).

- **Sections.**
Sections are optional and each can be checked on and off on the event edit page.

- **Ratings support.**
If `etecf_uscf_rating` fields are configured in ETECF, USCF IDs entered in as full `ratings.uschess.org` profile URLs are displayed as numeric member IDs and USCF rating values are turned into links that point to players' profiles at `ratings.uschess.org`. Note that ratings are self-reported by attendees themselves during registration and need to be verified by a TD.

- **Field mapping.**
*Event Registrations → Settings*. Choose which ETECF fields provide the section, first name, last name, USCF ID, and rating. Defaults: `etecf_section`, `etecf_first_name`, `etecf_last_name`, `etecf_uscf_member_id`, `etecf_uscf_rating`.

- **Profile pictures.**
When an event has "Show profile pictures" turned on (in the same Event Registrations meta box as the Registrations tab toggle), every registrations table gets a photo column. Attendees show their ETECF profile photo (the `etecf_profile_photo` Image field) at a fixed 40x40; anyone without a saved photo shows a plain silhouette placeholder instead, so the column always lines up.

- **Demo mode.**
*Event Registrations → Settings*, at the bottom of the page. Adds test registrants to a chosen event so the Registrations tab, exports, and photo column can all be exercised without a real ticket sale. The event needs at least one ticket already (a free RSVP or $0 ticket works): test registrants reference that ticket the same way a real attendee would, and an event with no ticket at all is refused with an error notice rather than creating registrants Event Tickets can't render. Demo mode draws from a fixed pool of 50 well-known chess players (clearly non-real USCF IDs in the 90000001-90000050 range) and never adds more than 50 registrants total per event; pick how many to add (1-50, default 5) and which section to put them in (free text, with suggestions drawn from ETECF's section field when one is configured). Test registrants are `tec_tc_attendee` posts with no order behind them, so they are not real sales: Event Tickets' own Attendees screens list them without an order to link to, and ticket stock, ticket sales, and revenue are all untouched. Players with a public-domain (or CC0) Wikipedia portrait get it sideloaded into the photo field automatically; everyone else gets the same silhouette placeholder as a real registrant with no photo. A "Remove all test registrants" button clears every test registrant from the selected event (and only that event) once you're done, including any portraits it sideloaded. On a site WordPress reports as a production environment, adding test registrants requires an extra confirmation checkbox, since they'll be visible next to real registrants until removed.

# Uninstall

Removes `etr_options` and ETR's post meta (`_etr_show_registrations`, `_etr_hidden_sections`, `_etr_status`, `_etr_show_photos`, `_etr_test_used`, `_etr_test_avatar_ids`, `_etr_test_registrant`). ETECF data is left alone. Leftover test-registrant posts themselves (attendees, the placeholder order, sideloaded test-avatar attachments) are not deleted on uninstall (they live in Event Tickets' and the media library's own post types, not in any of ETR's own data) - use "Remove all test registrants" in Settings first.
