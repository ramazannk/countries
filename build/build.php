<?php
/**
 * Author: Cristian Romanescu <cristi _at_ eaudeweb dot ro>
 * Created: 201407071327
 */

require_once 'bootstrap.php';

global $argv;

if (count($argv) < 2) {
  echo "Usage: php build.php <css|json|csv|validate_flags|gh_pages|test_html>\n";
  exit(-1);
}

$cmd = $argv[1];

call_user_func("exec_$cmd");

/** Export SQL data in JSON */
function exec_json() {
  global $cfg, $em;
  $q = $em->createQuery("SELECT c FROM Country c ORDER BY c.name");
  $data = $q->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
  $v = json_encode($data, JSON_PRETTY_PRINT);
  file_put_contents($cfg->json_dump, $v);
}

/** Export SQL data in CSV */
function exec_csv() {
  global $cfg, $em;
  $q = $em->createQuery("SELECT c FROM Country c ORDER BY c.name");
  $data = $q->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);

  $fp = fopen($cfg->csv_dump, 'w');
  fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
  foreach ($data as $country) {
    $row = array();
    $row[0] = $country['name'];
    $row[1] = $country['name_official'];
    $row[2] = $country['code2l'];
    $row[3] = $country['code3l'];
    $row[4] = $country['flag_32'];
    $row[5] = $country['flag_128'];
    $row[6] = $country['latitude'];
    $row[7] = $country['longitude'];
    $row[8] = $country['zoom'];
    fputcsv($fp, $row);
  }
  fclose($fp);
}

/** Validate flag files exist on disk */
function exec_validate_flags() {
  global $em;
  $q = $em->createQuery("SELECT c FROM Country c ORDER BY c.name");
  $data = $q->getResult(\Doctrine\ORM\Query::HYDRATE_ARRAY);
  foreach ($data as $country) {
    $name = $country['name'];
    $flag_32 = $country['flag_32'];
    $flag_128 = $country['flag_128'];
    if (empty($flag_32)) {
      echo "WARN: PNG 32px flag not set for: {$name}\n";
    }
    else {
      $f32 = "../data/flags/PNG-32/{$flag_32}";
      if (!is_file($f32)) {
        echo "ERR : Missing PNG 32px flag on disk for: {$name}\n";
      }
    }
    if (empty($flag_128)) {
      echo "WARN: PNG 128px flag not set for: {$name} ({$f32})\n";
    }
    else {
      $f128 = "../data/flags/PNG-128/{$flag_128}";
      if (!is_file($f128)) {
        echo "ERR : Missing 128px flag on disk for: {$name} ({$f128})\n";
      }
    }
    $svg = '../data/flags/svg/' . $country['code2l'] . '.svg';
    if (!is_file($svg)) {
      echo "ERR : Missing SVG flag on disk for: {$name} ({$f128})\n";
    }
  }
  echo "No output above means everything is OK\n";
}

function exec_gh_pages() {
  ob_start();
  require_once 'www/gh-index.php';

  mkdir('gh-pages');
  $data = ob_get_clean();
  if ($f = fopen('gh-pages/index.html', 'w+')) {
    fwrite($f, $data);
    fclose($f);
  }
  $countries = get_countries();
  /** @var Country $country */
  foreach ($countries as $country) {
    $filename = slugify($country->getCode3l()) . '.html';
    if ($f = fopen('gh-pages/' . $filename, 'w+')) {
      $_GET['code'] = $country->getCode3l();
      ob_start();
      $page_title = $country->getNameOfficial();
      require 'www/includes/header.inc';
      require 'www/detail.php';
      require 'www/includes/footer.inc';
      $data = ob_get_clean();
      fwrite($f, $data);
      fclose($f);
    }
  }
}

/** Generate the CSS sprite */
function exec_css() {
  global $cfg;

  $countries = get_countries('c.code2l');
  $content = <<<EOT
.flag { width: 128px; height: 64px; background: url(countries-large.png) no-repeat 0; }
.flag.small { width: 32px; height: 16px; background: url(countries-small.png) no-repeat 0; }
\n
EOT;
  $xl = $yl = $xs = $ys = $i = 0;
  /** @var Country $country */
  foreach ($countries as $country) {
    if ($i % 10 == 0 && $i) {
      $yl -= 64;
      $ys -= 16;
      $xl = 0;
      $xs = 0;
    }
    $xlp = $xl == 0 ? $xl : $xl . 'px';
    $xsp = $xs == 0 ? $xs : $xs . 'px';
    $ylp = $yl == 0 ? $yl : $yl . 'px';
    $ysp = $ys == 0 ? $ys : $ys . 'px';

    $content .= <<<EOT
.flag.large-{$country->getCode2l()}, .flag.large-{$country->getCode3l()} { background-position: {$xlp} {$ylp}; }
.flag.small-{$country->getCode2l()}, .flag.small-{$country->getCode3l()} { background-position: {$xsp} {$ysp}; }
\n
EOT;
    $xl -= 128;
    $xs -= 32;
    $i++;
  }
  file_put_contents($cfg->css_sprite, $content);
}


function exec_test_html() {
  global $em;
  $q = $em->createQuery("SELECT c FROM Country c ORDER BY c.name");
  $data = $q->getResult();

  $content = <<<EOT
<html>
  <table border="1" cellpadding="10" cellspacing="0">
      <tr>
        <th>#</th>
        <th>Name</th>
        <th>Code</th>
        <th>Code 3</th>
        <th>SVG</th>
        <th>PNG 32</th>
        <th>PNG 128</th>
        <th>Latitude</th>
        <th>Longitude</th>
        <th>Zoom</th>
        <th>Official Name</th>
        <th>Region(s)</th>
      </tr>

\n
EOT;

  $languages = [
    'ar' => 'Arabic',
    'en' => 'English',
    'es' => 'Spanish',
    'fr' => 'French',
    'it' => 'Italian',
    'zh' => 'Chinese',
    'ru' => 'Russian',
  ];

  /**
   * @var int $i
   * @var \Country $country
   */
  foreach ($data as $i => $country) {
    $iso2 = $country->getCode2l();

    $names = $country->getCountryNames();
    $name_str = '<ul>';
    /** @var \CountryName $name */
    foreach($names as $name) {
      $name_str .= '<li>' . $languages[$name->getLanguage()] . ': ' . $name->getName() . '</li>';
    }
    $name_str .= '</ul>';

    $regions = $country->getCountryRegions();
    $region_str = '<ul>';
    /** @var \CountryRegion $name */
    foreach($regions as $region) {
      $region_str .= '<li>' . $region->getRegion()->getName() . '</li>';
    }
    $region_str .= '</ul>';

    $content .= <<<EOT
      <tr>
        <td>{$i}</td>
        <td>{$country->getName()}</td>
        <td>{$country->getCode2l()}</td>
        <td>{$country->getCode3l()}</td>
        <td align="left"><img src="data/flags/SVG/{$iso2}.svg" width="180" height="100" /></td>
        <td align="left"><img src="data/flags/PNG-32/{$iso2}-32.png" width="32" height="16" /></td>
        <td align="left"><img src="data/flags/PNG-128/{$iso2}-128.png" width="128" height="64" /></td>
        <td align="right">{$country->getLatitude()}</td>
        <td align="righ">{$country->getLongitude()}</td>
        <td align="right">{$country->getZoom()}</td>
        <td>{$name_str}</td>
        <td style="white-space: nowrap">{$region_str}</td>
      </tr>
EOT;
  }

  $content .= <<<EOT
  </table>
</html>
EOT;
  file_put_contents('../flags.html', $content);
}