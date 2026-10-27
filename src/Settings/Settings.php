<?php
/**
 * Plugin settings: field definitions, defaults, reading and sanitizing.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Settings;

use Digitum\Bookings\Services\Ratings\RatingSources;

defined( 'ABSPATH' ) || exit;

/**
 * All settings live in one option, grouped by tab: `dgb_settings[tab][key]`.
 */
final class Settings {

	public const OPTION = 'dgb_settings';

	/**
	 * Merged settings for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $cache = null;

	/**
	 * Settings tabs in display order.
	 *
	 * @return array<string, string> Tab id => label.
	 */
	public static function tabs(): array {
		return array(
			'general'   => __( 'General', 'digitum-bookings' ),
			'currency'  => __( 'Currency & VAT', 'digitum-bookings' ),
			'payments'  => __( 'Payments', 'digitum-bookings' ),
			'emails'    => __( 'Emails', 'digitum-bookings' ),
			'analytics' => __( 'Analytics', 'digitum-bookings' ),
			'seo'       => __( 'Schema & SEO', 'digitum-bookings' ),
			'privacy'   => __( 'Privacy', 'digitum-bookings' ),
			'branding'  => __( 'Branding', 'digitum-bookings' ),
			'updates'   => __( 'Updates', 'digitum-bookings' ),
		);
	}

