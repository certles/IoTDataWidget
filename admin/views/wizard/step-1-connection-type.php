<?php
/**
 * Widok: Krok 1 kreatora — wybór typu połączenia (grupa, ew. podtyp).
 *
 * @package IoT_Data_Widget
 *
 * @var array<string, array{label: string, types: array, action?: string}> $groups Wszystkie grupy (patrz IOTDW_Setup_Wizard::get_groups()) — 'action' opcjonalne, poprawka 2.1 (docs/archiwum/poprawki-ogolne.md).
 * @var string                                             $group_key Wybrana grupa (pusty string = jeszcze nie wybrano żadnej).
 * @var string                                             $mode      'pull'/'push'/'' — zgłoszenie 2026-09-07: bez tego w $iotdw_base_url, klik "Back" z podlisty grupy (np. "Local device") gubił kontekst Kroku 0 i wracał tam zamiast na szczyt Kroku 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_skip_url = admin_url( 'admin.php?page=iot-data-widget' );
$iotdw_base_url = '' !== $mode
	? add_query_arg( array( 'mode' => $mode ), admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG ) )
	: admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG );
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>

	<?php if ( '' !== $group_key && isset( $groups[ $group_key ] ) ) : ?>
		<h2>
			<?php
			printf(
				/* translators: %s: nazwa wybranej grupy, np. "Local device" */
				esc_html__( 'What kind of %s connection?', 'iot-data-widget' ),
				esc_html( $groups[ $group_key ]['label'] )
			);
			?>
		</h2>
		<p class="iot-dw-wizard__options">
			<?php foreach ( $groups[ $group_key ]['types'] as $iotdw_type_key => $iotdw_connection_type ) : ?>
				<?php
				$iotdw_type_url = add_query_arg(
					array(
						'step' => 2,
						'type' => $iotdw_type_key,
					),
					$iotdw_base_url
				);
				?>
				<a class="button button-hero" href="<?php echo esc_url( $iotdw_type_url ); ?>">
					<?php echo esc_html( $iotdw_connection_type['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</p>
		<p><a href="<?php echo esc_url( $iotdw_base_url ); ?>">&larr; <?php esc_html_e( 'Back', 'iot-data-widget' ); ?></a></p>
	<?php else : ?>
		<h2><?php esc_html_e( 'What do you want to connect?', 'iot-data-widget' ); ?></h2>
		<p class="iot-dw-wizard__options">
			<?php foreach ( $groups as $iotdw_group_slug => $iotdw_group ) : ?>
				<?php if ( ! empty( $iotdw_group['action'] ) ) : ?>
					<?php
					// Poprawka 2.1 (docs/archiwum/poprawki-ogolne.md) — jedyna grupa,
					// która nie nawiguje do Kroku 2, tylko od razu zapisuje
					// (POST), stąd formularz zamiast zwykłego linku <a>. Patrz
					// IOTDW_Setup_Wizard::get_groups() i handle_use_demo().
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<input type="hidden" name="action" value="<?php echo esc_attr( $iotdw_group['action'] ); ?>" />
						<?php wp_nonce_field( $iotdw_group['action'] ); ?>
						<button type="submit" class="button button-hero">
							<?php echo esc_html( $iotdw_group['label'] ); ?>
						</button>
					</form>
				<?php else : ?>
					<?php
					$iotdw_type_keys      = array_keys( $iotdw_group['types'] );
					$iotdw_single_type    = 1 === count( $iotdw_type_keys ) ? $iotdw_type_keys[0] : null;
					$iotdw_group_link_url = null !== $iotdw_single_type
						? add_query_arg(
							array(
								'step' => 2,
								'type' => $iotdw_single_type,
							),
							$iotdw_base_url
						)
						: add_query_arg( array( 'group' => $iotdw_group_slug ), $iotdw_base_url );
					?>
					<a class="button button-hero" href="<?php echo esc_url( $iotdw_group_link_url ); ?>">
						<?php echo esc_html( $iotdw_group['label'] ); ?>
					</a>
				<?php endif; ?>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<p>
		<a href="<?php echo esc_url( $iotdw_skip_url ); ?>"><?php esc_html_e( "Skip the wizard, I'll configure manually", 'iot-data-widget' ); ?></a>
	</p>
</div>
