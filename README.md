# Event Tickets Registrations (ETR)

Adds a rename-able "Registrations" tab to event pages created by The Events Calendar (TEC) that are configured to use Event Tickets (ET) and further enhanced by [Event Tickets Extra Custom Fields](https://github.com/christefano/wp-etecf) (ETECF). Registrants are neatly grouped in a table on the "Registrations" tab by section previously declared by ETECF. Sections, the section options, and the attendee fields themselves are all managed by [ETECF](https://github.com/christefano/wp-etecf).

ETR was built for the McMinnville Chess Club, but it has been generalized to be used for any WordPress site using The Events Calendar (TEC), Event Tickets (ET), and Event Tickets Extra Custom Fields (ETECF) for chess tournaments or really any type of event. Please note that it hasn't been tested with Event Tickets' RSVP feature or optional WooCommerce integration.

If you find this plugin useful, consider [making a donation](https://macchess.org/donate) to the McMinnville Chess Club!

## Requirements

- [The Events Calendar](https://wordpress.org/plugins/the-events-calendar/)
- [Event Tickets](https://wordpress.org/plugins/event-tickets/)
- [Event Tickets Extra Custom Fields](https://github.com/christefano/wp-etecf)

## Features

- **Field mapping.**
*Event Registrations → Settings*. Choose which ETECF fields provide the section, first name, last name, USCF ID, and rating. Defaults: `etecf_section`, `etecf_first_name`, `etecf_last_name`, `etecf_uscf_member_id`, `etecf_uscf_rating`.

- **Per-event toggle.**
The "Event Registrations" meta box on the event edit screen turns on the "Registrations" tab and can hide individual sections for that event.

- **Display.**
On a single event with Event Registrations enabled, the event info splits into rename-able "Tournament Details" and "Registrations" tabs. Sections follow the ETECF option order and empty sections are skipped. Rows are sorted by descending rating (anything blank, e.g. an unrated player, is displayed last) and then by name. The rename-able "Registrations" tab shows a live count, and the total number of current registrations is shown at the bottom.

- **Editor tools.**
Users who can edit the event get extras that stay hidden from the public: click any name to open a card showing all of that attendee's fields, and from that card mark (or clear) the player as a no-show. No-shows sink to the bottom of their section, are struck through, and don't get a pairing number (making pairing a bit easier).

- **Exports.**
A "Print" button opens a clean, print-ready wall sheet (one section per page for now) and is available to everyone. Users who can edit the event also get "Download CSV" and "Pairing export" buttons. The "Pairing export" botton formats one player per row (last name, first name, USCF ID, rating, section) for import into pairing software such as SwissSys or WinTD. Both exports end with a Status column that flags no-shows.

- **Shortcode.**
`[etr_registrations event="123"]` embeds a table anywhere (defaults to the current event, or the most recently created event when used off an event page).

Sections are optional but at least one is required. This rule is enforced by ETECF, not by ETR. Two niceties are that any USCF IDs entered as full `ratings.uschess.org` profile URLs are displayed just as member IDs and USCF rating values are turned into links that point to players' profiles at `ratings.uschess.org`.

## Versions

ETR's version number mirrors the versions of The Event Calendar and Event Tickets that ETR is known to work with. For example, ETR v5.2 is known to work with The Events Calendar v5.2.x and Event Tickets v5.2.x and may not have been tested with newer versions.

## Uninstall

Removes `etr_options` and ETR's post meta (`_etr_show_registrations`, `_etr_hidden_sections`, `_etr_status`). ETECF data is left alone.
