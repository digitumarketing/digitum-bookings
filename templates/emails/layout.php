<?php
/**
 * Email layout. Override in a theme at digitum-bookings/emails/layout.php.
 *
 * @package Digitum\Bookings
 *
 * @var array $view subject, body (HTML, already escaped), business, accent, footer.
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $view['subject'] ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;color:#1c1917;line-height:1.5">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f4">
	<tr>
		<td align="center" style="padding:24px 12px">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden">
				<tr>
					<td style="background:<?php echo esc_attr( $view['accent'] ); ?>;color:#ffffff;padding:16px 24px;font-size:18px;font-weight:700"><?php echo esc_html( $view['business'] ); ?></td>
				</tr>
				<tr>
					<td style="padding:24px;font-size:15px">
						<?php echo $view['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template HTML (wp_kses_post) with escaped merge tag values. ?>
					</td>
				</tr>
				<?php if ( '' !== $view['footer'] ) : ?>
					<tr>
						<td style="padding:12px 24px;border-top:1px solid #e7e5e4;color:#57534e;font-size:12px"><?php echo esc_html( $view['footer'] ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
		</td>
	</tr>
</table>
</body>
</html>
