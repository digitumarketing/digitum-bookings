# Digitum Bookings: admin guide

For the people who run bookings day to day. Menu: **Digitum Bookings** (or the name set in Settings › Branding).

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

## Who can do what

| Role | Can |
|---|---|
| Administrator | everything |
| Booking manager | everything except Settings and Emails |
| Tour guide | Dashboard (today), Manifest (view and print), Check-in |
