=== Post Forwarder ===
Contributors: sylwesterulatowski
Tags: post, forward, sync, linkedin, twitter, x, social media, syndication, wordpress
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forward WordPress posts to LinkedIn, X (Twitter), and other WordPress sites automatically on publish — with image thumbnails and hashtags.

== Description ==

Post Forwarder lets you syndicate content from one WordPress site to any combination of destinations with a single click at publish time. Configure as many portals as you need and choose per-post which ones receive each article.

**Supported Destinations**

* **LinkedIn** — posts as a link-share article card with your excerpt, featured image thumbnail, and hashtags auto-generated from your WordPress post tags. Supports personal profiles and organisation pages. Authorized via OAuth through the relay server — no configuration required.
* **X (Twitter)** — posts the title and post URL as a tweet; featured image attaches directly on paid API tiers or appears as a link preview card. Authorized via OAuth 2.0 through the relay server. Each user must supply their own X developer app credentials (free).
* **WordPress sites** — forwards via REST API with taxonomy mapping, featured image upload, ACF field support, and duplicate prevention. One-click authorization using WordPress Application Passwords, or enter credentials manually.

**LinkedIn Highlights**

* Zero-configuration OAuth — click "Connect with LinkedIn" and authorize in one step.
* Featured image is uploaded directly to LinkedIn and attached as the article thumbnail.
* WordPress post tags are automatically converted to LinkedIn hashtags (e.g. the tag "my topic" becomes #MyTopic in the post).
* Commentary (excerpt) is truncated to LinkedIn's 3000-character limit so posts never fail.
* Supports personal profiles (`urn:li:person:...`) and organization pages (`urn:li:organization:...`).
* Token expiry is shown in settings; reconnect before it expires to avoid gaps.

**Relay Server**

LinkedIn and X OAuth flows are handled by a shared relay server. The plugin ships with a default relay that works out of the box — no setup required. For X, each user must supply their own developer app credentials (Client ID + Secret). Advanced users who want full control can self-host the open-source relay.

== Platform Requirements & Limitations ==

= LinkedIn =

* No extra configuration required — the built-in shared relay handles OAuth.
* LinkedIn access tokens expire after **60 days**. The plugin shows the expiry date and prompts you to reconnect.
* For organization page posts set the Author URN to `urn:li:organization:YOUR_ORG_ID`. You'll also need the **Community Management API** product approved on the LinkedIn app (not needed for personal profile posts).
* LinkedIn requires the post URL to be publicly reachable to show an article card. Posts from localhost or `.local`/`.test` domains fall back to image-only or text+URL mode.

= X (Twitter) =

**You need a free X developer account to post tweets.** X limits write access per developer app, so each plugin user must connect their own app.

**Step 1 — Create an X developer account (one-time)**

1. Go to [developer.x.com](https://developer.x.com) and sign in with your X account.
2. Click **Sign up** and agree to the terms — your developer account is created instantly.

**Step 2 — Create an app and get credentials**

1. In the [Developer Portal](https://console.x.com) go to **Apps** and click **Create App**.
2. Give it any name (e.g. "My Post Forwarder").
3. Open the app, click **Keys & Tokens** then **User authentication settings** then **Set up**.
4. Set:
   - **App permissions**: Read and write
   - **Type of App**: Web App, Automated App or Bot
   - **Callback URI**: `https://post-forwarder-relay.sylwesterulatowski.workers.dev/x/callback`
   - **Website URL**: your site URL
5. Click **Save** and copy the **Client ID** and **Client Secret** (the secret is shown only once).

**Step 3 — Connect in the plugin**

1. In **Post Forwarder -> Settings -> Connection Configuration**, add an X portal.
2. Enter the Client ID and Client Secret you copied.
3. Click **Save Portals**, then click **Save & Connect with X**.
4. Authorize the app on X and you are redirected back with a "Connected" badge.

**Notes:**
* The free tier allows 1,500 tweet writes per month. Each user's app has its own separate quota.
* X access tokens are long-lived and refreshed automatically before expiry.
* Featured images on the free tier appear as link preview cards (public sites only), not as direct attachments. The X Basic plan ($100/month) enables direct image upload.

= WordPress =

* The destination site must run WordPress 5.6+ with the REST API enabled (default).
* The connecting user must have at least the Editor role on the destination site.
* Use "Save & Connect with WordPress" for one-click Application Password authorization, or enter a username and Application Password manually.

== Installation ==

= Plugin =

1. Upload the plugin folder to `/wp-content/plugins/post-forwarder/`, or install via **Plugins -> Add New**.
2. Activate the plugin.
3. Go to **Post Forwarder -> Settings** to configure portals.

= Relay Server =

The plugin uses a shared relay server out of the box — no setup needed for LinkedIn or X OAuth. Just click "Save & Connect" in portal settings.

**Self-hosting (advanced):** Deploy the open-source Cloudflare Worker from the plugin repository. Then add this line to `wp-config.php`:

`define( 'POST_FORWARDER_RELAY_URL', 'https://your-worker.workers.dev' );`

= WordPress portal credentials (manual) =

1. On the destination site go to **Users -> Profile**.
2. Scroll to **Application Passwords** and create a new one.
3. Enter the username and generated password in the plugin settings, or use the one-click button.

== Frequently Asked Questions ==

= Does LinkedIn need any setup? =

No. The shared relay handles OAuth for you — click "Connect with LinkedIn" and authorize. No LinkedIn API keys needed on your end.

= What are the hashtags in LinkedIn posts? =

Your WordPress post tags are converted to LinkedIn hashtags automatically. For example, a tag "social media tips" becomes `#SocialMediaTips` appended at the end of the post commentary.

= Does the featured image appear on LinkedIn? =

Yes. The plugin uploads your featured image directly to LinkedIn and attaches it as the article thumbnail. If no featured image is set, the article card may still show a preview from the linked page's Open Graph tags.

= Why does X posting fail with a quota error? =

Each developer app has 1,500 tweet writes per month on the free tier. If you see a quota error, wait until next month or upgrade to X API Basic. Make sure you entered your own Client ID and Secret — blank fields use the shared relay quota instead of your own.

= Do X tokens expire after 2 hours? =

No. The plugin uses OAuth 2.0 with `offline.access` scope for long-lived tokens, refreshed automatically. The 2-hour limit applies to old OAuth 1.0a tokens, which this plugin does not use.

= How often do LinkedIn tokens expire? =

Every 60 days. The plugin shows the expiry date in portal settings. Reconnect before expiry to avoid forwarding failures.

= Do I need to set up a relay server? =

No. The built-in shared relay handles LinkedIn and X OAuth. WordPress-to-WordPress forwarding needs no relay at all.

= Can I forward to multiple destinations at once? =

Yes. Check as many portals as you like in the post editor sidebar — all selected portals receive the post when you publish or update.

= What happens if a destination is unreachable? =

The plugin logs the error and shows it in the post editor sidebar after saving. Other destinations are still attempted.

= Can I forward custom post types? =

Yes for WordPress portals. LinkedIn and X always receive a link-post with excerpt and featured image regardless of post type.

== Screenshots ==

1. Settings page — LinkedIn, X, and WordPress portals with one-click OAuth connect buttons
2. Post editor sidebar — select destinations and see forwarding results per portal
3. LinkedIn post with featured image thumbnail and auto-generated hashtags from post tags

== Changelog ==

= 3.0.0 =
* LinkedIn: featured image uploaded directly and shown as article thumbnail in LinkedIn post
* LinkedIn: WordPress post tags automatically converted to hashtags (e.g. #MyTag) in commentary
* LinkedIn: commentary truncated at LinkedIn's 3000-character limit to prevent silent failures
* LinkedIn: token expiry date displayed in settings with reconnect prompt when expired
* LinkedIn: organization page support via `urn:li:organization:...` Author URN
* LinkedIn: zero-config OAuth via shared relay — no LinkedIn API keys required
* X (Twitter): OAuth 2.0 via relay server with automatic long-lived token refresh
* X: per-user developer app credentials (Client ID + Secret) for independent API quotas
* WordPress portal: one-click Application Password authorization flow
* WordPress portal: duplicate prevention, taxonomy mapping, ACF field forwarding, featured image upload
* Post editor sidebar: live forwarding result notices after publish (Gutenberg)
* Calendar view for scheduling posts across all channels

= 2.1.0 =
* Multi-portal support with selective per-post forwarding
* Intelligent taxonomy mapping with tag fallback
* Featured image transfer
* Duplicate prevention
* ACF integration
* Improved error handling and logging

= 2.0.0 =
* Complete rewrite with REST API support
* Taxonomy and meta field forwarding

= 1.0.0 =
* Initial release — basic post forwarding

== Upgrade Notice ==

= 3.0.0 =
Major update. LinkedIn posts now include featured image thumbnails and auto-hashtags from post tags. All existing portal configurations are preserved on upgrade.

== Technical Notes ==

**WordPress REST API endpoints used:**
* `/wp-json/wp/v2/posts`
* `/wp-json/wp/v2/{post_type}`
* `/wp-json/wp/v2/media`
* `/wp-admin/authorize-application.php`

**Social API endpoints used:**
* LinkedIn REST API v2 — `rest/images` (upload), `rest/posts` (publish), `v2/userinfo` (auto-detect person URN)
* X API v2 — `2/tweets`, `2/oauth2/token`

**Security:**
* OAuth tokens are stored in the WordPress database. Enable at-rest encryption on your host for best protection.
* Sensitive fields (access tokens, client secrets, passwords) are never echoed back to the browser — submitting a blank field preserves the saved value.
* All form inputs are sanitized and validated; WordPress nonces protect all forms and OAuth callbacks.
* One-time relay tokens with 60-second TTL are used for the relay-to-WordPress credential handoff.

**Minimum Requirements:**
* WordPress 5.6+
* PHP 7.4+
