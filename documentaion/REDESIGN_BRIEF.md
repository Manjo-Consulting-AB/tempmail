# Mail Shield — Product & UI Redesign Brief

This is the single source of truth for the 2026 visual/communicative redesign.
Every redesign issue references a section here. Read this file before touching
any frontend file.

**Status:** approved. All open questions in this document have been decided by
the product owner — do not re-ask them, do not deviate.

---

## 1. What this redesign is (and is not)

The product already works. This is a **visual and communicative** redesign of
the website, not a rebuild.

**Do:**
- Change markup, CSS, copy, page titles, meta tags, and page composition.
- Reuse existing routes, endpoints, session handling, and JS behaviour.

**Do not:**
- Change backend logic, PDO queries, the AJAX/POST action surface, data models,
  cron jobs, IMAP/parser code, or the client-agent security model.
- Replace working functionality with mock functionality.
- Remove an existing feature or an existing link target.
- Rewrite backend code because a cleaner architecture would be nicer.

If a frontend change appears to require a backend change, stop and say so in
the pull request instead of making it.

---

## 2. Positioning

The product is **not** positioned as a TempMail service. Temporary email is one
feature among several.

- **Name:** `Mail Shield` (two words, both capitalised). Never "MailShield",
  never "Mailshield", never "TempMail".
- **Headline:** `Your inbox for everything else.`
- **Supporting line:** `Keep your primary inbox for what matters.`

The core idea: *one place for all the email you don't want cluttering your
primary inbox* — shopping, newsletters, registrations, forums, services you
want to try, temporary email, projects, secondary identities, automated email.

Mental model to sell: **"my second inbox"**, not "a disposable address".

**Address kinds.** User-facing copy names the two kinds of address
**Sticky addresses** (Pro, up to 10, no expiry — formerly "personal
addresses") and **Timed addresses** (run on a clock and delete themselves —
formerly "temporary addresses"). Never call either kind "personal" or
"temporary" addresses in copy. "Temporary email" / "disposable email"
remain valid as *keywords* describing the category (§14) — e.g. on
`temporary-email.php` — but the product's own name for the address is
*timed address*. Code identifiers (`is_personal`, `include_temporary`,
`create_personal`, …) keep their old names.

**Operator:** the service is operated by Manjo Consulting AB and runs on the
domain `manjo.me`. The company name stays in the footer and in legal copy.

### 2.1 Name vs. domain

`Mail Shield` is the brand. The mail domain is still `manjo.me`, so example
addresses read `shopping@manjo.me`. **Never hardcode the domain in copy** —
always render it from `$config['email']['domain']` so the page cannot lie if
the domain changes:

```php
<?php $domain = $config['email']['domain'] ?? 'manjo.me'; ?>
<span class="ms-addr">shopping@<?php echo htmlspecialchars($domain); ?></span>
```

---

## 3. Brand personality

Modern, smart, clean, technical, trustworthy, slightly playful, efficient,
understated. Reference feeling: **Linear × Superhuman × modern utility SaaS** —
as a direction, not something to copy.

**Forbidden:** hacker aesthetics, neon, "dark web" feel, generic AI-SaaS
aesthetics, heavy gradients, stock photos, decorative illustrations,
cybersecurity visual language.

### 3.1 The name says "Shield" — how far does that go?

Decided: **utility base with a discreet shield mark.**

- The layout, palette and typography stay in the light utility register
  described in §5. The site does not sell security theatre.
- The logo carries a small geometric shield mark (§4).
- Protection may show up in copy as *less clutter / your real inbox stays
  clean* — never as threat language, padlocks, shields-as-hero-art, matrix
  greens, or "military-grade" claims.

### 3.2 The "spine" mental model

Mail Shield is the spine between the user and all their secondary email.
Addresses, temporary mail, webhooks, RSS and notifications are branches leading
into one inbox.

Use this **subtly** — thin connector lines, small nodes, a hub-and-branch
rhythm in a diagram at most. Do not build a literal sci-fi network graphic.

---

## 4. Logo and marks

- **Wordmark:** `Mail Shield`, Inter 600, letter-spacing `-0.02em`, in
  `var(--ms-text)`.
- **Mark:** a small shield, 1.5px stroke, `currentColor`, geometric and flat —
  a rounded pentagon shield silhouette with one horizontal fold line
  suggesting an envelope flap. No gloss, no gradient, no badge, no lock.
