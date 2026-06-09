<?php
/**
 * Sparse / malformed-data robustness test. Renders every section type through
 * the REAL validate->generate pipeline with intentionally broken/missing data
 * (the shapes the AI actually emits) and reports any crash. This catches the
 * "default guards X, sibling doesn't" class the rich gallery can't.
 *   wp eval-file /tmp/sparse-test.php --allow-root
 */
error_reporting(E_ERROR | E_PARSE);
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

$base = array(
  'colors' => array('primary'=>'#0F172A','accent'=>'#6366F1','dark_bg'=>'#0F172A','light_bg'=>'#F8FAFC','white'=>'#FFFFFF','text_dark'=>'#0F172A','text_muted'=>'#64748B','text_light'=>'rgba(255,255,255,0.72)','gold'=>'#F59E0B','border'=>'rgba(15,23,42,0.08)'),
  'fonts' => array('heading'=>'Sora','body'=>'Inter'),
  'layout' => array('boxed_width'=>1200,'section_padding'=>96,'card_radius'=>16,'button_radius'=>10,'card_shadow'=>array('horizontal'=>0,'vertical'=>8,'blur'=>30,'spread'=>-8,'color'=>'rgba(15,23,42,0.12)')),
);

// Edge cases: [label, sectionType, sectionData]. Each exercises a known crash path.
$cases = array(
  array('hero empty-cta', 'hero', array('headline'=>'Hi','subheadline'=>'x','cta_primary'=>array('text'=>''))),
  array('hero no-cta', 'hero', array('headline'=>'Hi','subheadline'=>'x')),
  array('hero/split no-image', 'hero', array('variant'=>'split','headline'=>'Hi','subheadline'=>'x','cta_primary'=>array('text'=>'Go'))),
  array('cta_final no-cta', 'cta_final', array('headline'=>'Ready?','description'=>'x')),
  array('cta_final string-cta', 'cta_final', array('headline'=>'Ready?','cta'=>'Book Now')),
  array('cta_final/image no-image', 'cta_final', array('variant'=>'image','headline'=>'Ready?','cta'=>array('text'=>'Go'))),
  array('competitive_edge string-cta', 'competitive_edge', array('headline'=>'Why us','items'=>array('A','B'),'cta'=>'Call')),
  array('competitive_edge no-cta', 'competitive_edge', array('headline'=>'Why us','items'=>array('A','B'))),
  array('competitive_edge/image no-image', 'competitive_edge', array('variant'=>'image','headline'=>'Why us','items'=>array('A'))),
  array('logo_bar strings', 'logo_bar', array('headline'=>'Trusted','logos'=>array('Vela','Drift'))),
  array('footer string-links', 'footer', array('business_name'=>'X','links'=>array('Work','About'))),
  array('footer/light string-links', 'footer', array('variant'=>'light','business_name'=>'X','links'=>array('Work','About'))),
  array('gallery/cards no-url', 'gallery', array('variant'=>'cards','headline'=>'Work','images'=>array(array('caption'=>'a'), array('caption'=>'b')))),
  array('testimonials no-quote', 'testimonials', array('headline'=>'Reviews','items'=>array(array('name'=>'Joe')))),
  array('testimonials/featured blank', 'testimonials', array('variant'=>'featured','headline'=>'Reviews','items'=>array(array('name'=>'A'), array('quote'=>'Great')))),
  array('testimonials/minimal blank', 'testimonials', array('variant'=>'minimal','headline'=>'Reviews','items'=>array(array('quote'=>'Nice'), array('name'=>'B')))),
  array('testimonials/grid blank', 'testimonials', array('variant'=>'grid','headline'=>'Reviews','items'=>array(array('quote'=>'Yes'), array()))),
  array('steps no-num', 'steps', array('headline'=>'How','items'=>array(array('title'=>'A','desc'=>'x'), array('title'=>'B','desc'=>'y')))),
  array('results items-key', 'results', array('headline'=>'R','items'=>array(array('value'=>'3x','label'=>'lift')))),
  array('features no-icon', 'features', array('headline'=>'F','items'=>array(array('title'=>'A','desc'=>'x')))),
  array('pricing no-cta', 'pricing', array('headline'=>'Plans','plans'=>array(array('name'=>'Pro','price'=>'$9','features'=>array('a'))))),
  array('features/bento 2-items (fallback)', 'features', array('variant'=>'bento','headline'=>'F','items'=>array(array('icon'=>'fas fa-bolt','title'=>'A','desc'=>'x'), array('title'=>'B')))),
  array('cta_final/split no-bullets (fallback)', 'cta_final', array('variant'=>'split','headline'=>'Ready?','description'=>'x','cta'=>array('text'=>'Go'))),
  array('cta_final/split blank+obj bullets', 'cta_final', array('variant'=>'split','headline'=>'Ready?','cta'=>'Book','bullets'=>array('', '  ', array('text'=>'Free consult'), array('no_text'=>1), 'Fast turnaround'))),
  array('results/bars string-cta', 'results', array('variant'=>'bars','headline'=>'R','metrics'=>array(array('value'=>'340%','label'=>'lift')),'cta'=>'See more')),
  array('results/bars non-numeric value', 'results', array('variant'=>'bars','headline'=>'R','metrics'=>array(array('value'=>'Doubled','label'=>'output'), array('value'=>'$2.5M','label'=>'revenue')))),
  array('testimonials/minimal single quote', 'testimonials', array('variant'=>'minimal','headline'=>'Reviews','items'=>array(array('quote'=>'Great','name'=>'A')))),
);

$gen = new PressGo_Generator();
$pass = 0; $fail = 0;
foreach ($cases as $case) {
  list($label, $type, $sec) = $case;
  $cfg = $base;
  $cfg['sections'] = array($type);
  $cfg[$type] = $sec;
  try {
    $valid = PressGo_Config_Validator::validate($cfg);
    if (is_wp_error($valid)) { echo "SKIP  $label :: " . $valid->get_error_message() . PHP_EOL; continue; }
    $els = $gen->generate($valid);
    $n = is_array($els) ? count($els) : 0;
    echo "PASS  $label ($n elements)" . PHP_EOL; $pass++;
  } catch (\Throwable $e) {
    echo "CRASH $label :: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL; $fail++;
  }
}
echo "\n== $pass passed, $fail crashed ==" . PHP_EOL;