	/**
	 * Field definitions per tab.
	 *
	 * Supported types: text, email, tel, url, textarea, number, checkbox, select, multicheck, color, key, slug,
	 * secret (never shown again; empty input keeps it) and heading (a section title, not stored).
	 * Keys starting with an underscore are internal: stored, never rendered.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function fields(): array {
		return array(
			'general'   => array(
				'business_name'    => array(
					'type'    => 'text',
					'label'   => __( 'Business name', 'digitum-bookings' ),
					'default' => '',
				),
				'phone'            => array(
					'type'    => 'tel',
					'label'   => __( 'Phone', 'digitum-bookings' ),
					'default' => '',
				),
				'email'            => array(
					'type'    => 'email',
					'label'   => __( 'Email', 'digitum-bookings' ),
					'default' => '',
				),
				'address'          => array(
					'type'    => 'textarea',
					'label'   => __( 'Address', 'digitum-bookings' ),
					'help'    => __( 'Used in emails, schema and llms.txt.', 'digitum-bookings' ),
					'default' => '',
				),
				'product_mode'     => array(
					'type'    => 'select',
					'label'   => __( 'Tours come from', 'digitum-bookings' ),
					'options' => array(
						'own'    => __( 'Digitum Bookings “Tours” post type', 'digitum-bookings' ),
						'attach' => __( 'An existing post type on this site', 'digitum-bookings' ),
					),
					'default' => 'own',
					'help'    => __( 'Attach to an existing post type to keep its URLs. Changing this refreshes permalinks once.', 'digitum-bookings' ),
				),
				'attach_post_type' => array(
					'type'    => 'select',
					'label'   => __( 'Existing post type', 'digitum-bookings' ),
					'options' => array( self::class, 'attachable_post_types' ),
					'default' => '',
					'help'    => __( 'Used when “Tours come from” is set to an existing post type. If that post type later disappears (for example the theme is removed), Digitum Bookings registers it with the same name and URL slug.', 'digitum-bookings' ),
				),
				'_attach_slug'     => array(
					'type'    => 'slug',
					'default' => '',
				),
				'own_cpt_key'      => array(
					'type'    => 'key',
					'label'   => __( 'Post type name', 'digitum-bookings' ),
					'default' => 'dgb_tour',
					'help'    => __( 'Internal name, 20 characters at most. Only change this on a new site.', 'digitum-bookings' ),
				),
				'own_cpt_slug'     => array(
					'type'    => 'slug',
					'label'   => __( 'URL slug', 'digitum-bookings' ),
					'default' => 'tour',
					'help'    => __( 'Tours appear at /slug/tour-name/.', 'digitum-bookings' ),
				),
				'auto_insert'      => array(
					'type'    => 'checkbox',
					'label'   => __( 'Booking widget', 'digitum-bookings' ),
					'text'    => __( 'Add the booking widget to single tour pages automatically', 'digitum-bookings' ),
					'default' => true,
				),
				'sticky_bar'       => array(
					'type'    => 'checkbox',
					'label'   => __( 'Mobile sticky bar', 'digitum-bookings' ),
					'text'    => __( 'Show price and Book button at the bottom of the screen on phones', 'digitum-bookings' ),
					'default' => true,
				),
				'hold_minutes'     => array(
					'type'    => 'number',
					'label'   => __( 'Unpaid hold (card, PayPal)', 'digitum-bookings' ),
					'suffix'  => __( 'minutes', 'digitum-bookings' ),
					'min'     => 5,
					'max'     => 120,
					'step'    => 1,
					'default' => 15,
				),
				'terms_url'        => array(
					'type'    => 'url',
					'label'   => __( 'Booking terms page', 'digitum-bookings' ),
					'help'    => __( 'Link shown next to “I accept the booking terms”.', 'digitum-bookings' ),
					'default' => '',
				),
				'cancellation'     => array(
					'type'    => 'textarea',
					'label'   => __( 'Cancellation policy (short)', 'digitum-bookings' ),
					'help'    => __( 'One or two sentences shown at payment and on the confirmation.', 'digitum-bookings' ),
					'default' => '',
				),
				'country_code'     => array(
					'type'    => 'tel',
					'label'   => __( 'Default phone country code', 'digitum-bookings' ),
					'default' => '+971',
				),
			),
			'currency'  => array(
				'currency'        => array(
					'type'    => 'select',
					'label'   => __( 'Currency', 'digitum-bookings' ),
					'options' => array( self::class, 'currencies' ),
					'default' => 'AED',
				),
				'vat_mode'        => array(
					'type'    => 'select',
					'label'   => __( 'Prices are entered', 'digitum-bookings' ),
					'options' => array(
						'inclusive' => __( 'Including VAT', 'digitum-bookings' ),
						'exclusive' => __( 'Excluding VAT', 'digitum-bookings' ),
					),
					'default' => 'inclusive',
				),
				'vat_rate'        => array(
					'type'    => 'number',
					'label'   => __( 'VAT rate', 'digitum-bookings' ),
					'suffix'  => '%',
					'min'     => 0,
					'max'     => 50,
					'step'    => 0.01,
					'default' => 5,
				),
				'trn'             => array(
					'type'    => 'text',
					'label'   => __( 'Tax registration number (TRN)', 'digitum-bookings' ),
					'help'    => __( 'Shown on receipts.', 'digitum-bookings' ),
					'default' => '',
				),
				'weekend_days'    => array(
					'type'    => 'multicheck',
					'label'   => __( 'Weekend days', 'digitum-bookings' ),
					'help'    => __( 'A tour’s weekend uplift applies on these days.', 'digitum-bookings' ),
					'options' => array(
						'1' => __( 'Monday', 'digitum-bookings' ),
						'2' => __( 'Tuesday', 'digitum-bookings' ),
						'3' => __( 'Wednesday', 'digitum-bookings' ),
						'4' => __( 'Thursday', 'digitum-bookings' ),
						'5' => __( 'Friday', 'digitum-bookings' ),
						'6' => __( 'Saturday', 'digitum-bookings' ),
						'7' => __( 'Sunday', 'digitum-bookings' ),
					),
					'default' => array( '6', '7' ),
				),
				'second_heading'  => array(
					'type'    => 'heading',
					'label'   => __( 'Second currency', 'digitum-bookings' ),
					'help'    => __( 'Shows an approximate amount in another currency next to prices, for example for visitors from abroad. Guests are always charged in your currency; emails, receipts and structured data only use your currency.', 'digitum-bookings' ),
					'default' => null,
				),
				'second_enabled'  => array(
					'type'    => 'checkbox',
					'label'   => __( 'Second currency', 'digitum-bookings' ),
					'text'    => __( 'Show prices in a second currency', 'digitum-bookings' ),
					'default' => false,
				),
				'second_currency' => array(
					'type'    => 'select',
					'label'   => __( 'Second currency', 'digitum-bookings' ),
					'options' => array( self::class, 'second_currencies' ),
					'default' => '',
				),
				'second_rate'     => array(
					'type'    => 'number',
					'label'   => __( 'Exchange rate', 'digitum-bookings' ),
					'suffix'  => __( 'of your currency = 1 unit of the second currency', 'digitum-bookings' ),
					'help'    => __( 'Not updated automatically. With no rate the second amount stays hidden.', 'digitum-bookings' ),
					'min'     => 0,
					'max'     => 1000000,
					'step'    => 0.000001,
					'default' => 0,
				),
				'second_rounding' => array(
					'type'    => 'select',
					'label'   => __( 'Round the second amount to', 'digitum-bookings' ),
					'options' => array(
						'whole'    => __( 'Whole units', 'digitum-bookings' ),
						'decimals' => __( 'Two decimals', 'digitum-bookings' ),
					),
					'default' => 'whole',
				),
				'second_template' => array(
					'type'    => 'text',
					'label'   => __( 'Wording', 'digitum-bookings' ),
					/* translators: {amount} is a placeholder: keep it as it is. */
					'help'    => __( '{amount} is replaced by the converted amount. Empty: “about {amount}” in the visitor’s language.', 'digitum-bookings' ),
					'default' => '',
				),
			),
			'payments'  => array(
				'arrival_enabled'            => array(
					'type'    => 'checkbox',
					'label'   => __( 'Pay on arrival', 'digitum-bookings' ),
					'text'    => __( 'Guests can book now and pay cash or card at the meeting point', 'digitum-bookings' ),
					'default' => true,
				),
				'stripe_heading'             => array(
					'type'    => 'heading',
					'label'   => 'Stripe',
					'help'    => __( 'Cards, Apple Pay and Google Pay. Card details go straight from the guest to Stripe; this site never sees them.', 'digitum-bookings' ),
					'default' => null,
				),
				'stripe_enabled'             => array(
					'type'    => 'checkbox',
					'label'   => __( 'Card payments', 'digitum-bookings' ),
					'text'    => __( 'Accept cards, Apple Pay and Google Pay through Stripe', 'digitum-bookings' ),
					'default' => false,
				),
				'stripe_mode'                => array(
					'type'    => 'select',
					'label'   => __( 'Stripe mode', 'digitum-bookings' ),
					'options' => array(
						'test' => __( 'Test (no real money)', 'digitum-bookings' ),
						'live' => __( 'Live', 'digitum-bookings' ),
					),
					'default' => 'test',
				),
				'stripe_test_publishable'    => array(
					'type'    => 'text',
					'label'   => __( 'Test publishable key', 'digitum-bookings' ),
					'help'    => __( 'Starts with pk_test_. Stripe › Developers › API keys.', 'digitum-bookings' ),
					'default' => '',
				),
				'stripe_test_secret'         => array(
					'type'    => 'secret',
					'label'   => __( 'Test secret key', 'digitum-bookings' ),
					'help'    => __( 'Starts with sk_test_ (or a restricted key rk_test_ with PaymentIntents and Refunds write access).', 'digitum-bookings' ),
					'default' => '',
				),
				'stripe_test_webhook_secret' => array(
					'type'    => 'secret',
					'label'   => __( 'Test webhook signing secret', 'digitum-bookings' ),
					'help'    => __( 'Starts with whsec_. Add the webhook URL shown above in Stripe › Developers › Webhooks with the events payment_intent.succeeded, payment_intent.payment_failed and charge.refunded.', 'digitum-bookings' ),
					'default' => '',
				),
				'stripe_live_publishable'    => array(
					'type'    => 'text',
					'label'   => __( 'Live publishable key', 'digitum-bookings' ),
					'default' => '',
				),
				'stripe_live_secret'         => array(
					'type'    => 'secret',
					'label'   => __( 'Live secret key', 'digitum-bookings' ),
					'default' => '',
				),
				'stripe_live_webhook_secret' => array(
					'type'    => 'secret',
					'label'   => __( 'Live webhook signing secret', 'digitum-bookings' ),
					'default' => '',
				),
				'paypal_heading'             => array(
					'type'    => 'heading',
					'label'   => 'PayPal',
					'help'    => __( 'PayPal Checkout. Create a REST app at developer.paypal.com to get the client id and secret.', 'digitum-bookings' ),
					'default' => null,
				),
				'paypal_enabled'             => array(
					'type'    => 'checkbox',
					'label'   => __( 'PayPal', 'digitum-bookings' ),
					'text'    => __( 'Accept PayPal', 'digitum-bookings' ),
					'default' => false,
				),
				'paypal_mode'                => array(
					'type'    => 'select',
					'label'   => __( 'PayPal mode', 'digitum-bookings' ),
					'options' => array(
						'sandbox' => __( 'Sandbox (no real money)', 'digitum-bookings' ),
						'live'    => __( 'Live', 'digitum-bookings' ),
					),
					'default' => 'sandbox',
				),
				'paypal_client_id'           => array(
					'type'    => 'text',
					'label'   => __( 'Client id', 'digitum-bookings' ),
					'default' => '',
				),
				'paypal_secret'              => array(
					'type'    => 'secret',
					'label'   => __( 'Secret', 'digitum-bookings' ),
					'default' => '',
				),
				'paypal_webhook_id'          => array(
					'type'    => 'text',
					'label'   => __( 'Webhook id', 'digitum-bookings' ),
					'help'    => __( 'Add the webhook URL shown above to your PayPal app with the events Payment capture completed, denied and refunded, then paste the webhook id here.', 'digitum-bookings' ),
					'default' => '',
				),
				'paypal_currency'            => array(
					'type'    => 'select',
					'label'   => __( 'Charge PayPal payments in', 'digitum-bookings' ),
					'options' => array( self::class, 'paypal_currencies' ),
					'help'    => __( 'PayPal does not accept AED, SAR, OMR, QAR, BHD or KWD. Amounts in those currencies are converted to this currency, and the guest sees both amounts before paying.', 'digitum-bookings' ),
					'default' => 'USD',
				),
				'paypal_rate'                => array(
					'type'    => 'number',
					'label'   => __( 'Exchange rate', 'digitum-bookings' ),
					'suffix'  => __( 'of your currency = 1 unit of the PayPal currency', 'digitum-bookings' ),
					'help'    => __( 'For AED and USD the official rate is 3.6725. Only used when your currency is not accepted by PayPal.', 'digitum-bookings' ),
					'min'     => 0.0001,
					'max'     => 100000,
					'step'    => 0.0001,
					'default' => 3.6725,
				),
				'deposit_heading'            => array(
					'type'    => 'heading',
					'label'   => __( 'Deposits', 'digitum-bookings' ),
					'default' => null,
				),
				'deposit_enabled'            => array(
					'type'    => 'checkbox',
					'label'   => __( 'Deposits', 'digitum-bookings' ),
					'text'    => __( 'Let guests pay part now and the rest on arrival', 'digitum-bookings' ),
					'help'    => __( 'When this is off, guests always pay the full amount or choose pay on arrival. Each tour can turn deposits off or use its own percentage.', 'digitum-bookings' ),
					'default' => false,
				),
				'deposit_percent'            => array(
					'type'    => 'number',
					'label'   => __( 'Deposit percentage', 'digitum-bookings' ),
					'suffix'  => '%',
					'min'     => 1,
					'max'     => 99,
					'step'    => 1,
					'default' => 20,
				),
				'deposit_methods'            => array(
					'type'    => 'multicheck',
					'label'   => __( 'Deposit allowed with', 'digitum-bookings' ),
					'options' => array(
						'stripe'        => __( 'Stripe', 'digitum-bookings' ),
						'paypal'        => __( 'PayPal', 'digitum-bookings' ),
						'bank_transfer' => __( 'Bank transfer', 'digitum-bookings' ),
					),
					'default' => array( 'stripe', 'paypal', 'bank_transfer' ),
				),
				'offers_stack'               => array(
					'type'    => 'checkbox',
					'label'   => __( 'Coupons and offers', 'digitum-bookings' ),
					'text'    => __( 'Let guests use a coupon on top of an automatic offer', 'digitum-bookings' ),
					'help'    => __( 'Off: a booking gets either the offer or the coupon, whichever saves the guest more.', 'digitum-bookings' ),
					'default' => false,
				),
				'bank_heading'               => array(
					'type'    => 'heading',
					'label'   => __( 'Bank transfer', 'digitum-bookings' ),
					'default' => null,
				),
				'bank_enabled'               => array(
					'type'    => 'checkbox',
					'label'   => __( 'Bank transfer', 'digitum-bookings' ),
					'text'    => __( 'Offer bank transfer (fill in the bank details below)', 'digitum-bookings' ),
					'default' => false,
				),
				'bank_account_name'          => array(
					'type'    => 'text',
					'label'   => __( 'Bank transfer: account name', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_name'                  => array(
					'type'    => 'text',
					'label'   => __( 'Bank name', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_iban'                  => array(
					'type'    => 'text',
					'label'   => __( 'IBAN', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_account_number'        => array(
					'type'    => 'text',
					'label'   => __( 'Account number', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_swift'                 => array(
					'type'    => 'text',
					'label'   => __( 'SWIFT / BIC', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_instructions'          => array(
					'type'    => 'textarea',
					'label'   => __( 'Instructions for the guest', 'digitum-bookings' ),
					'default' => __( 'Use your booking reference as the payment reference. After paying, upload a screenshot of the transfer on the booking page. We confirm your booking by email once the payment is checked.', 'digitum-bookings' ),
				),
				'bank_upload'                => array(
					'type'    => 'checkbox',
					'label'   => __( 'Screenshot upload', 'digitum-bookings' ),
					'text'    => __( 'Let guests upload the transfer screenshot on the booking page or from the email link', 'digitum-bookings' ),
					'default' => true,
				),
				'bank_whatsapp'              => array(
					'type'    => 'tel',
					'label'   => __( 'WhatsApp number (other way to send it)', 'digitum-bookings' ),
					'help'    => __( 'Shown as a small link under the upload box. Leave empty to hide.', 'digitum-bookings' ),
					'default' => '',
				),
				'bank_hold_hours'            => array(
					'type'    => 'number',
					'label'   => __( 'Hold bank transfer bookings for', 'digitum-bookings' ),
					'suffix'  => __( 'hours', 'digitum-bookings' ),
					'help'    => __( 'If the booking is not marked as paid by then, it is cancelled and both sides get an email.', 'digitum-bookings' ),
					'min'     => 1,
					'max'     => 168,
					'step'    => 1,
					'default' => 24,
				),
			),
			'emails'    => array(
				'from_name'        => array(
					'type'    => 'text',
					'label'   => __( 'From name', 'digitum-bookings' ),
					'help'    => __( 'Empty: the business name (Settings › General), or the site title.', 'digitum-bookings' ),
					'default' => '',
				),
				'from_email'       => array(
					'type'    => 'email',
					'label'   => __( 'From email', 'digitum-bookings' ),
					'help'    => __( 'Use an address on your own domain, and an SMTP plugin so emails are delivered reliably. Empty: the business email.', 'digitum-bookings' ),
					'default' => '',
				),
				'admin_recipients' => array(
					'type'    => 'text',
					'label'   => __( 'Staff recipients', 'digitum-bookings' ),
					'help'    => __( 'Comma-separated email addresses for staff emails. Empty: the business email, or the site admin email.', 'digitum-bookings' ),
					'default' => '',
				),
				'reminder_hours'   => array(
					'type'    => 'number',
					'label'   => __( 'Reminder', 'digitum-bookings' ),
					'suffix'  => __( 'hours before the session (0 = no reminder)', 'digitum-bookings' ),
					'min'     => 0,
					'max'     => 168,
					'step'    => 1,
					'default' => 24,
				),
				'summary_enabled'  => array(
					'type'    => 'checkbox',
					'label'   => __( 'Daily summary', 'digitum-bookings' ),
					'text'    => __( 'Email staff a summary of tomorrow’s bookings every day', 'digitum-bookings' ),
					'default' => true,
				),
				'summary_time'     => array(
					'type'    => 'select',
					'label'   => __( 'Send the summary at', 'digitum-bookings' ),
					'options' => array( self::class, 'hours' ),
					'default' => '18:00',
				),
				'webhook_url'      => array(
					'type'    => 'url',
					'label'   => __( 'Webhook URL', 'digitum-bookings' ),
					'help'    => __( 'Optional. Booking and payment events are sent here as signed JSON (Zapier, Make, GoHighLevel…). See docs/DEVELOPER-HOOKS.md.', 'digitum-bookings' ),
					'default' => '',
				),
				'webhook_secret'   => array(
					'type'    => 'secret',
					'label'   => __( 'Webhook signing secret', 'digitum-bookings' ),
					'help'    => __( 'Each request carries X-DGB-Signature: sha256=HMAC of "timestamp.body" with this secret.', 'digitum-bookings' ),
					'default' => '',
				),
			),
			'analytics' => array(
				'datalayer'            => array(
					'type'    => 'checkbox',
					'label'   => __( 'dataLayer events', 'digitum-bookings' ),
					'text'    => __( 'Push GA4 ecommerce events (view_item … purchase, generate_lead) to dataLayer for Google Tag Manager', 'digitum-bookings' ),
					'help'    => __( 'No tags are fired directly; Google Tag Manager owns all tags.', 'digitum-bookings' ),
					'default' => true,
				),
				'enhanced_conversions' => array(
					'type'    => 'checkbox',
					'label'   => __( 'Enhanced conversions', 'digitum-bookings' ),
					'text'    => __( 'Add the guest’s hashed (SHA-256) email and phone to the purchase event', 'digitum-bookings' ),
					'default' => true,
				),
				'attribution_days'     => array(
					'type'    => 'number',
					'label'   => __( 'Attribution cookie', 'digitum-bookings' ),
					'suffix'  => __( 'days (UTM and gclid, first-party)', 'digitum-bookings' ),
					'min'     => 1,
					'max'     => 365,
					'step'    => 1,
					'default' => 90,
				),
				'turnstile_site_key'   => array(
					'type'    => 'text',
					'label'   => __( 'Cloudflare Turnstile site key', 'digitum-bookings' ),
					'help'    => __( 'Optional bot check on booking and enquiry forms. A hidden honeypot field is always on.', 'digitum-bookings' ),
					'default' => '',
				),
				'turnstile_secret'     => array(
					'type'    => 'text',
					'label'   => __( 'Cloudflare Turnstile secret key', 'digitum-bookings' ),
					'default' => '',
				),
			),
			'seo'       => array(
				'schema_output'   => array(
					'type'    => 'select',
					'label'   => __( 'Structured data', 'digitum-bookings' ),
					'options' => array(
						'auto'       => __( 'Automatic: inside Rank Math or Yoast when active, otherwise on its own', 'digitum-bookings' ),
						'standalone' => __( 'Always on its own', 'digitum-bookings' ),
						'off'        => __( 'Off', 'digitum-bookings' ),
					),
					'help'    => __( 'Tour pages get Product or TouristTrip JSON-LD with the same prices the page shows. In automatic mode it joins the SEO plugin’s graph and replaces any other Product node on the page.', 'digitum-bookings' ),
					'default' => 'auto',
				),
				'business_type'   => array(
					'type'    => 'select',
					'label'   => __( 'Business type', 'digitum-bookings' ),
					'options' => array(
						'TravelAgency'  => __( 'Travel agency / tour operator', 'digitum-bookings' ),
						'LocalBusiness' => __( 'Local business', 'digitum-bookings' ),
						'Organization'  => __( 'Organization', 'digitum-bookings' ),
					),
					'help'    => __( 'Used when no SEO plugin describes the business. Name, phone, email and address come from General.', 'digitum-bookings' ),
					'default' => 'TravelAgency',
				),
				'rating_source'   => array(
					'type'    => 'select',
					'label'   => __( 'Rating source', 'digitum-bookings' ),
					'options' => array( RatingSources::class, 'options' ),
					'help'    => __( 'Star ratings in search results come only from real reviews. Review plugins appear here when they are active (Site Reviews: assign each review to its tour).', 'digitum-bookings' ),
					'default' => 'none',
				),
				'rating_fallback' => array(
					'type'    => 'select',
					'label'   => __( 'Tours without reviews', 'digitum-bookings' ),
					'options' => array(
						'none' => __( 'No rating', 'digitum-bookings' ),
						'site' => __( 'Use the average of all reviews on the site', 'digitum-bookings' ),
					),
					'default' => 'none',
				),
				'llms_enabled'    => array(
					'type'    => 'checkbox',
					'label'   => __( 'llms.txt', 'digitum-bookings' ),
					'text'    => __( 'Serve /llms.txt with your tours, prices, sessions, pickup and contact details for AI assistants', 'digitum-bookings' ),
					'default' => true,
				),
				'llms_intro'      => array(
					'type'    => 'textarea',
					'label'   => __( 'llms.txt introduction', 'digitum-bookings' ),
					'help'    => __( 'One or two sentences about the business. Empty uses the site tagline.', 'digitum-bookings' ),
					'default' => '',
				),
			),
			'privacy'   => array(
				'delete_on_uninstall' => array(
					'type'    => 'checkbox',
					'label'   => __( 'On uninstall', 'digitum-bookings' ),
					'text'    => __( 'Delete all booking tables and settings when the plugin is deleted', 'digitum-bookings' ),
					'help'    => __( 'Off keeps your data. Guest data is never deleted automatically; erase it from a booking when needed.', 'digitum-bookings' ),
					'default' => false,
				),
			),
			'branding'  => array(
				'about'        => array(
					'type'    => 'heading',
					'label'   => __( 'Digitum Bookings by Digitum Marketing', 'digitum-bookings' ),
					'help'    => __( '“Booking by Digitum” is shown under every booking widget and booking page.', 'digitum-bookings' ),
					'default' => '',
				),
				'accent_color' => array(
					'type'    => 'color',
					'label'   => __( 'Widget accent colour', 'digitum-bookings' ),
					'default' => '#B45309',
				),
			),
			'updates'   => array(
				'github_token' => array(
					'type'    => 'secret',
					'label'   => __( 'GitHub token', 'digitum-bookings' ),
					'help'    => __( 'Only needed while the GitHub repository is private: a fine-grained token with read-only access to its contents. Updates come from the latest release on github.com/digitumarketing/digitum-bookings.', 'digitum-bookings' ),
					'default' => '',
				),
			),
		);
	}

	/**
	 * Default values for every field.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::fields() as $tab => $fields ) {
			foreach ( $fields as $key => $field ) {
				if ( 'heading' === $field['type'] ) {
					continue;
				}
				$defaults[ $tab ][ $key ] = $field['default'];
			}
		}
		return $defaults;
	}

	/**
	 * Whole hours of the day for time selects.
	 *
	 * @return array<string, string>
	 */
	public static function hours(): array {
		$hours = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$value           = sprintf( '%02d:00', $h );
			$hours[ $value ] = $value;
		}
		return $hours;
	}

	/**
	 * Currencies a site can use (ISO 4217).
	 *
	 * @return array<string, string>
	 */
	public static function currencies(): array {
		$codes = array( 'AED', 'AUD', 'BHD', 'BRL', 'CAD', 'CHF', 'CNY', 'CZK', 'DKK', 'EGP', 'EUR', 'GBP', 'HKD', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JOD', 'JPY', 'KES', 'KRW', 'KWD', 'LKR', 'MAD', 'MXN', 'MYR', 'NOK', 'NZD', 'OMR', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'SAR', 'SEK', 'SGD', 'THB', 'TND', 'TRY', 'TWD', 'TZS', 'USD', 'VND', 'ZAR' );
		return array_combine( $codes, $codes );
	}

	/**
	 * Second currency choices (none first).
	 *
	 * @return array<string, string>
	 */
	public static function second_currencies(): array {
		return array( '' => __( '— Choose —', 'digitum-bookings' ) ) + self::currencies();
	}

	/**
	 * Currencies PayPal accepts.
	 *
	 * @return array<string, string>
	 */
	public static function paypal_currencies(): array {
		return array_combine( \Digitum\Bookings\Gateways\PayPal::CURRENCIES, \Digitum\Bookings\Gateways\PayPal::CURRENCIES );
	}

	/**
	 * Public post types an admin can attach to (ours excluded).
	 *
	 * @return array<string, string>
	 */
	public static function attachable_post_types(): array {
		$options = array( '' => __( '— Choose —', 'digitum-bookings' ) );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( in_array( $type->name, array( 'attachment', 'dgb_tour' ), true ) ) {
				continue;
			}
			$options[ $type->name ] = sprintf( '%s (%s)', $type->labels->name, $type->name );
		}
		return $options;
	}

	/**
	 * Every setting, stored values merged over defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			$merged = self::defaults();
			foreach ( $merged as $tab => $values ) {
				if ( isset( $stored[ $tab ] ) && is_array( $stored[ $tab ] ) ) {
					$merged[ $tab ] = array_merge( $values, array_intersect_key( $stored[ $tab ], $values ) );
				}
			}
			$this->cache = $merged;
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $tab Tab id.
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function get( string $tab, string $key ) {
		$all = $this->all();
		return $all[ $tab ][ $key ] ?? null;
	}

	/**
	 * Save values for one or more tabs (already sanitized).
	 *
	 * @param array<string, array<string, mixed>> $values Values by tab.
	 */
	public function update( array $values ): void {
		$all = $this->all();
		foreach ( $values as $tab => $tab_values ) {
			if ( isset( $all[ $tab ] ) && is_array( $tab_values ) ) {
				$all[ $tab ] = array_merge( $all[ $tab ], array_intersect_key( $tab_values, $all[ $tab ] ) );
			}
		}
		update_option( self::OPTION, $all );
		$this->cache = null;
	}

	/**
	 * Forget the cached values (after the option changed elsewhere).
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Sanitize one submitted tab and merge it into the current settings.
	 *
	 * Fields of other tabs are kept as they are; an unchecked checkbox in the submitted tab becomes false.
	 *
	 * @param string               $tab     Submitted tab id.
	 * @param array<string, mixed> $input   Raw input for that tab.
	 * @param array<string, mixed> $current Current settings (all tabs).
	 * @return array<string, array<string, mixed>> Settings to store.
	 */
	public static function sanitize_tab( string $tab, array $input, array $current ): array {
		$fields = self::fields();
		$result = array_replace_recursive( self::defaults(), $current );
		$result = array_intersect_key( $result, $fields );
		if ( ! isset( $fields[ $tab ] ) ) {
			return $result;
		}
		foreach ( $fields[ $tab ] as $key => $field ) {
			if ( str_starts_with( $key, '_' ) || 'heading' === $field['type'] ) {
				continue;
			}
			// A secret left empty keeps the saved value (the form never shows it).
			if ( 'secret' === $field['type'] && '' === trim( (string) ( $input[ $key ] ?? '' ) ) ) {
				continue;
			}
			$result[ $tab ][ $key ] = self::sanitize_value( $field, $input[ $key ] ?? null );
		}
		return $result;
	}

	/**
	 * Sanitize one value by its field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Raw value (null when not submitted).
	 * @return mixed
	 */
	public static function sanitize_value( array $field, $value ) {
		$default = $field['default'];
		switch ( $field['type'] ) {
			case 'checkbox':
				return ! empty( $value );

			case 'multicheck':
				$allowed = array_map( 'strval', array_keys( self::resolve_options( $field ) ) );
				$value   = is_array( $value ) ? array_map( 'strval', $value ) : array();
				return array_values( array_intersect( $allowed, $value ) );

			case 'select':
				$value = is_scalar( $value ) ? (string) $value : '';
				return array_key_exists( $value, self::resolve_options( $field ) ) ? $value : $default;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$number = (float) $value;
				if ( isset( $field['min'] ) ) {
					$number = max( (float) $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( (float) $field['max'], $number );
				}
				$is_int = ! isset( $field['step'] ) || floor( (float) $field['step'] ) === (float) $field['step'];
				if ( $is_int ) {
					return (int) round( $number );
				}
				// Keep as many decimals as the step has (at least 2): step 0.0001 keeps 4.
				$step_decimals = strlen( rtrim( substr( sprintf( '%.6F', (float) $field['step'] ), -6 ), '0' ) );
				return round( $number, max( 2, $step_decimals ) );

			case 'email':
				return is_string( $value ) ? sanitize_email( $value ) : '';

			case 'tel':
				return is_string( $value ) ? trim( (string) preg_replace( '/[^0-9+()\-\s]/', '', $value ) ) : '';

			case 'url':
				return is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';

			case 'textarea':
				return is_string( $value ) ? sanitize_textarea_field( $value ) : '';

			case 'secret':
				return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

			case 'color':
				$color = is_string( $value ) ? sanitize_hex_color( $value ) : null;
				return $color ? $color : $default;

			case 'key':
				$key = is_string( $value ) ? substr( sanitize_key( $value ), 0, 20 ) : '';
				return '' !== $key ? $key : $default;

			case 'slug':
				$slug = is_string( $value ) ? sanitize_title( $value ) : '';
				return '' !== $slug ? $slug : $default;

			default:
				return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
	}

	/**
	 * Options of a select or multicheck field (they may be a callable).
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, string>
	 */
	public static function resolve_options( array $field ): array {
		$options = $field['options'] ?? array();
		if ( is_callable( $options ) ) {
			$options = call_user_func( $options );
		}
		return is_array( $options ) ? $options : array();
	}
}
