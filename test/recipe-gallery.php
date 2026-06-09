<?php
/**
 * Recipe-book gallery harness. Renders EVERY section variant with rich + sparse
 * data into labelled gallery pages so we can visually audit the whole recipe book
 * and catch regressions. Run on wp.pressgo.app:
 *   wp eval-file /tmp/recipe-gallery.php --allow-root
 * Prints PAGEID lines for each gallery page.
 */

error_reporting(E_ERROR | E_PARSE); // hush deprecation/notice noise; try/catch handles real fatals
$pd = '/var/www/wp.pressgo.app/htdocs/wp-content/plugins/pressgo-builder/';
foreach (array(
  'includes/generator/class-pressgo-element-factory.php',
  'includes/generator/class-pressgo-style-utils.php',
  'includes/generator/class-pressgo-icons.php',
  'includes/generator/class-pressgo-widget-helpers.php',
  'includes/generator/class-pressgo-section-builder.php',
  'includes/generator/class-pressgo-generator.php',
  'includes/class-pressgo-config-validator.php',
) as $f) { require_once $pd . $f; }

// ── Brand + rich sample data shared across all sections ──
$base = array(
  'business_name' => 'Northstar Studio',
  'industry' => 'design agency',
  'colors' => array(
    'primary' => '#0F172A', 'accent' => '#6366F1', 'dark_bg' => '#0F172A',
    'light_bg' => '#F8FAFC', 'white' => '#FFFFFF', 'text_dark' => '#0F172A',
    'text_muted' => '#64748B', 'text_light' => 'rgba(255,255,255,0.72)',
    'gold' => '#F59E0B', 'border' => 'rgba(15,23,42,0.08)',
    'primary_dark' => '#0B1120', 'primary_light' => '#EEF2FF', 'accent_hover' => '#4F46E5',
  ),
  'fonts' => array('heading' => 'Sora', 'body' => 'Inter'),
  'layout' => array('boxed_width' => 1200, 'section_padding' => 96, 'card_radius' => 16, 'button_radius' => 10,
    'card_shadow' => array('horizontal' => 0, 'vertical' => 8, 'blur' => 30, 'spread' => -8, 'color' => 'rgba(15,23,42,0.12)')),
);

$IMG = 'https://images.pexels.com/photos/3184465/pexels-photo-3184465.jpeg?auto=compress&cs=tinysrgb&w=1200';