- Rendered as **inline SVG** through the shared brand helper, never as a
  webfont icon and never as a raster image.
- Favicon uses the mark alone in `--ms-accent` on `--ms-bg`.

---

## 5. Visual design system

Light only. There is no dark mode in this redesign (`assets/js/site-controls.js`
deliberately strips old theme classes — leave that behaviour alone).

### 5.1 Colour tokens

```css
--ms-bg:            #FAFAF9;  /* page, warm off-white */
--ms-bg-raised:     #FFFFFF;  /* cards, product surfaces */
--ms-bg-sunken:     #F4F4F2;  /* wells, code, alternating bands */
--ms-text:          #16161A;  /* near-black */
--ms-text-muted:    #6B6B73;
--ms-text-faint:    #9A9AA2;
--ms-border:        #E5E5E1;  /* thin hairlines */
--ms-border-strong: #D4D4CF;
--ms-accent:          #12695A; /* deep teal-green */
--ms-accent-hover:    #0E5649;
--ms-accent-soft:     #E6F2EF; /* tinted backgrounds only */
--ms-accent-contrast: #FFFFFF; /* text on accent */
--ms-danger:  #B4453C;
--ms-warning: #A86A1F;
--ms-success: #2F6B4F;
```

The accent is used **sparingly**: primary buttons, links, the logo mark, one or
two focal details per section. No rainbow gradients, no neon, no accent-coloured
section backgrounds larger than a card.

### 5.2 Typography

Self-hosted **Inter** (see issue for the font install). Stack:

```css
--ms-font-sans: 'Inter var', 'Inter', -apple-system, BlinkMacSystemFont,
                'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
--ms-font-mono: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas,
                'Liberation Mono', monospace;
```

Scale — tight headings, generous body line-height:

| Token | Size | Line height | Tracking | Weight |
|---|---|---|---|---|
| `--ms-fs-display` | `clamp(2.5rem, 6vw, 4.25rem)` | 1.02 | -0.03em | 600 |
| `--ms-fs-h1` | `clamp(2rem, 4vw, 3rem)` | 1.08 | -0.025em | 600 |
| `--ms-fs-h2` | `clamp(1.6rem, 3vw, 2.25rem)` | 1.15 | -0.02em | 600 |
| `--ms-fs-h3` | `1.25rem` | 1.3 | -0.01em | 600 |
| `--ms-fs-lede` | `clamp(1.0625rem, 1.6vw, 1.25rem)` | 1.6 | 0 | 400 |
| `--ms-fs-body` | `1rem` | 1.65 | 0 | 400 |
| `--ms-fs-sm` | `0.875rem` | 1.55 | 0 | 400 |
| `--ms-fs-xs` | `0.8125rem` | 1.5 | 0 | 400 |
| `--ms-fs-eyebrow` | `0.75rem` | 1.4 | 0.08em | 600, uppercase |

Email addresses always render in `--ms-font-mono`.

### 5.3 Space, radius, shadow, layout

```css
/* 4px base scale */
--ms-space-1: .25rem;  --ms-space-2: .5rem;   --ms-space-3: .75rem;
--ms-space-4: 1rem;    --ms-space-5: 1.5rem;  --ms-space-6: 2rem;
--ms-space-7: 2.5rem;  --ms-space-8: 3rem;    --ms-space-10: 4rem;
--ms-space-12: 5rem;   --ms-space-16: 6.5rem;

--ms-radius-sm: 6px; --ms-radius-md: 10px; --ms-radius-lg: 14px;
--ms-radius-xl: 20px; --ms-radius-pill: 999px;

--ms-shadow-xs: 0 1px 2px rgba(22,22,26,.04);
--ms-shadow-sm: 0 1px 3px rgba(22,22,26,.06), 0 1px 2px rgba(22,22,26,.04);
--ms-shadow-md: 0 4px 12px rgba(22,22,26,.06), 0 1px 3px rgba(22,22,26,.04);
--ms-shadow-lg: 0 18px 48px rgba(22,22,26,.10), 0 2px 8px rgba(22,22,26,.05);

--ms-container: 1120px;
--ms-container-narrow: 720px;
--ms-gutter: clamp(1rem, 4vw, 2rem);
--ms-section-y: clamp(4rem, 9vw, 7.5rem);
```

