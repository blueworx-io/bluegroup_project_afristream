<?php
/**
 * Legacy shortcodes ported from the "afristream code snippets" plugin so it can
 * be retired without breaking pages that still reference these tags:
 *
 *   [user_acf_fields]      — the logged-in user's app credentials
 *   [troubleshooting_guide] — the detailed troubleshooting accordion
 *
 * The acf in [user_acf_fields] is now only a name. The fields it shows belong
 * to this plugin (see includes/fields.php); the tag keeps its old spelling
 * because pages in the wild reference it and renaming it would break them.
 *
 * Both are restyled in the AfriStream brand colours. The portal's own Profile
 * tab is the primary surface for this data — these exist for pages built before
 * it and for anyone embedding the details on their own page.
 *
 * @package bluegroup-project-afristream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [user_acf_fields] — display the logged-in user's app credentials.
 */
function afristream_portal_user_acf_fields_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p>Please log in to view your details.</p>';
	}

	$licenses = afristream_portal_user_credentials();

	ob_start();
	?>
	<div class="wso-user-acf-card">
		<div class="wso-user-acf-card__header">
			<h3>Your AfriStream App Profile Details</h3>
			<p>These will be used when accessing any AfriStream platforms or content. These cannot be edited.</p>
		</div>

		<div class="wso-user-acf-card__body">
			<?php if ( ! empty( $licenses ) ) : ?>
				<?php foreach ( $licenses as $index => $license ) : ?>
					<div class="wso-user-acf-license-block">
						<?php if ( ! empty( $license['user'] ) ) : ?>
							<div class="wso-user-acf-row">
								<label class="wso-user-acf-label">Active Username</label>
								<div class="wso-user-acf-copy-group">
									<div
										id="acf-username-<?php echo esc_attr( $index ); ?>"
										class="wso-user-acf-input wso-user-acf-display"
										data-copy-value="<?php echo esc_attr( $license['user'] ); ?>"
									>
										<?php echo esc_html( $license['user'] ); ?>
									</div>
									<button
										type="button"
										class="wso-user-acf-button"
										onclick="navigator.clipboard.writeText(document.getElementById('acf-username-<?php echo esc_js( $index ); ?>').getAttribute('data-copy-value')); this.innerText='Copied'; setTimeout(() => this.innerText='Copy', 1500);"
									>
										Copy
									</button>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $license['pass'] ) ) : ?>
							<div class="wso-user-acf-row">
								<label class="wso-user-acf-label">Password</label>
								<div class="wso-user-acf-copy-group">
									<div
										id="acf-password-<?php echo esc_attr( $index ); ?>"
										class="wso-user-acf-input wso-user-acf-display"
										data-copy-value="<?php echo esc_attr( $license['pass'] ); ?>"
									>
										<?php echo esc_html( $license['pass'] ); ?>
									</div>
									<button
										type="button"
										class="wso-user-acf-button"
										onclick="navigator.clipboard.writeText(document.getElementById('acf-password-<?php echo esc_js( $index ); ?>').getAttribute('data-copy-value')); this.innerText='Copied'; setTimeout(() => this.innerText='Copy', 1500);"
									>
										Copy
									</button>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php else : ?>
				<p>No active license assigned.</p>
			<?php endif; ?>
		</div>
	</div>

	<style>
		.wso-user-acf-card {
			max-width: 100%;
			margin: 30px auto;
			background: #ffffff;
			border: 1px solid #e5e7eb;
			border-radius: 16px;
			box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
			overflow: hidden;
			font-family: inherit;
		}

		.wso-user-acf-card__header {
			padding: 24px 24px 16px;
			background: linear-gradient(135deg, #65009F, #CD2DF5);
			color: #ffffff;
		}

		.wso-user-acf-card__header h3 {
			margin: 0 0 6px;
			font-size: 24px;
			line-height: 1.2;
			color: #ffffff !important;
		}

		.wso-user-acf-card__header p {
			margin: 0;
			font-size: 14px;
			opacity: 0.85;
			color: #ffffff !important;
		}

		.wso-user-acf-card__body {
			padding: 24px;
		}

		.wso-user-acf-license-block + .wso-user-acf-license-block {
			margin-top: 24px;
			padding-top: 24px;
			border-top: 1px solid #e5e7eb;
		}

		.wso-user-acf-row + .wso-user-acf-row {
			margin-top: 20px;
		}

		.wso-user-acf-label {
			display: block;
			margin-bottom: 8px;
			font-size: 14px;
			font-weight: 600;
			color: #374151;
		}

		.wso-user-acf-copy-group {
			display: flex;
			gap: 12px;
			align-items: center;
			flex-wrap: wrap;
		}

		.wso-user-acf-input {
			flex: 1;
			min-width: 240px;
			height: 48px;
			padding: 0 16px;
			border: 1px solid #cbd5e1;
			border-radius: 12px;
			background: #f1f5f9;
			color: #475569;
			font-size: 15px;
			cursor: not-allowed;
			box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.04);
		}

		.wso-user-acf-display {
			display: flex;
			align-items: center;
			width: 100%;
			user-select: text;
			-webkit-user-select: text;
			white-space: nowrap;
			overflow: hidden;
			text-overflow: ellipsis;
		}

		.wso-user-acf-button {
			height: 48px;
			padding: 0 18px;
			border: none;
			border-radius: 12px;
			background: #65009F;
			color: #ffffff;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
			transition: 0.2s ease;
		}

		.wso-user-acf-button:hover {
			background: #4A0073;
			transform: translateY(-1px);
		}

		.wso-user-acf-button:active {
			transform: translateY(0);
		}

		@media (max-width: 640px) {
			.wso-user-acf-copy-group {
				flex-direction: column;
				align-items: stretch;
			}

			.wso-user-acf-button {
				width: 100%;
			}
		}
	</style>
	<?php

	return ob_get_clean();
}
add_shortcode( 'user_acf_fields', 'afristream_portal_user_acf_fields_shortcode' );

