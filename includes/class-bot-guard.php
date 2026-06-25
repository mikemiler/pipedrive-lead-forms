<?php
/**
 * Bot protection. Guiding principle: stay invisible and user friendly.
 * Only very confident signals (honeypot, tampered signature) block silently.
 * Weak signals (too fast) only flag the lead so a real visitor is never lost.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless checks plus a per IP rate limiter.
 */
class Pdlead_Bot_Guard {

	const ACTION_ALLOW = 'allow';
	const ACTION_FLAG  = 'flag';
	const ACTION_DROP  = 'drop';
	const ACTION_ERROR = 'error';

	const TS_MAX_AGE = DAY_IN_SECONDS;

	/**
	 * HMAC secret used to sign timestamps.
	 *
	 * @return string
	 */
	private static function secret() {
		$secret = get_option( 'pdlead_hmac_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'pdlead_hmac_secret', $secret, false );
		}
		return $secret;
	}

	/**
	 * Sign a timestamp.
	 *
	 * @param int $ts Unix timestamp.
	 * @return string
	 */
	public static function sign_ts( $ts ) {
		return hash_hmac( 'sha256', (string) $ts, self::secret() );
	}

	/**
	 * Issue a fresh token: nonce plus signed timestamp.
	 *
	 * @return array
	 */
	public static function issue_token() {
		$ts = time();
		return array(
			'nonce' => wp_create_nonce( PDLEAD_NONCE_ACTION ),
			'ts'    => $ts,
			'sig'   => self::sign_ts( $ts ),
		);
	}

	/**
	 * Salted hash of an IP address for privacy friendly storage and limiting.
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function ip_hash( $ip ) {
		return wp_hash( 'pdlead_ip|' . $ip );
	}

	/**
	 * Resolve the client IP.
	 *
	 * REMOTE_ADDR is used by default because it cannot be spoofed. The
	 * Cloudflare connecting IP header is only trusted when the site explicitly
	 * opts in via define( 'PDLEAD_TRUST_CF_IP', true ) in wp-config.php, which
	 * should only be set when all traffic really reaches the origin through
	 * Cloudflare. Otherwise an attacker could forge the header to bypass the
	 * rate limiter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		if ( defined( 'PDLEAD_TRUST_CF_IP' ) && PDLEAD_TRUST_CF_IP && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '';
	}

	/**
	 * Evaluate a submission against all enabled checks.
	 *
	 * @param array  $params  Raw request parameters.
	 * @param string $ip      Client IP.
	 * @return array { action: string, reason: string, message: string }
	 */
	public static function evaluate( $params, $ip ) {
		// 1. Honeypot. Very high confidence: a filled hidden field means a bot.
		$honeypot = isset( $params[ Pdlead_Form_Renderer::HONEYPOT ] ) ? trim( (string) $params[ Pdlead_Form_Renderer::HONEYPOT ] ) : '';
		if ( '' !== $honeypot ) {
			return self::result( self::ACTION_DROP, 'honeypot' );
		}

		// 2. Timestamp integrity and age.
		$ts  = isset( $params['pdlead_ts'] ) ? (int) $params['pdlead_ts'] : 0;
		$sig = isset( $params['pdlead_sig'] ) ? (string) $params['pdlead_sig'] : '';

		if ( $ts <= 0 || '' === $sig || ! hash_equals( self::sign_ts( $ts ), $sig ) ) {
			// Tampered or missing signature. Treat as a bot, drop silently.
			return self::result( self::ACTION_DROP, 'bad_signature' );
		}

		$age = time() - $ts;
		if ( $age > self::TS_MAX_AGE ) {
			// Stale token (form left open a long time). Ask the client to refresh.
			return self::result( self::ACTION_ERROR, 'expired', __( 'Your session expired. Please submit again.', 'pipedrive-lead-forms' ) );
		}

		// 3. Rate limit. Friendly error, never a silent drop.
		if ( Pdlead_Settings::get( 'ratelimit_enabled' ) && '' !== $ip ) {
			if ( self::is_rate_limited( self::ip_hash( $ip ) ) ) {
				return self::result( self::ACTION_ERROR, 'rate_limited', __( 'Too many attempts. Please try again in a few minutes.', 'pipedrive-lead-forms' ) );
			}
		}

		// 4. Turnstile (optional). A clear failure is a hard error so a real
		// user can retry. A network error to Cloudflare fails open (we never
		// want to lose a lead) but flags the lead for review.
		$soft_flag = false;
		if ( Pdlead_Settings::get( 'turnstile_enabled' ) && Pdlead_Settings::get( 'turnstile_secret_key' ) ) {
			$token  = isset( $params['cf-turnstile-response'] ) ? (string) $params['cf-turnstile-response'] : '';
			$result = self::verify_turnstile( $token, $ip );
			if ( 'fail' === $result ) {
				return self::result( self::ACTION_ERROR, 'captcha', __( 'Captcha verification failed. Please try again.', 'pipedrive-lead-forms' ) );
			}
			if ( 'error' === $result ) {
				$soft_flag = true;
			}
		}

		// 5. Time trap. Weak signal: flag, do not drop.
		$min = max( 0, (int) Pdlead_Settings::get( 'time_trap_seconds' ) );
		if ( $min > 0 && $age < $min ) {
			return self::result( self::ACTION_FLAG, 'too_fast' );
		}

		if ( $soft_flag ) {
			return self::result( self::ACTION_FLAG, 'captcha_unverified' );
		}

		return self::result( self::ACTION_ALLOW, 'clean' );
	}

	/**
	 * Build a result array.
	 *
	 * @param string $action  Action constant.
	 * @param string $reason  Machine reason.
	 * @param string $message Optional human message.
	 * @return array
	 */
	private static function result( $action, $reason, $message = '' ) {
		return array(
			'action'  => $action,
			'reason'  => $reason,
			'message' => $message,
		);
	}

	/**
	 * Fixed window per IP rate limiter using a transient.
	 *
	 * @param string $ip_hash Salted IP hash.
	 * @return bool True when the limit is exceeded.
	 */
	private static function is_rate_limited( $ip_hash ) {
		$max    = max( 1, (int) Pdlead_Settings::get( 'ratelimit_max' ) );
		$window = max( 60, (int) Pdlead_Settings::get( 'ratelimit_window' ) );
		$key    = 'pdlead_rl_' . $ip_hash;

		$bucket = get_transient( $key );
		$now    = time();

		if ( ! is_array( $bucket ) || ! isset( $bucket['start'] ) || ( $now - $bucket['start'] ) > $window ) {
			$bucket = array(
				'start' => $now,
				'count' => 0,
			);
		}

		++$bucket['count'];
		set_transient( $key, $bucket, $window );

		return $bucket['count'] > $max;
	}

	/**
	 * Validate a Cloudflare Turnstile token server side.
	 *
	 * @param string $token Token from the widget.
	 * @param string $ip    Client IP.
	 * @return string One of 'pass', 'fail' or 'error' (could not verify).
	 */
	private static function verify_turnstile( $token, $ip ) {
		if ( '' === $token ) {
			return 'fail';
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => Pdlead_Settings::get( 'turnstile_secret_key' ),
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Cloudflare unreachable. Fail open so a real lead is not lost, but
			// signal 'error' so the caller flags the submission for review.
			return 'error';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['success'] ) ? 'pass' : 'fail';
	}
}
