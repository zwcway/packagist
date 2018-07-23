<?php
namespace zwcway\Packagist;

define('D', DIRECTORY_SEPARATOR);

class Packagist {
  public $base = 'https://packagist.org/';
  public $encrypt = 'sha256';
  protected $providers_url = '';
  protected $uri;
  protected $dumps = [];
  protected $retry = 0;

  public function __construct() {
    if (!file_exists('p')) {
      mkdir('p', 0755);
    }
    set_error_handler([$this, 'handleError']);
  }

  /**
   * 命令入口
   *
   * @param $argc
   * @param $argv
   *
   * @return string
   */
  public function runCli($argc, $argv) {
    $argc >= 2 && $argc <= 3 OR $this->help();

    $command = strtolower($argv[1]);

    if (isset($argv[2])) {
      switch ($option = strtolower($argv[2])) {
        case '--debug':
          Log::enableDebug();
          break;
        case '-h':
          $this->help($command);
          break;
        default:
          Log::error("Unknown option {$argv[2]}");
          $this->help();
          break;
      }
    }

    switch ($command) {
      case 'dumpindex':
      case 'di':
        $this->dumpIndex();
        break;
      case 'dumpall':
      case 'da':
        $this->dumpAll();
        break;
      case 'packages':
      case 'dp':
        $this->dumpPackages();
        break;
      case 'cleanindex':
      case 'ci':
        $this->cleanIndex();
        break;
      case 'cleanpackages':
      case 'cp':
        $this->cleanPackages();
        break;
      case 'cleanall':
      case 'ca':
        $this->cleanAll();
        break;
      case '-h':
      case 'help':
      case '--help':
        $this->help();
        break;
      default:
        Log::error("Unknown command {$argv[1]}");
        $this->help();
        break;
    }
    return 'done';
  }

  public function run($uri) {
    if ($uri == '') {
      return;
    }
    $count = preg_match('~^(.*?)\$([A-Za-z0-9]+)(.+)~', $uri, $matches);
    $hash = '';
    if ($count) {
      $name = $matches[1];
      $hash = $matches[2];
      $suffix = $matches[3];
    }

    return $this->get($uri, $hash);
  }

  /**
   * 输出帮助
   *
   * @param string $command
   */
  public function help($command = '') {
    echo "Usage：\n";
    echo "    Command [Option]\n";
    echo "\n";
    echo "Option：\n";
    echo "    -h|--help          Display this help message.\n";
    echo "    --debug            Print Debug message.\n";
    echo "\n";
    echo "Command：\n";
    echo "    cleanall[ca]       Clean all files auto.\n";
    echo "    cleanindex[ci]     Clean all index files day ago.\n";
    echo "    cleanpackages[cp]  Clean all files day ago.\n";
    echo "    dumpall[da]        Download all files\n";
    echo "    dumpindex[di]      Download index files\n";
    echo "    packages[dp]       Download packages.json\n";
    exit();
  }

  public function handleError(
    $level,
    $message,
    $file = '',
    $line = 0,
    $context = []
  ) {
    if (error_reporting() & $level) {
      throw new \ErrorException($message, 0, $level, $file, $line);
    }
  }

  /**
   * 下载 packages.json
   *
   * @return array
   */
  protected function dumpPackages() {
    $packagesJson = $this->get('packages.json', '', FALSE);
    $packages = json_decode($packagesJson, TRUE);
    $packages['updated'] = date(DATE_W3C);
    unset($packages['search']);
    file_put_contents('packages.json', json_encode($packages));
    $this->providers_url = $packages['providers-url'];
    return $packages;

  }

  /**
   * 下载索引
   *
   * @return array
   */
  protected function dumpIndex() {
    $packages = $this->dumpPackages();
    $names = [];
    foreach ($packages['provider-includes'] as $key => $hash) {
      $name = str_replace('%hash%', reset($hash), $key);
      $names[] = $name;
    }

    return $this->multiDump($names);
  }

