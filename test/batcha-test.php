<?php
/* Batch A regression suite — older-builder normalizers + truthfulness fixes. */
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
// stats: non-numeric values render verbatim, never a counter at 0
run('stats non-numeric values', 'stats', array('items'=>array(array('value'=>'Family Owned','label'=>'Since 1987','icon'=>'fas fa-home'), array('value'=>'500+','label'=>'Roofs Replaced','icon'=>'fas fa-bolt'))), array('Family Owned','Since 1987','"ending_number":500'), array('"ending_number":0'));
run('stats string items', 'stats', array('items'=>array('Family Owned','24/7 Service')), array('Family Owned'));
run('stats dark non-numeric', 'stats', array('variant'=>'dark','items'=>array(array('value'=>'A+','label'=>'BBB Rating'))), array('A+','BBB Rating'), array('"ending_number":0'));
run('stats inline mixed', 'stats', array('variant'=>'inline','items'=>array(array('value'=>'12 yrs','label'=>'Experience'), array('value'=>'Same Day','label'=>'Quotes'))), array('Same Day','"ending_number":12'));
// steps
run('steps string items', 'steps', array('headline'=>'How','items'=>array('Book a call','Get a plan','Launch')), array('Book a call','Launch'));
run('steps timeline time markers', 'steps', array('variant'=>'timeline','headline'=>'Agenda','items'=>array(array('num'=>'6:00 PM','title'=>'Doors open','desc'=>'x'), array('num'=>'7:30 PM','title'=>'Main event','desc'=>'y'))), array('6:00 PM','border-radius:999px'));
// faq aliases
run('faq question/answer aliases', 'faq', array('headline'=>'FAQ','items'=>array(array('question'=>'How long?','answer'=>'Two weeks.'), array('q'=>'Cost?','a'=>'$99'))), array('How long?','Two weeks.','Cost?'));
run('faq junk items', 'faq', array('headline'=>'FAQ','items'=>array('just a string', array('answer'=>'orphan answer'), array('q'=>'Real?','a'=>'Yes')))  , array('Real?'), array('orphan answer'));
// team
run('team string members', 'team', array('headline'=>'Team','members'=>array('Jane Doe','John Smith')), array('Jane Doe','John Smith'));
run('team member no role', 'team', array('headline'=>'Team','members'=>array(array('name'=>'Solo Founder'))), array('Solo Founder'));
// results default
run('results string-cta + array color', 'results', array('headline'=>'R','metrics'=>array(array('value'=>'97%','label'=>'Satisfaction','color'=>array('bad'=>1))),'cta'=>'See more'), array('97','See more'), array('Array'));
// pricing
run('pricing string plan + custom price', 'pricing', array('headline'=>'P','plans'=>array(array('name'=>'Custom','price'=>'Custom','features'=>array('Everything')), 'Starter')), array('Custom','Starter'), array('/mo'));
run('pricing period suppression', 'pricing', array('headline'=>'P','plans'=>array(array('name'=>'A','price'=>'from $99/mo + setup','features'=>array('x'),'cta'=>'Go'))), array('from $99\\/mo + setup'), array('"editor":"\\/mo"'));
run('pricing single plan centered', 'pricing', array('headline'=>'P','plans'=>array(array('name'=>'Solo','price'=>'$49','highlighted'=>true,'features'=>array('a','b'),'cta'=>array('text'=>'Buy')))), array('Solo','hidden-mobile'));
// testimonials
run('testimonials 5 quotes adaptive', 'testimonials', array('headline'=>'T','items'=>array(array('quote'=>'q1','name'=>'A'),array('quote'=>'q2','name'=>'B'),array('quote'=>'q3','name'=>'C'),array('quote'=>'q4','name'=>'D'),array('quote'=>'q5','name'=>'E'))), array('q5'));
run('testimonials featured first-item contract', 'testimonials', array('variant'=>'featured','headline'=>'T','items'=>array(array('quote'=>'Short but mighty quote from the first customer','name'=>'First'), array('quote'=>str_repeat('Very long rambling quote ',20),'name'=>'Second'))), array('Short but mighty'));
run('testimonials featured anon quotes survive', 'testimonials', array('variant'=>'featured','headline'=>'T','items'=>array(array('quote'=>'Featured one with plenty of words to pass the stub check easily'), array('quote'=>'Anonymous second quote'), array('quote'=>'Anonymous third quote'))), array('Anonymous second quote','Anonymous third quote'));
// social proof
run('social_proof object categories', 'social_proof', array('headline'=>'Trusted','categories'=>array(array('name'=>'SaaS'), array('text'=>'Fintech'), 'Healthcare')), array('SaaS','Fintech','Healthcare'), array('Array'));
run('social_proof comma string', 'social_proof', array('headline'=>'Trusted','categories'=>'Plumbing, HVAC, Roofing'), array('Plumbing','Roofing'));
// logo bar
run('logo_bar comma string', 'logo_bar', array('headline'=>'Clients','logos'=>'Acme, Globex, Initech'), array('Acme','Initech'));
// footer
run('footer string column skipped', 'footer', array('business_name'=>'X','columns'=>array('junk string', array('title'=>'Links','links'=>array('Home'))),'contact'=>'123 Main St, Greer SC'), array('Links','123 Main St'));
// map
run('map array address', 'map', array('address'=>array('street'=>'1 Main St','city'=>'Greer','state'=>'SC','zip'=>'29651')), array('1 Main St, Greer, SC, 29651'), array('Array'));
// gallery external URLs → plain image grid, not gallery widget
run('gallery external urls safe', 'gallery', array('headline'=>'Work','images'=>array('https://images.pexels.com/photos/265087/pexels-photo-265087.jpeg','https://images.pexels.com/photos/590016/pexels-photo-590016.jpeg')), array('pexels-photo-265087'), array('"wp_gallery"'));
// newsletter
run('newsletter string cta', 'newsletter', array('headline'=>'News','cta'=>'Join the list'), array('Join the list'));
// competitive edge
run('ce object benefits', 'competitive_edge', array('headline'=>'Why','description'=>'d','benefits'=>array(array('text'=>'Licensed & insured'), 'Fast turnaround', array('junk'=>1))), array('Licensed & insured','Fast turnaround'), array('Array'));
// hero long headline adaptive
run('hero long headline scales', 'hero', array('headline'=>'Same-Day Freight Brokerage for Manufacturers Across the Southeast United States','subheadline'=>'x','cta_primary'=>array('text'=>'Go')), array('"header_size":"h1"'), array());
echo "done".PHP_EOL;
