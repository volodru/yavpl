<?php
/**
 * @NAME: Mail
 * @DESC: Mail
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.06
 * @DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.05
 * добавлен метод getContent - т.к. иногда надо что-то сделать с уже набранным контентом
 *
 * 1.04
 * CC, BCC, TO - теперь массивы, каждый ->setXX(XX, YY) создает новый массив
 * а ->addXX(XX, YY) добавляет новый элемент в существующий
 * почти все поля класса убраны в приват, доступ только через методы
 * дефолтный администратор берется из настроек апача $_SERVER['SERVER_ADMIN']
 *
 * 1.03
 * в список разрешенных аттачментов добавлено расширение для *.txt файлов
 *
 * 1.02
 * в список разрешенных аттачментов добавлено расширение для *.cer файлов
 *
 * 1.01
 * добавлен заголовок с версией, описанием и проч. к этому файлу
 * добавлено поле public $charset, метод public setCharset($charset)
 */

if (!defined('ADMIN_EMAIL'))
{
	define('ADMIN_EMAIL', $_SERVER['SERVER_ADMIN']);
	//obsolete die('ADMIN_EMAIL is undefined. Set ADMIN_EMAIL to something real before loading class Mail');
}

if (!defined('REMOTE_ADDR'))
{
	define('REMOTE_ADDR', ( isset($_SERVER['HTTP_X_FOREIGN_IP']) ) ? $_SERVER['HTTP_X_FOREIGN_IP'] : $_SERVER['REMOTE_ADDR']);
}

if (!defined('CHARSET_FOR_EMAILS'))
{//should be initialized once per project. probable in main.php
	//define('CHARSET_FOR_EMAILS', 'windows-1251');//utf-8
	define('CHARSET_FOR_EMAILS', 'utf-8');//windows-1251
}

class Mail
{
	private $to = [];
	private $cc = [];
	private $bcc = [];
	private $subj;
	private $content;
	private $content_type = 'text/plain';// or text/html
	private $robot_email = ADMIN_EMAIL;
	private $from_email;
	private $from_name;
	private $organization; //you should define('ORGANIZATION_FIELD_FOR_EMAILS', 'you organization')
	private $attachments = [];
	private $charset = CHARSET_FOR_EMAILS;

	private $__typeByExt = [
		'doc'	=> 'application/msword',
		'docx'	=> 'application/msword',
		'xls'	=> 'application/vnd.ms-excel',
		'xlsx'	=> 'application/vnd.ms-excel',
		'rtf'	=> 'application/msword',
		'ppt'	=> 'application/mspowerpoint',
		'odt'	=> 'application/vnd.oasis.opendocument.text',
		'pptx'	=> 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'gif'	=> 'image/gif',
		'jpg'	=> 'image/jpeg',
		'jpeg'	=> 'image/jpeg',
		'png'	=> 'image/png',
		'swf'	=> 'application/x-shockwave-flash',
		'pdf'	=> 'application/pdf',
		'zip'	=> 'application/zip',
		'rar'	=> 'application/rar',
		'7z'	=> 'application/x-7z-compressed',
		'txt'	=> 'text/plain',
		'htm'	=> 'text/html',
		'html'	=> 'text/html',
		'csv'	=> 'text/csv',
		'psd'	=> 'application/octet-stream',//ХЗ
		'cer'	=> 'application/octet-stream',// public keys
	];

	function __construct($to_email = '', $subj = '', $content = '', $from_email = '', $from_name = '')
	{
		if ($to_email != '')
		{
			$this->setTo($to_email, '');
		}
		$this->setFrom(
			($from_email != '') ? $from_email : ADMIN_EMAIL,
			($from_name != '') ? $from_name : 'Administrator');
		$this->setSubj($subj);
		$this->setContent($content);
        $this->uid = strtoupper(md5(uniqid(time())));
        $this->setOrganization((defined('ORGANIZATION_FIELD_FOR_EMAILS')) ? ORGANIZATION_FIELD_FOR_EMAILS : $_SERVER['SERVER_NAME']);
        $this->setCharset(CHARSET_FOR_EMAILS);
	}

	public function setCharset($charset)
	{
		$this->charset = $charset;
		return $this;
	}

	public function getTypeByExt($extension)
	{
		if (!isset($this->__typeByExt[$extension]))
		{
			die("MAIL: Unsupported file extension! [$extension]");
		}
		return $this->__typeByExt[$extension];
	}

	public function getSupportedTypes()
	{
		return array_values($this->__typeByExt);
	}

	public function setContentType($type)
	{
		if (($type == 'text/html') || ($type == 'text/plain'))
		{
			$this->content_type = $type;
		}
		return $this;
	}

	public function setFrom($email, $name = '')
	{
		$this->from_email = $email;
		$this->from_name = $name;
		return $this;
	}

	public function setTo($email, $name = '')
	{
		$this->to = [[$email, $name]];
		return $this;
	}

	public function addTo($email, $name = '')
	{
		$this->to[] = [$email, $name];
		return $this;
	}

	public function setCC($email, $name = '')
	{
		$this->cc = [[$email, $name]];
		return $this;
	}

	public function addCC($email, $name = '')
	{
		$this->cc[] = [$email, $name];
		return $this;
	}