  /**
   * 下载所有
   */
  protected function dumpAll() {
    $names = $this->dumpIndex();
    $names = array_reverse($names);

    foreach ($names as $name) {
      $packages = [];
      $cleans = [];
      $include = json_decode($this->get($name), TRUE);
      foreach ($include['providers'] as $key => $hash) {
        $pgkName = ltrim(
          str_replace(
            ['%hash%', '%package%'],
            [reset($hash), $key],
            $this->providers_url),
          '/');
        $packages[] = $pgkName;

        $cleans[$pgkName] = ltrim(
          str_replace(
            ['%hash%', '%package%'],
            ['*', $key],
            $this->providers_url),
          '/');
      }
      $providers = [];
      Log::info("Dumping $name");
      foreach ($this->multiDump($packages) as $pgkName) {
        if (isset($cleans[$pgkName])) {
          $providers[] = $this->redir($cleans[$pgkName]);
        } else {
          Log::info("$pgkName not found");
        }
      }
      $this->cleanPackages($providers);
    }
    $this->cleanIndex();
  }

  /**
   * 清理索引
   */
  protected function cleanIndex() {
    $providers = [];
    foreach (glob('p/provider-*') as $provider) {
      if (is_file($provider)) {
        $providers[$provider] = filectime($provider);
      }
    }
    $this->filterUnlink($providers);
  }

  /**
   * 清理全部
   *
   * @param string $dir
   */
  protected function cleanPackages($dir = 'p/*') {
    if (!is_array($dir)) {
      $dir = glob($dir);
    }
    foreach ($dir as $provider) {
      $packages = [];
      $files = [];
      if (is_dir($provider)) {
        $files = glob("$provider/*");
      } else {
        $files = glob($provider);
      }
      foreach ($files as $package) {
        $packages[$package] = filectime($package);
      }
      $this->filterUnlink($packages);
    }
  }

  /**
   * 删除未索引的文件
   */
  protected function cleanAll() {
    $name = 'packages.json';
    if (file_exists($name)) {
      $files = [];
      $packages = json_decode(file_get_contents($name), TRUE);
      $providerUrl = $packages['providers-url'];
      foreach($packages['provider-includes'] as $key => $hash) {
        $files[$name = $this->redir(str_replace('%hash%', reset($hash), $key))] = 1;

        if (file_exists($name)) {
          $include = json_decode(file_get_contents($name), TRUE);
          foreach ($include['providers'] as $key => $hash) {
            $files[$name = $this->redir(ltrim(
              str_replace(
                ['%hash%', '%package%'],
                [reset($hash), $key],
                $providerUrl),
              '/'))] = 1;
          }
          unset($include);
        }
      }
      unset($packages);

      Log::debug("Found " . count($files) . ' files');

      foreach(glob('p/*') as $file) {
        if (is_dir($file)) {
          foreach(glob($file . '/**/*') as $file) {
            if (is_file($file) && !isset($files[$file])) {
              Log::info("Deleting $file");
              @unlink($file);
            }
          }
        } else {
          if (!isset($files[$file])) {
            Log::info("Deleting $file");
            @unlink($file);
          }
        }
      }
    }
  }

  /**
   * 删除过时文件
   *
   * @param $set
   */
  protected function filterUnlink($set) {
    if (count($set) <= 1) return;

    $now = time();
    foreach ($set as $name => $time) {
      if ($now - $time > 86400) {
        $realname = $this->redir($name);
        $dirname = dirname($realname);
        Log::info("unlink {$dirname} {$name}\n");
        @unlink($realname);
      }
    }
  }

  /**
   * 多线程下载
   *
   * @param array $names
   * @param bool  $force
   *
   * @return array
   */
  protected function multiDump(array $names, $force = FALSE) {
    $multiCurl = NULL;

    if (extension_loaded('curl')) {
      $multiCurl = new MultiCurl(100);
      $options = [
        CURLOPT_SSL_VERIFYPEER => FALSE,
      ];
      if ($proxy = getenv('http_proxy')) {
        $options[CURLOPT_PROXY] = $proxy;
      }

      $multiCurl->setOptions($options);
      $multiCurl->setTimeout(60000);
    }

    $downloaded = [];
    $progress = 0;
    $count = count($names);
    foreach ($names as $i => $name) {
      $this->dumpFile($name, '', $force, $multiCurl, function($name, $download)
      use (&$progress, $count, &$downloaded) {
        $downloaded[] = $name;
        Log::progress('Downloading', $progress++, $count);
      });
    }

    if ($multiCurl) {
      $multiCurl->execute();
    }

    unset($multiCurl, $names);

    return $downloaded;
  }