// Rich per-section sample data (the section key the generator reads).
$data = array(
  'hero' => array(
    'eyebrow' => 'AWARD-WINNING DESIGN', 'badge' => 'Now booking Q3 projects',
    'headline' => 'Design that moves your business forward',
    'subheadline' => 'We craft brands, websites, and products that people remember. Strategy-led, beautifully built, shipped on time.',
    'cta_primary' => array('text' => 'Start a Project', 'url' => '#', 'icon' => 'fas fa-arrow-right'),
    'cta_secondary' => array('text' => 'See Our Work', 'url' => '#work'),
    'trust_line' => 'Trusted by 120+ founders and teams', 'image' => $IMG,
  ),
  'stats' => array('headline' => 'Numbers that matter', 'items' => array(
    array('icon' => 'fas fa-rocket', 'value' => '120+', 'label' => 'Projects shipped'),
    array('icon' => 'fas fa-star', 'value' => '4.9', 'label' => 'Average rating'),
    array('icon' => 'fas fa-clock', 'value' => '6 wks', 'label' => 'Typical timeline'),
    array('icon' => 'fas fa-users', 'value' => '40+', 'label' => 'Happy clients'),
  )),
  'social_proof' => array('headline' => 'Trusted by teams of every size',
    'categories' => array('SaaS', 'E-commerce', 'Healthcare', 'Fintech', 'Nonprofit', 'Education')),
  'features' => array('eyebrow' => 'WHAT WE DO', 'headline' => 'Everything you need to launch',
    'subheadline' => 'One partner, end to end.', 'items' => array(
    array('title' => 'Brand Identity', 'desc' => 'Logo, palette, and a system your team can actually use.', 'icon' => 'fas fa-palette', 'image' => $IMG),
    array('title' => 'Web Design', 'desc' => 'Conversion-focused sites that load fast and look sharp.', 'icon' => 'fas fa-laptop-code', 'image' => $IMG),
    array('title' => 'Product UX', 'desc' => 'Flows and interfaces tested with real users.', 'icon' => 'fas fa-wand-magic-sparkles', 'image' => $IMG),
    array('title' => 'Motion', 'desc' => 'Subtle animation that guides attention.', 'icon' => 'fas fa-film', 'image' => $IMG),
    array('title' => 'Copywriting', 'desc' => 'Words that sell without the cliches.', 'icon' => 'fas fa-pen-nib', 'image' => $IMG),
  )),
  'steps' => array('eyebrow' => 'HOW IT WORKS', 'headline' => 'A simple, proven process', 'items' => array(
    array('number' => '1', 'title' => 'Discovery', 'desc' => 'We dig into your goals, market, and audience.'),
    array('number' => '2', 'title' => 'Design', 'desc' => 'Concepts, iterations, and a direction you love.'),
    array('number' => '3', 'title' => 'Build', 'desc' => 'Pixel-perfect, responsive, production-ready.'),
    array('number' => '4', 'title' => 'Launch', 'desc' => 'We ship, measure, and refine.'),
  )),
  'results' => array('headline' => 'Results our clients see', 'items' => array(
    array('value' => '3.2x', 'label' => 'Conversion lift'),
    array('value' => '48%', 'label' => 'Faster load time'),
    array('value' => '2 wks', 'label' => 'To first launch'),
  )),
  'competitive_edge' => array('headline' => 'Why teams choose Northstar',
    'subheadline' => 'Senior talent, no hand-offs, real outcomes.', 'image' => $IMG, 'items' => array(
    'Senior designers on every project', 'Fixed timelines, no surprises',
    'Conversion-tested layouts', 'Ongoing support after launch'),
    'cta' => array('text' => 'Book a Call', 'url' => '#')),
  'testimonials' => array('headline' => 'What our clients say', 'items' => array(
    array('name' => 'Sara Lin', 'role' => 'Founder, Vela', 'quote' => 'They redesigned our site and conversions doubled in a month. Genuinely the best agency we have worked with.', 'avatar' => $IMG, 'rating' => 5),
    array('name' => 'Marcus Webb', 'role' => 'CMO, Drift', 'quote' => 'Strategic, fast, and a joy to work with. The new brand finally feels like us.', 'avatar' => $IMG, 'rating' => 5),
    array('name' => 'Priya Shah', 'role' => 'CEO, Northwind', 'quote' => 'Clear process, gorgeous output, on time. We have already booked the next project.', 'avatar' => $IMG, 'rating' => 5),
  )),
  'faq' => array('headline' => 'Frequently asked questions', 'items' => array(
    array('q' => 'How long does a project take?', 'a' => 'Most projects run 4 to 8 weeks depending on scope. We give you a firm timeline up front.'),
    array('q' => 'Do you offer payment plans?', 'a' => 'Yes. We split projects into milestones so cost is predictable.'),
    array('q' => 'What if I need changes after launch?', 'a' => 'Every project includes 30 days of support, and we offer ongoing retainers.'),
    array('q' => 'Who owns the final files?', 'a' => 'You do, fully. We hand over everything organized and documented.'),
  )),
  'pricing' => array('headline' => 'Simple, transparent pricing', 'plans' => array(
    array('name' => 'Starter', 'price' => '$3k', 'period' => 'project', 'features' => array('Landing page', '1 revision round', '2 week delivery'), 'cta' => array('text' => 'Get Started', 'url' => '#')),
    array('name' => 'Growth', 'price' => '$8k', 'period' => 'project', 'features' => array('Full website', '3 revision rounds', 'Brand refresh', 'Priority support'), 'cta' => array('text' => 'Get Started', 'url' => '#'), 'highlight' => true),
    array('name' => 'Scale', 'price' => 'Custom', 'period' => '', 'features' => array('Product + brand', 'Dedicated team', 'Ongoing retainer'), 'cta' => array('text' => 'Talk to Sales', 'url' => '#')),
  )),
  'logo_bar' => array('headline' => 'Brands we have partnered with',
    'logos' => array('Vela', 'Drift', 'Northwind', 'Acme', 'Lumen', 'Orbit')),
  'team' => array('headline' => 'Meet the team', 'members' => array(
    array('name' => 'Alex Reed', 'role' => 'Creative Director', 'bio' => '15 years shaping brands.', 'photo' => $IMG),
    array('name' => 'Jordan Kim', 'role' => 'Lead Designer', 'bio' => 'Obsessed with detail.', 'photo' => $IMG),
    array('name' => 'Sam Diaz', 'role' => 'Engineer', 'bio' => 'Makes it real and fast.', 'photo' => $IMG),
  )),
  'gallery' => array('headline' => 'Selected work',
    'images' => array($IMG, $IMG, $IMG, $IMG, $IMG, $IMG)),
  'newsletter' => array('headline' => 'Get design tips in your inbox',
    'subheadline' => 'One short email a week. No spam.', 'cta' => array('text' => 'Subscribe', 'url' => '#')),
  'cta_final' => array('headline' => 'Ready to build something great?',
    'subheadline' => 'Tell us about your project and we will get back within a day.',
    'cta' => array('text' => 'Start a Project', 'url' => '#'), 'image' => $IMG,
    'bullets' => array('Senior team, no hand-offs', 'Fixed milestones, no surprises', 'Launch in weeks, not months')),
  'footer' => array('business_name' => 'Northstar Studio',
    'tagline' => 'Design that moves you forward.', 'phone' => '(512) 555-0142',
    'email' => 'hello@northstar.studio', 'address' => '101 Congress Ave, Austin TX',
    'links' => array('Work', 'Services', 'About', 'Contact')),
  'map' => array('address' => '101 Congress Ave, Austin TX', 'height' => 400),
  'disclaimer' => array('text' => 'Results vary. Northstar Studio is an independent agency.'),
);

