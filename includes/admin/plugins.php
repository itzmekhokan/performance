<?php
/**
 * Admin settings helper functions.
 *
 * @package performance-lab
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gets plugin info for the given plugin slug from WordPress.org.
 *
 * @since 2.8.0
 *
 * @param string $plugin_slug The string identifier for the plugin in questions slug.
 * @return array{name: string, slug: string, short_description: string, requires: string|false, requires_php: string|false, requires_plugins: string[], download_link: string, version: string}|WP_Error Array of plugin data or WP_Error if failed.
 */
function perflab_query_plugin_info( string $plugin_slug ) {
	$plugin = get_transient( 'perflab_plugin_info_' . $plugin_slug );

	if ( $plugin ) {
		return $plugin;
	}

	$fields = array(
		'name',
		'slug',
		'short_description',
		'requires',
		'requires_php',
		'requires_plugins',
		'download_link',
		'version', // Needed by install_plugin_install_status().
	);

	$plugin = plugins_api(
		'plugin_information',
		array(
			'slug'   => $plugin_slug,
			'fields' => array_fill_keys( $fields, true ),
		)
	);

	if ( is_wp_error( $plugin ) ) {
		return $plugin;
	}

	if ( is_object( $plugin ) ) {
		$plugin = (array) $plugin;
	}

	// Only store what we need.
	$plugin = wp_array_slice_assoc( $plugin, $fields );

	// Make sure all fields default to false in case another plugin is modifying the response from WordPress.org via the plugins_api filter.
	$plugin = array_merge( array_fill_keys( $fields, false ), $plugin );

	set_transient( 'perflab_plugin_info_' . $plugin_slug, $plugin, HOUR_IN_SECONDS );

	return $plugin;
}

/**
 * Returns an array of WPP standalone plugins.
 *
 * @since 2.8.0
 *
 * @return string[] List of WPP standalone plugins as slugs.
 */
function perflab_get_standalone_plugins(): array {
	return array_keys(
		perflab_get_standalone_plugin_data()
	);
}

/**
 * Renders plugin UI for managing standalone plugins within PL Settings screen.
 *
 * @since 2.8.0
 */
function perflab_render_plugins_ui() {
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$plugins              = array();
	$experimental_plugins = array();

	foreach ( perflab_get_standalone_plugin_data() as $plugin_slug => $plugin_data ) {
		$api_data = perflab_query_plugin_info( $plugin_slug ); // Data from wordpress.org.

		// Skip if the plugin is not on WordPress.org or there was a network error.
		if ( $api_data instanceof WP_Error ) {
			wp_admin_notice(
				esc_html(
					sprintf(
						/* translators: 1: plugin slug. 2: error message. */
						__( 'Failed to query WordPress.org Plugin Directory for plugin "%1$s". %2$s', 'performance-lab' ),
						$plugin_slug,
						$api_data->get_error_message()
					)
				),
				array( 'type' => 'error' )
			);
			continue;
		}

		$plugin_data = array_merge(
			array(
				'experimental' => false,
			),
			$plugin_data, // Data defined within Performance Lab.
			$api_data
		);

		// Separate experimental plugins so that they're displayed after non-experimental plugins.
		if ( $plugin_data['experimental'] ) {
			$experimental_plugins[ $plugin_slug ] = $plugin_data;
		} else {
			$plugins[ $plugin_slug ] = $plugin_data;
		}
	}

	if ( ! $plugins && ! $experimental_plugins ) {
		return;
	}
	?>
	<div class="wrap plugin-install-php">
		<h1><?php esc_html_e( 'Performance Features', 'performance-lab' ); ?></h1>
		<div class="wrap">
			<form id="plugin-filter" method="post">
				<div class="wp-list-table widefat plugin-install wpp-standalone-plugins">
					<h2 class="screen-reader-text"><?php esc_html_e( 'Plugins list', 'default' ); ?></h2>
					<div id="the-list">
						<?php
						foreach ( $plugins as $plugin_data ) {
							perflab_render_plugin_card( $plugin_data );
						}
						foreach ( $experimental_plugins as $plugin_data ) {
							perflab_render_plugin_card( $plugin_data );
						}
						?>
					</div>
				</div>
			</form>
		</div>
		<div class="clear"></div>
	</div>
	<?php
}

