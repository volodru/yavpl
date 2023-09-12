<?php
declare(strict_types=1);
/**
 * @NAME: Debug function
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2023- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 * DATE: 2023-04-28
 * Вынесены в глобальное пространство имен


/** Мега универсальный отладчик. Название - сокращение от DumpArray
 */
function da($v)
{
	require_once('sage.phar');
	if (APPLICATION_RUNNING_MODE == 'cli')
	{
		print var_export($v, true)."\n";
	}
	else
	{
		print "<xmp>".var_export($v, true)."</xmp>";
		//sage($v);
	}
}

/** DumpArray in temp File - специально для отладки кукиев и сессий
 */
function daf($v)
{
	if (APPLICATION_ENV != 'production')
	{
		$l = fopen('/tmp/'.($_SERVER['SERVER_NAME']??'SERVER').'__'.date('Y_m_d__H_i_s').'.log', 'a+');
		fwrite($l, var_export($v, true)."\n");
		//fwrite($l, $v); //FOR DEBUG BINARIES
		fclose($l);
	}
}

/** Обертка над print_backtrace - возвращает трейс в красивом виде через print_r
 */
function __getBacktrace()
{
	return print_r(debug_backtrace(0, 5), true);
}

/** Обертка для вывода только на девелопе/тесте, короче, кроме продакшн
 */
function __printBacktrace()
{
	if (defined('APPLICATION_ENV') && (APPLICATION_ENV != 'production'))
	{
		da(__getBacktrace());
	}
}

/** Мегаотладчик по почте - в случае проблем высылаем ошибку по email
 */
function sendBugReport($subject = 'Bug report', $message = 'Common bug', $is_fatal = false)
{
	if (APPLICATION_RUNNING_MODE != 'cli')
	{
		if (!isset($_SESSION)){session_start();}
	}

	$a = [];
	exec('hostname', $a);
	$server_name = $_SERVER['SERVER_NAME'] ?? trim(join('', $a));//именно имя сервера - чтобы отличать проекты друг от друга. для CLI уже пофигу

	if (APPLICATION_ENV != 'production')
	{//на девелопе убивать баги на месте, а с продакшена пусть придет письмо
		print "
<h1>BUG REPORT from [{$server_name}]</h1>
<h2>{$subject}</h2>
<h2>{$message}</h2>
<div>TRACE:<xmp>".__getBacktrace()."</xmp></div>";
		exit();
	}

	(new \YAVPL\Mail(ADMIN_EMAIL, "[{$server_name}] {$subject}", "{$message}
".($_SERVER['SCRIPT_URI']??'').'?'.($_SERVER['QUERY_STRING'] ?? '')."
____________________________________________________
TRACE\n".__getBacktrace()."
--------------------------
SERVER\n" . print_r($_SERVER ?? [], true) ."
--------------------------
GET\n" . print_r($_GET, true) ."
--------------------------
POST\n" . print_r($_POST, true) ."
--------------------------
COOKIE\n" . print_r($_COOKIE, true) ."
--------------------------
SESSION\n" . print_r($_SESSION ?? [], true)))->send();
	if ($is_fatal)
	{
		print $subject.CRLF.$message;
		die();
	}
}

/** Инструмент для посылки технических уведомлений.
 * Уведомления предполагаются административного характера или бизнес-процессы и т.п.
 * Не для ошибочных ситуаций! Для потециальных ошибок и предупреждений использовать sendBugReport()
 */
function sendNotification($subject = 'Notification', $message = 'Message')
{
	if (!isset($_SESSION)){session_start();}

	$a = [];
	exec('hostname', $a);
	$server_name = $_SERVER['SERVER_NAME'] ?? trim(join('', $a));//именно имя сервера - чтобы отличать проекты друг от друга. для CLI уже пофигу

	(new \YAVPL\Mail(ADMIN_EMAIL, "[{$server_name}] {$subject}", "{$message}
____________________________________________________
SESSION\n" . print_r($_SESSION, true)))->send();
}

