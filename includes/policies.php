<?php
/**
 * The four policy pages: terms, privacy, refunds and cancellation.
 *
 * Each is a real page at its own URL rather than a section of the landing
 * page, because that is what a payment provider reviewing the business asks
 * for — a link it can open, in the site's own branding, saying the same thing
 * the checkout does.
 *
 * The copy lives here rather than in WordPress so it cannot be edited into
 * disagreeing with the plugin: the price comes from the same setting the
 * pricing card reads, and the delivery timeline is the same list the pricing
 * section prints. Anything a customer could hold us to is written once.
 *
 * @package bluegroup-project-afristream
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where to write when any of this needs answering. There is no registered
 * company behind AfriStream and no trading address to publish, so this is the
 * contact route, everywhere, in every document.
 */
const AFRISTREAM_CONTACT = 'support@afristream.io';

/**
 * When these documents last changed. Fixed rather than generated: a policy
 * page that prints today's date every day is claiming a review that never
 * happened. Move it by hand when the wording actually moves.
 */
const AFRISTREAM_POLICIES_UPDATED = '27 August 2026';

/**
 * What the customer gets, and how soon.
 *
 * Printed on the pricing section as well as in the terms — a delivery timeline
 * that only exists inside a policy page is not the "clearly visible" a payment
 * reviewer means.
 */
const AFRISTREAM_DELIVERY = array(
	'Portal access, the written setup guides and the search are live the moment your payment clears.',
	'Your setup session is booked and held within 2 business days of you asking for it.',
	'A device we source for you is set up, updated and posted within 5 to 10 business days of its price being agreed with you.',
);

/**
 * The documents themselves.
 *
 * Each section is a heading plus a list of blocks. A block is either a string,
 * printed as a paragraph, or array( 'list' => array( … ) ), printed as a
 * bulleted list. Two shapes is all these pages have ever needed, and a third
 * would be a reason to reach for the editor instead.
 */
