<?php
/*
  Downwind - flexible remote HTTP requests using native PHP streams
  in public domain | by Proger_XP | https://github.com/ProgerXP/Downwind

  Standalone unless you're doing multipart/form-data upload() - in this case
  http://proger.i-forge.net/MiMeil class is necessary (see encodeMultipartData()).
  Note that you'll need to not only require() it but also set up as described there.

  If you are going to use SOCKS proxying you will need Phisocks class from
  https://github.com/ProgerXP/Phisocks (it's standalone and also in public domain).

 ***

  $downwind = Downwind::make('http://google.com', array(
    'Cookie' => 'test=test',
  ));

  $downwind->addQuery('q' => 'search-me');
  $downwind->upload('filevar', 'original.txt', fopen('file.txt', 'r'));
  $downwind->contextOptions['ignore_errors'] = 1;
  $downwind->thruSocks('localhost', 1083);

  echo $downwind->fetchData();

*/

class Downwind {
  //= array of str
  static $agents;
  static $maxFetchSize = 20971520;      // 20 MiB
  static $saveDataChunk = 4194304;      // 4 MiB
  //= array of int HTTP statuses which trigger Location redirect
  static $redirectCodes = array(301, 302, 303, 307, 308);

  public $contextOptions = array();
  public $url;
  public $headers;
  //= str URL-encoded data, array upload data (handles and/or strings)
  public $data;
  //= null, Phisocks to proxy requests through
  public $phisocks;

  public $handle;
  public $responseHeaders;
  public $reply;

  static function mimeByExt($ext) {
    $mail = new MiMeil('', '');
    return $mail->MimeByExt($ext, 'application/octet-stream');
  }

  static function queryStr(array $query, $noQuestionMark = false) {
    $query = http_build_query($query, '', '&');
    if (!$noQuestionMark and "$query" !== '') { $query = "?$query"; }
    return $query;
  }

  static function randomAgent() {
    return static::$agents ? static::$agents[ array_rand(static::$agents) ] : '';
  }

  static function makeQuotientHeader($items, $append = null) {
    is_string($items) and $items = array_filter(explode(' ', $items));
    shuffle($items);

    $count = mt_rand(1, 4) + isset($append);
    $qs = range(1, 1 / $count, 1 / $count);
    isset($append) and array_splice($items, $count - 1, 0, array($append));

    $parts = array();

    for ($i = 0; $i < $count and $items; ++$i) {
      $parts[] = array_shift($items).($i ? ';q='.round($qs[$i], 1) : '');
    }

    return join(',', $parts);
  }

  static function it($url, $headers = array()) {
    $obj = static::make($url, $headers);
    $resp = $obj->fetchData();
    $obj->close();
    return $resp;
  }

  static function make($url, $headers = array()) {
    return new static($url, $headers);
  }

  function __construct($url, $headers = array()) {
    $this->url($url);

    is_array($headers) or $headers = array('referer' => $headers);
    $this->headers = array_change_key_case($headers);
  }

  function __destruct() {
    $this->close()->freeData();
  }

  function freeData($name = null) {
    if (is_array($this->data)) {
      if (!isset($name)) {
        foreach ($this->data as &$file) {
          is_resource($h = $file['data']) and fclose($h);
        }
      } elseif (isset($this->data[$name])) {
        is_resource($h = $this->data[$name]['data']) and fclose($h);
        unset($this->data[$name]);
      }
    }

    isset($name) or $this->data = null;
    return $this;
  }

