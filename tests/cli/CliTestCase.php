<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base of the `cli` tests: a freshly installed WordPress site for every
 * test, and helpers that run WP-CLI commands against it.
 *
 * Every command is its own PHP process, like on a server. The plugin is
 * installed but not active after set_up(); tests activate it themselves.
 */
abstract class CliTestCase extends TestCase {

	const PLUGIN = 'wp-svg-images';

	/** An SVG that sanitization changes: a handler, a script and a shape. */
	const DIRTY_SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="30" height="20" onload="alert(1)"><script>alert(1)</script><rect width="1" height="1"/></svg>';

	/** What the plugin makes of DIRTY_SVG. */
	const SANITIZED_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="20"><rect width="1" height="1"></rect></svg>';

	/**
	 * Temporary folders to delete after the test.
	 *
	 * @var string[]
	 */
	private $temporary_folders = array();

	public function set_up() {
		parent::set_up();
		$this->delete_folder( $this->uploads_dir() );
		$this->reset_database();
		$this->wp_ok( 'core', 'install', '--url=http://example.org', '--title=WP SVG Images CLI tests', '--admin_user=admin', '--admin_password=password', '--admin_email=admin@example.org', '--skip-email' );
	}

	public function tear_down() {
		foreach ( $this->temporary_folders as $folder ) {
			$this->delete_folder( $folder );
		}
		$this->temporary_folders = array();
		$this->delete_folder( $this->uploads_dir() );
		parent::tear_down();
	}

	/**
	 * Empties the site's database. The image has no `mysql` client, which
	 * `wp db reset` needs, so this goes through PHP.
	 */
	private function reset_database() {
		mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
		$database = new mysqli( getenv( 'WP_TESTS_DB_HOST' ), getenv( 'WP_TESTS_DB_USER' ), getenv( 'WP_TESTS_DB_PASSWORD' ) );
		$name     = 'wpsvg_' . getenv( 'WPSVG_SUITE' );
		$database->query( "DROP DATABASE IF EXISTS `$name`" );
		$database->query( "CREATE DATABASE `$name` CHARACTER SET utf8mb4" );
		$database->close();
	}

	/**
	 * @return string Absolute path of the site's uploads folder.
	 */
	protected function uploads_dir() {
		return rtrim( getenv( 'WP_CORE_DIR' ), '/' ) . '/wp-content/uploads-cli';
	}

	/**
	 * Runs one WP-CLI command.
	 *
	 * @param string ...$arguments Command and arguments, one per item.
	 * @return array{code: int, stdout: string, stderr: string} Output is
	 *         trimmed and comes without WP-CLI's own deprecation notices.
	 */
	protected function wp( ...$arguments ) {
		$command = array_merge(
			array( 'wp', '--path=' . getenv( 'WP_CORE_DIR' ), '--allow-root', '--no-color' ),
			$arguments
		);
		$environment                     = getenv();
		$environment['WP_CONFIG_PATH']   = dirname( __DIR__ ) . '/bootstrap/wp-cli-config.php';
		$environment['WP_CLI_CACHE_DIR'] = sys_get_temp_dir() . '/wpsvg-wp-cli-cache';

		$process = proc_open(
			implode( ' ', array_map( 'escapeshellarg', $command ) ),
			array(
				0 => array( 'file', '/dev/null', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			sys_get_temp_dir(),
			$environment
		);
		$this->assertIsResource( $process, 'WP-CLI starts' );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'code'   => proc_close( $process ),
			'stdout' => $this->without_wp_cli_deprecations( $stdout ),
			'stderr' => $this->without_wp_cli_deprecations( $stderr ),
		);
	}

	/**
	 * Drops the deprecation notices PHP raises for WP-CLI's own code, which
	 * the pinned WP-CLI release prints on the newest PHP version. Notices
	 * from WordPress or the plugin name other files and stay.
	 *
	 * @param string $output Standard output or standard error of a command.
	 * @return string The remaining lines, trimmed.
	 */
	private function without_wp_cli_deprecations( $output ) {
		$lines = preg_grep(
			'~^(PHP )?Deprecated: .+ in phar://\S*/wp/\S+ on line \d+$~',
			preg_split( '/\R/', $output ),
			PREG_GREP_INVERT
		);

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Runs one WP-CLI command that has to succeed without complaints.
	 *
	 * @param string ...$arguments Command and arguments, one per item.
	 * @return string Standard output, trimmed.
	 */
	protected function wp_ok( ...$arguments ) {
		$result = $this->wp( ...$arguments );
		$this->assertSame(
			array( 0, '' ),
			array( $result['code'], $result['stderr'] ),
			'wp ' . implode( ' ', $arguments ) . "\n" . $result['stdout']
		);

		return $result['stdout'];
	}

	/**
	 * Activates the plugin and saves per-role permissions.
	 *
	 * @param array|null $permissions Role slug => 0, 1 or 2; null saves nothing.
	 */
	protected function activate_plugin( $permissions = null ) {
		$this->wp_ok( 'plugin', 'activate', self::PLUGIN );
		if ( null !== $permissions ) {
			$settings = array();
			foreach ( $permissions as $role => $value ) {
				$settings[ 'role_' . $role ] = $value;
			}
			$this->wp_ok( 'option', 'update', 'wpsvg_settings', json_encode( $settings ), '--format=json' );
		}
	}

	/**
	 * @param string $login User login.
	 * @param string $role  Role slug.
	 * @return int User ID.
	 */
	protected function create_user( $login, $role ) {
		return (int) $this->wp_ok( 'user', 'create', $login, "$login@example.org", "--role=$role", '--porcelain' );
	}

	/**
	 * Writes content to a temporary file that is deleted after the test.
	 *
	 * @param string $content
	 * @param string $name    File name.
	 * @return string Absolute path.
	 */
	protected function file_to_import( $content, $name = 'logo.svg' ) {
		$folder = sys_get_temp_dir() . '/' . uniqid( 'wpsvg-cli-', true );
		mkdir( $folder );
		$this->temporary_folders[] = $folder;
		$path                      = "$folder/$name";
		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * @param int $attachment_id
	 * @return string Content of the attachment's stored file.
	 */
	protected function stored_content( $attachment_id ) {
		$relative = $this->wp_ok( 'post', 'meta', 'get', (string) $attachment_id, '_wp_attached_file' );

		return file_get_contents( $this->uploads_dir() . '/' . $relative );
	}

	/**
	 * @return int Number of attachments on the site.
	 */
	protected function attachment_count() {
		return (int) $this->wp_ok( 'post', 'list', '--post_type=attachment', '--post_status=any', '--format=count' );
	}

	/**
	 * @param string $folder Absolute path; removed with everything in it.
	 */
	private function delete_folder( $folder ) {
		if ( ! is_dir( $folder ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $folder );
	}
}