const AFRISTREAM_POLICIES = array(
	'terms'        => array(
		'template' => 'afristream-terms.php',
		'slug'     => 'terms',
		'label'    => 'AfriStream — Terms of Service',
		'title'    => 'Terms of Service',
		'lede'     => 'What you are buying from AfriStream, what we do for you, and what we do not.',
		'sections' => array(
			array(
				'h'    => 'Who we are',
				'body' => array(
					'AfriStream is an independent device-setup and content-discovery service. We are a small operation, reachable by email at ' . AFRISTREAM_CONTACT . ', and we answer as a person rather than a ticket queue.',
					'These terms apply to everyone who buys an AfriStream subscription. Buying one means accepting them.',
				),
			),
			array(
				'h'    => 'What the subscription buys',
				'body' => array(
					'One annual subscription covering all of the following:',
					array(
						'list' => array(
							'Guided setup for your streaming device, with a real person',
							'Written step-by-step setup guides for every device we cover',
							'A search that tells you which of the services you already pay for is carrying a title',
							'The AfriStream customer portal',
							'Support by email when something stops working',
						),
					),
				),
			),
			array(
				'h'    => 'What it is not',
				'body' => array(
					'This is the part worth reading twice. AfriStream does not host, stream, supply, share or resell any video, channel, film, series, sports feed or subscription. We are not affiliated with, endorsed by, or acting for any streaming service or broadcaster, and we do not sell access to anybody else’s service.',
					'Everything you watch plays in the provider’s own app, on an account you hold and pay for directly with that provider. Cancelling AfriStream does not cancel any of them, and subscribing to AfriStream does not give you any of them.',
					'All service names and logos referred to on this site are the trademarks of their respective owners.',
				),
			),
			array(
				'h'    => 'What you need to bring',
				'body' => array(
					array(
						'list' => array(
							'A streaming device — a smart TV, stick, box, laptop, phone or tablet — either one you already own or one we source for you',
							'Your own internet connection',
							'Your own accounts with whichever streaming services you want to watch',
						),
					),
				),
			),
			array(
				'h'    => 'Price and payment',
				'body' => array(
					'The subscription is [[price]] a year. If you ask us to source a device for you, the setup work on that device is charged once at [[setup_fee]], and the device itself is quoted and agreed with you in writing before anything is ordered.',
					'Payment is taken through SureCart and billed in South African Rand. Prices shown elsewhere on this site in other currencies are converted for reference only — the amount charged is the Rand amount, and your bank sets the rate you actually pay. Card details are handled by the payment provider and never reach us.',
				),
			),
			array(
				'h'    => 'When you get it',
				'body' => array(
					'',
					array( 'list' => AFRISTREAM_DELIVERY ),
					'Business days mean Monday to Friday, excluding public holidays. If anything is going to take longer than this, we tell you rather than letting the date pass.',
				),
			),
			array(
				'h'    => 'Term, renewal and cancellation',
				'body' => array(
					'A subscription runs for one year from the day you pay. You can cancel at any time and keep your access until that year is up. Cancelling stops the subscription renewing.',
					'The full detail is in our Cancellation Policy.',
				),
			),
			array(
				'h'    => 'Refunds',
				'body' => array(
					'We refund the subscription in full within 14 days of payment, as long as your setup session has not taken place yet. The full detail, including what is not refundable, is in our Refund Policy.',
				),
			),
			array(
				'h'    => 'Fair use',
				'body' => array(
					'A subscription covers one household. Please do not ask us to help you get around a provider’s terms, share an account you are not allowed to share, or reach content you are not entitled to — we will say no, and repeated requests are grounds for us ending the subscription.',
				),
			),
			array(
				'h'    => 'What we cannot promise',
				'body' => array(
					'We have no control over the streaming services you subscribe to. We cannot promise that any of them will be available, will keep carrying a particular title, or will work on a particular device, and we are not responsible when they change, break or withdraw something.',
					'The catalogue information in the portal comes from third-party sources and can be incomplete or out of date. Treat it as a good guide to where to look, not as a guarantee.',
					'The service is provided as it is. Nothing here limits any rights you have under consumer law that cannot be limited.',
				),
			),
			array(
				'h'    => 'Changes to these terms',
				'body' => array(
					'We may update these terms. The current version is always the one on this page, with the date it last changed at the top. If a change materially affects what you have already paid for, we will email you about it.',
				),
			),
			array(
				'h'    => 'Getting in touch',
				'body' => array(
					'Questions about any of this go to ' . AFRISTREAM_CONTACT . '.',
				),
			),
		),
	),
	'privacy'      => array(
		'template' => 'afristream-privacy.php',
		'slug'     => 'privacy',
		'label'    => 'AfriStream — Privacy Policy',
		'title'    => 'Privacy Policy',
		'lede'     => 'What we hold about you, why, and how to get it back or get rid of it.',
		'sections' => array(
			array(
				'h'    => 'Who is responsible for your information',
				'body' => array(
					'AfriStream. Write to ' . AFRISTREAM_CONTACT . ' about anything on this page and a person will answer.',
				),
			),
			array(
				'h'    => 'What we collect',
				'body' => array(
					'Only what running the service actually needs:',
					array(
						'list' => array(
							'Your name and email address, so we can set your account up and talk to you',
							'The AfriStream app profile credentials we issue to you',
							'Whatever you choose to tell us in a support email',
							'Your order and subscription record, held by our payment provider',
							'Ordinary server logs from our web host, which include IP addresses and are kept for security and diagnostics',
						),
					),
					'We run no analytics, no advertising, no tracking pixels and no third-party cookies. Nobody is following you around this site, because there is nothing here doing the following.',
				),
			),
			array(
				'h'    => 'Payment details',
				'body' => array(
					'Payments are processed by SureCart. Your card number never reaches AfriStream and we could not store it if we wanted to. SureCart holds the order and subscription record under its own privacy terms.',
				),
			),
			array(
				'h'    => 'What we use it for',
				'body' => array(
					array(
						'list' => array(
							'Setting you up and giving you access to the portal',
							'Answering your support emails',
							'Taking payment and keeping a record of what you bought',
							'Emailing you about something that materially affects your subscription',
						),
					),
					'We do not sell your information, rent it, or share it for anyone else’s marketing.',
				),
			),
			array(
				'h'    => 'Who else touches it',
				'body' => array(
					array(
						'list' => array(
							'SureCart, our payment provider, which processes your payment and holds the order record',
							'Our web host and email provider, which necessarily handle the data passing through them',
						),
					),
					'The portal also queries The Movie Database for film and series information. Those requests carry no personal information about you — they ask about titles, not about people.',
				),
			),
			array(
				'h'    => 'Cookies and what your browser stores',
				'body' => array(
					'The public site sets no tracking cookies. It does remember your chosen display currency in your own browser’s local storage; that setting never leaves your device and never reaches us.',
					'The customer portal uses a normal WordPress login session so it can tell it is you. That is what keeps you signed in, and nothing else.',
				),
			),
			array(
				'h'    => 'How long we keep it',
				'body' => array(
					'Your account details for as long as you are a customer, and for a reasonable period afterwards in case you come back or a billing question comes up. Support emails for as long as they are useful for supporting you. Payment records for as long as we are required to keep them.',
				),
			),
			array(
				'h'    => 'Your rights',
				'body' => array(
					'Email ' . AFRISTREAM_CONTACT . ' and ask us for a copy of what we hold about you, to correct something that is wrong, or to delete your information altogether. We will do it, and we will not make you jump through hoops. Deleting your information means ending your subscription, because we cannot run the service without it.',
				),
			),
			array(
				'h'    => 'Changes to this policy',
				'body' => array(
					'The current version is the one on this page, dated at the top.',
				),
			),
		),
	),
	'refunds'      => array(
		'template' => 'afristream-refunds.php',
		'slug'     => 'refund-policy',
		'label'    => 'AfriStream — Refund Policy',
		'title'    => 'Refund Policy',
		'lede'     => 'Fourteen days to change your mind, as long as we have not done the work yet.',
		'sections' => array(
			array(
				'h'    => 'The short version',
				'body' => array(
					'We refund your subscription in full, within 14 days of payment, as long as your setup session has not taken place. Email ' . AFRISTREAM_CONTACT . ' and ask.',
				),
			),
			array(
				'h'    => 'What is refundable',
				'body' => array(
					'The annual subscription fee, in full, if both of these are true:',
					array(
						'list' => array(
							'It is within 14 days of the day you paid',
							'Your guided setup session has not happened yet',
						),
					),
					'The reason for the second condition is simple: the setup session is a person’s time, and once it has been spent it cannot be handed back. Everything else in the subscription — the guides, the search, the portal — stays refundable for the full 14 days whether you have used it or not.',
				),
			),
			array(
				'h'    => 'What is not refundable',
				'body' => array(
					array(
						'list' => array(
							'A device we have already ordered for you. It is sourced specifically for your TV and your connection, on a price you agreed in writing before we bought it.',
							'The device setup fee, once that device has been set up and posted.',
							'The subscription, once your setup session has taken place, or once 14 days have passed.',
						),
					),
					'If a device has not yet been ordered, there is nothing to refund and nothing to return — we simply cancel it.',
				),
			),
			array(
				'h'    => 'How to ask for one',
				'body' => array(
					'Email ' . AFRISTREAM_CONTACT . ' from the address you ordered with, and say you would like a refund. You do not have to give a reason. We will not try to talk you out of it.',
				),
			),
			array(
				'h'    => 'How long it takes',
				'body' => array(
					'We process an agreed refund within 5 business days, back to the card or account you paid from. Your bank usually takes a few days longer than that to show it, which is out of our hands.',
				),
			),
			array(
				'h'    => 'A note on currency',
				'body' => array(
					'You are billed in South African Rand, so refunds are made in Rand, for the Rand amount you paid. If your bank converted that into another currency when you paid, the amount that lands back may differ slightly from what left — exchange rates move between the two dates, and neither we nor your bank can freeze them.',
				),
			),
			array(
				'h'    => 'After 14 days',
				'body' => array(
					'You can still cancel at any time. Cancelling stops the subscription renewing and leaves your access running to the end of the year you paid for. See our Cancellation Policy.',
				),
			),
			array(
				'h'    => 'If something is our fault',
				'body' => array(
					'None of the above limits what you are owed if we fail to deliver what you paid for. Tell us what went wrong and we will put it right or refund it, whichever you would rather have.',
				),
			),
		),
	),
	'cancellation' => array(
		'template' => 'afristream-cancellation.php',
		'slug'     => 'cancellation-policy',
		'label'    => 'AfriStream — Cancellation Policy',
		'title'    => 'Cancellation Policy',
		'lede'     => 'Cancel whenever you like. You keep what you paid for until the year is up.',
		'sections' => array(
			array(
				'h'    => 'The short version',
				'body' => array(
					'Email ' . AFRISTREAM_CONTACT . ' and say you would like to cancel. Your access carries on until the end of the year you have already paid for, and then stops. No notice period, no cancellation fee, no phone call to sit through.',
				),
			),
			array(
				'h'    => 'How to cancel',
				'body' => array(
					'Email ' . AFRISTREAM_CONTACT . ' from the address you ordered with. We will confirm it in writing, usually the same working day. If you have not had a confirmation from us within 2 business days, assume the email went astray and send it again — do not assume you are still being billed.',
				),
			),
			array(
				'h'    => 'What happens next',
				'body' => array(
					array(
						'list' => array(
							'Your portal access, guides, search and support carry on until the last day of the year you paid for',
							'The subscription does not renew, and no further payment is taken',
							'Your account is closed at the end of the term',
						),
					),
				),
			),
			array(
				'h'    => 'Cancelling and refunds are different things',
				'body' => array(
					'Cancelling stops the next payment. It does not refund the year you are in. If you are still inside the 14-day window and want your money back rather than the rest of the year, that is a refund — see our Refund Policy, which is the more generous of the two if you qualify for it.',
				),
			),
			array(
				'h'    => 'Devices',
				'body' => array(
					'A device you bought through us is yours. Cancelling the subscription does not affect it, and we will not ask for it back.',
					'Your own streaming subscriptions are unaffected too — they were never ours to cancel. If you want to stop paying for Netflix or Showmax, you have to cancel those with the providers directly.',
				),
			),
			array(
				'h'    => 'If we end a subscription',
				'body' => array(
					'We may end a subscription if it is being used against the fair use terms, or if a payment fails and is not put right. We will tell you why first and give you a chance to fix it.',
					'If we end your subscription for any reason other than that, we refund the unused part of the year.',
				),
			),
		),
	),
);

