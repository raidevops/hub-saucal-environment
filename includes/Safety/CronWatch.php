<?php
/**
 * Runtime monitor: detect a plugin/class that thrashes the WP-Cron option.
 *
 * Background
 * ----------
 * WordPress keeps every scheduled event in a SINGLE `wp_options` row named
 * `cron` (autoloaded). Every `wp_schedule_event()` / `wp_unschedule_event()` /
 * `wp_reschedule_event()` call rewrites that one row via `update_option()`.
 *
 * A misbehaving plugin that (un)schedules events on *every* request — e.g. a
 * hook that strips and re-adds a schedule on `init`, or an unschedule loop — turns
 * that single row into a write hotspot. Under concurrency the requests serialise
 * on the row lock and pile up, which looks like a site-wide slowdown / "database
 * is locked" incident even though no single query is slow. (We hit exactly this
 * with a checkout-performance shim that re-migrated PYS's cron schedule on every
 * request.)
 *
 * What this does
 * --------------
 * It listens for writes to the `cron` option and, using a backtrace, attributes
 * each write to the nearest non-core class + plugin/mu-plugin/theme. When a
 * single caller rewrites `cron` two or more times in one request (or keeps doing
 * it across requests) it persists a compact finding to the {@see self::OPTION}
 * site option. {@see \SaucalHub\Safety\Checks\CronOptionThrash} reads that option
 * (locally, or over the shared DB for remote sites) and the admin notice below
 * surfaces it — naming the class and plugin and suggesting an update or removal.
 *
 * Self-bounding: a healthy site writes `cron` 0–1 times per request and never
 * trips the threshold, so we never touch the DB on it. On a thrashing site we
 * persist at most once per {@see self::FLUSH_THROTTLE} seconds per offender.
 *
 * Limitation: attribution relies on the cron API funnelling through
 * `update_option('cron', …)`. Code that rewrites the row with raw SQL bypasses
 * this and won't be attributed.
 *
 * @package SaucalHub
 */

namespace SaucalHub\Safety;

