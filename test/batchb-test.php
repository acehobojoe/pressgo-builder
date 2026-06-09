<?php
error_reporting(E_ERROR | E_PARSE);
$pd = '/var/www/wp.pressgo.app/htdocs/wp-content/plugins/pressgo-builder/';
foreach (array('includes/generator/class-pressgo-element-factory.php','includes/generator/class-pressgo-style-utils.php','includes/generator/class-pressgo-icons.php','includes/generator/class-pressgo-widget-helpers.php','includes/generator/class-pressgo-section-builder.php','includes/generator/class-pressgo-generator.php','includes/class-pressgo-config-validator.php') as $f) require_once $pd.$f;
function run($label, $type, $sec, $mustContain = array(), $mustNotContain = array()) {
  static $base = null, $gen = null;
  if (null === $gen) {
    $gen = new PressGo_Generator();
    $base = array(
      'colors' => array('primary'=>'#7C3AED','accent'=>'#A78BFA','dark_bg'=>'#1E1B2E','light_bg'=>'#FAF9FC','white'=>'#FFFFFF','text_dark'=>'#1E1B2E','text_muted'=>'#6B6580','text_light'=>'rgba(255,255,255,0.72)','gold'=>'#F59E0B','border'=>'rgba(30,27,46,0.08)'),
      'fonts' => array('heading'=>'Sora','body'=>'Inter'),
      'layout' => array('boxed_width'=>1200,'section_padding'=>96,'card_radius'=>16,'button_radius'=>10,'card_shadow'=>array('horizontal'=>0,'vertical'=>8,'blur'=>30,'spread'=>-8,'color'=>'rgba(30,27,46,0.10)')),
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
$IMG='https://images.pexels.com/photos/265087/pexels-photo-265087.jpeg';
// pricing.list
run('pricing.list grouped menu', 'pricing', array('variant'=>'list','headline'=>'Our Menu','items'=>array(
  array('name'=>'Margherita','price'=>'$14','desc'=>'San Marzano, fresh mozzarella','category'=>'Pizza'),
  array('name'=>'Diavola','price'=>'$17','category'=>'Pizza'),
  array('name'=>'Tiramisu','price'=>'$9','category'=>'Dolci'),
)), array('Margherita','$14','Pizza','Dolci','San Marzano'));
run('pricing.list strings + junk', 'pricing', array('variant'=>'list','headline'=>'Services','items'=>array('Haircut', array('name'=>'Color','price'=>'$120'), 42)), array('Haircut','Color','$120'));
run('pricing.list no items -> plans fallback', 'pricing', array('variant'=>'list','headline'=>'P','plans'=>array(array('name'=>'Basic','price'=>'$9','features'=>array('a'),'cta'=>'Go'))), array('Basic'));
// map.contact
run('map.contact full', 'map', array('variant'=>'contact','headline'=>'Visit Us','address'=>'113 E Poinsett St, Greer SC','phone'=>'(864) 555-0101','email'=>'hi@shop.com','hours'=>array('Mon-Fri 9-6','Sat 10-4'),'note'=>'Walk-ins welcome','cta'=>array('text'=>'Book Now','url'=>'#')), array('113 E Poinsett','tel:8645550101','Mon-Fri 9-6','google_maps','Book Now'), array());
run('map.contact no address', 'map', array('variant'=>'contact','phone'=>'(864) 555-0101','hours'=>array('Daily 8-8')), array('tel:','Daily 8-8'), array('google_maps'));
run('map.contact bare address fallback', 'map', array('variant'=>'contact','address'=>'1 Main St, Greer SC'), array('google_maps'));
// gallery.before_after
run('before_after pairs', 'gallery', array('variant'=>'before_after','headline'=>'Transformations','pairs'=>array(
  array('before'=>$IMG,'after'=>$IMG,'result'=>'Lost 32 lbs in 16 weeks','caption'=>'Marcus, 12-week program'),
  array('before'=>'fakeToken','after'=>$IMG),
)), array('Lost 32 lbs','Before','After','Marcus'), array('fakeToken'));
run('before_after none -> cards fallback', 'gallery', array('variant'=>'before_after','headline'=>'Work','images'=>array(array('url'=>$IMG,'caption'=>'x'))), array('pexels-photo-265087'));
// gallery.videos
run('gallery.videos mixed', 'gallery', array('variant'=>'videos','headline'=>'Films','videos'=>array('https://www.youtube.com/watch?v=abc123', array('url'=>'https://vimeo.com/123','title'=>'Brand film','caption'=>'2026 reel'), 'https://not-a-video.com/x.mp4')), array('youtube.com','vimeo.com','Brand film'), array('not-a-video'));
run('gallery.videos none -> null', 'gallery', array('variant'=>'videos','headline'=>'Films','videos'=>array('https://not-video.org')), array(), array('Films'));
// competitive_edge.comparison
run('ce.comparison full', 'competitive_edge', array('variant'=>'comparison','headline'=>'Why us','description'=>'d','us_label'=>'CleanPro','them_label'=>'Typical cleaners','benefits'=>array('Insured team','Flat pricing'),'them_points'=>array('Rotating strangers','Hidden fees'),'cta'=>'Get a quote'), array('CleanPro','Typical cleaners','Rotating strangers','ph-x','ph-check-circle','Get a quote'));
run('ce.comparison no them -> default fallback', 'competitive_edge', array('variant'=>'comparison','headline'=>'Why us','description'=>'d','benefits'=>array('A','B')), array('icon-list'));
// team.spotlight + auto-route
run('team.spotlight full', 'team', array('variant'=>'spotlight','eyebrow'=>'YOUR ATTORNEY','members'=>array(array('name'=>'Dana Whitfield','role'=>'Founding Partner','bio'=>'Two decades of family law.','credentials'=>array('SC Bar 1998','AV Preeminent Rated'),'cta'=>array('text'=>'Book a consult')))), array('Dana Whitfield','SC Bar 1998','ph-medal','Book a consult'));
run('team 1 member auto-routes to spotlight', 'team', array('headline'=>'Meet your coach','members'=>array(array('name'=>'Sam Okoro','role'=>'Head Coach','bio'=>'CSCS, 10 years.'))), array('Sam Okoro','"size":38'));
run('team spotlight no photo initials', 'team', array('variant'=>'spotlight','members'=>array(array('name'=>'Maya Lindqvist','role'=>'Owner'))), array('ML','border-radius:9999px'));
echo "done".PHP_EOL;
