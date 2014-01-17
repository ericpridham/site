<?php

class SiteFtp extends SiteComponent {
  public function connect($server, $user = null, $pass = null, $type = 'ftp')
  {
    switch ($type) {
      case 'ftp':
        return new SiteFtpConnection($type, $server, $user, $pass);
      case 'sftp':
        return new SiteSftpConnection($type, $server, $user, $pass);
      default:
        throw new Exception('Type not supported.');
    }
  }
}

class SiteFtpBase {
  protected $server;
  protected $user;
  protected $pass;
  protected $ftp;

  public function __construct($type, $server, $user, $pass)
  {
    $this->server = $server;
    $this->user = $user;
    $this->pass = $pass;

    $this->connect();
  }
}

class SiteFtpConnection extends SiteFtpBase {
  public function connect()
  {
    $this->ftp = @ftp_connect($this->server);
    if ($this->ftp === false) {
      throw new Exception('ftp: connection failed');
    }
    if (@ftp_login($this->ftp, $this->user, $this->pass) === false) {
      throw new Exception('ftp: authentication failed');
    }
  }

  public function ls($path)
  {      
    $r = ftp_nlist($this->ftp, $path);
    if (!is_array($r)) {
      return array();
    }
    $files = array();
    foreach ($r as $file) {
      if ($file{0} == '/') {
        $file = substr($file,1);
      }
      $files[] = $file;
    }
    return $files;
  }

  public function size($path)
  {
    return ftp_size($this->ftp, $path);
  }

  public function mtime($path)
  {
    return ftp_mdtm($this->ftp, $path);
  }

  public function get($remote, $local, $mode = FTP_BINARY)
  {
    return ftp_get($this->ftp, $local, $remote, $mode);
  }
}

class SiteSftpConnection extends SiteFtpBase {
  protected $ssh;
  public function connect()
  {
    $this->ssh2 = ssh2_connect($this->server);
    if ($this->ssh2 === false) {
      throw new UserException('sftp: connection failed (a)');
    }
    if (@ssh2_auth_password($this->ssh2, $this->user, $this->pass) === false) {
      throw new UserException('sftp: authentication failed');
    }
    $this->ftp = @ssh2_sftp($this->ssh2);
    if ($this->ftp === false) {
      throw new UserException('sftp: connection failed (b)');
    }
  }

  public function sftp_path($path)
  {
    return "ssh2.sftp://{$this->ftp}/$path";
  }

  public function ls($path)
  {
    if ($path{0} != '/') {
      $path = '/'.$path;
    }
    $dir = @opendir($this->sftp_path($path));
    $files = array();
    if ($dir !== false) {
      while (($file = readdir($dir)) !== false) {
        $files[] = $file;
      }
    }
    return $files;
  }

  public function size($path)
  {
    return filesize($this->sftp_path($path));
  }

  public function mtime($path)
  {
    return filemtime($this->sftp_path($path));
  }

  public function get($remote, $local)
  {
    $data = file_get_contents($this->sftp_path($remote));
    if ($data === false) {
      return false;
    }
    if (file_put_contents($local, $data) === false) {
      return false;
    }
    return true;
  }
}

?>