/**
 * [troubleshooting_guide] — the detailed troubleshooting accordion.
 */
function afristream_portal_troubleshooting_shortcode() {
	ob_start();
	?>
	<div class="wso-acc-card">
		<div class="wso-acc-card__header">
			<h3>AfriStream Troubleshooting Guide</h3>
			<p>Follow these steps if you are experiencing connection issues.</p>
		</div>
		<div class="wso-acc-card__body">

			<?php
			$sections = afristream_portal_troubleshooting_sections();

			foreach ( $sections as $index => $section ) :
				$panel_id   = 'wso-acc-panel-' . $index;
				$chevron_id = 'wso-acc-chevron-' . $index;
				?>
				<div class="wso-acc-item">
					<button
						class="wso-acc-trigger"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $panel_id ); ?>"
						onclick="wsoAccToggle(this, '<?php echo esc_js( $panel_id ); ?>', '<?php echo esc_js( $chevron_id ); ?>')"
					>
						<div class="wso-acc-trigger-left">
							<span class="wso-acc-badge"><?php echo esc_html( $section['badge'] ); ?></span>
							<span class="wso-acc-title"><?php echo esc_html( $section['title'] ); ?></span>
						</div>
						<svg
							id="<?php echo esc_attr( $chevron_id ); ?>"
							class="wso-acc-chevron"
							viewBox="0 0 24 24"
							fill="none"
							stroke="currentColor"
							stroke-width="2"
							aria-hidden="true"
						>
							<polyline points="6 9 12 15 18 9" />
						</svg>
					</button>
					<div
						id="<?php echo esc_attr( $panel_id ); ?>"
						class="wso-acc-panel"
						role="region"
						aria-hidden="true"
					>
						<div class="wso-acc-content">
							<?php echo wp_kses_post( $section['content'] ); ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>

		</div>
	</div>

	<style>
		.wso-acc-card {
			max-width: 100%;
			margin: 30px auto;
			background: #ffffff;
			border: 1px solid #e5e7eb;
			border-radius: 16px;
			box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
			overflow: hidden;
			font-family: inherit;
		}

		.wso-acc-card__header {
			padding: 24px 24px 16px;
			background: linear-gradient(135deg, #65009F, #CD2DF5);
			color: #ffffff;
		}

		.wso-acc-card__header h3 {
			margin: 0 0 6px;
			font-size: 24px;
			line-height: 1.2;
			color: #ffffff !important;
		}

		.wso-acc-card__header p {
			margin: 0;
			font-size: 14px;
			opacity: 0.85;
			color: #ffffff !important;
		}

		.wso-acc-card__body {
			padding: 16px 24px 24px;
		}

		.wso-acc-item {
			border: 1px solid #e5e7eb;
			border-radius: 12px;
			margin-bottom: 10px;
			overflow: hidden;
		}

		.wso-acc-trigger {
			width: 100%;
			background: #fcfdfe;
			border: none;
			padding: 16px 20px;
			display: flex;
			align-items: center;
			justify-content: space-between;
			cursor: pointer;
			text-align: left;
			gap: 12px;
		}

		.wso-acc-trigger:hover {
			background: #faf5ff;
		}

		.wso-acc-trigger-left {
			display: flex;
			align-items: center;
			gap: 12px;
		}

		.wso-acc-badge {
			background: linear-gradient(135deg, #65009F, #CD2DF5);
			color: #ffffff;
			font-size: 11px;
			font-weight: 700;
			padding: 4px 10px;
			border-radius: 20px;
			white-space: nowrap;
		}

		.wso-acc-title {
			font-size: 15px;
			font-weight: 600;
			color: #1e293b;
		}

		.wso-acc-chevron {
			width: 20px;
			height: 20px;
			color: #64748b;
			flex-shrink: 0;
			transition: transform 0.25s ease;
		}

		.wso-acc-chevron.wso-open {
			transform: rotate(180deg);
		}

		.wso-acc-panel {
			max-height: 0;
			overflow: hidden;
			transition: max-height 0.3s ease;
		}

		.wso-acc-panel.wso-open {
			max-height: 1400px;
		}

		.wso-acc-content {
			padding: 16px 20px 20px;
			border-top: 1px solid #e5e7eb;
			font-size: 14px;
			color: #475569;
			line-height: 1.7;
		}

		.wso-acc-content h4 {
			font-size: 13px;
			font-weight: 700;
			color: #374151;
			margin: 0 0 8px;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}

		.wso-acc-content ul,
		.wso-acc-content ol {
			margin: 8px 0 0;
			padding-left: 20px;
		}

		.wso-acc-content li {
			margin-bottom: 5px;
		}

		.wso-acc-content p {
			margin: 0 0 10px;
		}

		.wso-acc-content code {
			background: #f5e9ff;
			border: 1px solid #e5cbf5;
			border-radius: 6px;
			padding: 2px 8px;
			font-size: 13px;
			font-family: monospace;
			color: #4A0073;
		}

		@media (max-width: 640px) {
			.wso-acc-trigger-left {
				flex-wrap: wrap;
			}
		}
	</style>

	<script>
		function wsoAccToggle(btn, panelId, chevronId) {
			var panel   = document.getElementById(panelId);
			var chevron = document.getElementById(chevronId);
			var isOpen  = panel.classList.contains('wso-open');

			panel.classList.toggle('wso-open', !isOpen);
			chevron.classList.toggle('wso-open', !isOpen);

			btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
			panel.setAttribute('aria-hidden',  isOpen ? 'true'  : 'false');
		}
	</script>
	<?php

	return ob_get_clean();
}
add_shortcode( 'troubleshooting_guide', 'afristream_portal_troubleshooting_shortcode' );

/**
 * The troubleshooting content, shared by the [troubleshooting_guide] shortcode
 * and (indirectly) mirrored by the portal's own Troubleshooting tab.
 */
function afristream_portal_troubleshooting_sections() {
	return array(
		array(
			'badge'   => 'Note',
			'title'   => 'Before You Start',
			'content' => '
				<ul>
					<li>Do not uninstall your app unless instructed</li>
					<li>Enter your username and password exactly correct or the app will require a re-connection (Step 3).</li>
					<li>Username and Password are case sensitive</li>
					<li>DNS updates can take some time to propagate</li>
				</ul>
			',
		),
		array(
			'badge'   => 'Step 1',
			'title'   => 'Restart the App',
			'content' => '
				<ol>
					<li>Close the app completely and reopen it.</li>
					<li>If channels/content still do not load, continue to the next step.</li>
				</ol>
			',
		),
		array(
			'badge'   => 'Step 2',
			'title'   => 'Clear Cache &amp; App Data',
			'content' => '
				<h4>Firestick / Android TV</h4>
				<ol>
					<li>Go to Settings</li>
					<li>Open Applications</li>
					<li>Select Manage Installed Applications</li>
					<li>Select your streaming app</li>
					<li>Choose: <strong>Clear Cache</strong> then <strong>Clear Data</strong></li>
					<li>Re-open the app</li>
					<li>Enter your login details again</li>
				</ol>
			',
		),
		array(
			'badge'   => 'Step 3',
			'title'   => 'Reconnect the Playlist / Server',
			'content' => '
				<p>If the app opens but shows no channels/content:</p>
				<ol>
					<li>Open the app menu</li>
					<li>Select <strong>Edit Playlist</strong> or <strong>Update Playlist</strong></li>
					<li>Re-enter your login details carefully</li>
					<li>Save changes</li>
					<li>Press Connect</li>
					<li>Wait 10&ndash;15 seconds for content to load</li>
				</ol>
			',
		),
		array(
			'badge'   => 'Step 4',
			'title'   => 'Playlist Not Working?',
			'content' => '
				<p>This usually means the old DNS/server is cached. Try:</p>
				<ul>
					<li>Rebooting the device</li>
					<li>Clearing app cache again</li>
					<li>Reconnecting the playlist</li>
					<li>Waiting 15&ndash;30 minutes for DNS propagation</li>
				</ul>
			',
		),
		array(
			'badge'   => 'Step 6',
			'title'   => 'Try an Alternative App',
			'content' => '
				<p>If the current app still does not connect after following all previous steps, try installing an alternative supported app.</p><br>
				<h4>Install Alternative App</h4>
				<ol>
					<li>Open the Downloader app</li>
					<li>Enter one of the provided codes:
						<ul>
							<li><code>569138</code></li>
							<li><code>6573365</code></li>
							<li><code>617725</code></li>
							<li><code>9469460</code></li>
						</ul>
					</li>
					<li>Download and install the app</li>
					<li>Open the new app</li>
					<li>Enter your existing login details</li>
					<li>Allow a few seconds for playlists/content to sync</li>
				</ol><br>
				<h4>If It Still Does Not Work</h4>
				<ul>
					<li>Restart your device</li>
					<li>Retry the login carefully</li>
					<li>Wait for DNS propagation to complete</li>
					<li>Try another listed app if available</li>
				</ul>
			',
		),
		array(
			'badge'   => 'Info',
			'title'   => 'Important Notes',
			'content' => '
				<ul>
					<li>Your username/password stay the same</li>
					<li>Most issues are caused by cached DNS or outdated playlist data</li>
					<li>Full restoration may take some time while apps are updated</li>
				</ul>
			',
		),
	);
}

/**
 * Declare the legacy shortcodes on the Configurations page.
 *
 * @param array $items Registry entries so far.
 * @return array
 */
function afristream_register_shortcode_registry( $items ) {
	$items[] = array(
		'group'  => 'Portal',
		'name'   => 'App credentials shortcode',
		'type'   => 'shortcode',
		'handle' => 'user_acf_fields',
		'file'   => 'includes/shortcodes.php',
		'status' => array(
			'state' => 'ok',
			'label' => __( 'Legacy tag name, no ACF dependency', 'bluegroup-project-afristream' ),
		),
	);

	$items[] = array(
		'group'  => 'Portal',
		'name'   => 'Troubleshooting guide shortcode',
		'type'   => 'shortcode',
		'handle' => 'troubleshooting_guide',
		'file'   => 'includes/shortcodes.php',
	);

	return $items;
}
add_filter( 'afristream_registry', 'afristream_register_shortcode_registry' );