	public function setBCC($email, $name = '')
	{
		$this->bcc = [[$email, $name]];
		return $this;
	}

	public function addBCC($email, $name = '')
	{
		$this->bcc[] = [$email, $name];
		return $this;
	}

	public function setSubj($subj)
	{
		$this->subj = $subj;
		return $this;
	}

	public function setContent($content)
	{
		$this->content = $content;
		return $this;
	}

	public function getContent()
	{
		return $this->content;
	}

	public function setOrganization($organization)
	{
		$this->organization = $organization;
		return $this;
	}

	public function attach($file_path, $file_name)
	{
		$content = fread(fopen($file_path, 'r'), filesize($file_path));
        $content = chunk_split(base64_encode($content));

        $file_name = strtolower($file_name);
        $point_pos = strrpos($file_name, '.');
		$this->attachments[] = [
			'file_name'	=> basename($file_name),
			'type'		=> $this->getTypeByExt(strtolower(substr($file_name, $point_pos + 1, strlen($file_name) - $point_pos))),
			'content'	=> $content,
		];
		return $this;
	}

	public function encodeCyr($str)
	{
		$arr = preg_split('//', $str, -1, PREG_SPLIT_NO_EMPTY);
		$str = '=?'.$this->charset.'?Q?';//koi8-r
		foreach ($arr as $chr)
		{
			$str .= '='.strtoupper(bin2hex($chr));
		}
		$str .= '?=';
        return $str;
	}

	private function convertFieldToLine($data)
	{
		$result = [];
		foreach ($data as $a)
		{
			$result[] = ($a[1] != '') ? '"'.$this->encodeCyr($a[1])."\" <{$a[0]}>" : $a[0];
		}
		return join(';', $result);
	}

	public function send()
	{
		$content = $this->content;

		if ($this->from_email == '')
		{
			$this->from_email = ADMIN_EMAIL;//$_SERVER['SERVER_NAME'];
		}

		//$from_line = ($this->from_name != '') ? '"'.$this->encodeCyr($this->from_name)."\" <{$this->from_email}>" : $this->from_email;
		$from_line = $this->convertFieldToLine([[$this->from_email, $this->from_name]]);

		$to_line = $this->convertFieldToLine($this->to);

		if (count($this->to) == 0)
		{
			$to_line = ADMIN_EMAIL;
			$this->content = "Field 'TO:' is undefined!\n\n".$this->content;
			$this->subj = "!_TO_ field is undefined! ".$this->subj;
		}

		$subj = $this->encodeCyr($this->subj);

		$errors_to = ADMIN_EMAIL;

		$letter = "MIME-Version: 1.0
Content-Language: ru
Organization: {$this->organization}
X-user_IP: {$_SERVER['REMOTE_ADDR']}
From: {$from_line}
Reply-To: {$from_line}
Errors-To: {$errors_to}
To: {$to_line}
Subject: {$subj}";

		if (count($this->cc) > 0)
		{
			$letter .= "\nCc: ".$this->convertFieldToLine($this->cc);
		}

		if (count($this->bcc) > 0)
		{
			$letter .= "\nBcc: ".$this->convertFieldToLine($this->bcc);
		}

		if (count($this->attachments) == 0)
		{
			$letter .= "
Content-type: {$this->content_type}; charset={$this->charset}
Content-Transfer-Encoding: 8bit


".$this->content;
		}
		else
		{
			$letter .= "
Content-Type: multipart/mixed; boundary={$this->uid}; charset={$this->charset}

--{$this->uid}
Content-Type: {$this->content_type}
Content-Transfer-Encoding: 8bit


".$this->content;

			foreach ($this->attachments as $attachment)
			{
				$letter .= "
--{$this->uid}
Content-Type: {$attachment['type']}; name=\"{$attachment['file_name']}\"; charset={$this->charset}
Content-Transfer-Encoding: base64
Content-Disposition: attachment; filename=\"{$attachment['file_name']}\"
Content-ID: <{$attachment['file_name']}>


{$attachment['content']}
";
			}
			$letter .= "--{$this->uid}--";
		}

		if (defined('APPLICATION_ENV') && (APPLICATION_ENV == 'production'))
		{
			if ($f = popen("/usr/sbin/sendmail -t -i -f {$this->from_email}", 'w'))
			{
				fwrite($f, $letter);
				$st = pclose($f);
				/*if ($st != 0)
				 	die(" (Mail status is: $st)");*/
			}
			else
			{
				die("Write to sendmail failed!");
			}
		}
		else
		{
			//obsolete $log_file_name = "/tmp/www_mail_sent_from_{$_SERVER['SERVER_NAME']}.log";
			$log_file_name = "/tmp/mail_from_{$_SERVER['SERVER_NAME']}_".date('Y-m-d--H-i-s').'_'.rand(1,10000).".eml";
			//obsolete if ($f = fopen($log_file_name, 'a+'))
			if ($f = fopen($log_file_name, 'w'))
			{
				//obsolete fwrite($f, "\n\n__________".date("d.m.Y H:i:s")."_____________________\n\n");
				fwrite($f, $letter);
				fclose($f);
			}
			else
			{
				die("Server does not seem production and file [$log_file_name] cannot be created.");
			}
		}
	}
}