if ( ! defined( 'ABSPATH' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	exit;
}

/**
 * WP-Cron option write monitor.
 */
final class CronWatch {

	/**
	 * Site option holding accumulated findings (autoload = no).
	 */
	const OPTION = 'saucal_hub_cron_watch';

	/**
	 * Scratch option a forensic second-pass writes its findings to, for the
	 * parent process to read back (autoload = no). Always transient.
	 */
	const PROBE_OPTION = 'saucal_hub_cron_watch_probe';

	/**
	 * Default number of `cron`-option writes by one caller in a single request
	 * that counts as suspicious. Filterable via `saucal_hub_cron_watch_threshold`.
	 */
	const DEFAULT_THRESHOLD = 2;

	/**
	 * Drop offenders not seen for this long (seconds), so a fixed plugin clears.
	 */
	const WINDOW = DAY_IN_SECONDS;

	/**
	 * Minimum seconds between DB persists for an already-known offender.
	 */
	const FLUSH_THROTTLE = MINUTE_IN_SECONDS;

	/**
	 * Per-request, per-signature write accumulator.
	 *
	 * @var array<string,array>
	 */
	private static $request = array();

	/**
	 * Whether the shutdown flush already ran this request.
	 *
	 * @var bool
	 */
	private static $flushed = false;

	/**
	 * Attach listeners. Cheap on healthy sites: the cron hooks only fire when the
	 * `cron` option actually changes, and the shutdown flush no-ops with no data.
	 *
	 * @return void
	 */
	public static function hooks(): void {
		add_action( 'add_option_cron', array( __CLASS__, 'on_cron_add' ), 10, 2 );
		add_action( 'update_option_cron', array( __CLASS__, 'on_cron_update' ), 10, 2 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 0 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		}
	}

	/**
	 * Threshold (filterable).
	 *
	 * @return int
	 */
	public static function threshold(): int {
		$value = (int) apply_filters( 'saucal_hub_cron_watch_threshold', self::DEFAULT_THRESHOLD );
		return max( 2, $value );
	}

	/**
	 * `add_option('cron', …)` — first ever write.
	 *
	 * @param string $option Option name (always 'cron' here).
	 * @param mixed  $value  New value.
	 *
	 * @return void
	 */
	public static function on_cron_add( $option, $value ): void {
		self::record( array(), $value );
	}

	/**
	 * `update_option('cron', …)` — the common path.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 *
	 * @return void
	 */
	public static function on_cron_update( $old_value, $value ): void {
		self::record( $old_value, $value );
	}

	/**
	 * Accumulate one attributed write for this request.
	 *
	 * @param mixed $old_cron Previous cron array.
	 * @param mixed $new_cron New cron array.
	 *
	 * @return void
	 */
	private static function record( $old_cron, $new_cron ): void {
		$origin = self::attribute();
		$sig    = $origin['type'] . ':' . $origin['slug'] . ':' . ( '' !== $origin['class'] ? $origin['class'] : $origin['function'] );

		if ( ! isset( self::$request[ $sig ] ) ) {
			self::$request[ $sig ]           = $origin;
			self::$request[ $sig ]['writes'] = 0;
			self::$request[ $sig ]['hooks']  = array();
		}

		++self::$request[ $sig ]['writes'];

		foreach ( self::changed_hooks( $old_cron, $new_cron ) as $hook ) {
			self::$request[ $sig ]['hooks'][ $hook ] = true;
		}
	}

	/**
	 * Walk the call stack and attribute the write to the nearest non-core frame
	 * living under wp-content (and not inside this plugin).
	 *
	 * @return array{class:string,function:string,file:string,line:int,type:string,slug:string}
	 */
	private static function attribute(): array {
		$frames      = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$content_dir = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		// Plugin root = two levels up from this file (includes/Safety/). Computed
		// from __DIR__ rather than the PLUGIN_FILE constant so it also works under
		// cli-bootstrap.php, where the plugin bootstrap (and that constant) is not
		// loaded.
		$self_dir = trailingslashit( wp_normalize_path( dirname( __DIR__, 2 ) ) );

		$count = count( $frames );
		for ( $i = 0; $i < $count; $i++ ) {
			$frame = $frames[ $i ];
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( $frame['file'] );

			// Skip WordPress core (the option/cron/hook plumbing) and ourselves.
			if ( false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
				continue;
			}
			if ( '' !== $self_dir && 0 === strpos( $file, $self_dir ) ) {
				continue;
			}
			// Must live under wp-content (plugins / mu-plugins / themes).
			if ( 0 !== strpos( $file, $content_dir ) ) {
				continue;
			}

			// $frame is the cron-call SITE — its file:line is exactly where the
			// (un)schedule happened. The RESPONSIBLE method is this frame's own
			// class/function if it has one; but when the call site is a bare WP
			// function (wp_unschedule_event / update_option called directly from
			// user code), the offending method is the caller — the next frame out
			// (whose own file is core, because it was invoked via do_action).
			$class    = isset( $frame['class'] ) ? (string) $frame['class'] : '';
			$function = isset( $frame['function'] ) ? (string) $frame['function'] : '';
			if ( '' === $class && isset( $frames[ $i + 1 ] ) ) {
				$caller   = $frames[ $i + 1 ];
				$class    = isset( $caller['class'] ) ? (string) $caller['class'] : '';
				$function = isset( $caller['function'] ) ? (string) $caller['function'] : $function;
			}

			return self::describe_origin(
				$class,
				$function,
				$file,
				isset( $frame['line'] ) ? (int) $frame['line'] : 0,
				$content_dir
			);
		}

		// Could not pin it to a wp-content file (core/wp-config scheduling).
		return array(
			'class'    => '',
			'function' => '',
			'file'     => '',
			'line'     => 0,
			'type'     => 'unknown',
			'slug'     => '',
		);
	}

	/**
	 * Build a structured origin (responsible class/function + plugin location).
	 *
	 * @param string $caller_class    Responsible class ('' for a plain function).
	 * @param string $caller_function Responsible function/method name.
	 * @param string $file            Normalised absolute call-site file.
	 * @param int    $line            Call-site line.
	 * @param string $content_dir     Normalised, trailing-slashed wp-content dir.
	 *
	 * @return array
	 */
	private static function describe_origin( string $caller_class, string $caller_function, string $file, int $line, string $content_dir ): array {
		$rel   = ltrim( substr( $file, strlen( $content_dir ) ), '/' );
		$parts = explode( '/', $rel );

		$type = 'unknown';
		$slug = '';
		if ( count( $parts ) >= 2 && 'plugins' === $parts[0] ) {
			$type = 'plugin';
			$slug = $parts[1];
		} elseif ( count( $parts ) >= 2 && 'mu-plugins' === $parts[0] ) {
			$type = 'mu-plugin';
			$slug = $parts[1];
		} elseif ( count( $parts ) >= 2 && 'themes' === $parts[0] ) {
			$type = 'theme';
			$slug = $parts[1];
		}

		return array(
			'class'    => $caller_class,
			'function' => $caller_function,
			'file'     => $rel,
			'line'     => $line,
			'type'     => $type,
			'slug'     => $slug,
		);
	}

	/**
	 * The cron hook names that changed between two cron arrays. Falls back to the
	 * full set when only timestamps/args moved (a reschedule of the same hooks).
	 *
	 * @param mixed $old_cron Previous cron array.
	 * @param mixed $new_cron New cron array.
	 *
	 * @return array<int,string>
	 */
	private static function changed_hooks( $old_cron, $new_cron ): array {
		$o       = self::hook_names( $old_cron );
		$n       = self::hook_names( $new_cron );
		$changed = array_values( array_unique( array_merge( array_diff( $o, $n ), array_diff( $n, $o ) ) ) );
		if ( empty( $changed ) ) {
			$changed = array_values( array_unique( array_merge( $o, $n ) ) );
		}
		return array_slice( $changed, 0, 20 );
	}

	/**
	 * Flatten the hook names present in a cron array.
	 *
	 * @param mixed $cron Cron array.
	 *
	 * @return array<int,string>
	 */
	private static function hook_names( $cron ): array {
		if ( ! is_array( $cron ) ) {
			return array();
		}
		$hooks = array();
		foreach ( $cron as $timestamp => $hooks_at_time ) {
			if ( 'version' === $timestamp || ! is_array( $hooks_at_time ) ) {
				continue;
			}
			foreach ( array_keys( $hooks_at_time ) as $hook ) {
				$hooks[ (string) $hook ] = true;
			}
		}
		return array_keys( $hooks );
	}

	/**
	 * Persist suspicious findings at shutdown (throttled). No-op when this request
	 * had no caller cross the per-request threshold.
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( self::$flushed ) {
			return;
		}
		self::$flushed = true;

		if ( empty( self::$request ) ) {
			return;
		}

		$threshold  = self::threshold();
		$suspicious = array();
		foreach ( self::$request as $sig => $row ) {
			if ( $row['writes'] >= $threshold ) {
				$suspicious[ $sig ] = $row;
			}
		}
		if ( empty( $suspicious ) ) {
			return;
		}

		$data      = get_option( self::OPTION, array() );
		$data      = is_array( $data ) ? $data : array();
		$offenders = isset( $data['offenders'] ) && is_array( $data['offenders'] ) ? $data['offenders'] : array();
		$now       = time();
		$dirty     = false;

		foreach ( $suspicious as $sig => $row ) {
			$existing = $offenders[ $sig ] ?? null;

			if ( null === $existing ) {
				$offenders[ $sig ] = self::build_record( $row, $now );
				$dirty             = true;
				continue;
			}

			$worse = $row['writes'] > (int) ( $existing['max_per_req'] ?? 0 );
			$stale = ( $now - (int) ( $existing['last_seen'] ?? 0 ) ) >= self::FLUSH_THROTTLE;
			if ( ! $worse && ! $stale ) {
				continue; // Throttled: already recorded recently and not worse.
			}

			$existing['requests']    = (int) ( $existing['requests'] ?? 0 ) + 1;
			$existing['writes']      = (int) ( $existing['writes'] ?? 0 ) + (int) $row['writes'];
			$existing['max_per_req'] = max( (int) ( $existing['max_per_req'] ?? 0 ), (int) $row['writes'] );
			$existing['last_seen']   = $now;
			$existing['hooks']       = array_slice(
				array_values( array_unique( array_merge( (array) ( $existing['hooks'] ?? array() ), array_keys( $row['hooks'] ) ) ) ),
				0,
				20
			);
			$existing                = self::refresh_plugin_meta( $existing );
			$offenders[ $sig ]       = $existing;
			$dirty                   = true;
		}

		// Prune offenders that have aged out (e.g. the plugin was fixed/removed).
		foreach ( $offenders as $sig => $offender ) {
			if ( ( $now - (int) ( $offender['last_seen'] ?? 0 ) ) > self::WINDOW ) {
				unset( $offenders[ $sig ] );
				$dirty = true;
			}
		}

		if ( ! $dirty ) {
			return;
		}

		$data['offenders'] = $offenders;
		$data['updated']   = $now;
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Build a fresh offender record, enriched with plugin metadata.
	 *
	 * @param array $row Per-request accumulator row.
	 * @param int   $now Timestamp.
	 *
	 * @return array
	 */
	private static function build_record( array $row, int $now ): array {
		$record = array(
			'class'       => $row['class'],
			'function'    => $row['function'],
			'file'        => $row['file'],
			'line'        => $row['line'],
			'type'        => $row['type'],
			'slug'        => $row['slug'],
			'hooks'       => array_slice( array_keys( $row['hooks'] ), 0, 20 ),
			'writes'      => (int) $row['writes'],
			'requests'    => 1,
			'max_per_req' => (int) $row['writes'],
			'first_seen'  => $now,
			'last_seen'   => $now,
		);
		return self::refresh_plugin_meta( $record );
	}

	/**
	 * Resolve human plugin name, version and available-update version for an
	 * offender record. Only called at flush time (throttled), so the
	 * `get_plugins()` cost is rare.
	 *
	 * @param array $record Offender record.
	 *
	 * @return array
	 */
	private static function refresh_plugin_meta( array $record ): array {
		$record['plugin_name']    = '' !== ( $record['slug'] ?? '' ) ? $record['slug'] : __( 'unknown', 'saucal-hub' );
		$record['plugin_file']    = '';
		$record['plugin_version'] = '';
		$record['update_version'] = '';

		if ( 'plugin' !== ( $record['type'] ?? '' ) || '' === ( $record['slug'] ?? '' ) ) {
			return $record;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$slug = $record['slug'];
		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( 0 !== strpos( $plugin_file, $slug . '/' ) ) {
				continue;
			}
			$record['plugin_file']    = $plugin_file;
			$record['plugin_name']    = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $slug;
			$record['plugin_version'] = $plugin_data['Version'] ?? '';
			break;
		}

		if ( '' !== $record['plugin_file'] ) {
			$updates = get_site_transient( 'update_plugins' );
			if ( isset( $updates->response[ $record['plugin_file'] ]->new_version ) ) {
				$record['update_version'] = (string) $updates->response[ $record['plugin_file'] ]->new_version;
			}
		}

		return $record;
	}

	/**
	 * Evaluate stored findings into a list of current offenders with a level.
	 *
	 * Pure: takes the raw option array (works for local or remote data) and
	 * returns offenders keyed by signature, each tagged 'unsafe' or 'warning'.
	 *
	 * @param mixed    $data Stored option value.
	 * @param int|null $now  Reference time (defaults to now).
	 *
	 * @return array<string,array>
	 */
	public static function evaluate( $data, ?int $now = null ): array {
		$now       = $now ?? time();
		$offenders = ( is_array( $data ) && isset( $data['offenders'] ) && is_array( $data['offenders'] ) ) ? $data['offenders'] : array();
		$threshold = self::threshold();
		$out       = array();

		foreach ( $offenders as $sig => $offender ) {
			$last = (int) ( $offender['last_seen'] ?? 0 );
			if ( ( $now - $last ) > self::WINDOW ) {
				continue;
			}
			$max_per_req = (int) ( $offender['max_per_req'] ?? 0 );
			if ( $max_per_req < $threshold ) {
				continue;
			}
			$requests              = (int) ( $offender['requests'] ?? 0 );
			$offender['signature'] = $sig;
			$offender['level']     = ( $requests >= 2 || $max_per_req >= ( $threshold * 2 ) )
				? Check::STATUS_UNSAFE
				: Check::STATUS_WARNING;
			$out[ $sig ]           = $offender;
		}

		uasort(
			$out,
			static function ( $a, $b ) {
				return (int) ( $b['writes'] ?? 0 ) <=> (int) ( $a['writes'] ?? 0 );
			}
		);

		return $out;
	}

	/**
	 * One-line human summary of an offender (used by the Check and the notice).
	 *
	 * @param array $offender Offender record.
	 *
	 * @return string
	 */
	public static function describe_offender( array $offender ): string {
		$who = '' !== ( $offender['class'] ?? '' )
			? $offender['class']
			: ( ( $offender['function'] ?? '' ) ? $offender['function'] . '()' : __( 'unknown caller', 'saucal-hub' ) );

		$plugin = $offender['plugin_name'] ?? ( $offender['slug'] ?? __( 'unknown', 'saucal-hub' ) );
		if ( isset( $offender['type'] ) && 'plugin' !== $offender['type'] ) {
			/* translators: 1: name, 2: type (mu-plugin/theme) */
			$plugin = sprintf( __( '%1$s (%2$s)', 'saucal-hub' ), $plugin, $offender['type'] );
		}

		$suggestion = ( '' !== ( $offender['update_version'] ?? '' ) )
			? sprintf(
				/* translators: %s: version */
				__( 'Update it (version %s is available) or remove/replace it.', 'saucal-hub' ),
				$offender['update_version']
			)
			: __( 'Update it to a fixed version, or remove/replace the offending code.', 'saucal-hub' );

		return sprintf(
			/* translators: 1: plugin name, 2: class/function, 3: max writes in one request, 4: request count, 5: suggestion */
			__( '%1$s — %2$s rewrote the WP-Cron option up to %3$d× in a single request (seen across %4$d sampled requests). This locks the wp_options `cron` row and stalls concurrent requests. %5$s', 'saucal-hub' ),
			$plugin,
			$who,
			(int) ( $offender['max_per_req'] ?? 0 ),
			(int) ( $offender['requests'] ?? 0 ),
			$suggestion
		);
	}

	/**
	 * Admin notice surfacing local UNSAFE offenders.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$offenders = self::evaluate( get_option( self::OPTION, array() ) );
		$critical  = array_filter(
			$offenders,
			static function ( $offender ) {
				return Check::STATUS_UNSAFE === $offender['level'];
			}
		);
		if ( empty( $critical ) ) {
			return;
		}

		$worst = reset( $critical );
		$url   = admin_url( 'admin.php?page=' . \SaucalHub\Admin\Page::SLUG );
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Saucal Hub: a plugin is thrashing the WP-Cron option.', 'saucal-hub' ); ?></strong>
			</p>
			<p><?php echo esc_html( self::describe_offender( $worst ) ); ?></p>
			<?php if ( '' !== ( $worst['file'] ?? '' ) ) : ?>
				<p><code><?php echo esc_html( $worst['file'] . ( $worst['line'] ? ':' . $worst['line'] : '' ) ); ?></code></p>
			<?php endif; ?>
			<?php if ( count( $critical ) > 1 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of additional offenders */
						esc_html__( '+%d more offender(s) detected.', 'saucal-hub' ),
						(int) ( count( $critical ) - 1 )
					);
					?>
				</p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Saucal Hub → Safety', 'saucal-hub' ); ?></a></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Forensic (active) detection — driven by the CLI command.
	 *
	 * The passive monitor only sees writes as real traffic arrives on a site
	 * where the plugin is active. This actively replays the per-request action
	 * hooks under instrumentation so a SINGLE in-context run reproduces and
	 * attributes the thrash — even on a site where the plugin is not active
	 * (loaded via cli-bootstrap.php).
	 * ------------------------------------------------------------------ */

	/**
	 * Run a forensic probe and return attributed offenders.
	 *
	 * SAFE by default — it reads what THIS process captured *naturally* during
	 * its own WP bootstrap. As long as the cron listeners were attached before
	 * `init` fired (the plugin's hooks() on `plugins_loaded`, or the early hook in
	 * cli-bootstrap.php), any code that (un)schedules events on a per-request hook
	 * has already been recorded into self::$request — no re-execution needed, so a
	 * single in-context `wp` run reproduces and attributes the thrash.
	 *
	 * The opt-in $replay mode re-fires `init`/`wp_loaded` to force detection on an
	 * otherwise idle load. That re-runs hook callbacks and CAN fatal if one isn't
	 * re-entrant, so the CLI gates it behind --replay on clone hosts only.
	 *
	 * @param int  $iterations Replay iterations (only used when $replay is true).
	 * @param bool $replay     Re-fire per-request hooks (unsafe; opt-in).
	 *
	 * @return array<string,array> Offender records (evaluate() shape) + 'level'.
	 */
	public static function forensic_probe( int $iterations = 1, bool $replay = false ): array {
		$now       = time();
		$aggregate = array();

		$ingest = static function ( array $request ) use ( &$aggregate, $now ) {
			foreach ( $request as $sig => $row ) {
				if ( ! isset( $aggregate[ $sig ] ) ) {
					$aggregate[ $sig ]                = self::build_record( $row, $now );
					$aggregate[ $sig ]['requests']    = 0;
					$aggregate[ $sig ]['writes']      = 0;
					$aggregate[ $sig ]['max_per_req'] = 0;
				}
				++$aggregate[ $sig ]['requests'];
				$aggregate[ $sig ]['writes']     += (int) $row['writes'];
				$aggregate[ $sig ]['max_per_req'] = max( (int) $aggregate[ $sig ]['max_per_req'], (int) $row['writes'] );
				$aggregate[ $sig ]['hooks']       = array_slice(
					array_values( array_unique( array_merge( (array) $aggregate[ $sig ]['hooks'], array_keys( $row['hooks'] ) ) ) ),
					0,
					20
				);
			}
		};

		// Natural capture from this process's own bootstrap (safe).
		if ( ! empty( self::$request ) ) {
			$ingest( self::$request );
		}

		// Opt-in active replay (unsafe; guarded by the CLI).
		if ( $replay ) {
			add_action( 'add_option_cron', array( __CLASS__, 'on_cron_add' ), 10, 2 );
			add_action( 'update_option_cron', array( __CLASS__, 'on_cron_update' ), 10, 2 );
			$rounds = max( 1, $iterations );
			for ( $i = 0; $i < $rounds; $i++ ) {
				self::$request = array();
				try {
					self::replay_request_hooks();
				} catch ( \Throwable $e ) {
					// A callback wasn't re-entrant; keep what we captured and stop.
					$ingest( self::$request );
					break;
				}
				$ingest( self::$request );
			}
		}

		// We own persistence via store_findings(); don't let shutdown double-write.
		self::$flushed = true;
		self::$request = array();

		return self::evaluate( array( 'offenders' => $aggregate ), $now );
	}

	/**
	 * Re-fire the action hooks where per-request (un)schedulers commonly live.
	 * `init` is the usual culprit; `wp_loaded` covers a few more. We deliberately
	 * do NOT replay `plugins_loaded` (re-bootstrapping is too broad).
	 *
	 * @return void
	 */
	private static function replay_request_hooks(): void {
		do_action( 'init' );
		do_action( 'wp_loaded' );
		if ( is_admin() ) {
			do_action( 'admin_init' );
		}
	}

	/**
	 * Persist forensic findings as an AUTHORITATIVE snapshot.
	 *
	 * A forensic run is a deliberate point-in-time re-check in the site's own
	 * context, so its result is the truth for the current code state: it REPLACES
	 * the stored offenders rather than merging. That means once the offending code
	 * is fixed/removed, a re-run with --report clears the finding immediately (the
	 * check flips back to SAFE) instead of waiting out the 24h window.
	 *
	 * @param array<string,array> $offenders Result of forensic_probe().
	 *
	 * @return void
	 */
	public static function store_findings( array $offenders ): void {
		$now      = time();
		$snapshot = array();

		foreach ( $offenders as $sig => $offender ) {
			unset( $offender['level'], $offender['signature'] );
			$offender['last_seen'] = $now;
			if ( empty( $offender['first_seen'] ) ) {
				$offender['first_seen'] = $now;
			}
			// Forensic confirmation is deterministic — make sure it registers as a
			// sustained offender so evaluate() escalates it to UNSAFE.
			$offender['requests'] = max( 2, (int) ( $offender['requests'] ?? 0 ) );
			$snapshot[ $sig ]     = $offender;
		}

		// Empty snapshot (issue fixed) → drop the option entirely → SAFE.
		if ( empty( $snapshot ) ) {
			delete_option( self::OPTION );
			return;
		}

		update_option(
			self::OPTION,
			array(
				'offenders' => $snapshot,
				'updated'   => $now,
			),
			false
		);
	}
}
