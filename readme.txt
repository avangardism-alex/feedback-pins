=== Feedback Pins – QA & Client Feedback ===
Contributors: avangardism
Tags: feedback, client review, annotation, qa, bug report
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your clients pin feedback right on the page. Your team sees it all, live, on one board. Screenshots and Slack included. Free, no account.

== Description ==

**Stop collecting website feedback in endless email threads.** With Feedback Pins, your clients click the element they want to talk about, type a note, and a numbered pin stays on the page. Everyone on the project sees everyone else's notes, live, on the page and on a shared board.

Built by [AVANGARDISM](https://avangardism.com/?utm_source=wordpress.org&utm_medium=plugin&utm_campaign=readme), a web agency that uses it on its own client projects. Free, for good: no account, no premium version, no external service.

= How it works =

1. Send your client the invitation link from **Tools > Feedback Pins**.
2. They type their first name once, then click **+ Note** and click any element of any page.
3. A screenshot is taken, the element is framed, the note is saved with its severity.
4. Your team sees the numbered pin on the page and the card on the board, within 15 seconds, without reloading.
5. Move cards from *To do* to *In progress* to *Done*, answer, export.

= Features =

* **Pin notes on any element**, on any page, with the page, the section and the screen size recorded automatically.
* **Automatic screenshots** of what the reviewer was looking at, with the element framed and the click point marked.
* **Shared in real time**: everyone who opened the link sees the others' notes, on the pages and on the board.
* **Feedback board** in three columns with drag and drop, filters by page, person and severity, and answers.
* **"View on the page" links** that scroll to the element, open the note and make the element blink.
* **Slack notifications** with the screenshot and a direct link, sent by your own server.
* **Markdown and JSON export**, grouped by page and section, ready to paste into a ticket or an AI assistant.
* **Four severities**: blocker, major, minor, nice to have.
* **No account for your clients**: a shared key in the link is enough.
* **Lightweight**: visitors who never opened a review link only download a few hundred bytes.
* **Works behind page caches**: review mode is decided in the browser, not on the server.
* **Accessible**: usable with the keyboard alone (Tab to an element, Enter to pin it), screen reader announcements, WCAG AA contrast, reduced motion respected.
* **Translation ready**, French included.

= Your data stays yours =

Notes are stored as a private post type in your own database, screenshots in your own `uploads` folder. Nothing is sent to AVANGARDISM, ever. The only outgoing call is the optional Slack webhook, which you configure yourself.

= Need a website? =

AVANGARDISM designs and builds websites, e-commerce stores and business software, with a user-first approach. [Let's talk about your project](https://avangardism.com/contact?utm_source=wordpress.org&utm_medium=plugin&utm_campaign=readme).

== Installation ==

1. Install the plugin from **Plugins > Add New**, or upload the `feedback-pins` folder to `/wp-content/plugins/`.
2. Activate it.
3. Go to **Tools > Feedback Pins** and copy the invitation link.
4. Send it to your reviewers. That's it.

Logged-in administrators also get a **Feedback** menu in the toolbar to review the current page or open the board.

= Optional constants =

Set them in `wp-config.php` to keep secrets out of the database:

`define( 'FPINS_KEY', 'your-own-key' );`
`define( 'FPINS_SLACK_WEBHOOK', 'https://hooks.slack.com/services/…' );`

== Frequently Asked Questions ==

= Do my clients need a WordPress account? =

No. The invitation link carries a shared key. Anyone who has it can read and write notes, nobody else can. You can generate a new key at any time, which disables the links sent before.

= Is the key visible in server logs? =

No. It travels in the URL fragment (`#fpins_key=…`), which browsers never send to the server, and is removed from the address bar as soon as the page opens. Afterwards it is sent in an HTTP header.

= Where is the board? =

At `/feedback-board/` on your site. If a real page already uses that address, the page wins: change the path with the `fpins_board_path` filter.

= Does it slow down my site? =

Regular visitors get a tiny inline loader and nothing else. The toolbar, its styles and the screenshot library are only downloaded by people who opened a review link.

= Some screenshots look slightly different from the page =

Screenshots are drawn in the browser by html2canvas, which re-creates the page from its HTML and CSS. Some effects (CSS masks, certain background images, cross-origin images) can be simplified. The note is always located on the element itself, so the pin stays accurate.

= Does it work with page builders and caching plugins? =

Yes. The toolbar sits on top of the page and does not depend on the theme or builder, and the review mode is decided in the browser, so full-page caches are fine.

= Can I customize it? =

A few filters are available: `fpins_board_path`, `fpins_capability`, `fpins_sync_interval`, `fpins_notes_limit`, `fpins_note`, `fpins_slack_message`, and the `fpins_note_created` action.

= What happens when I delete the plugin? =

Deleting it from the Plugins screen removes every note, every screenshot and every setting. Deactivating it keeps everything.

== External services ==

This plugin can send notifications to **Slack**, only if you enter a Slack incoming webhook URL in **Tools > Feedback Pins** (or define `FPINS_SLACK_WEBHOOK`).

* What is sent: when a note is created, its text, author first name, page path, section name, screen width, severity, the public URL of its screenshot and links back to your site.
* Where: to the webhook URL you provided, on `hooks.slack.com`, directly from your server.
* When: each time a new note is created, and when you click "Save and send a test".
* Slack terms of service: https://slack.com/terms-of-service — privacy policy: https://slack.com/trust/privacy/privacy-policy

No other external request is made. The screenshot library (html2canvas) is bundled with the plugin and served from your site.

== Privacy ==

Notes store the first name typed by the reviewer, the text of the note, the page path, the screen size and the browser user agent. Screenshots may show whatever was on the reviewer's screen within the page. They are saved in `wp-content/uploads/feedback-pins/` under random, unguessable names so that Slack can display them. Delete a note to delete its screenshot.

== Screenshots ==

1. Pin a note on any element: the screenshot is taken while you type.
2. The feedback board: every note of the team, in three columns.
3. Notes on the page, with the side list and the note details.
4. The settings page: invitation link, key, Slack.

== Changelog ==

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release.

== Credits ==

Screenshots are drawn with [html2canvas](https://html2canvas.hertzen.com) 1.4.1 by Niklas von Hertzen, MIT license. Non-minified source: https://github.com/niklasvh/html2canvas/tree/v1.4.1
