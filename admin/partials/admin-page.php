<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap pressgo-wrap">
	<div class="pressgo-header">
		<h1>PressGo <span class="pressgo-subtitle">AI Page Builder</span></h1>
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
				<button type="button" id="pressgo-image-clear" class="pressgo-image-clear" style="display:none;">✕</button>
			</div>
			<div class="pressgo-control-group">
				<input type="text" id="pressgo-page-title" placeholder="Page Title (optional)" value="" />
			</div>
			<div class="pressgo-control-group">
				<button type="button" id="pressgo-generate-btn" class="button button-primary button-hero">
					<span class="dashicons dashicons-superhero-alt"></span> Generate Page
				</button>
			</div>
		</div>
	</div>

	<div id="pressgo-workspace" class="pressgo-workspace" style="display: none;">
		<div class="pressgo-split-panel">
			<div class="pressgo-activity-panel" id="pressgo-activity">
				<h3>AI Activity</h3>
				<div id="pressgo-activity-log" class="pressgo-activity-log"></div>
			</div>
			<div class="pressgo-preview-panel" id="pressgo-preview">
				<h3>Page Preview</h3>
				<div id="pressgo-section-preview" class="pressgo-section-preview"></div>
				<div id="pressgo-result-actions" class="pressgo-result-actions" style="display: none;">
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
