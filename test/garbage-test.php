<?php
/* Garbage-shape regression test for the review findings. */
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
$gen = new PressGo_Generator();
function run($label, $type, $sec, $mustContain = array(), $mustNotContain = array()) {
  static $base = null, $gen = null;
  if (null === $gen) {
    $gen = new PressGo_Generator();
    $base = array(
      'colors' => array('primary'=>'#0F172A','accent'=>'#6366F1','dark_bg'=>'#0F172A','light_bg'=>'#F8FAFC','white'=>'#FFFFFF','text_dark'=>'#0F172A','text_muted'=>'#64748B','text_light'=>'rgba(255,255,255,0.72)','gold'=>'#F59E0B','border'=>'rgba(15,23,42,0.08)'),
      'fonts' => array('heading'=>'Sora','body'=>'Inter'),
      'layout' => array('boxed_width'=>1200,'section_padding'=>96,'card_radius'=>16,'button_radius'=>10,'card_shadow'=>array('horizontal'=>0,'vertical'=>8,'blur'=>30,'spread'=>-8,'color'=>'rgba(15,23,42,0.12)')),
    );
  }
  $cfg = $base; $cfg['sections']=array($type); $cfg[$type]=$sec;
  try {
    $valid = PressGo_Config_Validator::validate($cfg);
    if (is_wp_error($valid)) { echo "SKIP  $label :: ".$valid->get_error_message().PHP_EOL; return; }
    $els = $gen->generate($valid);
    $j = json_encode($els);
    $bad = array();
    foreach ($mustContain as $m) if (strpos($j, $m)===false) $bad[] = "missing '$m'";
    foreach ($mustNotContain as $m) if (strpos($j, $m)!==false) $bad[] = "contains '$m'";
    echo ($bad ? "FAIL " : "PASS ")."$label".($bad ? ' :: '.implode('; ',$bad) : '').PHP_EOL;
  } catch (\Throwable $e) {
    echo "CRASH $label :: ".$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine().PHP_EOL;
  }
}
// #2 critical: bento 2 string items -> features fallback must not crash, text preserved
run('bento 2 string items (fallback)', 'features', array('variant'=>'bento','headline'=>'F','items'=>array('Fast turnaround','Flat pricing')), array('Fast turnaround','Flat pricing'));
// #3: bento 4 string items -> tiles must carry the text
run('bento 4 string items', 'features', array('variant'=>'bento','headline'=>'F','items'=>array('Fast turnaround','Flat pricing','Real human support','Secure by default')), array('Fast turnaround','Flat pricing','Real human support','Secure by default'));
// #4: bento item0 contentless -> dropped, next item becomes hero (no empty gradient slab)
run('bento item0 icon-only', 'features', array('variant'=>'bento','headline'=>'F','items'=>array(array('icon'=>'fas fa-bolt'), array('title'=>'Secure','desc'=>'s'), array('title'=>'Fast','desc'=>'f'), array('title'=>'Easy','desc'=>'e'))), array('Secure','Fast','Easy'));
// features default: string items (no crash, text kept)
run('features string items', 'features', array('headline'=>'F','items'=>array('Only thing we do', 'Second thing')), array('Only thing we do'));
// #5: results/bars string metrics -> value parsed, text kept
run('bars string metrics', 'results', array('variant'=>'bars','headline'=>'R','metrics'=>array('340% average growth','24/7 support')), array('average growth','support'));
// #5b: metric missing value -> label-only card, no empty h3 (heading widgets with title "")
run('bars label-only metric', 'results', array('variant'=>'bars','headline'=>'R','metrics'=>array(array('label'=>'Customer satisfaction'), array('value'=>'98%','label'=>'Retention'))), array('Customer satisfaction','Retention'));
// #6: array-typed value/quote must NOT render the literal "Array"
run('bars array value', 'results', array('variant'=>'bars','headline'=>'R','metrics'=>array(array('value'=>array('n'=>340,'unit'=>'%'),'label'=>'Growth'))), array('Growth'), array('"title":"Array"','"editor":"Array"'));
run('minimal array quote', 'testimonials', array('variant'=>'minimal','headline'=>'T','items'=>array(array('quote'=>array('text'=>'Loved it'),'name'=>'A'), array('quote'=>'Real quote','name'=>'B'))), array('Real quote'), array('Array'));
// icon-list typography keys present (icon_typography_*) and bare keys absent in split checklist
run('split checklist icon_typography', 'cta_final', array('variant'=>'split','headline'=>'Go?','cta'=>array('text'=>'Go'),'bullets'=>array('A thing','B thing')), array('icon_typography_typography'));
echo "done".PHP_EOL;
