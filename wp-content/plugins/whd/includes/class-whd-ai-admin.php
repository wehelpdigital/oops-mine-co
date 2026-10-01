<?php
/**
 * AI copywriting: the screens.
 *
 * Two places to be:
 *
 *   WHD → AI content   the setup — which model, how the shop talks, what the rules are, which
 *                      keywords exist. Four tabs, because they are four different jobs and mixing
 *                      them into one long page makes none of them get done.
 *
 *   the post screen    a panel on products, pages and journal posts: pick what to write, pick the
 *                      keywords, press the button, read what came back, apply it or throw it away.
 *
 * Nothing generates on its own. Every call to a provider is a person pressing Write, and every
 * piece of copy reaches the site only when a person presses Apply.
 *
 * @package whd
 */

defined( 'ABSPATH' ) || exit;

final class WHD_AI_Admin {

	const PAGE = 'whd-ai';

	public static function init() {
		// The plugin was active before this module existed, so the table cannot rely on activation.
		add_action( 'admin_init', [ 'WHD_AI_Keywords', 'maybe_install' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_box' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'rest' ] );

		add_action( 'admin_post_whd_ai_keywords_add', [ __CLASS__, 'handle_keywords_add' ] );
		add_action( 'admin_post_whd_ai_keywords_import', [ __CLASS__, 'handle_keywords_import' ] );
		add_action( 'admin_post_whd_ai_keywords_delete', [ __CLASS__, 'handle_keywords_delete' ] );
	}

	public static function register_settings() {
		register_setting( 'whd_ai_group', WHD_AI::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ 'WHD_AI', 'sanitize' ],
		] );
	}

