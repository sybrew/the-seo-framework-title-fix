<?php
/**
 * Plugin Name: The SEO Framework - Title Fix
 * Plugin URI: https://wordpress.org/plugins/the-seo-framework-title-fix/
 * Description: The Title Fix extension for The SEO Framework makes sure your title output is as configured. Even if your theme is doing it wrong.
 * Version: 1.0.1.2
 * Author: Sybre Waaijer
 * Author URI: https://cyberwire.nl/
 * License: GPLv3
 */

/**
 * Title Fix extension plugin for The SEO Framework
 * Copyright (C) 2016 Sybre Waaijer, CyberWire (https://cyberwire.nl/)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 3 as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

//* Notify the existence of this plugin through a lovely definition.
define( 'THE_SEO_FRAMEWORK_TITLE_FIX', true );

//* Define version, for future things.
define( 'THE_SEO_FRAMEWORK_TITLE_FIX_VERSION', '1.0.2' );

add_action( 'plugins_loaded', 'the_seo_framework_title_fix_init' );
/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 *
 * @return bool True if class is loaded
 */
function the_seo_framework_title_fix_init() {

	static $loaded = null;

	//* Don't init the class twice.
	if ( isset( $loaded ) )
		return $loaded;

	//* Check if the SEO Framework is active and get the Version
	if ( function_exists( 'the_seo_framework_version' ) ) {
		//* Get the version number.
		$theseoframework_version = the_seo_framework_version();

		/**
		 * Only run the plugin on The SEO Framework 2.5.2 and higher.
		 *
		 * @since 1.0.0
		 */
		if ( isset( $theseoframework_version ) && version_compare( $theseoframework_version, '2.5.1.99' , '>' ) ) {
			//* Initialize class.
			new The_SEO_Framework_Title_Fix();

			return $loaded = true;
		}
	}

	return $loaded = false;
}

/**
 * Class The_SEO_Framework_Title_Fix
 *
 * @since 1.0.0
 *
 * @final Please don't extend this extension.
 */
final class The_SEO_Framework_Title_Fix {

	/**
	 * Force the fix when no title-tag is present.
	 *
	 * @since 1.0.0
	 *
	 * @var bool Whether the fix should be forced or not.
	 */
	protected $force_title_fix = false;

	/**
	 * Check if title has been found, otherwise continue flushing till the bottom of plugin output.
	 *
	 * @since 1.0.0
	 *
	 * @var bool Whether title has been found and replaced already.
	 */
	protected $title_found_and_flushed = false;

	/**
	 * If Output Buffering is started or not.
	 * If started, don't start again.
	 * If stopped, don't stop again.
	 *
	 * @since 1.0.0
	 *
	 * @var bool Whether ob has started.
	 */
	protected $ob_started = false;

	/**
	 * The constructor, initialize plugin.
	 */
	public function __construct() {

		//* Start the plugin at header, where theme support has just been initialized.
		add_action( 'get_header', array( $this, 'start_plugin' ), -1 );

	}

	/**
	 * Start plugin at get_header. A semi constructor.
	 *
	 * @since 1.0.0
	 */
	public function start_plugin() {

		/**
		 * Don't fix the title in admin.
		 * get_header doesn't run in admin, but another plugin might init it.
		 */
		if ( false === is_admin() ) {

			if ( false === $this->current_theme_supports_title_tag() ) {
				/**
				 * Applies filters 'the_seo_framework_force_title_fix'
				 * @since 1.0.1
				 * @param bool Whether to force the title fixing.
				 */
				$this->force_title_fix = (bool) apply_filters( 'the_seo_framework_force_title_fix', version_compare( the_seo_framework_version(), '2.6.99' , '<' ) );
			}

			/**
			 * Only do something if the theme is doing it wrong. Or when the filter has been applied.
			 * Requires initial load after theme switch.
			 */
			if ( $this->force_title_fix || false === the_seo_framework()->theme_title_doing_it_right() ) {

				/**
				 * First run.
				 * Start at HTTP header.
				 * Stop right at where wp_head is run.
				 */
				add_action( 'get_header', array( $this, 'start_ob' ), 0 );
				add_action( 'wp_head', array( $this, 'maybe_rewrite_title' ), 0 );
				add_action( 'wp_head', array( $this, 'maybe_stop_ob' ), 0 );

				/**
				 * Second run. Capture WP head.
				 * 		{add_action( 'wp_head', 'wp_title' );.. who knows?}
				 * Start at where wp_head is run (last run left off).
				 * Stop right at the end of wp_head.
				 */
				add_action( 'wp_head', array( $this, 'maybe_start_ob' ), 0 );
				add_action( 'wp_head', array( $this, 'maybe_rewrite_title' ), 9999 );
				add_action( 'wp_head', array( $this, 'maybe_stop_ob' ), 9999 );

				/**
				 * Third run. Capture the page.
				 * Start at where wp_head has ended (last run left off),
				 *		or at wp_head start (first run left off).
				 * Stop at the footer.
				 */
				add_action( 'wp_head', array( $this, 'maybe_start_ob' ), 9999 );
				add_action( 'get_footer', array( $this, 'maybe_rewrite_title' ), -1 );
				add_action( 'get_footer', array( $this, 'maybe_stop_ob' ), -1 );

				/**
				 * Stop OB if it's still running at shutdown.
				 * Might prevent AJAX issues, if any.
				 */
				add_action( 'shutdown', array( $this, 'stop_ob' ), 0 );
			}
		}

	}