/**
 * One policy document's definition.
 *
 * @param string $key A key of AFRISTREAM_POLICIES.
 * @return array<string,mixed>|null
 */
function afristream_policy( $key ) {
	return isset( AFRISTREAM_POLICIES[ $key ] ) ? AFRISTREAM_POLICIES[ $key ] : null;
}

/**
 * Fill the placeholders the copy leaves for the plugin's own settings.
 *
 * The price in the terms has to be the price on the pricing card, and both
 * have to be the price the checkout charges. Writing it out as text here would
 * mean an admin could change the price in Settings and leave the terms quoting
 * last year's — which is the kind of disagreement a customer gets to hold us
 * to. It also picks up the currency switcher for free.
 *
 * @param string $text Copy possibly containing [[price]] or [[setup_fee]].
 * @return string HTML.
 */
function afristream_policy_fill( $text ) {
	return str_replace(
		array( '[[price]]', '[[setup_fee]]' ),
		array( afristream_price( afristream_landing_price() ), afristream_price( afristream_landing_setup_fee() ) ),
		esc_html( $text )
	);
}

/**
 * One document, as the article between the site header and footer.
 *
 * @param string $key A key of AFRISTREAM_POLICIES.
 * @return string
 */
function afristream_policy_article( $key ) {
	$policy = afristream_policy( $key );
	if ( ! $policy ) {
		return '';
	}

	$sections = '';
	foreach ( $policy['sections'] as $section ) {
		$blocks = '';
		foreach ( $section['body'] as $block ) {
			if ( is_array( $block ) && isset( $block['list'] ) ) {
				$items = '';
				foreach ( $block['list'] as $item ) {
					$items .= '<li>' . esc_html( $item ) . '</li>';
				}
				$blocks .= '<ul class="as-doc-list">' . $items . '</ul>';
				continue;
			}
			if ( '' === $block ) {
				continue;
			}
			$blocks .= '<p>' . afristream_policy_fill( $block ) . '</p>';
		}

		$sections .= '
      <section class="as-doc-sec" data-doc-section>
        <h2>' . esc_html( $section['h'] ) . '</h2>
        ' . $blocks . '
      </section>';
	}

	return '
<main class="as-doc" data-testid="policy-' . esc_attr( $key ) . '">
  <article class="as-doc-in" data-reveal>
    <header class="as-doc-head">
      <span class="as-eyebrow">Legal</span>
      <h1>' . esc_html( $policy['title'] ) . '</h1>
      <p class="as-doc-lede">' . esc_html( $policy['lede'] ) . '</p>
      <p class="as-doc-date" data-testid="policy-updated">Last updated ' . esc_html( AFRISTREAM_POLICIES_UPDATED ) . '</p>
    </header>' . $sections . '
    <p class="as-doc-foot">' . afristream_policy_other_links( $key ) . '</p>
  </article>
</main>';
}