	private static function url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE );
		return $tab ? $url . '&tab=' . $tab : $url;
	}

	private static function tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choosing which tab to draw
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		return in_array( $tab, [ 'settings', 'brief', 'rules', 'keywords' ], true ) ? $tab : 'settings';
	}

	private static function field( $key ) {
		return WHD_AI::OPTION . '[' . $key . ']';
	}

	/* ─────────────────────────── assets ─────────────────────────── */

	public static function assets( $hook ) {
		$screen  = get_current_screen();
		$on_page = isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$on_post = $screen && 'post' === $screen->base && in_array( $screen->post_type, WHD_AI::get()['post_types'], true );

		if ( ! $on_page && ! $on_post ) {
			return;
		}

		wp_enqueue_style( 'whd-admin', WHD_URL . 'admin/admin.css', [], WHD_VERSION );
		wp_enqueue_style( 'whd-ai', WHD_URL . 'admin/ai.css', [ 'whd-admin' ], WHD_VERSION );
		wp_enqueue_script( 'whd-ai', WHD_URL . 'admin/ai.js', [], WHD_VERSION, true );

		$post_id = $on_post ? (int) get_the_ID() : 0;
		wp_localize_script( 'whd-ai', 'WHD_AI_DATA', [
			'rest'      => [
				'root'  => esc_url_raw( rest_url( 'whd/v1/ai/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			],
			'postId'    => $post_id,
			'ready'     => WHD_AI::ready(),
			'settings'  => self::url(),
			'targets'   => $on_post ? self::target_list( $screen->post_type ) : [],
			'suggested' => $post_id ? WHD_AI_Keywords::suggest( $post_id, 8 ) : [],
			'i18n'      => [
				'writing'    => __( 'Writing…', 'whd' ),
				'write'      => __( 'Write it', 'whd' ),
				'rewrite'    => __( 'Write another', 'whd' ),
				'passed'     => __( 'Passes every check', 'whd' ),
				'failed'     => __( 'Needs a look', 'whd' ),
				'words'      => __( 'words', 'whd' ),
				'chars'      => __( 'characters', 'whd' ),
				'applied'    => __( 'Applied.', 'whd' ),
				'inserted'   => __( 'Dropped into the editor below — save the page to keep it.', 'whd' ),
				'noEditor'   => __( 'Could not find the editor on this screen. Copy the text instead.', 'whd' ),
				'copied'     => __( 'Copied', 'whd' ),
				'error'      => __( 'Something went wrong', 'whd' ),
				'drafting'   => __( 'Reading your site…', 'whd' ),
				'confirmSet' => __( 'Replace what is stored there now?', 'whd' ),
			],
		] );
	}

	/** The targets a post type can generate, flattened for JavaScript. */
	private static function target_list( $post_type ) {
		$out = [];
		foreach ( WHD_AI::targets( $post_type ) as $key => $spec ) {
			$out[] = [
				'key'    => $key,
				'label'  => $spec['label'],
				'apply'  => $spec['apply'],
				'inline' => in_array( $spec['apply'], [ 'content', 'excerpt' ], true ),
			];
		}
		return $out;
	}

	/* ─────────────────────────── WHD → AI content ─────────────────────────── */

	public static function page() {
		$tab = self::tab();
		echo '<div class="wrap whd-wrap whd-ai-wrap">';
		echo '<h1 class="whd-title">' . esc_html__( 'AI content', 'whd' ) . '</h1>';
		echo '<p class="whd-intro">' . esc_html__( 'Write product stories, descriptions and search snippets against your own brand brief, your own rules and your own keyword list. Every draft is checked before you see it, and nothing reaches the site until you apply it.', 'whd' ) . '</p>';

		$tabs = [
			'settings' => __( 'Settings', 'whd' ),
			'brief'    => __( 'Brief', 'whd' ),
			'rules'    => __( 'Rules', 'whd' ),
			'keywords' => __( 'Keywords', 'whd' ),
		];
		echo '<h2 class="nav-tab-wrapper whd-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		self::notice();

		switch ( $tab ) {
			case 'brief':
				self::tab_brief();
				break;
			case 'rules':
				self::tab_rules();
				break;
			case 'keywords':
				self::tab_keywords();
				break;
			default:
				self::tab_settings();
		}
		echo '</div>';
	}

	private static function notice() {
		$key    = 'whd_ai_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$notice['ok'] ? 'success' : 'warning',
			esc_html( $notice['message'] )
		);
	}

	private static function flash( $ok, $message ) {
		set_transient( 'whd_ai_notice_' . get_current_user_id(), [ 'ok' => $ok, 'message' => $message ], 60 );
	}

	/* ── Settings tab ── */

	private static function tab_settings() {
		$o = WHD_AI::get();
		echo '<form method="post" action="options.php" class="whd-form">';
		settings_fields( 'whd_ai_group' );
		self::carry_over( [ 'provider', 'model', 'max_tokens', 'temperature', 'post_types', 'recaptcha_site', 'recaptcha_version', 'recaptcha_project', 'recaptcha_score' ] );

		echo '<h2 class="whd-h2">' . esc_html__( 'Provider', 'whd' ) . ' '
			. '<span class="whd-pill ' . ( WHD_AI::ready() ? 'whd-pill--on' : 'whd-pill--off' ) . '">'
			. ( WHD_AI::ready() ? esc_html__( 'Connected', 'whd' ) : esc_html__( 'Not connected', 'whd' ) )
			. '</span></h2>';

		echo '<table class="form-table whd-table-settings"><tbody>';

		echo '<tr><th><label for="whd-ai-provider">' . esc_html__( 'Model provider', 'whd' ) . '</label></th><td>';
		echo '<select id="whd-ai-provider" name="' . esc_attr( self::field( 'provider' ) ) . '">';
		foreach ( WHD_AI::providers() as $value => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $o['provider'], $value, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Nothing is sent anywhere while this is off. Your page content and product data are sent to the provider you choose, so pick one whose terms you are happy with.', 'whd' ) . '</p></td></tr>';

		self::secret_row( 'anthropic_key', __( 'Anthropic API key', 'whd' ), $o['anthropic_key'], __( 'console.anthropic.com → API keys. Starts with sk-ant-.', 'whd' ), 'anthropic' );
		self::secret_row( 'openai_key', __( 'OpenAI API key', 'whd' ), $o['openai_key'], __( 'platform.openai.com → API keys. Starts with sk-.', 'whd' ), 'openai' );
		self::secret_row( 'google_key', __( 'Google API key', 'whd' ), $o['google_key'], __( 'aistudio.google.com → Get API key. Starts with AIza. The Generative Language API has to be enabled on the project.', 'whd' ), 'google' );

		echo '<tr><th><label for="whd-ai-model">' . esc_html__( 'Model', 'whd' ) . '</label></th><td>';
		echo '<input class="regular-text" id="whd-ai-model" list="whd-ai-models" name="' . esc_attr( self::field( 'model' ) ) . '" value="' . esc_attr( $o['model'] ) . '" placeholder="' . esc_attr( WHD_AI::default_model( $o['provider'] ) ) . '">';
		echo '<datalist id="whd-ai-models">';
		foreach ( WHD_AI::model_choices( $o['provider'] ) as $id => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $id ), esc_attr( $label ) );
		}
		echo '</datalist>';
		echo '<p class="description">' . esc_html__( 'Leave blank for the sensible default. Type any model name your account can reach — this field is not a fixed list, so a newer model works the day it ships.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th><label for="whd-ai-tokens">' . esc_html__( 'Longest reply', 'whd' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="whd-ai-tokens" min="200" max="8000" step="100" name="' . esc_attr( self::field( 'max_tokens' ) ) . '" value="' . esc_attr( $o['max_tokens'] ) . '"> ' . esc_html__( 'tokens', 'whd' );
		echo '<p class="description">' . esc_html__( 'Roughly three quarters of a word each. 1,600 is enough for a full product story.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th><label for="whd-ai-temp">' . esc_html__( 'Looseness', 'whd' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="whd-ai-temp" min="0" max="1" step="0.1" name="' . esc_attr( self::field( 'temperature' ) ) . '" value="' . esc_attr( $o['temperature'] ) . '">';
		echo '<p class="description">' . esc_html__( '0 writes the same safe sentence every time; 1 surprises you. 0.7 suits shop copy.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Show the panel on', 'whd' ) . '</th><td>';
		$labels = [
			'product' => __( 'Products', 'whd' ),
			'page'    => __( 'Pages', 'whd' ),
			'post'    => __( 'Journal posts', 'whd' ),
		];
		foreach ( $labels as $type => $label ) {
			printf(
				'<label class="whd-check"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>',
				esc_attr( self::field( 'post_types' ) ),
				esc_attr( $type ),
				checked( in_array( $type, $o['post_types'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<h2 class="whd-h2">' . esc_html__( 'reCAPTCHA', 'whd' ) . ' '
			. '<span class="whd-pill ' . ( WHD_AI::recaptcha_ready() ? 'whd-pill--on' : 'whd-pill--off' ) . '">'
			. ( WHD_AI::recaptcha_ready() ? esc_html__( 'Protecting forms', 'whd' ) : esc_html__( 'Not set up', 'whd' ) )
			. '</span></h2>';
		echo '<p class="whd-intro">' . esc_html__( 'Used by the AI stylist, which asks for an email address and would otherwise be worth a bot\'s time. These are a different pair from the model key above — get them at google.com/recaptcha. Both halves come from the same page there: the site key goes in the page, the secret key stays here. Without the secret nothing can actually be checked, and the wizard falls back to its own defences.', 'whd' ) . '</p>';
		echo '<table class="form-table whd-table-settings"><tbody>';

		echo '<tr><th><label for="recaptcha_version">' . esc_html__( 'Which reCAPTCHA', 'whd' ) . '</label></th><td><select id="recaptcha_version" name="' . esc_attr( self::field( 'recaptcha_version' ) ) . '">';
		foreach ( WHD_AI::recaptcha_versions() as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( WHD_AI::recaptcha_version(), $key, false ), esc_html( $label ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Google shows this when you create the key, and the three kinds are not interchangeable — a key made for one of the others, asked to draw a tickbox, answers "Invalid key type" and nobody can get past it. If the stylist shows that message, it is this setting that is wrong. Enterprise keys are the ones created in the Google Cloud console rather than at google.com/recaptcha.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th><label for="recaptcha_site">' . esc_html__( 'Site key', 'whd' ) . '</label></th><td>'
			. '<input class="regular-text" id="recaptcha_site" name="' . esc_attr( self::field( 'recaptcha_site' ) ) . '" value="' . esc_attr( $o['recaptcha_site'] ) . '" placeholder="6L…">'
			. '<p class="description">' . esc_html__( 'Public — it appears in the page, so it is not a secret. All three kinds use one.', 'whd' ) . '</p></td></tr>';

		self::secret_row( 'recaptcha_secret', __( 'Secret key', 'whd' ), $o['recaptcha_secret'], __( 'Never leaves the server. Paste it once; it is stored masked. Until it is here, no token is checked with Google.', 'whd' ), '', 'v2 v3' );

		echo '<tr data-whd-recaptcha="enterprise"' . self::captcha_hidden( 'enterprise' ) . '><th><label for="recaptcha_project">' . esc_html__( 'Google Cloud project ID', 'whd' ) . '</label></th><td>'
			. '<input class="regular-text" id="recaptcha_project" name="' . esc_attr( self::field( 'recaptcha_project' ) ) . '" value="' . esc_attr( $o['recaptcha_project'] ) . '" placeholder="my-project-123456">'
			. '<p class="description">' . esc_html__( 'The project the Enterprise key was created in — the ID, not the display name. It is in the Google Cloud console beside the project name, and in the address bar as ?project=…', 'whd' ) . '</p></td></tr>';

		self::secret_row(
			'recaptcha_api_key',
			__( 'API key', 'whd' ),
			$o['recaptcha_api_key'],
			__( 'An API key from that same project, allowed to use the reCAPTCHA Enterprise API. Enterprise has no secret key: this is what signs the check. If your Gemini key is from the same project and is not restricted to one API, it works here too.', 'whd' ),
			'',
			'enterprise'
		);

		echo '<tr data-whd-recaptcha="v3 enterprise"' . self::captcha_hidden( 'v3 enterprise' ) . '><th><label for="recaptcha_score">' . esc_html__( 'Lowest score to let through', 'whd' ) . '</label></th><td>'
			. '<input type="number" class="small-text" step="0.1" min="0.1" max="0.9" id="recaptcha_score" name="' . esc_attr( self::field( 'recaptcha_score' ) ) . '" value="' . esc_attr( (string) $o['recaptcha_score'] ) . '">'
			. '<p class="description">' . esc_html__( 'Every visitor is scored from 0 (certainly a bot) to 1 (certainly a person). 0.5 is Google\'s suggestion. Raise it if spam gets through; lower it if real people are being turned away.', 'whd' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<p class="whd-ai-brief__actions">';
		echo '<button type="button" class="button" id="whd-captcha-test"'
			. ' data-script="' . esc_attr( WHD_AI::recaptcha_shown() ? WHD_AI::recaptcha_script() : '' ) . '"'
			. ' data-site="' . esc_attr( $o['recaptcha_site'] ) . '"'
			. ' data-enterprise="' . ( WHD_AI::recaptcha_enterprise() ? '1' : '0' ) . '"'
			. ' data-silent="' . ( WHD_AI::recaptcha_silent() ? '1' : '0' ) . '">'
			. esc_html__( 'Check these keys now', 'whd' ) . '</button> ';
		echo '<span class="whd-ai-note" id="whd-captcha-result">';
		$missing = WHD_AI::recaptcha_missing();
		echo '' !== $missing
			/* translators: %s: what is missing, e.g. "the secret key" */
			? esc_html( sprintf( __( 'Still missing: %s. Nothing is being checked yet.', 'whd' ), $missing ) )
			: esc_html__( 'Mints a real token in this browser and asks Google to assess it.', 'whd' );
		echo '</span></p>';

		submit_button( __( 'Save settings', 'whd' ) );
		echo '</form>';

		self::log_section();
		self::provider_toggle_script();
	}

	/**
	 * A key box that never prints the stored value back to the browser.
	 *
	 * A saved key is shown as dots with only its first and last few characters, which is enough to
	 * tell two keys apart without putting one in the page — the value itself is never sent to the
	 * browser at all, so there is nothing in the markup to read.
	 *
	 * $provider empty means the row is not tied to a model provider and is always shown.
	 */
	/** Rows that only make sense for some reCAPTCHA kinds start out of sight for the others. */
	private static function captcha_hidden( $kinds ) {
		return in_array( WHD_AI::recaptcha_version(), explode( ' ', $kinds ), true ) ? '' : ' hidden';
	}

	private static function secret_row( $key, $label, $stored, $help, $provider, $captcha = '' ) {
		$scoped = '' !== $provider;
		$hidden = ( $scoped && WHD_AI::get()['provider'] !== $provider ) ? ' hidden' : '';
		if ( '' !== $captcha ) {
			$hidden = self::captcha_hidden( $captcha );
		}
		echo '<tr' . ( $scoped ? ' data-whd-ai-provider="' . esc_attr( $provider ) . '"' : '' )
			. ( '' !== $captcha ? ' data-whd-recaptcha="' . esc_attr( $captcha ) . '"' : '' ) . $hidden . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input class="regular-text" type="password" autocomplete="new-password" id="' . esc_attr( $key ) . '" name="' . esc_attr( self::field( $key ) ) . '" value="" placeholder="'
			. esc_attr( '' !== $stored ? __( 'Saved — leave blank to keep it', 'whd' ) : __( 'Paste your key', 'whd' ) ) . '">';
		if ( '' !== $stored ) {
			echo ' <code class="whd-secret">' . esc_html( self::mask( $stored ) ) . '</code>';
			echo ' <label class="whd-clear"><input type="checkbox" name="' . esc_attr( WHD_AI::OPTION . '[clear][' . $key . ']' ) . '" value="1"> ' . esc_html__( 'Remove the saved key', 'whd' ) . '</label>';
		}
		echo '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}

	/** "AIza••••••••••••••RzE" — enough to recognise, not enough to use. */
	private static function mask( $secret ) {
		$secret = (string) $secret;
		$len    = strlen( $secret );
		if ( $len <= 8 ) {
			return str_repeat( '•', max( 6, $len ) );
		}
		return substr( $secret, 0, 4 ) . str_repeat( '•', min( 18, max( 6, $len - 7 ) ) ) . substr( $secret, -3 );
	}

	private static function provider_toggle_script() {
		echo '<script>(function(){var s=document.getElementById("whd-ai-provider");if(s){var paint=function(){var r=document.querySelectorAll("[data-whd-ai-provider]");for(var i=0;i<r.length;i++){r[i].hidden=r[i].getAttribute("data-whd-ai-provider")!==s.value;}};s.addEventListener("change",paint);paint();}var v=document.getElementById("recaptcha_version");if(v){var show=function(){var r=document.querySelectorAll("[data-whd-recaptcha]");for(var i=0;i<r.length;i++){r[i].hidden=r[i].getAttribute("data-whd-recaptcha").split(" ").indexOf(v.value)===-1;}};v.addEventListener("change",show);show();}})();</script>';
		self::captcha_test_script();
	}

	/**
	 * "Check these keys now".
	 *
	 * Enterprise cannot be proved by looking at it: the site key lives in the browser, the project
	 * and API key live here, and only a round trip says whether the pair actually works. So the
	 * button mints a real token the way the stylist does and prints Google's answer verbatim.
	 */
	private static function captcha_test_script() {
		$strings = [
			'none'    => __( 'Save a site key first.', 'whd' ),
			'minting' => __( 'Asking Google…', 'whd' ),
			'noApi'   => __( 'The reCAPTCHA script did not load — check the site key, and whether this domain is on the key.', 'whd' ),
			'failed'  => __( 'Could not reach the site.', 'whd' ),
			'saveMsg' => __( 'Save the page first — this tests the keys that are stored, not the ones typed above.', 'whd' ),
			'tickbox' => __( 'The tickbox cannot be tested from here. Open the stylist on the site: if the box draws and accepts a tick, the keys are right.', 'whd' ),
		];
		?>
		<script>
		( function () {
			var btn = document.getElementById( 'whd-captcha-test' );
			var out = document.getElementById( 'whd-captcha-result' );
			var sel = document.getElementById( 'recaptcha_version' );
			var T = <?php echo wp_json_encode( $strings ); ?>;
			if ( ! btn || ! out ) { return; }

			var say = function ( text, bad ) {
				out.textContent = text;
				out.className = 'whd-ai-note' + ( bad ? ' is-bad' : '' );
			};

			// What is stored is what gets tested, so say so the moment the form is edited.
			var form = btn.closest( 'form' );
			if ( form ) {
				form.addEventListener( 'input', function () { btn.dataset.dirty = '1'; } );
				form.addEventListener( 'change', function () { btn.dataset.dirty = '1'; } );
			}

			btn.addEventListener( 'click', function () {
				var site = btn.dataset.site, src = btn.dataset.script;
				if ( ! site || ! src ) { return say( T.none, true ); }
				if ( btn.dataset.dirty ) { return say( T.saveMsg, true ); }
				if ( sel && sel.value !== btn.dataset.wasVersion && btn.dataset.wasVersion ) { return say( T.saveMsg, true ); }

				if ( '1' !== btn.dataset.silent ) { return say( T.tickbox, false ); }

				btn.disabled = true;
				say( T.minting, false );

				var ent = '1' === btn.dataset.enterprise;
				var ready = function () {
					var g = ent ? ( window.grecaptcha && window.grecaptcha.enterprise ) : window.grecaptcha;
					if ( ! g || ! g.ready ) { btn.disabled = false; return say( T.noApi, true ); }
					g.ready( function () {
						Promise.resolve( g.execute( site, { action: 'whd_admin_test' } ) ).then( function ( t ) {
							return fetch( WHD_AI_DATA.rest.root + 'recaptcha-test', {
								method: 'POST',
								headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': WHD_AI_DATA.rest.nonce },
								body: JSON.stringify( { token: t } )
							} ).then( function ( r ) { return r.json(); } );
						} ).then( function ( res ) {
							btn.disabled = false;
							say( res.message || '', ! res.ok || false === res.config );
						} ).catch( function () {
							btn.disabled = false;
							say( T.failed, true );
						} );
					} );
				};

				if ( document.querySelector( 'script[data-whd-captcha]' ) ) { return ready(); }
				var s = document.createElement( 'script' );
				s.src = src;
				s.setAttribute( 'data-whd-captcha', '1' );
				s.onload = ready;
				s.onerror = function () { btn.disabled = false; say( T.noApi, true ); };
				document.head.appendChild( s );
			} );

			btn.dataset.wasVersion = sel ? sel.value : '';
		} )();
		</script>
		<?php
	}

	private static function log_section() {
		$log = array_reverse( WHD_AI::logs() );
		echo '<h2 class="whd-h2">' . esc_html__( 'Last calls', 'whd' ) . '</h2>';
		echo '<table class="widefat striped whd-table whd-log"><thead><tr>'
			. '<th>' . esc_html__( 'When', 'whd' ) . '</th><th>' . esc_html__( 'Model', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Code', 'whd' ) . '</th><th>' . esc_html__( 'Result', 'whd' ) . '</th>'
			. '</tr></thead><tbody>';
		if ( ! $log ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Nothing written yet.', 'whd' ) . '</td></tr>';
		}
		foreach ( $log as $entry ) {
			$code = (int) ( $entry['code'] ?? 0 );
			$ok   = $code >= 200 && $code < 300;
			echo '<tr><td class="whd-muted">' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $entry['time'] ?? '' ) ) . '</td>'
				. '<td class="whd-muted">' . esc_html( $entry['model'] ?? '' ) . '</td>'
				. '<td><span class="whd-pill ' . ( $ok ? 'whd-pill--on' : 'whd-pill--off' ) . '">' . esc_html( $code ? (string) $code : '—' ) . '</span></td>'
				. '<td>' . esc_html( $entry['message'] ?? '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ── Brief tab ── */

	private static function tab_brief() {
		$o       = WHD_AI::get();
		$profile = WHD_AI::site_profile();

		echo '<form method="post" action="options.php" class="whd-form whd-ai-brief">';
		settings_fields( 'whd_ai_group' );
		self::carry_over( [ 'brief' ] );

		echo '<h2 class="whd-h2">' . esc_html__( 'How this shop talks', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . esc_html__( 'This is the top of every prompt. Write it once, in plain sentences, as though you were briefing a freelance copywriter on their first morning: who buys here, what they are worried about, how the shop sounds when it is being itself.', 'whd' ) . '</p>';

		echo '<p class="whd-ai-brief__actions">';
		echo '<button type="button" class="button" id="whd-ai-draft">' . esc_html__( 'Draft it from my site', 'whd' ) . '</button> ';
		echo '<button type="button" class="button" id="whd-ai-polish" ' . disabled( ! WHD_AI::ready(), true, false ) . '>' . esc_html__( 'Tighten it with AI', 'whd' ) . '</button> ';
		echo '<span class="whd-ai-brief__status" id="whd-ai-brief-status" role="status"></span>';
		echo '</p>';

		echo '<textarea class="large-text code whd-ai-brief__field" id="whd-ai-brief" name="' . esc_attr( self::field( 'brief' ) ) . '" rows="18" placeholder="' . esc_attr__( 'Leave this blank and the module writes its own from your categories, products and About page — press “Draft it from my site” to see what that looks like.', 'whd' ) . '">' . esc_textarea( $o['brief'] ) . '</textarea>';

		submit_button( __( 'Save the brief', 'whd' ) );
		echo '</form>';

		echo '<h2 class="whd-h2">' . esc_html__( 'What the module can see', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . esc_html__( 'Read straight from the site every time it writes, so the model is never guessing at your catalogue.', 'whd' ) . '</p>';
		echo '<table class="widefat striped whd-table"><tbody>';
		self::fact( __( 'Shop', 'whd' ), $profile['name'] . ( $profile['tagline'] ? ' — ' . $profile['tagline'] : '' ) );
		self::fact( __( 'Categories', 'whd' ), $profile['categories'] ? implode( ', ', $profile['categories'] ) : __( 'none published yet', 'whd' ) );
		self::fact( __( 'Recent products', 'whd' ), $profile['products'] ? implode( '; ', array_slice( $profile['products'], 0, 6 ) ) : __( 'none published yet', 'whd' ) );
		self::fact(
			__( 'Prices', 'whd' ),
			$profile['price_range']
				? sprintf( '%s – %s %s', number_format( $profile['price_range'][0], 2 ), number_format( $profile['price_range'][1], 2 ), $profile['currency'] )
				: __( 'no priced products', 'whd' )
		);
		self::fact( __( 'About page', 'whd' ), $profile['about'] ?: __( 'not found — a page with the slug “about”', 'whd' ) );
		echo '</tbody></table>';
	}

	private static function fact( $label, $value ) {
		echo '<tr><th scope="row" style="width:180px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/* ── Rules tab ── */

	private static function tab_rules() {
		$o = WHD_AI::get();
		echo '<form method="post" action="options.php" class="whd-form whd-ai-rules">';
		settings_fields( 'whd_ai_group' );
		self::carry_over( [ 'rules', 'banned_words', 'min_words', 'max_words', 'require_keywords', 'auto_repair' ] );

		echo '<h2 class="whd-h2">' . esc_html__( 'House rules', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . esc_html__( 'Every rule that is ticked goes into the prompt as a hard requirement. Turn one off rather than deleting it if you might want it back.', 'whd' ) . '</p>';

		echo '<div class="whd-ai-rulelist" id="whd-ai-rulelist">';
		foreach ( array_values( $o['rules'] ) as $i => $rule ) {
			self::rule_row( $i, $rule['text'], ! empty( $rule['on'] ) );
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="whd-ai-add-rule">' . esc_html__( '+ Add a rule', 'whd' ) . '</button></p>';

		echo '<h2 class="whd-h2">' . esc_html__( 'Words never to publish', 'whd' ) . '</h2>';
		echo '<p class="whd-intro">' . esc_html__( 'One per line. These go into the prompt and are then checked against what comes back — a draft containing any of them is marked and rewritten once, automatically. Matching is whole-word and case-insensitive.', 'whd' ) . '</p>';
		echo '<textarea class="large-text code" name="' . esc_attr( self::field( 'banned_words' ) ) . '" rows="10">' . esc_textarea( $o['banned_words'] ) . '</textarea>';
		echo '<p class="description">' . sprintf(
			/* translators: %d: number of banned words currently saved */
			esc_html__( '%d in the list.', 'whd' ),
			count( WHD_AI::banned_words() )
		) . '</p>';

		echo '<h2 class="whd-h2">' . esc_html__( 'Checks', 'whd' ) . '</h2>';
		echo '<table class="form-table whd-table-settings"><tbody>';

		echo '<tr><th>' . esc_html__( 'Keywords', 'whd' ) . '</th><td><label class="whd-check"><input type="checkbox" name="' . esc_attr( self::field( 'require_keywords' ) ) . '" value="1" ' . checked( $o['require_keywords'], 1, false ) . '> '
			. esc_html__( 'Every keyword must appear word for word', 'whd' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Off, keywords become a suggestion and the draft is never rejected for missing one.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Length', 'whd' ) . '</th><td>'
			. '<input type="number" class="small-text" min="0" name="' . esc_attr( self::field( 'min_words' ) ) . '" value="' . esc_attr( $o['min_words'] ) . '"> '
			. esc_html__( 'to', 'whd' ) . ' '
			. '<input type="number" class="small-text" min="0" name="' . esc_attr( self::field( 'max_words' ) ) . '" value="' . esc_attr( $o['max_words'] ) . '"> '
			. esc_html__( 'words', 'whd' )
			. '<p class="description">' . esc_html__( 'An outer bound for everything. Each kind of copy has its own sensible range inside this; a short description is never asked for 300 words because this says so.', 'whd' ) . '</p></td></tr>';

		echo '<tr><th>' . esc_html__( 'Repair', 'whd' ) . '</th><td><label class="whd-check"><input type="checkbox" name="' . esc_attr( self::field( 'auto_repair' ) ) . '" value="1" ' . checked( $o['auto_repair'], 1, false ) . '> '
			. esc_html__( 'When a draft fails a check, send it back once with the faults listed', 'whd' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Costs a second call. The repair is kept only if it fixes more than it breaks.', 'whd' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save the rules', 'whd' ) );
		echo '</form>';
	}

	private static function rule_row( $i, $text, $on ) {
		printf(
			'<div class="whd-ai-rule"><label class="whd-ai-rule__on"><input type="checkbox" name="%1$s[%2$d][on]" value="1" %3$s><span class="screen-reader-text">%4$s</span></label>'
			. '<textarea class="whd-ai-rule__text" name="%1$s[%2$d][text]" rows="2">%5$s</textarea>'
			. '<button type="button" class="button-link whd-ai-rule__remove" aria-label="%6$s">&times;</button></div>',
			esc_attr( self::field( 'rules' ) ),
			(int) $i,
			checked( $on, true, false ),
			esc_html__( 'Use this rule', 'whd' ),
			esc_textarea( $text ),
			esc_attr__( 'Remove this rule', 'whd' )
		);
	}

	/**
	 * Keep the settings the current tab does not show.
	 *
	 * One option, four forms: without this, saving the Rules tab would blank the brief, because the
	 * sanitiser rebuilds the whole array from what was posted.
	 *
	 * @param array $shown Keys this tab has real inputs for.
	 */
	private static function carry_over( array $shown ) {
		$o = WHD_AI::get();
		foreach ( $o as $key => $value ) {
			if ( in_array( $key, $shown, true ) || in_array( $key, WHD_AI::secret_fields(), true ) ) {
				continue; // secrets are kept by the sanitiser when the box is left blank
			}
			if ( 'rules' === $key ) {
				foreach ( array_values( $value ) as $i => $rule ) {
					printf( '<input type="hidden" name="%s[%d][text]" value="%s">', esc_attr( self::field( 'rules' ) ), (int) $i, esc_attr( $rule['text'] ) );
					if ( ! empty( $rule['on'] ) ) {
						printf( '<input type="hidden" name="%s[%d][on]" value="1">', esc_attr( self::field( 'rules' ) ), (int) $i );
					}
				}
				continue;
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					printf( '<input type="hidden" name="%s[]" value="%s">', esc_attr( self::field( $key ) ), esc_attr( $item ) );
				}
				continue;
			}
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( self::field( $key ) ), esc_attr( $value ) );
		}
	}

	/* ── Keywords tab ── */

	private static function tab_keywords() {
		WHD_AI_Keywords::maybe_install();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters, not actions
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$tag    = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable

		$per_page = 50;
		$total    = WHD_AI_Keywords::count( $search, $tag );
		$rows     = WHD_AI_Keywords::all( [
			'search' => $search,
			'tag'    => $tag,
			'limit'  => $per_page,
			'offset' => ( $paged - 1 ) * $per_page,
		] );

		echo '<div class="whd-ai-kw">';

		echo '<div class="whd-ai-kw__forms">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="whd-ai-kw__add">';
		wp_nonce_field( 'whd_ai_keywords_add' );
		echo '<input type="hidden" name="action" value="whd_ai_keywords_add">';
		echo '<h2 class="whd-h2">' . esc_html__( 'Add by hand', 'whd' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One per line. Add a monthly volume and a difficulty after commas if you have them: petite midi dress, 880, 24', 'whd' ) . '</p>';
		echo '<textarea class="large-text code" name="whd_ai_keywords" rows="7" placeholder="' . esc_attr__( "korean style dress\nthai fashion online\npetite midi dress, 880, 24", 'whd' ) . '"></textarea>';
		echo '<p><label>' . esc_html__( 'Tag them (optional)', 'whd' ) . ' <input type="text" name="whd_ai_tags" class="regular-text" placeholder="' . esc_attr__( 'dresses, spring', 'whd' ) . '"></label></p>';
		submit_button( __( 'Add keywords', 'whd' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="whd-ai-kw__import">';
		wp_nonce_field( 'whd_ai_keywords_import' );
		echo '<input type="hidden" name="action" value="whd_ai_keywords_import">';
		echo '<h2 class="whd-h2">' . esc_html__( 'Import a CSV', 'whd' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Straight from Ubersuggest, Ahrefs, Semrush or a spreadsheet of your own. The header row is read by name, so the column order does not matter — “Keyword” plus whichever of volume and difficulty are there. A keyword already in the list keeps its usage count.', 'whd' ) . '</p>';
		echo '<p><input type="file" name="whd_ai_csv" accept=".csv,text/csv" required></p>';
		echo '<p><label>' . esc_html__( 'Tag this import (optional)', 'whd' ) . ' <input type="text" name="whd_ai_tags" class="regular-text" placeholder="' . esc_attr__( 'ubersuggest, 2026', 'whd' ) . '"></label></p>';
		submit_button( __( 'Import', 'whd' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>'; // forms

		echo '<h2 class="whd-h2">' . sprintf(
			/* translators: %s: number of keywords */
			esc_html__( 'The list (%s)', 'whd' ),
			esc_html( number_format_i18n( $total ) )
		) . '</h2>';

		echo '<form method="get" class="whd-ai-kw__filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="keywords">';
		echo '<p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search keywords', 'whd' ) . '"> ';
		$tags = WHD_AI_Keywords::tags();
		if ( $tags ) {
			echo '<select name="tag"><option value="">' . esc_html__( 'Any tag', 'whd' ) . '</option>';
			foreach ( $tags as $value ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $tag, $value, false ), esc_html( $value ) );
			}
			echo '</select> ';
		}
		submit_button( __( 'Filter', 'whd' ), 'secondary', '', false );
		echo '</p></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'whd_ai_keywords_delete' );
		echo '<input type="hidden" name="action" value="whd_ai_keywords_delete">';
		echo '<table class="widefat striped whd-table"><thead><tr>'
			. '<td class="check-column"><input type="checkbox" id="whd-ai-kw-all" aria-label="' . esc_attr__( 'Select all', 'whd' ) . '"></td>'
			. '<th>' . esc_html__( 'Keyword', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Volume', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Difficulty', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Tags', 'whd' ) . '</th>'
			. '<th>' . esc_html__( 'Used', 'whd' ) . '</th>'
			. '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Nothing here yet. Add a few by hand, or import the CSV your keyword tool gave you.', 'whd' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			echo '<tr>'
				. '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( $row->id ) . '" aria-label="' . esc_attr( $row->keyword ) . '"></th>'
				. '<td><strong>' . esc_html( $row->keyword ) . '</strong></td>'
				. '<td>' . esc_html( $row->volume ? number_format_i18n( $row->volume ) : '—' ) . '</td>'
				. '<td>' . esc_html( $row->difficulty ?: '—' ) . '</td>'
				. '<td class="whd-muted">' . esc_html( $row->tags ) . '</td>'
				. '<td>' . esc_html( $row->used ? number_format_i18n( $row->used ) : '—' ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="whd-ai-kw__bulk">';
		submit_button( __( 'Delete selected', 'whd' ), 'delete', 'submit', false );
		echo '</p></form>';

		$pages = (int) ceil( $total / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( paginate_links( [
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			] ) );
			echo '</div></div>';
		}

		echo '<script>(function(){var a=document.getElementById("whd-ai-kw-all");if(!a){return;}a.addEventListener("change",function(){var b=document.querySelectorAll(\'input[name="ids[]"]\');for(var i=0;i<b.length;i++){b[i].checked=a.checked;}});})();</script>';
		echo '</div>';
	}

	/* ─────────────────────────── keyword form handlers ─────────────────────────── */

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'whd' ) );
		}
		check_admin_referer( $nonce );
		WHD_AI_Keywords::maybe_install();
	}

	private static function back() {
		wp_safe_redirect( self::url( 'keywords' ) );
		exit;
	}

	public static function handle_keywords_add() {
		self::guard( 'whd_ai_keywords_add' );
		$text  = isset( $_POST['whd_ai_keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['whd_ai_keywords'] ) ) : '';
		$tags  = isset( $_POST['whd_ai_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['whd_ai_tags'] ) ) : '';
		$added = WHD_AI_Keywords::add_lines( $text, $tags );
		self::flash( $added > 0, sprintf(
			/* translators: %d: number of keywords */
			_n( '%d keyword saved.', '%d keywords saved.', $added, 'whd' ),
			$added
		) );
		self::back();
	}

	public static function handle_keywords_import() {
		self::guard( 'whd_ai_keywords_import' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- the path is checked below
		$file = $_FILES['whd_ai_csv'] ?? null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::flash( false, __( 'No file arrived. Try again.', 'whd' ) );
			self::back();
		}
		$tags   = isset( $_POST['whd_ai_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['whd_ai_tags'] ) ) : '';
		$result = WHD_AI_Keywords::import_csv( $file['tmp_name'], $tags );
		if ( is_wp_error( $result ) ) {
			self::flash( false, $result->get_error_message() );
			self::back();
		}
		self::flash( $result['added'] > 0, sprintf(
			/* translators: 1: keywords imported, 2: rows skipped */
			__( '%1$d keywords imported, %2$d rows skipped.', 'whd' ),
			$result['added'],
			$result['skipped']
		) );
		self::back();
	}

	public static function handle_keywords_delete() {
		self::guard( 'whd_ai_keywords_delete' );
		$ids     = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : [];
		$deleted = WHD_AI_Keywords::delete( $ids );
		self::flash( $deleted > 0, sprintf(
			/* translators: %d: number of keywords */
			_n( '%d keyword deleted.', '%d keywords deleted.', $deleted, 'whd' ),
			$deleted
		) );
		self::back();
	}

	/* ─────────────────────────── the post-screen panel ─────────────────────────── */

	public static function meta_box() {
		foreach ( WHD_AI::get()['post_types'] as $type ) {
			add_meta_box(
				// Not 'whd-ai-panel': that id belongs to the div inside, and add_meta_box puts
				// its own id on the postbox wrapper.
				'whd-ai-box',
				__( 'Write with AI', 'whd' ),
				[ __CLASS__, 'box' ],
				$type,
				'normal',
				'low'
			);
		}
	}

	public static function box( $post ) {
		$targets = WHD_AI::targets( $post->post_type );
		if ( ! $targets ) {
			echo '<p>' . esc_html__( 'Nothing to write for this kind of page yet.', 'whd' ) . '</p>';
			return;
		}
		if ( ! WHD_AI::ready() ) {
			printf(
				'<p class="whd-ai-off">%s <a href="%s">%s</a></p>',
				esc_html__( 'No model is connected yet, so this panel cannot write anything.', 'whd' ),
				esc_url( self::url() ),
				esc_html__( 'Connect one in WHD → AI content.', 'whd' )
			);
			return;
		}
		?>
		<div class="whd-ai-panel" id="whd-ai-panel" data-post="<?php echo esc_attr( $post->ID ); ?>">

			<div class="whd-ai-panel__row">
				<label class="whd-ai-panel__label" for="whd-ai-target"><?php esc_html_e( 'What should it write?', 'whd' ); ?></label>
				<select id="whd-ai-target" class="whd-ai-panel__select">
					<?php foreach ( $targets as $key => $spec ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $spec['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="whd-ai-panel__row">
				<span class="whd-ai-panel__label"><?php esc_html_e( 'Keywords to work in', 'whd' ); ?></span>
				<div class="whd-ai-chips" id="whd-ai-chips"></div>
				<input type="text" class="whd-ai-panel__kw" id="whd-ai-keywords" placeholder="<?php esc_attr_e( 'or type your own, separated by commas', 'whd' ); ?>">
				<p class="description">
					<?php esc_html_e( 'Suggestions come from your keyword list, weighted towards this page and away from terms already used elsewhere. Click one to switch it on or off.', 'whd' ); ?>
					<a href="<?php echo esc_url( self::url( 'keywords' ) ); ?>"><?php esc_html_e( 'Manage the list', 'whd' ); ?></a>
				</p>
			</div>

			<div class="whd-ai-panel__row">
				<label class="whd-ai-panel__label" for="whd-ai-instruction"><?php esc_html_e( 'Anything specific about this one?', 'whd' ); ?></label>
				<textarea id="whd-ai-instruction" rows="2" class="whd-ai-panel__instruction" placeholder="<?php esc_attr_e( 'e.g. mention that it runs small through the shoulder, and that the linen softens after a wash', 'whd' ); ?>"></textarea>
			</div>

			<p class="whd-ai-panel__go">
				<button type="button" class="button button-primary" id="whd-ai-go"><?php esc_html_e( 'Write it', 'whd' ); ?></button>
				<span class="whd-ai-panel__status" id="whd-ai-status" role="status"></span>
			</p>

			<div class="whd-ai-result" id="whd-ai-result" hidden>
				<div class="whd-ai-result__check" id="whd-ai-check"></div>
				<textarea class="whd-ai-result__text" id="whd-ai-text" rows="16"></textarea>
				<p class="whd-ai-result__actions">
					<button type="button" class="button button-primary" id="whd-ai-apply"></button>
					<button type="button" class="button" id="whd-ai-copy"><?php esc_html_e( 'Copy', 'whd' ); ?></button>
					<button type="button" class="button" id="whd-ai-again"><?php esc_html_e( 'Write another', 'whd' ); ?></button>
					<span class="whd-ai-result__count" id="whd-ai-count"></span>
				</p>
				<p class="description"><?php esc_html_e( 'Edit it here first if you want to — what you apply is what is in the box, not what the model sent.', 'whd' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ─────────────────────────── REST ─────────────────────────── */

	public static function rest() {
		$can_edit = static function ( WP_REST_Request $r ) {
			$id = (int) $r->get_param( 'post_id' );
			return $id && current_user_can( 'edit_post', $id );
		};
		$is_admin = static function () {
			return current_user_can( 'manage_options' );
		};

		register_rest_route( 'whd/v1', '/ai/generate', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_generate' ],
			'permission_callback' => $can_edit,
		] );
		register_rest_route( 'whd/v1', '/ai/apply', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_apply' ],
			'permission_callback' => $can_edit,
		] );
		register_rest_route( 'whd/v1', '/ai/suggest', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'rest_suggest' ],
			'permission_callback' => $can_edit,
		] );
		register_rest_route( 'whd/v1', '/ai/brief', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_brief' ],
			'permission_callback' => $is_admin,
		] );
		register_rest_route( 'whd/v1', '/ai/recaptcha-test', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_recaptcha_test' ],
			'permission_callback' => $is_admin,
		] );
	}

	/** Assess a token minted on this screen, and hand back exactly what Google said. */
	public static function rest_recaptcha_test( WP_REST_Request $r ) {
		return new WP_REST_Response( WHD_AI::recaptcha_check( (string) $r->get_param( 'token' ), 'whd_admin_test' ), 200 );
	}

	public static function rest_generate( WP_REST_Request $r ) {
		$result = WHD_AI::generate( [
			'post_id'     => (int) $r->get_param( 'post_id' ),
			'target'      => sanitize_key( (string) $r->get_param( 'target' ) ),
			'keywords'    => (array) $r->get_param( 'keywords' ),
			'instruction' => sanitize_textarea_field( (string) $r->get_param( 'instruction' ) ),
		] );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 400 );
		}
		$result['html'] = WHD_AI::to_html( $result['text'] );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Put a draft where it belongs.
	 *
	 * Only the places with no field on this screen are written here — the story builder and the two
	 * search-snippet values. Long and short descriptions go into the editor in the browser instead,
	 * so they travel through the normal save and never overwrite an edit in progress.
	 */
	public static function rest_apply( WP_REST_Request $r ) {
		$post_id = (int) $r->get_param( 'post_id' );
		$target  = sanitize_key( (string) $r->get_param( 'target' ) );
		$text    = (string) $r->get_param( 'text' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new WP_REST_Response( [ 'message' => __( 'That page no longer exists.', 'whd' ) ], 404 );
		}
		$spec = WHD_AI::targets( $post->post_type )[ $target ] ?? null;
		if ( ! $spec ) {
			return new WP_REST_Response( [ 'message' => __( 'That cannot be applied here.', 'whd' ) ], 400 );
		}

		switch ( $spec['apply'] ) {
			case 'story':
				if ( ! class_exists( 'WHD_Product_Story' ) ) {
					return new WP_REST_Response( [ 'message' => __( 'The product story module is not available.', 'whd' ) ], 400 );
				}
				$design           = WHD_Product_Story::get( $post_id );
				$blocks           = WHD_AI::to_blocks( $text );
				if ( ! $blocks ) {
					return new WP_REST_Response( [ 'message' => __( 'There was nothing in that to save.', 'whd' ) ], 400 );
				}
				// Keep any images the owner already placed; replace the words.
				$images           = array_values( array_filter( $design['blocks'], static fn( $b ) => 'image' === ( $b['type'] ?? '' ) ) );
				$design['blocks'] = array_merge( $blocks, $images );
				WHD_Product_Story::save( $post_id, $design );
				update_post_meta( $post_id, WHD_Product_Story::META . '_off', '1' );
				$message = __( 'Saved as the product story and switched on.', 'whd' );
				break;

			case 'meta_description':
				update_post_meta( $post_id, '_omc_meta_description', sanitize_textarea_field( wp_strip_all_tags( $text ) ) );
				$message = __( 'Saved as this page’s meta description.', 'whd' );
				break;

			case 'seo_title':
				update_post_meta( $post_id, '_omc_seo_title', sanitize_text_field( wp_strip_all_tags( $text ) ) );
				$message = __( 'Saved as this page’s search title.', 'whd' );
				break;

			default:
				return new WP_REST_Response( [ 'message' => __( 'That one is applied in the editor, not here.', 'whd' ) ], 400 );
		}

		WHD_AI_Keywords::mark_used( (array) $r->get_param( 'keywords' ) );
		return new WP_REST_Response( [ 'message' => $message ], 200 );
	}

	public static function rest_suggest( WP_REST_Request $r ) {
		WHD_AI_Keywords::maybe_install();
		return new WP_REST_Response( [ 'keywords' => WHD_AI_Keywords::suggest( (int) $r->get_param( 'post_id' ), 8 ) ], 200 );
	}

	public static function rest_brief( WP_REST_Request $r ) {
		$mode = sanitize_key( (string) $r->get_param( 'mode' ) );

		if ( 'polish' === $mode ) {
			$draft = trim( (string) $r->get_param( 'text' ) ) ?: WHD_AI::draft_brief();
			$text  = WHD_AI::call(
				__( 'You write brand briefs for copywriters. You return the brief itself and nothing else — no preamble, no headings numbered like a report, no notes about what you changed.', 'whd' ),
				__( 'Tighten this brief for the copywriter who will work from it. Keep every fact. Cut anything that could be said of any shop. Say plainly who the customer is, how the shop sounds, and what it never does. Six short paragraphs at most.', 'whd' ) . "\n\n" . $draft
			);
			if ( is_wp_error( $text ) ) {
				return new WP_REST_Response( [ 'message' => $text->get_error_message() ], 400 );
			}
			return new WP_REST_Response( [ 'text' => trim( $text ) ], 200 );
		}

		return new WP_REST_Response( [ 'text' => WHD_AI::draft_brief() ], 200 );
	}
}
