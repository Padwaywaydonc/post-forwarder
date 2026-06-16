=== Post Forwarder ===
Contributors: sylwester1213
Tags: forward, syndication, linkedin, twitter, schedule
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forward WordPress posts to other WordPress sites, LinkedIn, and X — automatically on publish or scheduled from a built-in content calendar.

== Description ==

Post Forwarder lets you syndicate content from one WordPress site to any combination of destinations with a single click at publish time — or schedule it ahead with the built-in calendar. Configure as many portals as you need and choose per-post which ones receive each article.

**Supported Destinations**

* **WordPress sites** — forwards via the REST API with taxonomy mapping, featured image upload, ACF field support, and duplicate prevention. One-click authorization using WordPress Application Passwords, or enter credentials manually.
* **LinkedIn** — posts as a link-share article card with your excerpt, featured image thumbnail, and hashtags auto-generated from your WordPress post tags. Supports personal profiles and organisation pages. One-click connect — no configuration required.
* **X (Twitter)** — posts the title and post URL as a tweet; the featured image attaches directly on paid API tiers or appears as a link preview card. Connect with your own free X developer app.

**Plan ahead with the content calendar**

Post Forwarder includes a built-in weekly calendar so you can do more than fire-and-forget on publish. Schedule posts to go out to your connected channels at the time that suits you, see everything that's queued at a glance, and manage upcoming posts across all your WordPress sites, LinkedIn, and X from one screen. Great for keeping a steady publishing rhythm without babysitting the clock.

**WordPress forwarding highlights**

* One-click "Save & Connect with WordPress" using the built-in Application Password flow.
* Taxonomy mapping with automatic fallback to tags.
* Featured image, ACF fields, and custom post types are carried across.
* Duplicate prevention so the same post is never forwarded twice.

**LinkedIn highlights**

