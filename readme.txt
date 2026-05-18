=== Post Forwarder ===
Contributors: sylwesterulatowski
Tags: post, forward, sync, linkedin, twitter, x, facebook, instagram, meta, social media, syndication
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forward WordPress posts to multiple WordPress sites, LinkedIn, X (Twitter), and Meta (Facebook + Instagram) automatically on publish.

== Description ==

Post Forwarder lets you syndicate content from one WordPress site to any combination of destinations with a single click at publish time. Configure as many portals as you need and choose per-post which ones receive each article.

**Supported Destinations**

* **WordPress sites** — forwards via REST API with taxonomy mapping, featured image upload, ACF fields, and duplicate prevention. One-click authorization using WordPress Application Passwords (5.6+), or enter credentials manually.
* **LinkedIn** — posts as a link share with excerpt and featured image. Supports personal profiles and organisation pages. Authorized via OAuth through the relay server.
* **X (Twitter)** — posts a text thread with the post URL. Authorized via OAuth 2.0 through the relay server.
* **Meta (Facebook + Instagram)** — posts to a Facebook Page feed (with image attachment) and/or an Instagram Business profile. Authorized via Facebook OAuth through the relay server.

**Relay Server**

LinkedIn, X, and Meta OAuth flows are handled by a shared relay server maintained by the plugin developer. No setup is required — the relay works out of the box. Advanced users who want full control can self-host the open-source relay; see the Installation section.

== Platform Requirements & Limitations ==

Read this section before configuring each portal type to avoid unexpected errors.

= WordPress =

* The destination site must run WordPress 5.6+ with the REST API enabled (default).
* The "Save & Connect with WordPress" button uses the built-in Application Password authorization flow. Older sites that do not support this can still be connected by entering a username and Application Password manually.
* The connecting user must have at least the Editor role on the destination site.

= LinkedIn =

* The plugin uses the built-in shared relay server — no additional configuration required.
* Your LinkedIn Developer app needs the **w_member_social** and **openid / profile** products approved.
* LinkedIn access tokens expire after **60 days**. The plugin shows the expiry date and prompts you to reconnect when needed.
* For organization page posts the Author URN must be set to `urn:li:organization:YOUR_ORG_ID`, and the app needs the **Community Management API** product approved by LinkedIn.

= X (Twitter) =

* The plugin uses the built-in shared relay server — no additional configuration required.
* **A paid X API subscription is required.** The free tier does not allow writing posts. The Basic plan ($100/month at time of writing) is the minimum tier that grants write access.
* X access tokens obtained via OAuth 2.0 with `offline.access` scope are **long-lived** (they do not expire after 2 hours). The relay automatically refreshes them in the background when they near expiry.
* If you see a "no credits" or billing error when forwarding, your X developer account needs an active paid plan — this is an X platform requirement that the plugin cannot work around.

= Meta (Facebook + Instagram) =

**Facebook:**

