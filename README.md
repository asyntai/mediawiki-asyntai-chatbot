# Asyntai AI Chatbot for MediaWiki

Puts the [Asyntai](https://asyntai.com) AI chat assistant on every page of a
MediaWiki, and sends the wiki pages to the Asyntai knowledge base so the
assistant answers from them, with a link back to the page.

Works on public wikis and on internal wikis behind a login alike: the wiki
pushes its pages out from inside, so no crawler is needed.

## What it does

- **Chat widget.** One script tag in the head of every page. Paste the Widget
  ID from the Asyntai dashboard and the assistant is live.
- **Knowledge base sync.** Every saved page goes to Asyntai in the background
  through the job queue. A changed page is sent again, a deleted page is
  removed. One button sends the whole wiki at the start.
- **Settings page.** `Special:AsyntaiChatbot`, for users with the
  `asyntai-manage` right (administrators by default). It shows how many pages
  are in the knowledge base, how many wait in the queue, when the last page
  went out, and the last problem if there was one.

## Requirements

- MediaWiki 1.39 or later.
- An Asyntai account. The Free plan is enough for the chat widget.
- A paid Asyntai plan for the knowledge base sync, because it uses the
  Asyntai API.

## Installation

1. Unpack the extension into `extensions/AsyntaiChatbot`.
2. Add to `LocalSettings.php`:

   ```php
   wfLoadExtension( 'AsyntaiChatbot' );
   ```

3. Run `php maintenance/update.php` once. It creates two small tables.
4. Open `Special:AsyntaiChatbot`, paste your Widget ID, and save.
5. For the sync: paste an Asyntai API key, tick **Send wiki pages to
   Asyntai**, save, then press **Send all pages now**.

The queued pages go out as visitors open pages (`$wgJobRunRate`), or at once
with `php maintenance/runJobs.php`.

## Configuration

Everything an administrator needs is on the settings page. Four values can be
changed in `LocalSettings.php` as well:

| Setting | Default | Meaning |
| --- | --- | --- |
| `$wgAsyntaiScriptUrl` | `https://widget.asyntai.com/static/js/chat-widget.js` | Address of the widget script |
| `$wgAsyntaiApiBase` | `https://asyntai.com` | Base address of the Asyntai API |
| `$wgAsyntaiSyncMinChars` | `120` | Pages with less text are stubs and are not sent |
| `$wgAsyntaiSyncMaxChars` | `40000` | At most this many characters of one page are sent |

## How the sync decides what to send

- Pages in the namespaces chosen on the settings page. The main namespace is
  on from the start. Add `12` for Help or `4` for Project pages.
- Redirects are skipped.
- A page shorter than `$wgAsyntaiSyncMinChars` is a stub and is skipped.
- A page is sent again only when its latest revision changed since the last
  time.
- If Asyntai answers that the daily upload limit is reached, the job is
  retried later and the sync continues the next day.

## Uninstall

Remove the `wfLoadExtension` line. The widget and the sync stop at once. The
two tables `asyntai_settings` and `asyntai_pages` can be dropped by hand.
Pages already sent stay in the Asyntai knowledge base until you delete them
there.

## Support

hello@asyntai.com, or https://asyntai.com/documentation/integrations/mediawiki/

## License

GPL-2.0-or-later.