  function url($new = null) {
    if ($new) {
      if (!filter_var($new, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException("[$new] doesn't look like a valid URL.");
      }

      $this->url = $new;
      return $this;
    } else {
      return $this->url;
    }
  }

  function urlPart($part) {
    return parse_url($this->url, $part);
  }

  function addQuery($vars) {
    is_array($vars) or $vars = array($vars => 1);
    return $this->query($vars + $this->query());
  }

  function query(array $vars = null) {
    if (func_num_args()) {
      $this->url = strtok($this->url, '?').static::queryStr($vars);
      return $this;
    } else {
      strtok($this->url, '?');
      parse_str(strtok(null), $vars);
      return $vars;
    }
  }

  //= str HTTP method like 'post'
  function method($new = null) {
    if (func_num_args()) {
      $this->contextOptions['method'] = strtoupper($new);
      return $this;
    } else {
      return $this->contextOptions['method'];
    }
  }

  function post(array $data, $method = 'post') {
    $this->method($method)->freeData();
    $data and $this->data = static::queryStr($data, true);
    return $this;
  }

  //* $data str, resource - resources are freed by this instance.
  function upload($var, $originalName, $data) {
    $this->method('post')->freeData($var);
    is_array($this->data) or $this->data = array();
    $this->data[$var] = array('data' => $data, 'name' => $originalName);
    return $this;
  }

  function basicAuth($user, $password) {
    $this->headers['Authorization'] = 'Basic '.base64_encode("$user:$password");
    return $this;
  }

  //* $customizer callable - function (Phisocks $phisocks, Downwind $self)
  function thruSocks($host, $port = 1080, $customizer = null) {
    $this->phisocks = new Phisocks($host, $port);
    $customizer and call_user_func($customizer, $this->phisocks, $this);
    return $this;
  }

  function open() {
    if (!$this->handle) {
      $context = $this->createContext();

      if ($this->phisocks) {
        $this->handle = $this->connectSocks($context);
      } else {
        $this->handle = $this->connectDirect($context);
      }

      $this->opened($context, $this->handle);
    }

    return $this;
  }

  protected function connectDirect($context) {
    $h = fopen($this->url, 'rb', false, $context);
    if (!$h) {
      throw new RuntimeException("Cannot fopen({$this->url}).");
    }

    $this->responseHeaders = (array) stream_get_meta_data($h);
    return $h;
  }

  protected function connectSocks($context) {
    $options = stream_context_get_options($context) + array('http' => array());

    // 'proxy' and 'request_fulluri' are unsupported. 'timeout' has no effect
    // as it's Phisocks' responsibility. 'user_agent' is set via headers.
    // If 'protocol_version' is 1.1 then garbage will appear around fetched
    // data due to chunked encoding (also not supported natively by PHP streams).
    $options = $options['http'] + array(
      'method'              => 'GET',
      'header'              => array(),
      'content'             => '',
      'follow_location'     => 1,
      'max_redirects'       => 20,
      'protocol_version'    => '1.0',
      'ignore_errors'       => false,
    );

    if (!empty($options['proxy'])) {
      throw new RuntimeException("Stream proxy is unsupported when downwinding through Phisocks.");
    }

    $url = $this->url;
    $responses = array();

    for ($iteration = 0; $url; ++$iteration) {
      $h = $this->connectSocksTo($url, $options);
      $response = array();

      while ($line = stream_get_line($h, PHP_INT_MAX, "\n")) {
        $line = trim($line);
        if ($line === '') { break; }
        $responses[] = $response[] = $line;
      }

      if (stripos(strtok($response[0], ' '), 'HTTP/') !== 0) {
        throw new RuntimeException("Invalid HTTP response: must begin with \'HTTP/\'.");
      }

      $code = strtok(' ');

      if (!$options['ignore_errors'] and $code >= 400) {
        throw new RuntimeException("HTTP request failed with code $code.");
      }

      if ($options['follow_location'] and in_array($code, static::$redirectCodes)) {
        $url = $this->getLocationValue($response);
      } else {
        $url = null;
      }

      if ($url and $iteration >= $max = $options['max_redirects']) {
        throw new RuntimeException("Maximum number of redirects $max".
                                   " reached while downwinding [$this->url].");
      }
    }

    $this->responseHeaders = (array) stream_get_meta_data($h);
    $this->responseHeaders['wrapper_data'] = $responses;

    return $h;
  }

  //* $options - context options e.g. from stream_context_get_options().
  protected function connectSocksTo($url, array $options) {
    if (!preg_match('~^(https?)://([\w\-.\d]+)(:\d+)?(/.*)?()$~i', $url, $match)) {
      throw new RuntimeException("Unsupported URL [{$this->url}] for Phisocks proxying,".
                                 " only simple HTTP/HTTPS is supported as in".
                                 " http[s]://host.com:81[/...].");
    }

    list(, $scheme, $host, $port, $path) = $match;
    $https = strlen($scheme) === 5;
    $port or $port = ($https ? 443 : 80);
    $this->phisocks->connect($host, $port);
    $https and $this->phisocks->enableCrypto();

    $path or $path = '/';
    $request = strtoupper($options['method'])." $path HTTP/$options[protocol_version]\r\n".
               "Host: $host\r\n";

    $headers = join("\r\n", array_filter($options['header']));
    $headers and $request .= "$headers\r\n";

    strlen($options['content']) and $request .= "$options[content]\r\n";

    $this->phisocks->write("$request\r\n");
    return $this->phisocks->handle();
  }

  protected function getLocationValue(array $headers) {
    foreach ($headers as $header) {
      if (strtolower(substr($header, 0, 9)) === 'location:') {
        return trim(substr($header, 9));
      }
    }
  }

  // A callback for possible overriding in child classes.
  //
  //* $context resource of stream_context_create(), false/null if failed or
  //  used Phisocks
  //* $file resource of fopen(), false if failed
  protected function opened($context, $file = null) { }

  function read($limit = -1, $offset = -1) {
    $limit === -1 and $limit = PHP_INT_MAX;
    $limit = min(static::$maxFetchSize, $limit);

    $this->reply = stream_get_contents($this->open()->handle, $limit, $offset);
    if (!is_string($this->reply)) {
      throw new RuntimeException("Cannot get remote stream contents of [{$this->url}].");
    }

    return $this;
  }

  function close() {
    $s = $this->phisocks and $s->close();
    is_resource($h = $this->handle) and fclose($h);
    $this->handle = null;
    return $this;
  }

  function fetch($limit = -1) {
    $this->read($limit)->close();
    return $this->freeData();   // clean up after request has been completed.
  }

  function fetchData($limit = -1) {
    return $this->fetch($limit)->reply;
  }

  // $this->reply is garbage after calling this method.
  //
  //* $path str (file is overwritten) or resource
  function saveDataTo($path, $limit = -1) {
    $h = is_resource($path) ? $path : fopen($path, 'w');
    $limit === -1 and $limit = PHP_INT_MAX;
    $chunkSize = static::$saveDataChunk;

    for (; $limit > 0; $limit -= $chunkSize) {
      $written = $this->read($chunkSize) ? fwrite($h, $this->reply) : 0;
      if ($written < $chunkSize) { break; }
    }

    return $this->close()->freeData();
  }

  //= str HTML within <body> or entire response if no such tag
  function docBody() {
    $reply = $this->fetchData();
    preg_match('~<body>(.*)</body>~uis', $reply, $match) and $reply = $match[1];
    return trim($reply);
  }

  function createContext() {
    $options = array('http' => $this->contextOptions());
    return stream_context_create($options);
  }

  function contextOptions() {
    $options = $this->contextOptions;

    if (isset($this->data)) {
      if (is_array($this->data)) {
        $this->encodeMultipartData();
      } else {
        $this->headers['Content-Type'] = 'application/x-www-form-urlencoded';
      }

      $this->headers['Content-Length'] = strlen($this->data);
      $options['content'] = $this->data;
    }

    return array('header' => $this->normalizeHeaders()) + $options;
  }

  protected function encodeMultipartData() {
    $data = &$this->data;

    foreach ($data as $var => &$file) {
      if (is_resource($h = $file['data'])) {
        $read = stream_get_contents($h);
        fclose($h);
        $file['data'] = $read;
      }

      $name = $file['name'];
      $ext = ltrim(strrchr($name, '.'), '.');

      $file['headers'] = array(
        'Content-Type' => static::mimeByExt($ext),
        'Content-Disposition' => 'form-data; name="'.$var.'"; filename="'.$name.'"',
      );
    }

    // Join all data strings into one string using generated MIME boundary.
    $mail = new MiMeil('', '');
    $mail->SetDefaultsTo($mail);
    $data = $mail->BuildAttachments($data, $this->headers, 'multipart/form-data');
  }

  //= array of scalar like 'Accept: text/html'
  function normalizeHeaders() {
    foreach (get_class_methods($this) as $func) {
      if (substr($func, 0, 7) === 'header_') {
        $header = strtr(substr($func, 7), '_', '-');

        if (!isset( $this->headers[$header] )) {
          $this->headers[$header] = $this->$func();
        }
      }
    }

    $result = array();

    foreach ($this->headers as $header => $value) {
      if (!is_int($header)) {
        $func = array(get_class($this), 'normHeaderName');
        $header = preg_replace_callback('~(^|-).~', $func, strtolower($header));
      }

      if (is_array($value)) {
        if (!is_int($header)) {
          foreach ($value as &$s) { $s = "$header: "; }
        }

        $result = array_merge($result, array_values($value));
      } elseif (is_int($header)) {
        $result[] = $value;
      } elseif (($value = trim($value)) !== '') {
        $result[] = "$header: $value";
      }
    }

    return $result;
  }

  // preg_replace_callback().
  function normHeaderName($match) {
    return strtoupper($match[0]);
  }

  function has($header) {
    return isset($this->headers[$header]);
  }

  function header_accept_language($str = '') {
    return $str ? static::makeQuotientHeader($str) : '';
  }

  function header_accept_charset($str = '') {
    return $str ? static::makeQuotientHeader($str, '*') : '';
  }

  function header_accept($str = '') {
    return $str ? static::makeQuotientHeader($str, '*/*') : '';
  }

  function header_user_agent() {
    return static::randomAgent();
  }

  function header_cache_control() {
    return mt_rand(0, 1) ? 'max-age=0' : '';
  }

  function header_referer() {
    return $this->urlPart(PHP_URL_SCHEME).'://'.$this->urlPart(PHP_URL_HOST).'/';
  }
}