* You must have a **Facebook Page** that you administer. Posting to personal Facebook profiles via the API is not supported by Meta and has not been possible since 2018. There is no workaround.
* The plugin uses the built-in shared relay server — no additional configuration required.
* Your Meta Developer app must have the following permissions activated under Use Cases: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`.
* During development, only users who are listed as admins, developers, or testers of the Meta app can authorize. To allow any user to connect, the app must go through Meta App Review.
* Page access tokens obtained through the OAuth flow are **long-lived** (they do not expire on a short schedule like user tokens do).

**Instagram:**

* Your Instagram account must be a **Professional account** (Business or Creator), not a personal account. You can switch in the Instagram app under Settings → Account → Switch to Professional Account.
* The Instagram account must be **linked to a Facebook Page** that you manage. This is done in Facebook Page Settings → Instagram.
* Instagram posting via the API only works for Professional accounts linked to a Page. Personal Instagram accounts cannot receive posts via the API.
* Your Meta Developer app must have `instagram_basic` and `instagram_content_publish` permissions activated.
* If no Instagram account is detected after connecting, check that the Page has an Instagram Professional account linked in its settings.

== Installation ==

= Plugin =

1. Upload the plugin to `/wp-content/plugins/post-forwarder/` or install via the Plugins screen.
2. Activate the plugin.
3. Go to **Settings → Post Forwarding** to configure portals.

= Relay Server =

The plugin uses a shared relay server out of the box. No setup is needed for LinkedIn, X, or Meta — just click "Save & Connect" in the portal settings.

**Self-hosting (advanced):** If you want to run your own relay, deploy the open-source Cloudflare Worker from the plugin repository. Add `define( 'POST_FORWARDER_RELAY_URL', 'https://your-worker.workers.dev' );` to your `wp-config.php` to point the plugin to your instance.

= WordPress portal credentials (manual) =

1. On the destination WordPress site go to **Users → Profile**.
2. Scroll to **Application Passwords**, create a new password.
3. In the plugin settings, enter the username and the generated password, or use the "Save & Connect with WordPress" button for a one-click flow.

== Frequently Asked Questions ==

= Can I post to my personal Facebook or Instagram? =

No. Meta's Graph API does not allow posting to personal profiles. You need a Facebook Page (any category) for Facebook posts, and an Instagram Professional (Business or Creator) account linked to that Page for Instagram posts. This is a Meta platform restriction.

= Why does X posting fail with a billing or credits error? =

X requires a paid API subscription for write access. The Basic plan ($100/month) is the minimum. The plugin's code is correct — you need an active paid plan on your X Developer account.

= Do X tokens expire after 2 hours? =

No. The plugin uses OAuth 2.0 with `offline.access` scope, which gives long-lived tokens. The relay refreshes them automatically. The 2-hour limit applies to old OAuth 1.0a tokens, which this plugin does not use.

= How often do LinkedIn tokens expire? =

LinkedIn tokens are valid for 60 days. The plugin shows the expiry date in the portal settings. Reconnect before they expire to avoid forwarding failures.

= Do I need the relay server? =

Only for LinkedIn, X, and Meta. WordPress-to-WordPress forwarding works without it.

= Can I forward to multiple destinations at once? =

Yes. Check as many portals as you want in the post editor sidebar — all selected portals receive the post when you publish or update.

= What happens if a forwarding destination is unreachable? =

The plugin logs the error and reports it in the post editor sidebar after saving. Other destinations are still attempted.

= Can I forward custom post types? =

Yes for WordPress portals. Social platforms (LinkedIn, X, Meta) always receive a link post with the excerpt and featured image regardless of post type.

== Screenshots ==

1. Portal configuration — WordPress, LinkedIn, X, and Meta portals side by side
2. Post editor sidebar — select destinations and see forwarding results per portal
3. One-click OAuth connect buttons for each social platform
4. Advanced JSON configuration for power users

== Changelog ==

= 3.0.0 =
* Added LinkedIn forwarding with OAuth via relay server and featured image support
* Added X (Twitter) forwarding with OAuth 2.0 via relay server and automatic token refresh
* Added Meta (Facebook + Instagram) forwarding with Facebook Page posts, image attachment, and Instagram container publish flow
* Added Cloudflare Worker relay server for secure OAuth credential storage
* Added one-click "Save & Connect" buttons for WordPress (Application Password flow), LinkedIn, X, and Meta
* Added per-forwarding result notifications in the post editor sidebar
* Fixed token persistence bug where OAuth tokens were discarded on settings re-save
* Improved metabox to show correct connection badge and status for each portal type

= 2.1.0 =
* Added multi-portal support with selective forwarding
* Implemented intelligent taxonomy mapping with fallback to tags
* Added featured image transfer functionality
* Introduced duplicate prevention system
* Added ACF (Advanced Custom Fields) integration
* Improved error handling and logging
* Added user-friendly portal configuration interface
* Enhanced custom post type support
* Added internationalization support

= 2.0.0 =
* Complete rewrite with REST API support
* Added taxonomy and meta field forwarding
* Improved reliability and error handling

= 1.0.0 =
* Initial release
* Basic post forwarding functionality

== Upgrade Notice ==

= 3.0.0 =
Major update adding LinkedIn, X, and Meta (Facebook + Instagram) forwarding. A relay server is required for social platform OAuth. Existing WordPress portal configurations are fully preserved.

== Technical Notes ==

**WordPress REST API endpoints used:**
* `/wp-json/wp/v2/posts` — standard posts
* `/wp-json/wp/v2/{post_type}` — custom post types
* `/wp-json/wp/v2/media` — featured image uploads
* `/wp-admin/authorize-application.php` — Application Password authorization

**Social API endpoints used:**
* LinkedIn REST API v2 (Images + UGC Posts)
* X API v2 (Tweets)
* Meta Graph API v21 (Pages Feed, Photos, Instagram Media)

**Security:**
* OAuth credentials for LinkedIn, X, and Meta are stored only in the relay server — never in the WordPress database
* All form inputs are sanitized and validated
* WordPress nonces protect all forms and OAuth callbacks
* One-time tokens with short TTLs are used for the relay-to-WordPress credential handoff

**Minimum Requirements:**
* WordPress 5.6+
* PHP 7.4+
* Cloudflare account (free tier) for the relay server — required for LinkedIn, X, and Meta
