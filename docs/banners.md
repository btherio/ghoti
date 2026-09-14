# Banners

The banners module fills the banner positions a theme provides. Where those
banners come from is a per-site choice, made under **Admin Menu → Banners**:

| Source | What a banner position shows |
| --- | --- |
| **My own banners only** | A random row from the banner list. The original behaviour, and the default. |
| **Google AdSense only** | A Google ad unit, filled in the visitor's browser. |
| **Both** | One winner drawn from this size's banners *and* the ad unit together. |

**Both** is one pool, not two positions. With four small banners of your own,
the ad unit is one of five candidates for a small position — so roughly one
position in five is an ad, the layout keeps its shape, and adding banners of your
own makes ads rarer rather than pushing them somewhere else. A size with no
banners of your own falls through to the ad unit every time.

Themes ask for one of two sizes (`displayBanner(true)` for a small position,
`displayBanner(false)` for a wide one), and several themes have more than one
position on a page — `css/cyber/cyber.php` has three. Each is drawn
independently.

## Your own banners

Press **Add Banner** and give it a description, an image URL and a link URL. Use
HTTPS images you control: both URLs are scheme-checked when they are saved and
escaped again when they are rendered, but the image itself is fetched from
whatever host you name, by every visitor.

Tick **Small banner** for the compact size. **Delete** removes it everywhere.

## Setting up Google AdSense

AdSense is a client-side tag. The browser loads Google's script, Google decides
what to fill the slot with, and this site never sees the ad or the money — there
is no API key here and nothing for the server to call. What the module stores is
the three identifiers the tag needs.

