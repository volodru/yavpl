<?php
declare(strict_types=1);
namespace YAVPL;
/**
 * @NAME: DotEnv
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** Концепция .env фйла:
 * Считываем все строки из файла и делаем define всему, что выглядит валидным.
 * Формат строк КЛЮЧ = ЗНАЧЕНИЕ
 * Пробелы вокруг ключа и значения убираются.
 * Пустые строки игнорируются.
 * Начинающаяся с # строка, считается строкой комментария.
 *
 * .env файлы на серверах хранятся в папке с проектом.
 * При деплое после команды git checkout-index, файл .env копируется в папку проекта вместе с исходникам.
*/

class DotEnv
{
/** Полный путь к файлу с параметрами */
	public string $env_file_name;

/** Параметры:
 * env_file_name - путь и имя файла с настройками. Если не передан, то используется APPLICATION_PATH.'/.env'
 * required_constants - массив названий параметров, которые обязательно должны быть определены в .env файле */
	public function __construct(string $env_file_name = '')
	{
		if ($env_file_name == '')
		{
			print "APPLICATION_PATH=".APPLICATION_PATH;
			if (!defined('APPLICATION_PATH'))
			{
				exit("Не определена константа APPLICATION_PATH.");
			}
			else
			{
				$env_file_name = APPLICATION_PATH.'/.env';
			}
		}
		$this->env_file_name = $env_file_name;

		if (file_exists($env_file_name))
		{
			foreach (explode("\n", file_get_contents($env_file_name) ?? '') as $line)
			{
				$line = trim($line);

		        if ($line == '') {
		            continue;
		        }

		        $idx = strpos($line, '=');
				if ($idx === false) {
		            continue;
		        }

		        if ($line[0] == '#') {
		            continue;
		        }

				$k = trim(mb_substr($line, 0, $idx));
				$v = trim(mb_substr($line, $idx + 1));

		        if (defined($k))
		        {
		            exit("Невозможно установить значение [{$k}]=[{$v}], т.к. константа [{$k}] уже определена как [".constant($k)."]");
		        }

		        if (!define($k, $v))
		        {//вот уж непонятно, что такое надо сделать, чтобы это получить.
		            exit("Невозможно установить значение [{$k}]=[{$v}]");//по идее, все проверки надо сделать до этого места
		        }
			}
		}
		else
		{
			exit("Файл с настройками {$env_file_name} не найден или недоступен для вебсервера");
		}
	}

/** Параметры:
 * @array $required_constants массив названий параметров, которые обязательно должны быть определены в .env файле
 */
	public function validate(array $required_constants): void
	{
		/** Список констант, которые должны быть получены до начала какой-либо работы */
		foreach ($required_constants as $expected_to_be_defined)
		{
			if (!defined($expected_to_be_defined))
			{
				exit("В файле [{$this->env_file_name}] ожидается явное определение константы [{$expected_to_be_defined}]");
			}
		}
	}
}
