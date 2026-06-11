<?php
/**
 * PressGo canvas — minimal full-bleed template for AI-built pages on
 * non-Elementor render targets (Gutenberg/Bricks; Divi normally uses its own
 * blank template). No theme header/nav/title/sidebar/footer: the AI page
 * carries its own hero and footer sections, so theme chrome around them reads
 * as a broken double-header. Selected via template_include when the page's
 * _pressgo_target_builder needs it — never registered as a choosable theme
 * template.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style>
		/* Belt-and-suspenders: some themes print admin-bar/body margins via
		   wp_head; the page content owns the full viewport. */
		html { margin-top: 0 !important; }
		body.pressgo-canvas { margin: 0; padding: 0; }
		body.pressgo-canvas > .pg-canvas-main { display: block; }
	</style>
</head>
<body <?php body_class( 'pressgo-canvas' ); ?>>
<?php wp_body_open(); ?>
<main class="pg-canvas-main">
	<?php
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
