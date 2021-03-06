<?php
require_once dirname(__FILE__).'/resources/getallheaders.php';

final class DaGdHeadersController extends DaGdBaseClass {
  public $__help__ = array(
    'title' => 'headers',
    'summary' => 'Show HTTP headers for various conditions.',
    'path' => 'headers',
    'examples' => array(
      array(
        'arguments' => null,
        'summary' => 'The headers your browser is sending in its request'),
      array(
        'arguments' => array('google.com'),
        'summary' => 'The headers that "http://google.com/" sends'),
    ));

  protected $wrap_html = true;

  public function render() {
    $headers = array();
    $response = '';

    if (count($this->route_matches) > 1) {
      $site = $this->route_matches[1];

      if (!preg_match('@^https?://@i', $site)) {
        $site = 'http://'.$site;
      }

      $headers = @get_headers($site);
      if (!$headers) {
        error400('Headers could not be retrieved for that domain.');
        return;
      }

      foreach ($headers as $header) {
        $response .= $header."\n";
      }

    } else {
      $headers = getallheaders();
      foreach ($headers as $key => $value) {
        if (server_or_default('HTTP_X_DAGD_PROXY') == "1") {
          if (strpos($key, 'X-Forwarded-') === 0 ||
              $key == 'X-DaGd-Proxy') {
            continue;
          }
        }

        $response .= $key.': '.$value."\n";
      }
    }
    return $response;
  }
}