// Variant list per section type.
$variants = array(
  'hero' => array('default', 'split', 'image', 'gradient', 'minimal'),
  'stats' => array('default', 'dark', 'inline'),
  'social_proof' => array('default', 'dark'),
  'features' => array('default', 'alternating', 'minimal', 'image_cards', 'grid', 'bento'),
  'steps' => array('default', 'compact', 'timeline'),
  'results' => array('default', 'bars'),
  'competitive_edge' => array('default', 'image', 'cards'),
  'testimonials' => array('default', 'featured', 'grid', 'minimal'),
  'faq' => array('default', 'split'),
  'pricing' => array('default', 'compact'),
  'logo_bar' => array('default', 'dark'),
  'team' => array('default', 'compact'),
  'gallery' => array('default', 'cards'),
  'newsletter' => array('default', 'inline'),
  'cta_final' => array('default', 'card', 'image', 'split'),
  'footer' => array('default', 'light'),
  'map' => array('default'),
);

$gen = new PressGo_Generator();
$created = array();

// A simple full-width label band so each variant is identifiable in the gallery.
function gallery_label($text) {
  return array(
    'id' => PressGo_Element_Factory::eid(), 'elType' => 'container',
    'settings' => array('container_type' => 'flex', 'content_width' => 'full',
      'background_background' => 'classic', 'background_color' => '#111827',
      'padding' => array('unit' => 'px', 'top' => '10', 'right' => '20', 'bottom' => '10', 'left' => '20', 'isLinked' => false)),
    'elements' => array(array('id' => PressGo_Element_Factory::eid(), 'elType' => 'widget', 'widgetType' => 'heading',
      'settings' => array('title' => $text, 'header_size' => 'h6', 'title_color' => '#FFFFFF',
        'typography_typography' => 'custom', 'typography_font_size' => array('unit' => 'px', 'size' => 12, 'sizes' => array()),
        'typography_font_weight' => '600', 'typography_letter_spacing' => array('unit' => 'px', 'size' => 1, 'sizes' => array())))),
    'isInner' => false,
  );
}

foreach ($variants as $type => $vlist) {
  $elements = array();
  foreach ($vlist as $v) {
    $elements[] = gallery_label(strtoupper($type) . '  /  ' . strtoupper($v));
    $secCfg = $base;
    $secCfg['sections'] = array($type);
    $secCfg[$type] = array_merge($data[$type], array('variant' => $v));
    $valid = PressGo_Config_Validator::validate($secCfg);
    if (is_wp_error($valid)) { echo 'SKIP ' . $type . '/' . $v . ' ' . $valid->get_error_message() . PHP_EOL; continue; }
    try {
      $els = $gen->generate($valid);
      if (is_array($els)) foreach ($els as $e) if (is_array($e) && isset($e['elType'])) $elements[] = $e;
    } catch (\Throwable $err) {
      echo 'CRASH ' . $type . '/' . $v . '  ::  ' . $err->getMessage() . '  @ ' . basename($err->getFile()) . ':' . $err->getLine() . PHP_EOL;
      $elements[] = gallery_label('!! CRASH: ' . $type . '/' . $v . ' — ' . $err->getMessage());
    }
  }

  $title = 'GALLERY ' . $type;
  $existing = get_page_by_title($title, OBJECT, 'page');
  $pid = $existing ? $existing->ID : wp_insert_post(array('post_title' => $title, 'post_status' => 'publish', 'post_type' => 'page'));
  update_post_meta($pid, '_elementor_data', wp_slash(wp_json_encode($elements)));
  update_post_meta($pid, '_elementor_edit_mode', 'builder');
  update_post_meta($pid, '_wp_page_template', 'elementor_canvas');
  update_post_meta($pid, '_elementor_template_type', 'wp-page');
  delete_post_meta($pid, '_elementor_css');
  $created[$type] = $pid;
  echo 'PAGEID ' . $type . ' ' . $pid . ' ' . get_permalink($pid) . PHP_EOL;
}
echo 'DONE ' . count($created) . ' gallery pages' . PHP_EOL;