* Zero-configuration connect — click "Connect with LinkedIn" and authorize in one step.
* Featured image is uploaded directly to LinkedIn and shown as the article thumbnail.
* WordPress post tags become LinkedIn hashtags automatically (the tag "my topic" becomes #MyTopic).
* Supports personal profiles and organisation pages.
* Token expiry is shown in settings, with a reconnect prompt before it lapses.

**Compatibility**

Tested with WordPress 7.0 and PHP 8.3.

== Platform Requirements & Limitations ==

= WordPress =

* The destination site must run WordPress 5.6+ with the REST API enabled (default).
* The connecting user must have at least the Editor role on the destination site.
* Use "Save & Connect with WordPress" for one-click Application Password authorization, or enter a username and Application Password manually.

= LinkedIn =

* No extra configuration required — one-click connect handles sign-in.
* LinkedIn access tokens expire after **60 days**. The plugin shows the expiry date and prompts you to reconnect.
* For organisation page posts set the Author URN to `urn:li:organization:YOUR_ORG_ID`. You'll also need the **Community Management API** product approved on the LinkedIn app (not needed for personal profile posts).
* LinkedIn needs the post URL to be publicly reachable to show an article card. Posts from localhost or `.local`/`.test` domains fall back to image-only or text+URL mode.

= X (Twitter) =

**You need a free X developer account to post tweets.** X limits write access per developer app, so each plugin user connects their own app.

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
   - **Callback URI**: the callback URL shown in the plugin's X portal settings
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
* Featured images on the free tier appear as link preview cards (public sites only), not as direct attachments. The X Basic plan enables direct image upload.

== Installation ==

= Plugin =

1. Upload the plugin folder to `/wp-content/plugins/post-forwarder/`, or install via **Plugins -> Add New**.
2. Activate the plugin.
3. Go to **Post Forwarder -> Settings** to configure portals.

= WordPress portal credentials (manual) =

1. On the destination site go to **Users -> Profile**.
2. Scroll to **Application Passwords** and create a new one.
3. Enter the username and generated password in the plugin settings, or use the one-click button.

== External Services ==

Post Forwarder only sends data when you choose to connect an account or forward a post — nothing is sent in the background.

To publish to LinkedIn or X (Twitter), the plugin uses a small open-source connection helper to handle the secure sign-in step, then sends the post you're forwarding (such as its title, link, and image) to the network you connected. WordPress-to-WordPress forwarding talks only to the site you set up.

You stay in control: connect or disconnect any destination at any time, and advanced users can self-host the connection helper. Each network's handling of the content you send is governed by its own terms:

* LinkedIn — [Terms](https://www.linkedin.com/legal/user-agreement) · [Privacy](https://www.linkedin.com/legal/privacy-policy)
* X (Twitter) — [Terms](https://twitter.com/en/tos) · [Privacy](https://twitter.com/en/privacy)

== Frequently Asked Questions ==

= Can I forward to multiple destinations at once? =

Yes. Check as many portals as you like in the post editor sidebar — all selected portals receive the post when you publish or update.

= Can I schedule posts instead of forwarding on publish? =

Yes. Open **Post Forwarder -> Calendar** to schedule posts to your connected channels for a future date and time, and to review everything that's queued.

= Can I forward custom post types? =

Yes for WordPress portals. LinkedIn and X always receive a link-post with excerpt and featured image regardless of post type.

= Does LinkedIn need any setup? =

No. One-click connect handles sign-in for you — no LinkedIn API keys needed on your end.

= What are the hashtags in LinkedIn posts? =

Your WordPress post tags are converted to LinkedIn hashtags automatically. For example, a tag "social media tips" becomes `#SocialMediaTips` at the end of the post.

= Does the featured image appear on LinkedIn? =

Yes. The plugin uploads your featured image directly to LinkedIn and attaches it as the article thumbnail. If no featured image is set, the article card may still show a preview from the linked page's Open Graph tags.

= How often do LinkedIn tokens expire? =

Every 60 days. The plugin shows the expiry date in portal settings. Reconnect before expiry to avoid forwarding failures.

= Why does X posting fail with a quota error? =

Each developer app has 1,500 tweet writes per month on the free tier. If you see a quota error, wait until next month or upgrade your X API plan. Make sure you entered your own Client ID and Secret in the portal settings.

= What happens if a destination is unreachable? =

The plugin logs the error and shows it in the post editor sidebar after saving. Other destinations are still attempted.

== Screenshots ==

1. Connection settings — connect your WordPress sites, LinkedIn, and X with one-click authorization
2. Post editor — choose which destinations each post is forwarded to when you publish
3. Content calendar — schedule and review posts across all connected channels

== Changelog ==

= 3.0.2 =
* Restored the plugin icon and banner on the WordPress.org listing
* Corrected listing metadata (contributor username and tags)

= 3.0.1 =
* Compatibility with WordPress 7.0 and PHP 8.3
* Documented the built-in content calendar for scheduling posts
* Reorganised the readme: WordPress, then LinkedIn, then X
* Clearer, friendlier setup and service information

= 3.0.0 =
* WordPress portal: one-click Application Password authorization flow
* WordPress portal: duplicate prevention, taxonomy mapping, ACF field forwarding, featured image upload
* LinkedIn: featured image uploaded directly and shown as the article thumbnail
* LinkedIn: WordPress post tags automatically converted to hashtags (e.g. #MyTag)
* LinkedIn: commentary truncated at LinkedIn's 3000-character limit to prevent silent failures
* LinkedIn: token expiry date shown in settings with a reconnect prompt when expired
* LinkedIn: organisation page support via `urn:li:organization:...` Author URN
* LinkedIn: zero-config one-click connect — no LinkedIn API keys required
* X (Twitter): OAuth 2.0 with automatic long-lived token refresh
* X: per-user developer app credentials for independent API quotas
* Post editor sidebar: live forwarding result notices after publish (Gutenberg)
* Content calendar for scheduling posts across all channels

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

= 3.0.2 =
Maintenance release: restores the plugin icon/banner and corrects listing metadata. No functional changes.

= 3.0.1 =
Compatibility with WordPress 7.0 and PHP 8.3, plus clearer documentation including the content calendar. All existing portal configurations are preserved.

= 3.0.0 =
Major update. LinkedIn posts now include featured image thumbnails and auto-hashtags from post tags. All existing portal configurations are preserved on upgrade.

== Technical Notes ==

**Security:**
* OAuth tokens are stored in the WordPress database. Enable at-rest encryption on your host for best protection.
* Sensitive fields (access tokens, client secrets, passwords) are never echoed back to the browser — submitting a blank field preserves the saved value.
* All form inputs are sanitized and validated; WordPress nonces protect all forms and OAuth callbacks.

**Tested with:**
* WordPress 7.0
* PHP 8.3

**Minimum Requirements:**
* WordPress 5.6+
* PHP 7.4+