/**
 * The other three documents, linked from the bottom of this one.
 *
 * A reviewer opening any one policy should be one click from the rest, and a
 * customer reading about refunds is usually about to want the cancellation
 * terms too.
 *
 * @param string $key The document being read.
 * @return string
 */
function afristream_policy_other_links( $key ) {
	$links = array();
	foreach ( AFRISTREAM_POLICIES as $other => $policy ) {
		if ( $other === $key ) {
			continue;
		}
		$url = afristream_policy_url( $other );
		if ( $url ) {
			$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $policy['title'] ) . '</a>';
		}
	}
	return $links ? 'See also: ' . implode( ' · ', $links ) : '';
}

/**
 * The published page carrying a given policy template, if there is one.
 *
 * Found by template rather than by a setting, for the same reason the portal
 * page is detected: four more settings fields to fill in correctly is four
 * more chances to publish a footer linking at nothing.
 *
 * @param string $key A key of AFRISTREAM_POLICIES.
 * @return string The permalink, or '' when no page uses that template.
 */
function afristream_policy_url( $key ) {
	$pages = afristream_policy_pages();
	return isset( $pages[ $key ] ) ? $pages[ $key ] : '';
}

/**
 * Every policy page on the site, keyed by document.
 *
 * Resolved once and held for the rest of the request: the footer asks for
 * these on every page render, and each document's "see also" asks again.
 *
 * @return array<string,string>
 */