**Whitespace is the primary layout tool.** Cards structure information; they are
not the wrapper for everything. Borders are hairlines, shadows are barely
visible, radii are modest.

### 5.4 Motion

```css
--ms-ease: cubic-bezier(.2,.6,.2,1);
--ms-dur-fast: 120ms; --ms-dur: 180ms; --ms-dur-slow: 280ms;
```

Allowed: inbox messages appearing once on load, subtle hover, small
transitions, a single restrained routing/connector animation. Forbidden:
parallax, constant movement, bouncing, scroll-jacking, anything gimmicky.

Every animation must be inside:

```css
@media (prefers-reduced-motion: reduce) { /* no transforms, no keyframes */ }
```

### 5.5 Accessibility floor

- Body text ≥ 4.5:1 contrast; large text ≥ 3:1.
- Visible focus on every interactive element:
  `:focus-visible { outline: 2px solid var(--ms-accent); outline-offset: 2px; }`
- One `<h1>` per page; heading levels never skip.
- Decorative SVG gets `aria-hidden="true"`; meaningful SVG gets a `<title>`.
- Interactive elements are `<a>` or `<button>`, never a clickable `<div>`.

---

## 6. Product UI over decoration

> **Show the product. Don't decorate the product.**

Given a choice between a nice abstract illustration and a nice representation
of the Mail Shield inbox, always pick the inbox. The product is the hero image.

The hero mockup must read as a real product: subtle shadow, rounded corners,
thin borders, excellent typography, realistic spacing, restrained animation.
It is built from **HTML + CSS + inline SVG only** — no screenshots, no canvas,
no image files.

On mobile the mockup must still feel like a real inbox. Never shrink the
desktop composition into an unreadable miniature — drop the sidebar into a
horizontal address strip and keep the message rows full-size and legible.

---

## 7. Copy rules

Copy is short, confident, clear, human, slightly clever. Good copy feels
obvious. Mail Shield does not shout.

**Banned phrases:** revolutionary, next-generation, powerful solution, seamless
experience, unlock your productivity, game-changing, cutting-edge, military-grade,
and "AI-powered" unless something is actually AI.

**Never claim a feature the code does not have.** The honest inventory is §9.

All user-facing copy is **English**. Existing Swedish code comments stay as they
are; new comments may be English.

---

## 8. Approved copy deck

Use these strings verbatim unless an issue says otherwise.

### Navigation (public pages)

Keep it very simple. There is **no Pricing link** — online payment does not
exist yet.

```
[shield] Mail Shield      Features    How it works    FAQ    Log in    [ Create your inbox ]
```

- `Features` → `#features`
- `How it works` → `#how-it-works`
- `FAQ` → `/faq.php`
- `Log in` → `/pro_login.php`
- `Create your inbox` (primary button) → `/register.php?plan=regular`

### Footer

```
Product:  Create your inbox · Log in · Temporary email addresses · FAQ
More:     Blog
Legal:    Terms of service · Privacy policy · Refund policy
          © <year> Manjo Consulting AB · Mail Shield v<version>
```

`Temporary email addresses` → `/temporary-email.php`. The three Legal links →
`/terms.php`, `/privacy.php`, `/refund-policy.php`, which Paddle requires before
it approves the domain; `pricing.php` links them too.

### Hero

- H1: `Your inbox for everything else.`
- Lede: `Keep your primary inbox for what matters. Use Mail Shield for everything else.`
- Primary CTA: `Create your inbox`
- Secondary CTA: `See how it works`
- Note (signed out): `Includes <N> days of Pro, free. No card needed.`

