<?php
require_once('Mail.php');
require_once('Mail/mime.php');

class EmailComponent extends SiteComponent {
  static protected $mail;

  public function init()
  {
    $this->defaultConf(array(
      'method' => 'mail',
      'server' => 'localhost',
      'port'   => '25',
    ));

    $mail_params = array();
    switch ($this->getConf('method')) {
      case 'smtp':
        $mail_params = array(
          'host' => $this->getConf('server'),
          'port' => $this->getConf('port'),
        );

        if ($this->getConf('username')) {
          $mail_params = array_merge($mail_params, array(
            'auth' => true,
            'username' => $this->getConf('username'),
            'password' => $this->getConf('password'),
          ));
        }
        break;
    }

    $this->mail =& Mail::factory($this->getConf('method'), $mail_params);

    if (PEAR::isError($this->mail)) {
      throw new Exception('Could not initialize Mail (' . $this->mail->getMessage() . ' - ' . $this->mail->getUserinfo() . ')');
    }
  }

  public function send($to, $subject, $body, $type = null, $headers = null, $attachments = null)
  {
    if (is_null($type))    { $type    = 'text';  }
    if (is_null($headers)) { $headers = array(); }

    $to = str_replace(';', ',', $to);

    if (!isset($headers['From'])) {
      $headers['From'] = $this->getConf('default_from');
    }

    if (!isset($headers['To'])) {
      $headers['To'] = $to;
    }

    $headers['Subject'] = $subject;

    $required_headers = array('From', 'Subject');
    foreach ($required_headers as $field) {
      if (!@$headers[$field]) {
        throw new Exception("Must have a '$field' header.");
      }
    }

    // start
    $mime = new Mail_mime("\n");
    switch ($type) {
      case 'text':
        $mime->setTXTBody($body);
        break;

      case 'html':
        $mime->setHTMLBody($body);
        break;
    }

    if (is_array($attachments)) {
      $defaults = array(
        'type'     => 'application/octet-stream',
        'name'     => '',
        'isfile'   => true,
        'encoding' => 'base64',
      );
      foreach ($attachments as $attachment) {
        if (!isset($attachment['file'])) {
          throw new Exception("Attachment missing 'file' field.");
        }
        $a = array_merge($defaults, $attachment);

        $res = $mime->addAttachment(
          $a['file'], $a['type'], $a['name'], $a['isfile'], $a['encoding']
        );
      }
    }

    // order is important
    $b = $mime->get();
    $h = $mime->headers($headers);

    $res = $this->mail->send($to, $h, $b);

    if (PEAR::isError($res)) {
      throw new Exception('Could not send email (' . $res->getMessage() . ' - ' . $res->getUserinfo() . ')');
    }
  }
}
?>