function afristream_policy_pages() {
	if ( isset( $GLOBALS['afristream_policy_pages'] ) ) {
		return $GLOBALS['afristream_policy_pages'];
	}

	$found = array();

	foreach ( AFRISTREAM_POLICIES as $key => $policy ) {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_wp_page_template',
						'value' => $policy['template'],
					),
				),
			)
		);
		// The lowest-numbered published page wins, so publishing a second copy
		// of a policy never silently takes the footer link over.
		if ( $pages ) {
			$found[ $key ] = (string) get_permalink( $pages[0] );
		}
	}

	$GLOBALS['afristream_policy_pages'] = $found;
	return $found;
}

/**
 * Where we record that the pages have been created.
 *
 * Holds the install version rather than a boolean, so a later release that
 * adds a fifth document can create just that one by bumping the number.
 */
const AFRISTREAM_POLICIES_INSTALLED_OPTION = 'afristream_policies_installed';

/** Bump when a document is added that existing sites need a page for. */
const AFRISTREAM_POLICIES_INSTALL_VERSION = 1;

/**
 * Publish a page for every policy that has not got one.
 *
 * Runs once. The record of having run is what stops it recreating a page an
 * admin has deliberately deleted — being handed your own legal pages is
 * helpful, having them reappear every time you get rid of one is not.
 */
function afristream_policy_install() {
	if ( (int) get_option( AFRISTREAM_POLICIES_INSTALLED_OPTION, 0 ) >= AFRISTREAM_POLICIES_INSTALL_VERSION ) {
		return;
	}

	foreach ( AFRISTREAM_POLICIES as $key => $policy ) {
		afristream_policy_ensure_page( $key, $policy );
	}

	update_option( AFRISTREAM_POLICIES_INSTALLED_OPTION, AFRISTREAM_POLICIES_INSTALL_VERSION );
	unset( $GLOBALS['afristream_policy_pages'] );
}