`<N>` throughout this deck is the Pro trial length, rendered from
`$config['trial']['days']` (env `PRO_TRIAL_DAYS`, default 60, epic #267) and
never written into copy. Trial copy is shown to signed-out visitors only, and
when `<N>` is 0 every line falls back to its pre-trial wording (here:
`Free to start. No card needed.`).

### Problem

- H2: `Your inbox wasn't built for all this.`
- Body: `Every signup, newsletter, online purchase and random service adds another message to the inbox you actually care about.`
- Body: `Mail Shield gives those emails somewhere else to go.`

### How it works

- H2: `How it works`
1. `Create your inbox` — `Sign up with your real email address. It stays private.`
2. `Choose an address` — `Use a sticky address like shopping@<domain> when you want to hear from a service again, or create a timed one for mail you only need for a while.`
3. `Everything lands in one place` — `Read it on the web, or route it onward with RSS, webhooks or push.`

### Sticky addresses

- Eyebrow: `Sticky addresses`
- H2: `One account. Multiple addresses.`
- Body: `Create up to 10 sticky addresses and manage them from one inbox.`
- Examples: `shopping@`, `news@`, `projects@` (+ configured domain)
- Kicker: `Multiple identities. One inbox.`
- Tier note: `Sticky addresses are part of Pro — and included in your first <N> days.` (signed in: `Sticky addresses are part of Pro.`)

### Temporary email

- Eyebrow: `Timed addresses`
- H2: `Here when you need it. Gone when you don't.`
- Body: `Need an address for a signup, download or service you don't fully trust yet? Create a timed address. Use it. Forget it.`
- Retention: `Timed addresses clean up after themselves — 24 hours on a free account, or up to 7 days on Pro.`
- Link: `More about temporary email addresses` → `/temporary-email.php`

### Automation

- Eyebrow: `Automation`
- H2: `Email doesn't have to stay in your inbox.`
- Body: `Connect Mail Shield to the tools you already use.`
- Cards (exactly these six, in this order):
  1. `RSS` — `Read your inbox in any feed reader, through a private token-protected feed.`
  2. `Webhooks` — `POST every incoming message as JSON to your own endpoint.`
  3. `Pushover` — `Get incoming mail as a push notification on your phone.`
  4. `Digest emails` — `A periodic summary of what arrived, sent to your real inbox.`
  5. `Agent` — `Run filtering on your own mail server, driven by signed webhooks.`
  6. `API` — badge `Coming` — `Programmatic access to your addresses and messages.`
- Tier note: `Automation is part of Pro — and included in your first <N> days.` (signed in: `Automation is part of Pro.`)

`API` is the **only** roadmap item and must carry the `Coming` badge and no
link. Browser push notifications do not exist — never mention them.

### Smart cleanup

- Eyebrow: `Smart cleanup`
- H2: `Less clutter. Automatically.`
- Body: `Your second inbox shouldn't become another inbox you have to manage.`
- Points:
  - `Timed addresses and their messages are deleted automatically.`
  - `Allow and block lists per sender, so the noise stops before you see it.`
  - `Digest emails summarise what arrived while you were away.`
  - `Notifications for the messages that actually matter.`

### External email

- Eyebrow: `Power user`
- H2: `Your rules, on your own mail server.`
- Body: `Keep the mailbox you already run, and manage its sender rules from Mail Shield.`
- Technical detail (this section may be more technical than the rest and does
  not need to be fully understandable to a casual visitor):
  `The Agent runs on your own mail server, verifies RSA-signed webhooks from Mail Shield, and applies your filter scripts and sender lists locally.`
- Flow (three steps): `you edit your rules` → `signed update to your server` → `your server filters your mailbox`

Never say the user's own mailbox is brought into, connected to or handled by
Mail Shield: the Agent filters it in place, and no message from it reaches
Mail Shield. The signed webhook carries rule updates, sent when the user saves
or syncs them — never "when a message arrives".

### Free and Pro

- H2: `Free and Pro`
- Body: `Every new account starts with <N> days of Pro, free — sticky addresses and automation included. When the trial ends, you keep a free account, or stay on Pro.`
- Pro card chip: `First <N> days free`
- Note: `No card needed, and nothing to cancel. One trial per email address. After the trial, Pro continues with a voucher code — online payment is on the way.`
- CTAs: `Start with <N> days of Pro` → `/register.php?plan=regular`,
  `Get Pro with a code` → `/register.php?plan=pro`

### Final CTA

- H2: `Give your inbox some breathing room.`
- Body: `Keep your primary inbox for what matters — and start with <N> days of Pro, free.`
- CTA: `Create your inbox`

---

## 9. Honest feature inventory

Copy may only promise what this table says exists.

| Capability | Reality in code | How to say it |
|---|---|---|
| Timed addresses | Free/Regular: 24 h. Pro: configurable 1–7 days | "24 hours on a free account, up to 7 days on Pro" |
| Sticky addresses | Pro only, max 10, long-lived | "part of Pro" |
| Creating an address | Requires a registered account | never imply anonymous one-click generation |
| RSS feed | Pro, token-protected (`pro_feed.php`) | ships |
| Webhooks | Pro, generic JSON POST | ships |
| Pushover | Pro, a webhook kind | ships |
| Digest emails | Pro (`cron/send-digests.php`) | ships |
| Sender allow/block lists | Client Agent subsystem | ships, tied to Agent |
| Agent | Client Agent on the user's own server: rules managed here, applied to the user's own IMAP mailbox there | ships, power-user framing |
| External mailboxes | Does not exist — nothing reads, fetches or receives mail from a mailbox the user runs | never offer it as a feature; the Agent section may name "the mailbox you already run" only as the place its rules apply |
| Attachments | Signed, time-limited download links | ships |
| 2FA | TOTP on password login | ships |
| Public REST API | **does not exist** | `Coming` badge only |
| Browser/web push | **does not exist** (no VAPID) | never mention |
| Pro trial | Every new account, `<N>` days (default 60) from first email verification, once per email address (`pro_trial.php`, epic #267); afterwards the account is Free and sticky addresses go 7 days later | "`<N>` days of Pro, free" — never "free forever", never a trial for signed-in users |
| Online payment for Pro | **does not exist**; voucher code only | "after the trial, Pro continues with a voucher code" |
| Pricing | no prices defined | no Pricing page, no price claims |

---

## 10. Page inventory

### Public (unauthenticated)

| File | Role after redesign |
|---|---|
| `index.php` | Marketing landing page only. Also keeps the existing POST/AJAX action surface — leave that PHP untouched. |
| `inbox.php` | *New.* The inbox reader that used to live in `index.php`'s HTML (shared `?address=` links, logged-out viewing). |
| `temporary-email.php` | *New.* SEO page for temporary/disposable email. |
| `register.php` | Sign-up (free, or Pro with a voucher code). |
| `pro_login.php` | Magic link / password / 2FA login. |
| `faq.php` | FAQ. |
| `blog.php` | Markdown blog. |

### Authenticated app

`pro.php` (dashboard), `pro_profile_page.php` (settings), `client_agent_manage.php`,
`pro_contact.php`, `pro_feed.php` (RSS output — do not restyle).

### Frontend files

| File | Role |
|---|---|
| `assets/css/mailshield.css` | *New.* Tokens + own component layer. The design system. |
| `assets/css/mailshield-bootstrap.css` | *New.* Bridge that remaps Bootstrap to the tokens, for app/auth pages. |
| `assets/css/style.css` | Legacy. Keeps functional rules; its decorative rules get overridden by the bridge. Do not delete. |
| `partials/brand.php` | *New.* Logo/mark helper. |
| `partials/public_head.php`, `partials/public_nav.php`, `partials/public_footer.php` | *New.* Marketing page shell. |
| `partials/landing/*.php` | *New.* One file per landing section. |
| `partials/nav.php` | Existing app nav — restyled, links unchanged. |
| `assets/js/app.js` | Inbox behaviour. Only its generated markup changes. |
| `assets/js/site-controls.js` | No-op theme stub. Leave alone. |

---

## 11. CSS architecture (decided)

Two layers, because the app depends on Bootstrap's JS and on Bootstrap utility
classes emitted from thousands of lines of jQuery. Removing Bootstrap from the
app is a rewrite and is out of scope.

**Marketing pages** (`index.php`, `temporary-email.php`) load **only**
`mailshield.css`. No Bootstrap, no Font Awesome, no jQuery. Icons are inline SVG.

**App and auth pages** (`inbox.php`, `register.php`, `pro_login.php`, `pro.php`,
`pro_profile_page.php`, `client_agent_manage.php`, `pro_contact.php`, `faq.php`,
`blog.php`) keep their current `<head>` dependencies and load, in this order:

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<link href="assets/css/mailshield.css" rel="stylesheet">
<link href="assets/css/mailshield-bootstrap.css" rel="stylesheet">
```

The bridge loads last and wins. That way every existing Bootstrap-classed
element inherits the new identity with **zero markup and zero JS changes**, and
each page's layout can then be reworked as its own low-risk issue.

**Naming:** all new classes are prefixed `ms-`. Never redefine or shadow a
Bootstrap class name in `mailshield.css` (the bridge is the only place allowed
to restyle `.btn`, `.card`, etc.).

**No build step.** Plain CSS, no preprocessor, no npm, no bundler. Cache-bust
new assets with `filemtime()` the same way `app.js` already is.

---

## 12. Hard rules for every redesign pull request

1. `git grep -n "TempMail"` must not add new occurrences in user-facing output.
2. Do not change any `case '<action>'` block, SQL query, or session logic.
3. Do not change POST endpoints or the parameter names `app.js` sends.
   `app.js` posts to `index.php` with a relative URL — that must keep working.
4. Keep every existing link target reachable. If a link moves, redirect the old
   URL (302) rather than deleting it.
5. Escape everything: `htmlspecialchars()` on any PHP value printed into HTML.
   Never interpolate `$_GET`/`$_POST` into markup.
6. Semgrep (`p/php`, `p/security-audit`) is build-breaking. Fix findings, do not
   suppress them; a `// nosemgrep` needs a comment explaining the false positive.
7. `php -l` must pass on every PHP file you touch.
8. Check your work at 375px, 768px, 1024px and 1440px widths.
9. No new external CDN dependency, no npm, no build step, no tracking script.
10. Keep the existing Google Analytics snippet exactly where it already is on
    pages that have it. Do not add it to pages that do not.

---

## 13. Definition of done

A first-time visitor understands within ~5 seconds:

> "This is a separate inbox for all the email I don't want in my main inbox."

And within ~15 seconds:

> "I can have multiple permanent addresses, temporary addresses, automatic
> cleanup, and connect it to other systems."

The page must feel like a product that could credibly charge money, and must no
longer read — visually or conceptually — as a "10 Minute Mail" clone.

The test to apply to every screen: *does this look like a product I would pay
for, or like a free tempmail page?*

---

## 14. SEO guardrails

Do **not** lose the ability to rank for "tempmail", "temporary email",
"temporary email address", "disposable email".

- The homepage may naturally use "temporary email", "temporary email addresses"
  and "disposable email" in body copy — but the brand message stays Mail Shield.
- `temporary-email.php` is the dedicated keyword page. It carries the
  tempmail-oriented `<h1>`, headings and body copy, and links back to the
  landing page and to sign-up.
- Every public page gets a canonical URL, Open Graph and Twitter card tags, and
  a truthful meta description.
- Pages that must be `noindex, nofollow`: `inbox.php`, `pro.php`,
  `pro_profile_page.php`, `client_agent_manage.php`, `pro_contact.php`,
  `log_viewer.php`.
- `$config['email']['base_url']` (env `BASE_URL`, defaults to `https://manjo.me/`) is the origin for canonical/OG URLs. Never hardcode
  `https://manjo.me`.

---

## 15. How this ships

### 15.1 Integration branch — never merge a redesign PR into `main`

`.github/workflows/prod.yml` deploys to production on **every push to `main`** (rsync over SSH to
`domains/manjo.me/public_html`, gated on Semgrep + `composer audit`). A redesign PR merged into
`main` is therefore live within minutes.

So the redesign uses a long-lived integration branch:

```
main                    ← production. Untouched until go-live.
└── redesign/mail-shield ← every redesign PR targets THIS branch
    ├── PR: Redesign 01
    ├── PR: Redesign 02
    └── …
```

**Rules:**

1. Branch off `redesign/mail-shield`, not `main`.
2. Open the PR against `redesign/mail-shield`. If you opened it against `main` by mistake, retarget
   it — do not merge it.
3. Squash-merge each PR into the integration branch, so one issue is one commit.
4. Keep `redesign/mail-shield` current with `main` by **merging** `main` into it whenever `main`
   moves (hotfixes, unrelated work). Never rebase it — several people and agents branch off it.
5. Nothing is deployed until the go-live merge in §15.3.

### 15.2 CI on the integration branch

`main.yml` runs Semgrep only weekly and on demand, and `prod.yml` runs it on push to `main`. That
means a PR gets **no security scan** by default — and Semgrep gates the production deploy. Without
extra CI, 27 unscanned PRs would pile up and the first scan would happen at go-live, at the exact
moment a failure is most expensive.

`.github/workflows/redesign-pr.yml` closes that gap: it runs Semgrep and `composer audit` on every
PR targeting `redesign/mail-shield` and on every push to it. Treat a finding as blocking, the same
as on `main` (§12.6).

### 15.3 Go-live

1. `redesign/mail-shield` is complete: all issues merged, #110 QA pass done, #121 docs updated.
2. Merge the latest `main` into `redesign/mail-shield` and confirm CI is green.
3. **Rehearse the deploy.** Run the `Deploy to Production (Security gated)` workflow manually
   (`workflow_dispatch`) with the branch set to `redesign/mail-shield` and `mode` = **`verify`**.
   That runs the full pipeline and an `rsync --dry-run`, writing nothing, and prints the exact file
   list that would change in production. Read it before going further.
4. Merge `redesign/mail-shield` into `main` with a **merge commit** (`--no-ff`) — not a squash and
   not a rebase. That gives one revertable point while keeping the individual commits in history.
5. The push to `main` triggers the real deploy.

### 15.4 Rollback

```bash
git revert -m 1 <merge-commit-sha>
git push origin main          # the revert push redeploys the old design
```

One commit, one deploy. What that does and does not undo:

- **Code: fully restored.** Every file the redesign modified is overwritten back to its previous
  content by the next rsync.
- **Deleted files are not removed from the server.** The deploy runs `rsync` **without `--delete`**,
  so files the redesign added (`inbox.php`, `temporary-email.php`, `sitemap.php`, `robots.txt`,
  `site.webmanifest`, `assets/fonts/*`, `assets/css/mailshield*.css`, `partials/landing/*`) stay on
  the server after a rollback. They are orphans that nothing links to, which is harmless — but two
  are worth knowing about:
  - `robots.txt` keeps being served, and keeps pointing at `/sitemap.xml`. The rewrite rule in
    `.htaccess` will be gone, so that URL 404s. Delete `robots.txt` and `sitemap.php` from the
    server by hand if a rollback is meant to last.
  - Any inbox link shared as `inbox.php?address=…` during the new-design window keeps working,
    because `inbox.php` is still on disk — but it will be the *new* version running against the
    *old* `index.php` POST surface. Since the POST handler is unchanged by the split (§10), that
    still works; verify it if a rollback lasts more than a few hours.
- **No database change to unwind.** The redesign adds no migration, no schema change and no new
  table. That is deliberate (§1) and it is what makes a code-only rollback sufficient.
- **Service worker.** `assets/js/sw.js` caches a static shell. Rolling back restores the old
  `CACHE_NAME`, and the worker's own `activate` handler deletes every cache whose key differs — so
  returning visitors self-heal on their next visit, with a one-navigation lag.

### 15.5 Note on the brief's own location

The 27 child issues each open with an instruction to fetch this file from
`claude/manjo-redesign-m70zw3` if it is not already on the base branch. Once `redesign/mail-shield`
exists that fallback never fires, because this file is on the integration branch. The scratch branch
is kept alive anyway so the instruction stays valid if anyone follows it literally.

---

## Status

**2026-09-14.** Live. The §15.3 go-live merge landed on `main` 2026-09-12 (Redesign 01–27, issues
#95–#121). Redesign 28–31 (issues #150, #151, #154, #157) merged into `redesign/mail-shield`
afterward and reached `main`/production on 2026-09-14, reconciling a window where manual deploys
off `redesign/mail-shield` and ordinary pushes to `main` had been overwriting each other.

Documented as it actually shipped, where that differs from what this brief specified:

- §11 names two Mail Shield stylesheets; there are **three**. `assets/css/mailshield-fonts.css`
  (Redesign 01) was added ahead of them, so "marketing pages load only `mailshield.css`" in practice
  means **fonts + `mailshield.css`**, and the app group loads fonts first.
- §11's app/auth group stands as written: `faq.php` and `blog.php` are public pages that nevertheless
  kept Bootstrap and are styled through the bridge, rather than being rebuilt on the marketing shell.
- The `TEMPMAIL_APP` include guard ended up on `partials/landing/*.php` only. The `public_*` shell
  partials have no guard, because only guarded pages include them.
- A `.htaccess` rewrite for `/sitemap.xml` was needed; §14 implied the route without naming it.
- §9's "does not exist" rows were honoured: the `Coming` badge appears only in the automation landing
  section, and no price string, no pricing page and no push/VAPID reference exists anywhere in the tree.