	/**
	 * Start the Output Buffer.
	 *
	 * @since 1.0.0
	 */
	public function start_ob() {

		if ( false === $this->ob_started ) {
			ob_start();
			$this->ob_started = true;
		}

	}

	/**
	 * Clean the buffer and turn off the output buffering.
	 *
	 * @since 1.0.0
	 */
	public function stop_ob() {

		if ( $this->ob_started ) {
			ob_end_clean();
			$this->ob_started = false;
		}

	}

	/**
	 * Maybe start the Output Buffer if the title is not yet replaced.
	 *
	 * @since 1.0.0
	 */
	public function maybe_start_ob() {

		//* Reset the output buffer if not found.
		if ( false === $this->ob_started && false === $this->title_found_and_flushed ) {
			$this->start_ob();
		}

	}

	/**
	 * Maybe stop OB flush if title has been replaced already.
	 *
	 * @since 1.0.0
	 */
	public function maybe_stop_ob() {

		//* Let's not buffer all the way down.
		if ( $this->ob_started && $this->title_found_and_flushed ) {
			$this->stop_ob();
		}

	}

	/**
	 * Maybe rewrite the title, if not rewritten yet.
	 *
	 * @since 1.0.0
	 */
	public function maybe_rewrite_title( $content ) {

		$this->find_title_tag( $content );

		if ( $this->ob_started && false === $this->title_found_and_flushed ) {
			$content = ob_get_clean();
			$this->ob_started = false;

			$this->find_title_tag( $content );
		}

	}

	/**
	 * Finds the title tag and replaces it if found; will echo content from buffer otherwise.
	 *
	 * @uses _wp_can_use_pcre_u() WP Core function
	 *		(Compat for lower than WP 4.1.0 provided within The SEO Framework)
	 *
	 * @since 1.0.0
	 * @since 1.0.2: Echos $content, always.
	 *
	 * @param string $content The content with possible title tag.
	 * @return void When title is found.
	 */
	public function find_title_tag( $content ) {

		//* Check if we can use preg_match.
		if ( _wp_can_use_pcre_u() ) {

			//* Let's use regex.
			if ( 1 === preg_match( '/<title.*?<\/title>/is', $content, $matches ) ) {
				$title_tag = isset( $matches[0] ) ? $matches[0] : null;

				if ( isset( $title_tag ) ) {
					$this->replace_title_tag( $title_tag, $content );
					$this->title_found_and_flushed = true;
					return;
				}
			}
		} else {
			//* Let's count. 0.0003s faster, but less reliable.
			$start = stripos( $content, '<title' );

			if ( false !== $start ) {
				$end = stripos( $content, '</title>', $start );

				if ( false !== $end ) {
					//* +8 is "</title>" length
					$title_tag = substr( $content, $start, $end - $start + 8 );

					if ( false !== $title_tag ) {
						$this->replace_title_tag( $title_tag, $content );
						$this->title_found_and_flushed = true;
						return;
					}
				}
			}
		}

		//* Can't be escaped, as content is unknown.
		echo $content;

	}

	/**
	 * Replaces the title tag.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title_tag the Title tag with the title
	 * @param string $content The content containing the $title_tag
	 * @return string the content with replaced title tag.
	 */
	public function replace_title_tag( $title_tag, $content ) {

		$new_title = '<title>' . the_seo_framework()->title_from_cache( '', '' , '', true ) . '</title>' . $this->indicator();
		$count = 1;

		//* Replace the title tag within the header.
		$content = str_replace( $title_tag, $new_title, $content, $count );

		//* Can't be escaped, as content is unknown.
		echo $content;

	}

	/**
	 * Checks a theme's support for title-tag.
	 *
	 * @since 1.0.0
	 * @global array $_wp_theme_features
	 * @staticvar bool $supports
	 *
	 * @return bool True if the theme supports the title tag, false otherwise.
	 */
	public function current_theme_supports_title_tag() {

		static $supports = null;

		if ( isset( $supports ) )
			return $supports;

		global $_wp_theme_features;

		if ( false === empty( $_wp_theme_features['title-tag'] ) )
			return $supports = true;

		return $supports = false;
	}

	/**
	 * Returns a small indicator.
	 *
	 * @since 1.0.1
	 *
	 * @return string
	 */
	public function indicator() {

		/**
		 * Applies filters 'the_seo_framework_title_fixed_indicator'
		 * @since 1.0.1
		 * @param bool Whether to output an indicator or not.
		 */
		$indicator = (bool) apply_filters( 'the_seo_framework_title_fixed_indicator', true );

		if ( $indicator )
			return '<!-- fixed -->';

		return '';
	}
}
