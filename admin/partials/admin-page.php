<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pressgo-wrap">
	<div class="pressgo-header">
		<div class="pressgo-header-left">
			<h1>PressGo <span class="pressgo-subtitle">AI Page Builder</span></h1>
		</div>
		<div class="pressgo-header-right">
			<?php
			$pressgo_model_labels = array(
				'claude-sonnet-4-5-20250929' => 'Sonnet 4.5',
				'claude-opus-4-6'            => 'Opus 4.6',
				'claude-haiku-4-5-20251001'  => 'Haiku 4.5',
			);
			$pressgo_current_model = PressGo_Admin::get_model();
			$pressgo_model_label   = isset( $pressgo_model_labels[ $pressgo_current_model ] ) ? $pressgo_model_labels[ $pressgo_current_model ] : $pressgo_current_model;
			?>
			<span class="pressgo-model-badge">Claude <?php echo esc_html( $pressgo_model_label ); ?></span>
		</div>
	</div>

	<div class="pressgo-input-panel">
		<div class="pressgo-input-row">
			<textarea id="pressgo-prompt" rows="4" placeholder="Describe your landing page... e.g., 'A landing page for a dog grooming business called Pawfect Groom. Modern, friendly, blue and orange colors. Include pricing, testimonials, and a booking CTA.'"></textarea>
		</div>
		<div class="pressgo-controls-row">
			<div class="pressgo-control-group">
				<label for="pressgo-image" class="pressgo-upload-btn">
					<span class="dashicons dashicons-format-image"></span> Upload Image
					<input type="file" id="pressgo-image" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;" />
				</label>
				<span id="pressgo-image-name" class="pressgo-image-name"></span>
				<button type="button" id="pressgo-image-clear" class="pressgo-image-clear" style="display:none;">&times;</button>
			</div>
			<div class="pressgo-control-group">
				<input type="text" id="pressgo-page-title" placeholder="Page Title (optional)" value="" />
			</div>
			<div class="pressgo-control-group pressgo-control-right">
				<span class="pressgo-shortcut-hint">Ctrl+Enter</span>
				<button type="button" id="pressgo-generate-btn" class="button button-primary button-hero">
					<span class="dashicons dashicons-superhero-alt"></span> Generate Page
				</button>
			</div>
		</div>
	</div>

	<!-- Empty state — shown before first generation -->
	<div id="pressgo-empty-state" class="pressgo-empty-state">
		<div class="pressgo-empty-grid">
			<div class="pressgo-empty-card">
				<div class="pressgo-empty-card-icon"><span class="dashicons dashicons-text-page"></span></div>
				<h4>Describe Anything</h4>
				<p>Tell PressGo about your business in plain text. The more detail you give, the better the result.</p>
			</div>
			<div class="pressgo-empty-card">
				<div class="pressgo-empty-card-icon"><span class="dashicons dashicons-layout"></span></div>
				<h4>48 Layout Variants</h4>
				<p>AI selects the best layout from 48 section variants — hero, features, pricing, testimonials, and more.</p>
			</div>
			<div class="pressgo-empty-card">
				<div class="pressgo-empty-card-icon"><span class="dashicons dashicons-smartphone"></span></div>
				<h4>Mobile-First</h4>
				<p>Every page is fully responsive with auto-calculated tablet and mobile sizes. Looks great on any device.</p>
			</div>
		</div>

		<div class="pressgo-examples">
			<p class="pressgo-examples-label">Try an example:</p>
			<div class="pressgo-example-chips">
				<button type="button" class="pressgo-example-chip" data-prompt="A SaaS analytics dashboard landing page for a product called InsightHQ. Modern, dark theme with blue accents. Include pricing with 3 tiers, customer testimonials, and a free trial CTA.">SaaS Analytics Tool</button>
				<button type="button" class="pressgo-example-chip" data-prompt="A local dog grooming business called Pawfect Groom. Friendly, warm colors (orange and teal). Include services, pricing, testimonials from pet owners, a booking CTA, and a map showing our location at 123 Main St.">Dog Grooming Service</button>
				<button type="button" class="pressgo-example-chip" data-prompt="A fitness studio landing page for 'Iron Core Fitness'. Bold, energetic design with dark background and red accents. Include class schedule features, trainer profiles, membership pricing, and member testimonials.">Fitness Studio</button>
				<button type="button" class="pressgo-example-chip" data-prompt="An Italian restaurant called Trattoria Roma. Warm, elegant design with deep red and cream colors. Include the menu highlights, chef's story, customer reviews, location map, and a reservation CTA.">Italian Restaurant</button>
			</div>
		</div>
	</div>

	<!-- Workspace — shown during/after generation -->
	<div id="pressgo-workspace" class="pressgo-workspace" style="display: none;">
		<div class="pressgo-split-panel">
			<div class="pressgo-activity-panel" id="pressgo-activity">
				<h3>AI Activity</h3>
				<div id="pressgo-activity-log" class="pressgo-activity-log"></div>
			</div>
			<div class="pressgo-preview-panel" id="pressgo-preview">
				<h3>Page Layout</h3>
				<div id="pressgo-section-preview" class="pressgo-section-preview"></div>
				<div id="pressgo-result-actions" class="pressgo-result-actions" style="display: none;">
					<p class="pressgo-result-note">Your page is ready! This gets you 95% of the way there &mdash; open it in Elementor to add your finishing touches.</p>
					<a id="pressgo-edit-link" href="#" class="button button-primary" target="_blank">
						<span class="dashicons dashicons-edit"></span> Edit in Elementor
					</a>
					<a id="pressgo-view-link" href="#" class="button" target="_blank">
						<span class="dashicons dashicons-visibility"></span> View Page
					</a>
					<button type="button" id="pressgo-new-btn" class="button">
						<span class="dashicons dashicons-plus-alt2"></span> Generate Another
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
