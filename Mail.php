<?php
declare(strict_types=1);
namespace YAVPL;
/**
 * @NAME: Mail
 * @DESC: Mail
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 * @DATE: 2023-05-30
 * добавлены type-hints
 *
 * 1.07
 * @DATE: 2018-12-11
 * добавлено понятие "адрес техподдержки" - TECH_SUPPORT_EMAIL
 * по умолчанию - оно совпадает с ADMIN_EMAIL
 * в проектах туда стоит отправлять все технические письма (ошибки и т.п.)
 * админский емэйл - от кого по дефолту посылаются письма - желательно с адреса проекта (...@adrussia.ru, volod@volod.ru, tech@paraslovar.ru)
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
	define('ADMIN_EMAIL', $_SERVER['SERVER_ADMIN'] ?? '');
}

if (!defined('TECH_SUPPORT_EMAIL'))
{
	define('TECH_SUPPORT_EMAIL', ADMIN_EMAIL);
}

if (!defined('REMOTE_ADDR'))
{
	define('REMOTE_ADDR', ( isset($_SERVER['HTTP_X_FOREIGN_IP']) ) ? $_SERVER['HTTP_X_FOREIGN_IP'] : $_SERVER['REMOTE_ADDR']);
}

if (!defined('CHARSET_FOR_EMAILS'))
{//should be initialized once per project. probable in main.php
	define('CHARSET_FOR_EMAILS', 'utf-8');
}

#[\AllowDynamicProperties]
class Mail
{
	private array $to = [];
	private array $cc = [];
	private array $bcc = [];
	private string $subj;
	private string $content;
	private string $content_type = 'text/plain';// or text/html
	private string $robot_email = ADMIN_EMAIL;
	private string $from_email;
	private string $from_name;
	private string $organization; //you should do define('ORGANIZATION_FIELD_FOR_EMAILS', 'you organization')
	private array $attachments = [];
	private string $charset = CHARSET_FOR_EMAILS;
	private string $uid;

	private array $__typeByExt = [
		'doc'	=> 'application/msword',
		'docx'	=> 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'	=> 'application/vnd.ms-excel',
		'xlsx'	=> 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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

	public function __construct(string $to_email = '', string $subj = '', string $content = '', string $from_email = '', string $from_name = '')
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
		$this->uid = strtoupper(md5(uniqid('time'.time())));//uniqid требует строку
		$this->setOrganization((defined('ORGANIZATION_FIELD_FOR_EMAILS')) ? ORGANIZATION_FIELD_FOR_EMAILS : $_SERVER['SERVER_NAME']);
		$this->setCharset(CHARSET_FOR_EMAILS);
	}

	public function setCharset(string $charset): Mail
	{
		$this->charset = $charset;
		return $this;
	}

	public function getTypeByExt(string $extension): string
	{
		if (!isset($this->__typeByExt[$extension]))
		{
			die("MAIL: Unsupported file extension! [$extension]");
		}
		return $this->__typeByExt[$extension];
	}

	public function getSupportedTypes(): array
	{
		return array_values($this->__typeByExt);
	}

	public function setContentType(string $type): Mail
	{
		if (($type == 'text/html') || ($type == 'text/plain'))
		{
			$this->content_type = $type;
		}
		return $this;
	}

	public function setFrom(string $email, string $name = ''): Mail
	{
		$this->from_email = $email;
		$this->from_name = $name;
		return $this;
	}

	public function setTo(string $email, string $name = ''): Mail
	{
		$this->to = [[$email, $name]];
		return $this;
	}

	public function addTo(string $email, string $name = ''): Mail
	{
		$this->to[] = [$email, $name];
		return $this;
	}

	public function setCC(string $email, string $name = ''): Mail
	{
		$this->cc = [[$email, $name]];
		return $this;
	}

	public function addCC(string $email, string $name = ''): Mail
	{
		$this->cc[] = [$email, $name];
		return $this;
	}

	public function setBCC(string $email, string $name = ''): Mail
	{
		$this->bcc = [[$email, $name]];
		return $this;
	}

	public function addBCC(string $email, string $name = ''): Mail
	{
		$this->bcc[] = [$email, $name];
		return $this;
	}

	public function setSubj(string $subj): Mail
	{
		$this->subj = $subj;
		return $this;
	}

	public function setContent(string $content): Mail
	{
		$this->content = $content;
		return $this;
	}

	public function getContent(): string
	{
		return $this->content;
	}

	public function setOrganization(string $organization): Mail
	{
		$this->organization = $organization;
		return $this;
	}

	public function attachData($content, string $file_name): Mail
	{
		$content = chunk_split(base64_encode($content));
		$point_pos = strrpos($file_name, '.');
		$this->attachments[] = [
			'file_name'	=> basename($file_name),
			'type'		=> $this->getTypeByExt(strtolower(substr(strtolower($file_name), $point_pos + 1, strlen($file_name) - $point_pos))),
			'content'	=> $content,
		];
		return $this;
	}

	public function attachFile(string $file_path, string $file_name): Mail
	{
		$content = fread(fopen($file_path, 'r'), filesize($file_path));
		return $this->attachData($content, $file_name);
	}

	public function encodeCyr(string $str): string
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

	private function convertFieldToLine(array $data): string
	{
		$result = [];
		foreach ($data as $a)
		{
			$result[] = ($a[1] != '') ? '"'.$this->encodeCyr($a[1])."\" <{$a[0]}>" : $a[0];
		}
		return join(';', $result);
	}

	public function send(): void
	{
		if ($this->from_email == '')
		{
			$this->from_email = TECH_SUPPORT_EMAIL;
		}

		$from_line = $this->convertFieldToLine([[$this->from_email, $this->from_name]]);
		$to_line = $this->convertFieldToLine($this->to);

		if (count($this->to) == 0)
		{
			$to_line = TECH_SUPPORT_EMAIL;
			$this->content = "Field 'TO:' is undefined!\n\n".$this->content;
			$this->subj = "!_TO_ field is undefined! ".$this->subj;
		}

		$subj = $this->encodeCyr($this->subj);

		$errors_to = TECH_SUPPORT_EMAIL;

		$x_user_ip = isset($_SERVER['REMOTE_ADDR']) ? "\nX-user_IP: {$_SERVER['REMOTE_ADDR']}" : '';

		$letter = "MIME-Version: 1.0
Content-Language: ru
Organization: {$this->organization}{$x_user_ip}
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
				pclose($f);
				sleep(1);//EXPERIMENTAL - попытка предотвратить блокировку 25 порта в МСК
				//$st = pclose($f);
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
			$server_name = $_SERVER['SERVER_NAME'] ?? 'local';
			$log_file_name = "/tmp/mail_from_{$server_name}_".date('Y-m-d--H-i-s').'_'.rand(1,10000).".eml";
			if ($f = fopen($log_file_name, 'w'))
			{
				fwrite($f, $letter);
				fclose($f);
			}
			else
			{
				die("Server does not seem production and file [{$log_file_name}] cannot be created.");
			}
		}
	}
}