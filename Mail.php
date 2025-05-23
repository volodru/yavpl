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
{//should be initialized once per project. probable in defines.php
	define('CHARSET_FOR_EMAILS', 'utf-8');
}

#[\AllowDynamicProperties]
class Mail
{
	protected array $to = [];
	protected array $cc = [];
	protected array $bcc = [];
	protected string $subj;
	protected string $content;
	protected string $content_type = 'text/plain';// or text/html
	protected string $robot_email = ADMIN_EMAIL;
	protected string $from_email;
	protected string $from_name;
	protected string $organization; //you should do define('ORGANIZATION_FIELD_FOR_EMAILS', 'you organization')
	protected array $attachments = [];
	protected string $charset = CHARSET_FOR_EMAILS;
	protected string $uid;

	protected string $letter_body;//сформированное письмо.

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

	public bool $force_sending_even_on_non_production = false;//по-умолчанию почта расслыется только с продакшна.

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
		if ($point_pos == 0)
		{
			die("MAIL: Cannot recognize file type in [{$file_name}].");
		}
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

	protected function convertFieldToLine(array $data, bool $encode_cyrillic = true): string
	{
		$result = [];
		foreach ($data as $a)
		{
			if ($encode_cyrillic)
			{
				$result[] = ($a[1] != '') ? '"'.$this->encodeCyr($a[1]).'" <'.$a[0].'>' : $a[0];
			}
			else
			{
				$result[] = ($a[1] != '') ? '"'.$a[1].'" <'.$a[0].'>' : $a[0];
			}
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

		//$x_user_ip = isset($_SERVER['REMOTE_ADDR']) ? "\nX-user_IP: {$_SERVER['REMOTE_ADDR']}" : '';
		if (!empty($_SERVER['REMOTE_ADDR']))
		{
			$x_user_ip = "\nX-user_IP: {$_SERVER['REMOTE_ADDR']}";
		}
		else
		{
			$x_user_ip = '';
		}

		$this->letter_body = "MIME-Version: 1.0
Content-Language: ru
Organization: {$this->organization}{$x_user_ip}
From: {$from_line}
Reply-To: {$from_line}
Errors-To: {$errors_to}
To: {$to_line}
Subject: {$subj}";

		if (count($this->cc) > 0)
		{
			$this->letter_body .= "\nCc: ".$this->convertFieldToLine($this->cc);
		}

		if (count($this->bcc) > 0)
		{
			$this->letter_body .= "\nBcc: ".$this->convertFieldToLine($this->bcc);
		}

		if (count($this->attachments) == 0)
		{
			$this->letter_body .= "
Content-type: {$this->content_type}; charset={$this->charset}
Content-Transfer-Encoding: 8bit


".$this->content;
		}
		else
		{
			$this->letter_body .= "
Content-Type: multipart/mixed; boundary={$this->uid}; charset={$this->charset}

--{$this->uid}
Content-Type: {$this->content_type}
Content-Transfer-Encoding: 8bit


".$this->content;

			foreach ($this->attachments as $attachment)
			{
				$this->letter_body .= "
--{$this->uid}
Content-Type: {$attachment['type']}; name=\"{$attachment['file_name']}\"; charset={$this->charset}
Content-Transfer-Encoding: base64
Content-Disposition: attachment; filename=\"{$attachment['file_name']}\"
Content-ID: <{$attachment['file_name']}>


{$attachment['content']}
";
			}
			$this->letter_body .= "--{$this->uid}--";
		}

		if ((defined('APPLICATION_ENV') && (APPLICATION_ENV == 'production'))//отсылаем либо только на проде
			|| //или программист уверен, что можно рассылать на дев/тест и других серверах. в основном оно надо для тестовой аутентификации.
			$this->force_sending_even_on_non_production
			)
		{
			$command = "/usr/sbin/sendmail -t -i -f {$this->from_email}";
			if ($f = popen($command, 'w'))
			{
				fwrite($f, $this->letter_body);
				pclose($f);
				//$st = pclose($f);
				/*if ($st != 0) die(" (Mail status is: $st)");*/
			}
			else
			{
				die("Write to sendmail failed! Command line was: [{$command}]");
			}
		}
		else
		{
			$server_name = $_SERVER['SERVER_NAME'] ?? 'local';
			$log_file_name = "/tmp/mail_from_{$server_name}_".date('Y-m-d--H-i-s').'_'.rand(1,10000).".eml";
			if ($f = fopen($log_file_name, 'w'))
			{
				fwrite($f, $this->letter_body);
				fclose($f);
			}
			else
			{
				die("Server does not seem production and file [{$log_file_name}] cannot be created.");
			}
		}
	}
}