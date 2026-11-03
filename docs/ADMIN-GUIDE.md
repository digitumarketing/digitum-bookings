# Digitum Bookings: admin guide

For the people who run bookings day to day. Menu: **Digitum Bookings**.

## Getting started

A new install is empty: no tours, pickup zones, meeting points, offers or coupons, and no business details. Set the site up in this order (each step can be changed later):

1. **Install**: Plugins › Add New › Upload Plugin with `digitum-bookings.zip` from the latest GitHub release, then activate. If the site already has tours under `/tour/` (for example the Traveler theme's), the plugin uses them and their URLs stay the same (Settings › General › “Tours come from”).
2. **Settings › General**: business name, phone, email and address (used in emails, the confirmation page, structured data and llms.txt), booking terms link, cancellation summary, hold time for card payments, the phone country code filled in for guests (empty: guests type it), and the **Date format** for booking dates: “Fri 6 Nov 2026” (default) or “Fri, Nov 6, 2026”, used in the widget, on booking pages, in emails and in the bookings list.
3. **Settings › Currency & VAT**: currency (it starts as WooCommerce's currency, or the one of the site language's country, else USD: check it), VAT rate (starts at 0%) and whether prices include it, weekend days. **Show prices as**: the currency default (“699.00”) or whole units when the amount has no fraction (“699”; an amount such as 349.50 keeps its decimals). This only changes how prices are shown: totals, payments and structured data always use the full amount. Optionally a **second currency** shown next to prices for visitors from abroad, for example “AED 699 (about USD 190)”: choose the currency, enter the exchange rate (how much of your currency one unit of the second currency is worth; it is not updated automatically), whole units or two decimals, and the wording (empty uses “about {amount}” in the visitor's language). It appears in the booking widget, the mobile bar, price tables, the confirmation page and llms.txt. Guests always pay in your currency, and emails, receipts and structured data only show your currency.
4. **Pickup zones**: the areas you pick up from (and those you do not, with the message guests see), plus meeting points with address, map link and arrival instructions.
5. **Tours**: open a tour, fill in **Booking settings** (Pricing, Options, Sessions, Add-ons, Pickup & location, Rules, Schema & SEO), then tick **Take bookings for this tour**. The booking widget appears on the tour page in three steps: *Your trip*, *Pickup and details*, *Review and confirm*. On computers it shows one step at a time (Settings › General › **Stepped layout on desktop**, on by default, keeps it short for a sidebar); switch that off to show all three in one column. Phones always use steps in a bottom sheet. The price in step 3 lists the option, extras and pickup (“Hotel pickup, North Zone”, or “Included” when pickup is in the price), the total and the VAT note. When pay on arrival is your only payment method, one line under it says what to pay and where, e.g. “Pay AED 899 on the day, cash or card, when we pick you up. Nothing to pay now.”; the same sentence is on the booking page and in the confirmation email.
6. **Settings › Payments**: switch on pay on arrival, bank transfer (with your account details), Stripe and PayPal (start in test mode), and deposits if you take them. If PayPal does not accept your currency, enter the exchange rate to the PayPal currency; until then PayPal is not offered.
7. **Settings › Emails**: sender, staff recipients, reminder time; **Digitum Bookings › Emails › Send test email** checks that mail arrives (install an SMTP plugin if it does not).
8. **Settings › Branding** and **Mobile sticky bar** (General) to match the theme, if needed.
9. Check a tour page on a phone and make a test booking with pay on arrival, then cancel it (Bookings).

To try the plugin with sample content first, import a **demo pack** (Tools › Demo data) and remove it again in one click when you are done. A fresh install has no demo content.

## Prices and tour details on any page

Cards, hub pages and tour templates can show the same prices and details as the booking widget; they always come from the tour's own booking settings, so there is nothing to retype when a price changes. Leave the tour empty to use the tour being viewed (or the one in a loop of tour cards).

| What | Shortcode | Block / Elementor |
|---|---|---|
| “From AED 699.00 per vehicle”, or “Price on request” | `[dgb_from_price id="123" basis="yes" second_currency="yes"]` | “From price” block, Elementor dynamic tag **From price** (Digitum Bookings group) |
| Table of all options: option, duration, price, and the price per person for vehicles and private cars | `[dgb_price_table id="123"]` | “Price table” block and Elementor widget |
| Included, not included, requirements (from Booking settings; one item per line) | `[dgb_tour_list id="123" field="inclusions" style="check"]` (`field`: inclusions, exclusions, requirements; `style`: check, cross, plain) | “Tour list” block and Elementor widget |
| Key facts: duration, price basis, group size, minimum age, licence, pickup, meeting point, sessions with pickup times | `[dgb_tour_facts id="123" fields="duration,pickup,sessions"]` (empty `fields` shows all) | “Tour facts” block and Elementor widget |

| Hero price: “FROM”, “AED 699”, “per buggy, 30 min · VAT included” | `[dgb_price id="123" label="yes" basis="yes" basis_text="" second="yes"]` | “Tour price” block and Elementor widget (colours and typography in Style) |
| Facts as chips for a hero: duration range, seats, driver age, licence, pickup (no rating chip) | `[dgb_tour_facts id="123" style="chips" fields="duration,seats,age,licence,pickup"]` | “Tour facts” block and Elementor widget, Style: Chips |
| Gallery: a large image and four smaller ones (swipe row on phones, lightbox on click) | `[dgb_gallery id="123"]` | “Tour gallery” block and Elementor widget |
| Booking card for a sidebar (below) | `[dgb_booking_card id="123" title="" sticky="1" whatsapp="1" trust1="" trust2="" trust3=""]` | “Booking card” block and Elementor widget |

Elementor also has dynamic tags for single values: minimum age, seats or guests per car, pickup policy, meeting point name, meeting point map link (for buttons) and the next pickup window. A small stylesheet (under 1 KB) is added once per page; it follows the widget colours of Settings › Branding.

## Booking card and booking modal

**Settings › General › Booking form display**: **Modal on all devices** (the default) or **Inline**. With the modal, the three-step form opens over the page: a centred window on computers and tablets (768 px and wider) and a bottom sheet on phones. It closes with ×, Esc or a click outside, and keeps what the guest entered. On the page, the booking widget shows the price and a Book button instead of the form; in Elementor, the booking widget's **Display** can also be **Inline form**, **Teaser button** or **Hidden (modal only)** for one page.

These open the form, without code: the booking card's **Check availability**, any button or link to `#book` or `#dgb-book` (for example an Elementor hero button or the header “Book now”), any element with the CSS class `dgb-open`, the mobile bar's **Book now**, and `?book=1` at the end of a tour's address (for links from other pages).

The **booking card** is a short form for a sidebar: date (the plugin's calendar opens under the field), the options as pills with their prices (hidden when a tour has one option), the number of vehicles (“Buggies · 2 people each”) or adults and children, pickup (“Meet at …, free” and each pickup area with its price, or “included”), and the total. **Check availability** opens the form with these values, on step 2 when the date, session, option and guests are complete (otherwise on step 1 with the missing field marked). **Ask on WhatsApp** (green) opens WhatsApp to the business phone of Settings › General with the tour and the guest's choices; it is hidden without a phone number. The green “Pay on arrival” label shows when pay on arrival is the only payment method. Three trust lines can be edited in the widget; empty ones use the defaults (line 1 the cancellation summary, line 3 how guests pay); type `-` to hide one. **Sticky in sidebar** (on by default) keeps the card in view on computers 1025 px and wider. In the Style tab: primary colour, text colours, background, border, typography, padding and corner radius.

What a tour's vehicle or car is called comes from **Booking settings › Pricing › One vehicle is called / Several are called** (“buggy”, “buggies”); empty means vehicle or car. An option can carry a **Badge** (Options tab), for example “Most picked”, shown on its pill. Use one only when it is true.

The **Tour gallery** box on the tour edit screen holds up to 5 images in order (the first is the large one) with a caption each; the alt text comes from the Media Library. Without images the featured image is shown; there are never empty boxes. The same images are used in structured data.

## Mobile bar and the look of the widget

**Settings › General › Mobile sticky bar**: on phones, tour pages show a bar at the bottom of the screen with a short price line (“2 seater, per buggy from AED 699”), a WhatsApp button (with a business phone) and **Book now**. It hides while the booking form is open. Choose **Off**, **On**, or **On, and hide the theme's own bar on tour pages** when your theme already has a bottom bar (for example a WhatsApp or call bar): enter its CSS selector in **Theme bar to hide** (ask your web designer, or right-click the bar › Inspect, for example `.mobile-bar`). It is hidden on tour pages only. While the plugin's bar shows, the page gets extra space at the bottom so the bar never covers the footer.

**Settings › Branding**: accent colour, text on the accent colour, text colour, muted text, borders, panel background, corner radius, button text (as written, capitals, capitalised words) and the font. An empty field keeps the plugin default. **Font** uses the theme's font unless you enter a font family the theme already loads; the plugin never loads web fonts. If the accent colour and its text, or the text and the panel background, are too close for everyone to read (below WCAG AA, 4.5:1), the Branding tab shows a warning; you can still save.

### Styling from a theme

Everything below can be set in the theme's CSS without copying templates. Settings › Branding writes the same variables (on `.dgb-w, .dgb-o`), so a theme rule with higher specificity, for example `body .dgb-w { … }`, wins over both.

| CSS variable | Default | Used for |
|---|---|---|
| `--dgb-accent` | `#b45309` | Buttons, chosen day and option, focus rings, links, ticks in tour lists |
| `--dgb-on-accent` | `#fff` | Text on the accent colour |
| `--dgb-ink` | `#1c1917` | Text |
| `--dgb-muted` | `#57534e` | Hints, labels, the price basis |
| `--dgb-line` | `#d6d3d1` | Borders and table lines |
| `--dgb-soft` | `#f5f5f4` | Soft backgrounds (summaries, closed days) |
| `--dgb-bg` | `#fff` | Panel, cards, inputs, the mobile bar |
| `--dgb-danger` | `#991b1b` | Errors |
| `--dgb-radius` | `12px` | Corners (panels a little rounder, inputs at most 8px) |
| `--dgb-font` | the theme's | Font family inside the widget and the tour detail outputs |
| `--dgb-btn-transform` | `none` | Button text transform |

| Class | What it is |
|---|---|
| `.dgb-w` | The booking widget (a `section`); `.dgb-w-done` when it shows a booking's confirmation; `.is-stepped` when it shows one step at a time; `.dgb-portal` is the layer on `<body>` that holds the open phone sheet |
| `.dgb-body`, `.dgb-live`, `.dgb-review`, `.dgb-cal-selected`, `.dgb-phone-code`, `.dgb-pay-only`, `.dgb-consent`, `.dgb-note-toggle` | Scrolling part of the form, live total of step 1, review summary, chosen date in words, country code box, single payment line, terms and opt-in, “Add a note” |
| `.dgb-day.is-selected`, `.is-today`, `.is-open`, `.is-closed`, `.is-past` | Calendar day states |
| `.dgb-teaser`, `.dgb-teaser-price` | Price line and Book button above the closed widget on phones |
| `.dgb-panel`, `.dgb-head`, `.dgb-title`, `.dgb-close`, `.dgb-from`, `.dgb-progress`, `.dgb-form`, `.dgb-foot` | Panel (the bottom sheet on phones), its header with title, close button, “From” price and step counter, the form and its footer |
| `.dgb-step`, `.dgb-legend`, `.dgb-group`, `.dgb-field`, `.dgb-label`, `.dgb-hint`, `.dgb-field-error`, `.dgb-errors`, `.dgb-note` | A step (`data-step` = choose, guests, pickup, extras, details, pay), its heading, field groups, fields, labels, hints and errors |
| `.dgb-options`, `.dgb-options-2`, `.dgb-card`, `.dgb-card-body`, `.dgb-card-title`, `.dgb-card-meta` | Choice cards (options, sessions, pickup, extras, payment methods) |
| `.dgb-cal`, `.dgb-cal-head`, `.dgb-cal-month`, `.dgb-cal-grid`, `.dgb-cal-wd`, `.dgb-day` | Calendar |
| `.dgb-sessions` | Sessions of the chosen day |
| `.dgb-stepper`, `.dgb-count`, `.dgb-count-label`, `.dgb-count-note`, `.dgb-icon-btn` | Guest and vehicle counters |
| `.dgb-pickup`, `.dgb-meet`, `.dgb-zone-note`, `.dgb-phone`, `.dgb-coupon-row` | Pickup choice, meeting point details, zone message, phone with country code, coupon field |
| `.dgb-breakdown`, `.dgb-lines`, `.dgb-line` (`.is-total`, `.is-vat`, `.is-discount`…), `.dgb-price-errors`, `.dgb-offer-hint`, `.dgb-second` | Price breakdown, its lines, problems, “add one more” offer hint, second-currency amount |
| `.dgb-btn`, `.dgb-btn-primary`, `.dgb-btn-quiet`, `.dgb-submit` | Buttons |
| `.dgb-sticky` | Mobile bar; `body.dgb-has-sticky-bar` while it shows |
| `.dgb-result`, `.dgb-confirm`, `.dgb-confirm-title`, `.dgb-ref`, `.dgb-summary`, `.dgb-box`, `.dgb-bank`, `.dgb-copyable`, `.dgb-pay`, `.dgb-pay-button`, `.dgb-pay-element`, `.dgb-pay-message`, `.dgb-proof`, `.dgb-proof-row`, `.dgb-proof-files`, `.dgb-actions`, `.dgb-money` | Confirmation and booking page: reference, summary, bank details, payment box, screenshot upload |
| `.dgb-credit` | “Booking by Digitum” |
| `.dgb-o` | Every tour detail output, with `.dgb-from-price` (`__amount`, `__basis`, `--request`), `.dgb-price-table` (`__option`, `__duration`, `__price`, `__share`), `.dgb-tour-list` (`--check`, `--cross`, `--plain`, `__item`), `.dgb-tour-facts` (`__item`, `__item--duration` … `--sessions`, `__list`) |

## Every day

- **Dashboard**: today's sessions (bookings, guests, pickups, unpaid), revenue for the last 7 and 30 days, open quote requests and bank transfers waiting, each with **Mark as paid** and the screenshot when the guest sent one.
- **Manifest**: pick a day (and a tour). Each session lists guests, phone, adults / children / infants, vehicles, pickup hotel and window, the vehicle or guide you assign, and the amount to collect. **Print view** prints on A4 landscape; **CSV** opens in Excel or Google Sheets.
- **Check-in** (guides, on a phone): tap **Arrived** or **No-show** for each booking; **Undo** fixes a wrong tap. Amounts still to collect are shown in red.

## Bookings

- **Bookings** lists everything. Use the status tabs, the filters (tour, dates, source, payment method) and the search box (reference, name, email or phone). Tick bookings to cancel them or erase guest data in bulk. **Export CSV** exports what the filters show.
- Open a booking to see the trip, guest, payments, notes, emails and timeline. From there you can:
  - **Confirm** a booking that waits for payment (e.g. paid by another channel), **Cancel** it (the guest is emailed), mark the guest **arrived** or **no-show**.
  - **Change date or session**: the guest gets a "booking changed" email and the reminder moves.
  - **Payments**: Mark as paid (bank transfer), record cash / bank / card-terminal payments, refund card or PayPal payments (sent to Stripe or PayPal), copy the payment link for the balance.
  - **Send a quote** for price-on-request tours: enter the price; the guest gets a payment link.
  - **Internal notes** (staff only), **Email the confirmation again**, **Resend** any email from the log.
  - **Erase guest data** when a guest asks: name, email, phone, hotel and screenshots are removed; the booking and its amounts stay for reports.

## Calendar

Month, week or day view. Choose a tour to see all its sessions, close or open a day (**Close day**) or a single session (**Close**), and see bookings and guests per session. Drag a booking to another day (or to another session in the day view) to move it; the guest is emailed. Dates closed by a range in the tour's Booking settings › Rules are changed there.

## Emails

Digitum Bookings › **Emails** lists every email (to the guest and to staff). Switch each on or off, edit the subject and message (merge tags such as `{guest_name}`, `{date}`, `{pickup_window}`, `{payment_link}`), **Preview** it with your latest booking, or go back to the default wording. **Send test email** checks that the site can send mail; install an SMTP plugin if test emails do not arrive.

Settings › **Emails**: sender name and address, staff recipients (comma-separated), reminder time (24 hours before by default), the daily summary of tomorrow's bookings (18:00 by default), and a webhook URL for Zapier, Make or GoHighLevel.

## Search engines and AI assistants

- Every tour that takes bookings tells search engines what it is and what it costs (structured data). The price is always the one the page shows. Tours with a price on request never show a price. If Rank Math or Yoast SEO is active, the tour joins their data automatically; you do not need a Product schema in Rank Math for tours (if one exists, Digitum Bookings replaces it on tour pages).
- **Star ratings** (Settings › Schema & SEO › **Rating source**) only come from real reviews. With **None** (the default) no rating is shown to search engines. If the **Site Reviews** plugin is active it appears in the list: assign each review to its tour in Site Reviews, and the tour gets the average and count of its approved reviews plus the ten latest. **Tours without reviews** decides whether such a tour shows no rating (default) or the average of all reviews on the site. **Custom** is for a developer who connects another review platform.
- Give each tour a **featured image**; Google only shows product results with an image. More pictures can go in Booking settings › Schema & SEO › **Gallery images**.
- **/llms.txt** (link in Settings › Schema & SEO) lists your tours, prices, sessions with pickup times, pickup areas, meeting point and contact details for AI assistants such as ChatGPT. It updates within the hour when you change a tour. Write one or two sentences about the business in **llms.txt introduction**.

## Tools: importing tours and spreadsheets

Digitum Bookings › **Tools** (administrators only):

- **Import tours from the Traveler theme**: the list shows each Traveler tour with the options found in its "Duration | Old Price | New Price" table. Tick the tours, choose how tours with a table are priced (per vehicle for rentals priced per vehicle), and import. URLs, titles, text and images stay as they are. Old prices are not copied: a crossed-out price only shows during a dated promotion (set in Booking settings › Options). Read the log: tours without seats in their title, or without any price, need a look in their Booking settings. Tick **Switch on bookings** only after checking, or switch each tour on yourself.
- **Demo data** (administrators): nothing is created until you import a pack. The list shows each available pack with what it creates (tours, options, sessions, add-ons, zones, meeting points, coupons, offers, settings). Click **Import…**, check the summary and confirm. Tours are added as drafts with bookings off; a tour whose URL slug already exists is skipped (your post is never changed), and settings you already filled in are kept. The report lists what to check on each tour. Importing the same pack again does nothing; to import another pack, remove the current one first. While a pack is active, admin pages show a reminder (each administrator can dismiss it).
- **Remove demo data…** shows what will be deleted and asks you to confirm. Demo tours you edited, or that already have bookings, are listed: choose **Keep as my own tour** (it stays and is no longer demo data) or **Delete** (a tour with bookings goes to the trash instead, so the bookings keep it). Bookings are never deleted. Everything else the pack created is deleted, and the settings it changed are put back, unless you changed them yourself after the import. Meeting points still used by one of your tours, and zones used by a booking, are kept.
- **CSV export / import**: download tours, options or sessions, change them in Excel or Google Sheets, and upload the file again. Rows with an id update that row; rows without one are added. Tick the "delete" box only when the file holds the complete list for those tours. The report lists every line that failed.

## Privacy requests

When a guest asks for their data, use WordPress's **Tools › Export Personal Data** with their email: the file includes their bookings, uploaded screenshots and the emails sent to them. **Tools › Erase Personal Data** removes their name, email, phone, nationality, requests, hotel and screenshots from every booking; the bookings and amounts stay for your accounts. Nothing is ever deleted automatically. Settings › Privacy › Policy Guide has suggested wording for your privacy policy.

## Coupons

Digitum Bookings › **Coupons**: add a code (letters and numbers), a discount (a percentage of the price before VAT, or a fixed amount off the booking), the tours it is for (none ticked = all), how many bookings may use it and the days it works. Guests type the code in the payment step of the booking widget; the field only shows while at least one coupon can be used. A wrong or expired code is explained to the guest and ignored. The list shows how often each code was used; the code is also on the booking, in the CSV export and in webhooks.

## Offers

Digitum Bookings › **Offers** (booking managers): discounts that apply on their own, without a code. There are none until you add one.

- **Name** is for you; the **public label** is what guests see in the price breakdown (for example “Free hotel pickup on 2 or more vehicles”).
- **Effect**: a percentage off the booking, an amount off, a free add-on (choose “Hotel pickup” to make every tour's pickup add-on free), or a fixed price per person, vehicle or car.
- **Dates** with **tour dates** or **days the booking is made**; empty means no limit.
- **Conditions**, all optional and all must be met: tours, pricing type, number of vehicles or cars (at least / at most), number of guests, weekdays of the tour date, a minimum booking value, and specific options or sessions.
- **Priority**: one offer per booking; the highest priority wins, then the bigger saving.
- **Tell guests who nearly qualify** shows “Add 1 more vehicle to get: …” in the widget when one or two more vehicles, cars or guests are missing (it needs a public label).
- A coupon is not added on top of an offer unless Settings › Payments › **Coupons and offers** allows it; otherwise the guest gets whichever saves more, and is told when a code cannot be combined.
- An offer without any condition lowers the prices shown on the tour page (the old price is crossed out while it runs) and in search results, with the offer's last day as the price's end date. Other offers never change the shown “From” price.
- The booking keeps the offer and the saving; Reports has an **Offers** table.

## Reports

Digitum Bookings › **Reports** for any period (or Last 7 days, Last 30 days, This month, Last month, This year): bookings made, sold (confirmed, checked in or no-show), booked value, money collected (payments minus refunds), conversion (sold ÷ bookings made), cancellation rate and quote requests; then the same figures **by tour** and **by source** (from ads, organic, or taken by staff), and what each coupon brought in.

## Updates

New versions are published as GitHub releases. WordPress shows them under **Dashboard › Updates** and on the Plugins screen, where automatic updates can be switched on. **Settings › Updates** shows the installed and latest version and has **Check for updates now**. If the GitHub repository is made private, paste a read-only token there.

## Arabic

The plugin is translated into Arabic. When the site language (Settings › General › Site Language) or a user's profile language is Arabic, the booking widget, emails and admin screens are in Arabic and the widget reads right to left. Tour names, options and session names are your own text: write them in the language your guests read.

## Who can do what

| Role | Can |
|---|---|
| Administrator | everything, including Settings, Emails and Tools |
| Booking manager | everything except Settings, Emails and Tools: bookings, calendar, payments, coupons, reports, pickup zones, tours |
| Tour guide | Dashboard (today), Manifest (view and print), Check-in |