  /**
   * 获取单个文件
   *
   * @param        $name
   * @param string $hash
   * @param bool   $force
   *
   * @return string
   */
  protected function get($name, $hash = '', $force = FALSE) {
    if (!$force && file_exists($name)) {
      $content = file_get_contents($name);
      if (!$hash || $hash == hash($this->encrypt, $content)) {
        return $content;
      }
    }
    $this->dumpFile($name, $hash, $force);
    return file_get_contents($name);
  }

  /**
   * 解析路径
   *
   * @param $name
   *
   * @return array
   */
  protected function dirname($name) {
    $dirname = '';
    $basename = $name;
    if (($pos = strrpos($name, '/')) !== FALSE) {
      $dirname = substr($name, 0, $pos);
      $basename = substr($name, $pos + 1);
      $dirname{0} === '/' AND $dirname = substr($dirname, 1);
    }

    return [$dirname, $basename];
  }

  /**
   * 获取本地文件名
   *
   * @param $name
   *
   * @return string
   */
  protected function redir($name) {
    $realname = $name;
    list($dirname, $pkgname) = $this->dirname($name);
    if ($dirname) {
      list($dirname, $username) = $this->dirname($dirname);

      $dirname AND $dirname .= '/' . $username{0};
      isset($username[1]) AND $dirname .= '/' . $username{1};

      $realname = $dirname . '/' . $username . '/' . $pkgname;
      $realname{0} === '/' AND $realname = substr($realname, 1);
    }

    return $realname;
  }

  /**
   * 从远程服务器下载文件
   *
   * @param                $name
   * @param string         $hash
   * @param bool           $force
   * @param MultiCurl|null $multiCurl
   *
   * @param \Closure       $callback
   *
   * @return bool
   */
  protected function dumpFile(
    $name,
    $hash = '',
    $force = FALSE,
    MultiCurl $multiCurl = NULL,
    \Closure $callback = NULL
  ) {
    if ($this->retry >= 5) {
      Log::terminal(500, 'Retry Limited');
    }
    $realname = $this->redir($name);
    Log::debug("File '$name': '$realname'");

    if (!$force && file_exists($realname)) {
      Log::debug("$realname exists\n");
      $callback AND $callback($name, FALSE);
      return FALSE;
    }
    try {
      list($dirname, $basename) = $this->dirname($realname);
      if ($dirname && !file_exists($dirname)) {
        Log::debug("Folder '$dirname' not exists, create it.");
        mkdir($dirname, 0755, TRUE);
      }
      if ($multiCurl) {
        $multiCurl->addRequest(
          $this->url($name),
          NULL,
          function ($response, $url, $request_info, $user_data, $time) use (
            $name,
            $realname,
            $dirname,
            $callback
          ) {
            if ($response) {
              file_put_contents($realname, $response);
              Log::debug($name . " wrote ($realname)");
              $callback AND $callback($name, true);
            }
          }
        );

      }
      else {
        if ($proxy = getenv('http_proxy')) {
          $aContext = array(
            'http' => array(
              'proxy'            => $proxy,
              'request_fullname' => TRUE,
              'timeout'          => 60
            ),
          );
          $cxContext = stream_context_create($aContext);

          $content = file_get_contents($this->url($name), FALSE, $cxContext);

        }
        else {
          $content = file_get_contents($this->url($name));
        }

        if (!$content) {
          return FALSE;
        }
        if ($hash && $hash !== hash($this->encrypt, $content)) {
          Log::terminal(500, 'hash error');
        }
        file_put_contents($name, $content);
        Log::debug($name . " wrote");
        $callback AND $callback($name, true);
      }
    } catch (\Exception $e) {
      if (isset($http_response_header)) {
        $code = preg_match('~\d{3}~', $http_response_header[0], $matches);
        if (!$code) {
          Log::terminal(404, json_encode($http_response_header));
        }
        else {
          Log::terminal($matches[0], json_encode($http_response_header));
        }
      }
      else {
        Log::error($e->getMessage());
        Log::info("Retring " . $this->url($name));
        sleep(2);
        $this->retry++;
        $this->dumpFile($name, $hash, $force, $multiCurl);
        $this->retry = 0;
      }
    }

    return TRUE;
  }

  /**
   * 拼接网址
   *
   * @param $uri
   *
   * @return string
   */
  protected function url($uri) {
    return $this->base . $uri;
  }

}
