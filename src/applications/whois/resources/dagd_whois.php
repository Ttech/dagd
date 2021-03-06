<?php

require_once dirname(__FILE__).'/../../../resources/global_resources.php';

class DaGdWhois {
  private $domain;
  private $query = '';
  private $whois_server;
  private $whois_port = 43;
  private $skip_detail = false;
  // Used in the hardcode map where we never want to treat a server as
  // transient.
  private $query_directly = false;
  // Keep the transient result around in case we can't connect to the server
  // we're redirected to. In that case, we return this result.
  private $first_query_result = '';

  public function __construct($domain) {
    $this->domain = $domain;
  }

  /*
   * Given a domain (passed to the constructor), we need to use the tld of it
   * to connect to <tld>.whois-servers.net to get the real whois server.
   * Using this, we end up making two whois queries, but it's the cleanest
   * way to do this.
   *
   * @returns <string> the tld of the domain passed to the constructor.
   */
  private function tld() {
    $tld = explode('.', $this->domain);
    $tld = end($tld);
    return $tld;
  }

  /*
   * Look up the proper whois server for a domain, given its tld.
   * First, check if we specify an override in the dagd config. If we do, and it
   * contains only a query line, use it and continue. Otherwise, if it also
   * contains the server to use, use it and bail out.
   *
   * If we are still here, then we have some more work to do. We roughly emulate
   * the OpenBSD whois client here. First we try whois.nic.TLD. Failing that, we
   * try TLD.whois-servers.net.
   *
   * The result is stored in $this->whois_server.
   *
   * @returns <bool> true if successful, false if not.
   */
  public function fetchWhoisServer() {
    $hardcoded_tld_map = DaGdConfig::get('whois.hardcode_map');
    if (array_key_exists($this->tld(), $hardcoded_tld_map)) {
      $custom_tld = $hardcoded_tld_map[$this->tld()];

      // We can have a custom query without having a custom server...
      if (array_key_exists('query', $custom_tld)) {
        $this->query = $custom_tld['query'].' ';
      }

      if (array_key_exists('server', $custom_tld)) {
        $this->whois_server = $custom_tld['server'];
      }

      if (array_key_exists('port', $custom_tld)) {
        $this->whois_port = $custom_tld['port'];
      }

      if (array_key_exists('query_directly', $custom_tld)) {
        $this->query_directly = $custom_tld['query_directly'];
      }
    }

    if ($this->query_directly) {
      // If we are in the hardcode map and told to query directly, we don't
      // want to bother with any of the transient stuff below. Bail out after
      // setting the variables above.
      return true;
    }

    $transient_sock = null;
    if (filter_var($this->domain, FILTER_VALIDATE_IP)) {
      $default = DaGdConfig::get('whois.transient_server');

      $default_server = $default['server'];
      $default_port = $default['port'];

      if (strpos($default_server, ':') !== false) {
        list($default_server, $default_port) = explode(':', $default_server, 2);
      }

      $transient_sock = fsockopen($default_server, $default_port);
      if (!$transient_sock) {
        return false;
      }
      fwrite($transient_sock, $default['query'].' '.$this->domain."\r\n");
    } else {
      // A domain query (as opposed to an IP query)
      $errno = 0;
      $errstr = '';
      if (!empty($this->whois_server)) {
        // If we are in the hardcode map, then we set $this->whois_server
        // above. This becomes the *transient* server.
        $generic_timeout = DaGdConfig::get('whois.generic_tld_timeout');
        $transient_sock = fsockopen(
          $this->whois_server,
          $this->whois_port,
          $errno,
          $errstr,
          $generic_timeout);
        if (($errno != 0) || ($errno == 0 && $transient_sock === false)) {
          // We're in the hardcode map, but that server didn't work.
          // Bail out and call it a day.
          return false;
        }
        fwrite($transient_sock, $this->query.$this->domain."\r\n");
      } else {
        // We're not in the hardcode map.
        $generic_servers = DaGdConfig::get('whois.generic_tld_servers');
        $generic_timeout = DaGdConfig::get('whois.generic_tld_timeout');
        foreach ($generic_servers as $server) {
          $server_with_tld = str_replace('TLD', $this->tld(), $server['server']);
          $transient_sock = fsockopen(
            $server_with_tld,
            idx($server, 'port', 43),
            $errno,
            $errstr,
            $generic_timeout);
          if (($errno != 0) || ($errno == 0 && $transient_sock === false)) {
            continue;
          }
          fwrite($transient_sock, $server['query'].$this->domain."\r\n");
          break;
        }
      }

      if (!$transient_sock) {
        return false;
      }
    }

    $whois_server = null;
    $whois_info = '';
    $referred = false;

    // We need to store the entire result because even if we get referred to a
    // server, we might fail querying it and fail back to the transient result.
    while (!feof($transient_sock)) {
      $whois_info .= fgets($transient_sock);
    }

    fclose($transient_sock);
    $this->first_query_result = $whois_info;
    $blacklisted_referrals = DaGdConfig::get('whois.referral_blacklist');

    // Now we look for a referral server.
    foreach(preg_split('#((\r?\n)|(\r\n?))#', $whois_info) as $line){
      $referral = preg_match(
        '#(?:Whois Server|ReferralServer): (.*)#i',
        $line,
        $whois_server);

      // This can't be easy because there's an edge case where the referral
      // server doesn't exist, so after parsing we get a simple "\r" back.
      $referral_server_name = null;
      if (!empty($whois_server) && count($whois_server) > 1) {
        $referred = true;
        $referral_server_name = preg_replace(
            '#r?whois://#',
            '',
            $whois_server[1]);
        $referral_server_name = trim($referral_server_name);

        if (strpos($referral_server_name, ':') !== false) {
          $exp = explode(':', $referral_server_name, 2);
          $this->whois_server = trim($exp[0]);
          $this->whois_port = trim($exp[1]);
        } else {
          $this->whois_server = $referral_server_name;
        }
      }

      // If the server we found above is NOT in the blacklist, we are good to
      // jump away and query it.
      if ($referred) {
        if (!in_array($this->whois_server, $blacklisted_referrals)) {
          return true;
        }
        $referred = false;
      }
    }

    $this->skip_detail = true;
    return $whois_info;
  }

  /*
   * Now that we know which server to query, we can query them directly
   * and this should be the final step.
   *
   * @returns <string> the result from the real whois server.
   * @returns <bool> false if non successful.
   */
  private function fetchWhoisDetails() {
    $timeout = DaGdConfig::get('whois.redirect_timeout');
    $errno = 0;
    $errstr = '';
    $sock = fsockopen(
      $this->whois_server,
      (int)$this->whois_port,
      $errno,
      $errstr,
      $timeout);
    if (($errno != 0) || ($errno == 0 && $sock === false)) {
      return $this->first_query_result;
    }
    fwrite($sock, $this->domain."\r\n");
    $response = '';
    while (!feof($sock)) {
      $response .= fgets($sock);
    }
    fclose($sock);
    if (strlen($response)) {
      return $response;
    } else {
      return false;
    }
  }

  /*
   * Use the above functions to give actual whois info.
   *
   * @returns <string> whois info!
   */
  public function performQuery() {
    $whois_server = $this->fetchWhoisServer();
    if ($this->skip_detail) {
      return $whois_server;
    } else {
      return $this->fetchWhoisDetails();
    }
  }
}
