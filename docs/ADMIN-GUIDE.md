# Digitum Bookings: admin guide

For the people who run bookings day to day. Menu: **Digitum Bookings**.

## Getting started

1. **Install**: Plugins › Add New › Upload Plugin with `digitum-bookings.zip` from the latest GitHub release, then activate. If the site already has tours under `/tour/` (for example the Traveler theme's), the plugin uses them and their URLs stay the same (Settings › General › “Tours come from”).
2. **Settings › General**: business name, phone, email and address (used in emails, the confirmation page, structured data and llms.txt), booking terms link, cancellation summary, hold time for card payments.
3. **Settings › Currency & VAT**: currency, VAT rate and whether prices include it, weekend days.
4. **Pickup zones**: the areas you pick up from (and those you do not, with the message guests see), plus meeting points with address, map link and arrival instructions.
5. **Tours**: open a tour, fill in **Booking settings** (Pricing, Options, Sessions, Add-ons, Pickup & location, Rules, Schema & SEO), then tick **Take bookings for this tour**. The booking widget appears on the tour page.
6. **Settings › Payments**: switch on pay on arrival, bank transfer (with your account details), Stripe and PayPal (start in test mode), and deposits if you take them.
7. **Settings › Emails**: sender, staff recipients, reminder time; **Digitum Bookings › Emails › Send test email** checks that mail arrives (install an SMTP plugin if it does not).
8. For desertbookings.com, **Tools › Import Desert Bookings demo data** does steps 2 to 5 in one go with the agreed tours and prices; every tour stays switched off until you check its [CONFIRM] items.

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
- Give each tour a **featured image**; Google only shows product results with an image. More pictures can go in Booking settings › Schema & SEO › **Gallery images**.
- **/llms.txt** (link in Settings › Schema & SEO) lists your tours, prices, sessions with pickup times, pickup areas, meeting point and contact details for AI assistants such as ChatGPT. It updates within the hour when you change a tour. Write one or two sentences about the business in **llms.txt introduction**.

## Tools: importing tours and spreadsheets

Digitum Bookings › **Tools** (administrators only):

- **Import tours from the Traveler theme**: the list shows each Traveler tour with the options found in its "Duration | Old Price | New Price" table. Tick the tours, choose how tours with a table are priced (per vehicle for buggies, quads and bikes), and import. URLs, titles, text and images stay as they are. Old prices are not copied: a crossed-out price only shows during a dated promotion (set in Booking settings › Options). Read the log: tours without seats in their title, or without any price, need a look in their Booking settings. Tick **Switch on bookings** only after checking, or switch each tour on yourself.
- **Import Desert Bookings demo data** (administrators): sets up the business details, AED with 5% VAT, the Dubai / Sharjah / Abu Dhabi zones, the Al Aweer meeting point and all tours of the site with their options, sessions and pickup rules. Existing tours keep their URL, title and text; missing ones are added as drafts; your own settings are never overwritten and running it twice changes nothing. Bookings stay off on every tour: the report lists what to confirm first (session times, prices marked [CONFIRM], payment keys, the meeting point pin).
- **CSV export / import**: download tours, options or sessions, change them in Excel or Google Sheets, and upload the file again. Rows with an id update that row; rows without one are added. Tick the "delete" box only when the file holds the complete list for those tours. The report lists every line that failed.

## Privacy requests

When a guest asks for their data, use WordPress's **Tools › Export Personal Data** with their email: the file includes their bookings, uploaded screenshots and the emails sent to them. **Tools › Erase Personal Data** removes their name, email, phone, nationality, requests, hotel and screenshots from every booking; the bookings and amounts stay for your accounts. Nothing is ever deleted automatically. Settings › Privacy › Policy Guide has suggested wording for your privacy policy.

## Coupons

Digitum Bookings › **Coupons**: add a code (letters and numbers), a discount (a percentage of the price before VAT, or a fixed amount off the booking), the tours it is for (none ticked = all), how many bookings may use it and the days it works. Guests type the code in the payment step of the booking widget; the field only shows while at least one coupon can be used. A wrong or expired code is explained to the guest and ignored. The list shows how often each code was used; the code is also on the booking, in the CSV export and in webhooks.

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
