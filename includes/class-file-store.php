<?php
/**
 * File upload storage. Uploaded files live in a protected subdirectory of the
 * WordPress uploads folder, are validated against WordPress' own safe MIME
 * whitelist (optionally narrowed by the admin), and are referenced from the
 * submission payload by a relative path so retries and cleanup can find them.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, locates and removes uploaded files.
 */
class Pdlead_File_Store {

	/**
	 * Subdirectory (relative to the uploads basedir) holding all uploads.
	 */
	const SUBDIR = 'pdlead-uploads';

	/**
	 * Absolute path of the protected upload directory.
	 *
	 * @return string
	 */
	public static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
	}

	/**
	 * Resolve a stored relative path to an absolute path.
	 *
	 * @param string $relative Path relative to the uploads basedir.
	 * @return string
	 */
	public static function abs_path( $relative ) {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . ltrim( (string) $relative, '/' );
	}

	/**
	 * Create the upload directory and harden it against direct access.
	 * Best effort: .htaccess only protects Apache; on nginx the obscurity of
	 * randomized filenames plus the admin-only download handler is the guard.
	 */
	public static function ensure_protected() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Options -Indexes\n"
				. "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- one-off best effort hardening file; WP_Filesystem would need front-end credentials and failure is non-fatal.
			@file_put_contents( $htaccess, $rules );
		}

		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- one-off best effort listing guard; see above.
			@file_put_contents( $index, '' );
		}
	}

	/**
	 * Validate and store the files submitted for a single form field.
	 *
	 * @param string $key   Field key.
	 * @param array  $files The $_FILES['fields'] group from the request.
	 * @return array {
	 *     @type array[] $files  Stored file metadata (name, file, url, size, type).
	 *     @type bool    $error  True when any submitted file failed validation.
	 * }
	 */
	public static function handle_field_files( $key, $files ) {
		$result = array(
			'files' => array(),
			'error' => false,
		);

		$normalized = self::normalize( $key, $files );
		if ( empty( $normalized ) ) {
			return $result;
		}

		self::ensure_protected();

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$mimes    = Pdlead_Settings::allowed_file_mimes();
		$max_size = Pdlead_Settings::max_file_size_bytes();

		foreach ( $normalized as $file ) {
			// An empty file input is not an error.
			if ( UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				continue;
			}
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$result['error'] = true;
				continue;
			}
			if ( (int) $file['size'] > $max_size ) {
				$result['error'] = true;
				continue;
			}

			add_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );
			$moved = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => $mimes,
				)
			);
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_upload_dir' ) );

			if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
				$result['error'] = true;
				continue;
			}

			$uploads  = wp_upload_dir();
			$relative = ltrim( str_replace( $uploads['basedir'], '', $moved['file'] ), '/' );

			$result['files'][] = array(
				'name' => sanitize_file_name( $file['name'] ),
				'file' => $relative,
				'url'  => isset( $moved['url'] ) ? $moved['url'] : '',
				'size' => (int) $file['size'],
				'type' => isset( $moved['type'] ) ? $moved['type'] : '',
			);
		}

		return $result;
	}

	/**
	 * Delete a stored file by its relative path. Constrained to the upload dir.
	 *
	 * @param string $relative Path relative to the uploads basedir.
	 * @return bool
	 */
	public static function delete( $relative ) {
		$path = self::abs_path( $relative );
		$dir  = trailingslashit( self::dir() );

		// Never delete outside the managed directory.
		if ( 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $dir ) ) ) {
			return false;
		}
		if ( ! file_exists( $path ) ) {
			return false;
		}
		return wp_delete_file( $path ) || ! file_exists( $path );
	}

	/**
	 * Redirect wp_handle_upload into the protected subdirectory.
	 *
	 * @param array $dirs Upload directory parts.
	 * @return array
	 */
	public static function filter_upload_dir( $dirs ) {
		$dirs['subdir'] = '/' . self::SUBDIR;
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/**
	 * Flatten the PHP $_FILES grouping for a multi-file field into a list of
	 * single-file arrays.
	 *
	 * For an input named fields[key][] PHP nests the values as
	 * $files['name'][key][i], $files['tmp_name'][key][i], etc.
	 *
	 * @param string $key   Field key.
	 * @param array  $files The $_FILES['fields'] group.
	 * @return array[] List of { name, type, tmp_name, error, size } arrays.
	 */
	private static function normalize( $key, $files ) {
		if ( ! is_array( $files ) || ! isset( $files['name'][ $key ] ) ) {
			return array();
		}

		$names = $files['name'][ $key ];
		$out   = array();

		// Multiple files: each property is an array keyed by index.
		if ( is_array( $names ) ) {
			foreach ( array_keys( $names ) as $i ) {
				$out[] = array(
					'name'     => isset( $files['name'][ $key ][ $i ] ) ? $files['name'][ $key ][ $i ] : '',
					'type'     => isset( $files['type'][ $key ][ $i ] ) ? $files['type'][ $key ][ $i ] : '',
					'tmp_name' => isset( $files['tmp_name'][ $key ][ $i ] ) ? $files['tmp_name'][ $key ][ $i ] : '',
					'error'    => isset( $files['error'][ $key ][ $i ] ) ? $files['error'][ $key ][ $i ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $files['size'][ $key ][ $i ] ) ? $files['size'][ $key ][ $i ] : 0,
				);
			}
			return $out;
		}

		// Single file fallback.
		$out[] = array(
			'name'     => $files['name'][ $key ],
			'type'     => isset( $files['type'][ $key ] ) ? $files['type'][ $key ] : '',
			'tmp_name' => isset( $files['tmp_name'][ $key ] ) ? $files['tmp_name'][ $key ] : '',
			'error'    => isset( $files['error'][ $key ] ) ? $files['error'][ $key ] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $files['size'][ $key ] ) ? $files['size'][ $key ] : 0,
		);
		return $out;
	}
}