/**
 * One policy's page, created only if there is not already one.
 *
 * Three things it must not do: duplicate a page that already carries the
 * template, trample a page the admin has already made at that address, or
 * leave behind a page that renders nothing if somebody later switches the
 * template off. Hence the shortcode in the content — the template ignores it,
 * and it is what saves the page if the template is ever changed.
 *
 * @param string              $key    A key of AFRISTREAM_POLICIES.
 * @param array<string,mixed> $policy That document's definition.
 * @return int The page id, or 0 if there was nothing to do or it failed.
 */
function afristream_policy_ensure_page( $key, array $policy ) {
	if ( afristream_policy_url( $key ) ) {
		return 0;
	}

	// Somebody's own page at this address is adopted, not duplicated: they
	// went to the trouble of making it, and two "Refund Policy" pages is worse
	// than none. Only a published one, though — adopting a draft or something
	// in the trash would leave the footer linking at a page nobody can read.
	$existing = get_page_by_path( $policy['slug'] );
	if ( $existing && 'publish' === $existing->post_status ) {
		update_post_meta( $existing->ID, '_wp_page_template', $policy['template'] );
		return (int) $existing->ID;
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $policy['title'],
			'post_name'    => $policy['slug'],
			'post_content' => '[afristream_policy doc="' . $key . '"]',
		)
	);

	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}

	update_post_meta( $id, '_wp_page_template', $policy['template'] );
	return (int) $id;
}

// On activation, and again on the first admin screen after an update — a
// plugin updated in place never fires its activation hook, and this shipped
// after the first release that had the templates.
register_activation_hook( dirname( __DIR__ ) . '/bluegroup-project-afristream.php', 'afristream_policy_install' );
add_action( 'admin_init', 'afristream_policy_install' );

/**
 * Offer all four templates in the page editor's Template dropdown.
 *
 * @param array<string,string> $templates Registered page templates.
 * @return array<string,string>
 */
function afristream_policy_register_templates( $templates ) {
	foreach ( AFRISTREAM_POLICIES as $policy ) {
		$templates[ $policy['template'] ] = $policy['label'];
	}
	return $templates;
}
add_filter( 'theme_page_templates', 'afristream_policy_register_templates' );

/**
 * Render the whole document when the page's template is one of ours.
 *
 * Same shape as afristream_landing_template(), including hooking the enqueue
 * rather than calling it: the policy pages carry the landing header and footer,
 * so they need the same stylesheet, the same menu script and the same currency
 * switcher.
 *
 * @param string $template The template WordPress was going to use.
 * @return string
 */
function afristream_policy_template( $template ) {
	if ( ! is_page() ) {
		return $template;
	}

	$slug = get_page_template_slug( get_queried_object_id() );
	$key  = '';
	foreach ( AFRISTREAM_POLICIES as $policy_key => $policy ) {
		if ( $policy['template'] === $slug ) {
			$key = $policy_key;
			break;
		}
	}
	if ( '' === $key ) {
		return $template;
	}

	add_action( 'wp_enqueue_scripts', 'afristream_landing_enqueue' );

	// Printed here rather than returned: this IS the document.
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( wp_get_document_title() ); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'afristream-landing-page' ); ?>>
<?php wp_body_open(); ?>
<?php
	echo afristream_policy_body( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	wp_footer();
?>
</body>
</html>
	<?php
	exit;
}
add_filter( 'template_include', 'afristream_policy_template' );

/**
 * A policy page's body: the site header, the document, the site footer.
 *
 * @param string $key A key of AFRISTREAM_POLICIES.
 * @return string
 */
function afristream_policy_body( $key ) {
	$base = afristream_landing_url();

	return afristream_landing_open()
		. afristream_landing_header( $base )
		. afristream_policy_article( $key )
		. afristream_landing_footer( $base )
		. '</div>';
}

/**
 * [afristream_policy doc="refunds"] — the same document inside an existing page.
 *
 * @param array<string,string> $atts Shortcode attributes.
 * @return string
 */
function afristream_policy_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'doc' => 'terms' ), $atts, 'afristream_policy' );
	if ( ! afristream_policy( $atts['doc'] ) ) {
		return '';
	}
	afristream_landing_enqueue();
	return afristream_policy_article( $atts['doc'] );
}
add_shortcode( 'afristream_policy', 'afristream_policy_shortcode' );