1. **Get approved.** Apply at [adsense.google.com](https://adsense.google.com)
   with this site's domain. A new account cannot serve ads until Google has
   reviewed the site, which usually takes days and sometimes weeks. Everything
   below can be configured before approval; it simply will not fill.
2. **Find your publisher ID.** AdSense → *Account* → *Settings*. It looks like
   `ca-pub-0000000000000000`. This is the value the module validates strictly —
   it is interpolated into the script URL, so an id that does not match
   `ca-pub-` plus digits is rejected rather than stored.
3. **Create two ad units.** AdSense → *Ads* → *By ad unit* → **Display ads**.
   Make one for the small position and one for the wide one, and name them so
   the reports are readable (`ghoti-small`, `ghoti-wide`). Each unit has a **slot
   ID**, a run of digits, shown in the generated code as `data-ad-slot`.

   Two units, not one: a slot id is how AdSense reports on a position. Pointing
   both sizes at one unit merges a sidebar and a full-width footer into a single
   line of reporting.
4. **Fill in the form** under **Admin Menu → Banners** — publisher ID, both slot
   IDs, format, and the source you want. The panel refuses to switch ads on
   without a publisher id and at least one slot.
5. **Publish `ads.txt`.** The admin panel prints the exact line for your
   publisher id:

   ```
   google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0
   ```

   Save it as a file named `ads.txt` at the **root of your domain**
   (`https://example.com/ads.txt`), not in this module's directory. Without it,
   most buyers will not bid on your inventory — this is the single most common
   reason a correctly configured account earns nothing. The module cannot create
   the file for you: it is served from the document root and may be shared by
   sites the CMS knows nothing about.
6. **Turn on test mode first.** *Test mode* asks Google for placeholder ads:
   nothing is billed and nothing is earned, but a placeholder appearing proves
   the slot, the policy and the script are all working. Turn it off when you are
   satisfied.
7. **Optionally set a label.** Anything you type in *Label above each ad* is
   printed above each unit (`Advertisement` is the usual wording). Several
   jurisdictions require advertising to be identifiable as advertising.

### Ad format

`data-ad-format` — **Auto** suits nearly every site: the unit sizes itself to the
space the theme gives it. The fixed shapes are useful when a theme's banner
position has a shape of its own. **Fluid** is only for in-article and in-feed
units created as such in AdSense; pairing it with a display unit renders nothing.

## Content-security policy

Ads need origins the base policy does not allow. `index.php` widens
`script-src`, `connect-src` and `frame-src` **only while ads are actually
switched on**, with the hosts listed in `BannerAds::cspOrigins()`:

- `https://pagead2.googlesyndication.com` — the tag
- `https://googleads.g.doubleclick.net` — ad serving
- `https://tpc.googlesyndication.com` — creative frames
- `https://www.google.com` — frames some creatives use

That list covers the documented tag. It is **not** guaranteed complete: Google
serves creatives from hosts it does not publish a list of. If ads are blank and
the browser console shows *Refused to load … violates Content Security Policy*,
the named host belongs in `cspOrigins()`. Add it there, not to `index.php` — the
policy is assembled from that one method.

The widening is also why the shop and ads had to stop overwriting each other:
`$frameSrc` used to be assigned, so whichever feature ran second won. It is now
accumulated, and a site running the store and ads gets both sets of origins.

## Privacy

- A visitor sending **Global Privacy Control** or **Do Not Track** gets
  non-personalized ads: `requestNonPersonalizedAds` is set before any ad request,
  which puts `npa=1` on the call. The site already honours that signal for its
  own analytics; handing the same visitor to an ad network for profiling would
  have made that promise worthless.
- While ads are on, the privacy policy gains a paragraph naming Google as a
  recipient, linking Google's notice and My Ad Center, and stating what Google
  receives. It disappears again when ads are switched off.
- **There is no consent management platform here, and that is a real limit.**
  Jurisdictions that require prior opt-in consent for advertising cookies (the
  EU and UK, in practice) are not satisfied by a GPC check. If you have visitors
  there, you need a CMP — Google's own, or a third-party one — before serving
  ads to them. This module will not pretend otherwise.

## When ads do not appear

An unconfigured slot and a slot Google has not filled look identical: empty. The
status notes under the settings form answer the questions the page cannot. In
order of likelihood:

1. The account is not approved yet, or the site was approved and later flagged.
2. `ads.txt` is missing, or is at a path other than the domain root.
3. The slot id belongs to a different property, or to a non-display unit.
4. A new unit — new slots can take hours before they start filling.
5. A CSP violation in the browser console (see above).
6. An ad blocker. Check in a clean browser profile before assuming anything.

Blank space is also the correct behaviour when the database cannot be read: the
settings fall back to *local banners, no ads*, the policy stays narrow, and no ad
markup is emitted. Failing towards "no ads" is deliberate — the opposite failure
emits a tag the policy then blocks.

## Files

| File | What it is |
| --- | --- |
| `mod/banners/banners.php` | Entry point; `displayBanner()` picks the source, `adsActive()` answers the policy question |
| `mod/banners/banners.db.php` | Banner list plus the memoized `banner_settings` row |
| `mod/banners/banners.ads.php` | `BannerAds` — validation, markup, CSP origins, the `ads.txt` line |
| `mod/banners/banners.async.php` | Endpoints and `bannersui`, including the settings panel |
| `mod/banners/banners.sql` | `banners` and `banner_settings` |
| `tests/banners.php` | Source selection, validation, markup and policy assembly |

`getRandomBanner` is still registered as a public RPC endpoint and is legacy:
nothing in this tree calls it, themes call `displayBanner()` directly. It now
routes through `displayBanner(true)` so it cannot hand out local banner rows on
a site that has chosen ads — which means it returns rendered HTML for the small
position, where it once returned raw database rows.

`banner_settings` is a second table because `loadModuleSql()` decides whether a
module has ever been installed by probing a table named after the module — so
`banners` has to remain the banner list. Existing installs gain the new table on
the next request without any manual step; see [upgrading.md](upgrading.md).

## What is not verified here

The AdSense integration has never been run against a real AdSense account. The
tag is built to Google's documented shape and the tests cover validation, markup
and the policy assembly against fixtures — but whether Google fills these slots
can only be established by an approved account on a live domain. Start in test
mode.