/**
 * Renders individual plugin cards.
 *
 * This is adapted from `WP_Plugin_Install_List_Table::display_rows()` in core.
 *
 * @since 2.8.0
 *
 * @see WP_Plugin_Install_List_Table::display_rows()
 * @link https://github.com/WordPress/wordpress-develop/blob/0b8ca16ea3bd9722bd1a38f8ab68901506b1a0e7/src/wp-admin/includes/class-wp-plugin-install-list-table.php#L467-L830
 *
 * @param array{name: string, slug: string, short_description: string, requires_php: string|false, requires: string|false, requires_plugins: string[], version: string, experimental: bool} $plugin_data Plugin data augmenting data from the WordPress.org API.
 */
function perflab_render_plugin_card( array $plugin_data ) {

	$name        = wp_strip_all_tags( $plugin_data['name'] );
	$description = wp_strip_all_tags( $plugin_data['short_description'] );

	/** This filter is documented in wp-admin/includes/class-wp-plugin-install-list-table.php */
	$description = apply_filters( 'plugin_install_description', $description, $plugin_data );

	$compatible_php = ! $plugin_data['requires_php'] || is_php_version_compatible( $plugin_data['requires_php'] );
	$compatible_wp  = ! $plugin_data['requires'] || is_wp_version_compatible( $plugin_data['requires'] );
	$action_links   = array();

	$status = install_plugin_install_status( $plugin_data );

	if ( is_plugin_active( $status['file'] ) ) {
		$action_links[] = sprintf(
			'<button type="button" class="button button-disabled" disabled="disabled">%s</button>',
			esc_html( _x( 'Active', 'plugin', 'default' ) )
		);
	} elseif (
		$compatible_php &&
		$compatible_wp &&
		(
			( $status['file'] && current_user_can( 'activate_plugin', $status['file'] ) ) ||
			current_user_can( 'activate_plugins' )
		) &&
		(
			'install' !== $status['status'] ||
			current_user_can( 'install_plugins' )
		)
	) {
		$url = esc_url_raw(
			add_query_arg(
				array(
					'action'   => 'perflab_install_activate_plugin',
					'_wpnonce' => wp_create_nonce( 'perflab_install_activate_plugin' ),
					'slug'     => $plugin_data['slug'],
				),
				admin_url( 'options-general.php' )
			)
		);

		$action_links[] = sprintf(
			'<a class="button perflab-install-active-plugin" href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Activate', 'default' )
		);
	} else {
		$explanation    = 'install' !== $status['status'] || current_user_can( 'install_plugins' ) ? _x( 'Cannot Activate', 'plugin', 'default' ) : _x( 'Cannot Install', 'plugin', 'default' );
		$action_links[] = sprintf(
			'<button type="button" class="button button-disabled" disabled="disabled">%s</button>',
			esc_html( $explanation )
		);
	}

	if ( current_user_can( 'install_plugins' ) ) {
		$title_link_attr = ' class="thickbox open-plugin-details-modal"';
		$details_link    = esc_url_raw(
			add_query_arg(
				array(
					'tab'       => 'plugin-information',
					'plugin'    => $plugin_data['slug'],
					'TB_iframe' => 'true',
					'width'     => 600,
					'height'    => 550,
				),
				admin_url( 'plugin-install.php' )
			)
		);

		$action_links[] = sprintf(
			'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
			esc_url( $details_link ),
			/* translators: %s: Plugin name and version. */
			esc_attr( sprintf( __( 'More information about %s', 'default' ), $name ) ),
			esc_attr( $name ),
			esc_html__( 'Learn more', 'performance-lab' )
		);
	} else {
		$title_link_attr = ' target="_blank"';

		/* translators: %s: Plugin name. */
		$aria_label = sprintf( __( 'Visit plugin site for %s', 'default' ), $name );

		$details_link = __( 'https://wordpress.org/plugins/', 'default' ) . $plugin_data['slug'] . '/';

		$action_links[] = sprintf(
			'<a href="%s" aria-label="%s" target="_blank">%s</a>',
			esc_url( $details_link ),
			esc_attr( $aria_label ),
			esc_html__( 'Visit plugin site', 'default' )
		);
	}
	?>
	<div class="plugin-card plugin-card-<?php echo sanitize_html_class( $plugin_data['slug'] ); ?>">
		<?php
		if ( ! $compatible_php || ! $compatible_wp ) {
			echo '<div class="notice inline notice-error notice-alt">';
			if ( ! $compatible_php && ! $compatible_wp ) {
				echo '<p>' . esc_html_e( 'This plugin does not work with your versions of WordPress and PHP.', 'default' ) . '</p>';
				if ( current_user_can( 'update_core' ) && current_user_can( 'update_php' ) ) {
					echo wp_kses_post(
						sprintf(
							/* translators: 1: URL to WordPress Updates screen, 2: URL to Update PHP page. */
							' ' . __( '<a href="%1$s">Please update WordPress</a>, and then <a href="%2$s">learn more about updating PHP</a>.', 'default' ),
							esc_url( self_admin_url( 'update-core.php' ) ),
							esc_url( wp_get_update_php_url() )
						)
					);
					wp_update_php_annotation( '<p><em>', '</em></p>' );
				} elseif ( current_user_can( 'update_core' ) ) {
					echo wp_kses_post(
						sprintf(
							/* translators: %s: URL to WordPress Updates screen. */
							' ' . __( '<a href="%s">Please update WordPress</a>.', 'default' ),
							esc_url( self_admin_url( 'update-core.php' ) )
						)
					);
				} elseif ( current_user_can( 'update_php' ) ) {
					echo wp_kses_post(
						sprintf(
							/* translators: %s: URL to Update PHP page. */
							' ' . __( '<a href="%s">Learn more about updating PHP</a>.', 'default' ),
							esc_url( wp_get_update_php_url() )
						)
					);
					wp_update_php_annotation( '<p><em>', '</em></p>' );
				}
			} elseif ( ! $compatible_wp ) {
				esc_html_e( 'This plugin does not work with your version of WordPress.', 'default' );
				if ( current_user_can( 'update_core' ) ) {
					echo wp_kses_post(
						sprintf(
							/* translators: %s: URL to WordPress Updates screen. */
							' ' . __( '<a href="%s">Please update WordPress</a>.', 'default' ),
							esc_url( self_admin_url( 'update-core.php' ) )
						)
					);
				}
			} elseif ( ! $compatible_php ) {
				esc_html_e( 'This plugin does not work with your version of PHP.', 'default' );
				if ( current_user_can( 'update_php' ) ) {
					echo wp_kses_post(
						sprintf(
							/* translators: %s: URL to Update PHP page. */
							' ' . __( '<a href="%s">Learn more about updating PHP</a>.', 'default' ),
							esc_url( wp_get_update_php_url() )
						)
					);
					wp_update_php_annotation( '<p><em>', '</em></p>' );
				}
			}
			echo '</div>';
		}
		?>
		<div class="plugin-card-top">
			<div class="name column-name">
				<h3>
					<a href="<?php echo esc_url( $details_link ); ?>"<?php echo $title_link_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php echo wp_kses_post( $name ); ?>
					</a>
					<?php if ( $plugin_data['experimental'] ) : ?>
						<em class="perflab-plugin-experimental">
							<?php echo esc_html( _x( '(experimental)', 'plugin suffix', 'performance-lab' ) ); ?>
						</em>
					<?php endif; ?>
				</h3>
			</div>
			<div class="action-links">
				<ul class="plugin-action-buttons">
					<?php foreach ( $action_links as $action_link ) : ?>
						<li><?php echo wp_kses_post( $action_link ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="desc column-description">
				<p><?php echo wp_kses_post( $description ); ?></p>
			</div>
		</div>
	</div>
	<?php
}
