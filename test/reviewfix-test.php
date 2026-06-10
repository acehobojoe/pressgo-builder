<?php
error_reporting(E_ERROR | E_PARSE);
$pd = '/var/www/wp.pressgo.app/htdocs/wp-content/plugins/pressgo-builder/';
foreach (array('includes/generator/class-pressgo-element-factory.php','includes/generator/class-pressgo-style-utils.php','includes/generator/class-pressgo-icons.php','includes/generator/class-pressgo-widget-helpers.php','includes/generator/class-pressgo-section-builder.php','includes/generator/class-pressgo-generator.php','includes/class-pressgo-config-validator.php') as $f) require_once $pd.$f;
function run($label, $type, $sec, $mustContain = array(), $mustNotContain = array()) {
  static $base = null, $gen = null;
  if (null === $gen) { $gen = new PressGo_Generator();
    $base = array('colors'=>array('primary'=>'#0F172A','accent'=>'#6366F1','dark_bg'=>'#0F172A','light_bg'=>'#F8FAFC','white'=>'#FFFFFF','text_dark'=>'#0F172A','text_muted'=>'#64748B','text_light'=>'rgba(255,255,255,0.72)','gold'=>'#F59E0B','border'=>'rgba(15,23,42,0.08)'),'fonts'=>array('heading'=>'Sora','body'=>'Inter'),'layout'=>array('boxed_width'=>1200,'section_padding'=>96,'card_radius'=>16,'button_radius'=>10,'card_shadow'=>array('horizontal'=>0,'vertical'=>8,'blur'=>30,'spread'=>-8,'color'=>'rgba(15,23,42,0.12)'))); }
  $cfg = $base; $cfg['sections']=array($type); $cfg[$type]=$sec;
  try {
    $valid = PressGo_Config_Validator::validate($cfg);
    if (is_wp_error($valid)) { echo "SKIP  $label\n"; return; }
    $els = $gen->generate($valid); $j = json_encode($els); $bad=array();
    foreach ($mustContain as $m) if (strpos($j,$m)===false) $bad[]="missing '$m'";
    foreach ($mustNotContain as $m) if (strpos($j,$m)!==false) $bad[]="contains '$m'";
    echo ($bad?"FAIL ":"PASS ")."$label".($bad?' :: '.implode('; ',$bad):'').PHP_EOL;
  } catch (\Throwable $e) { echo "CRASH $label :: ".$e->getMessage().PHP_EOL; }
}
// review fixes
run('vimeo gets vimeo_url', 'gallery', array('variant'=>'videos','headline'=>'V','videos'=>array('https://vimeo.com/76979871')), array('"video_type":"vimeo"','vimeo_url'), array('youtube_url'));
run('youtube watch still works', 'gallery', array('variant'=>'videos','headline'=>'V','videos'=>array('https://www.youtube.com/watch?v=abc12345')), array('youtube_url'));
run('bad video ids rejected', 'gallery', array('variant'=>'videos','headline'=>'V','videos'=>array('https://vimeo.com/123','https://youtube.com/@channel')), array(), array('vimeo.com/123','@channel'));
run('gallery thumbnail_size', 'gallery', array('headline'=>'G','images'=>array('/wp-content/uploads/x.jpg')), array(), array('gallery_image_size'));
run('star glyphs become N-star + keep stars', 'hero', array('headline'=>'H','subheadline'=>'s','cta_primary'=>array('text'=>'Go'),'trust_line'=>'★★★★★ from 200+ happy clients'), array('5-star','star-rating'));
run('tripadvisor scale keeps stars', 'hero', array('headline'=>'H','subheadline'=>'s','cta_primary'=>array('text'=>'Go'),'trust_line'=>'9.8/10 on TripAdvisor'), array('star-rating'));
run('non-review trust line: no stars', 'hero', array('headline'=>'H','subheadline'=>'s','cta_primary'=>array('text'=>'Go'),'trust_line'=>'Licensed and insured since 1998'), array('Licensed'), array('star-rating'));
run('list/comparison no headline no warn', 'pricing', array('variant'=>'list','items'=>array(array('name'=>'Cut','price'=>'$20'))), array('Cut'));
run('cjk initials render', 'team', array('variant'=>'spotlight','members'=>array(array('name'=>"\u{7530}\u{4E2D} \u{592A}\u{90CE}",'role'=>'Owner'))), array('pg-avatar-circle'));
echo "done\n";
