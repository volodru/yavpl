<?php
/**
 * @NAME: ToolBar
 * @DESC: ToolBar
 * @AUTHOR: Vladimir Nikiforov aka Volod (volod@volod.ru)
 * @COPYRIGHT (C) 2009- Vladimir Nikiforov
 * @LICENSE LGPLv3 - http://www.gnu.org/licenses/lgpl-3.0.html
 */

/** CHANGELOG
 *
 * 1.02
 * DATE: 2015-10-30
 * кодировка установлена в UTF8
 *
 * 1.01
 * Код выведен из Controller и View
 */

class ToolBar
{
	private $__elements = [];

	function __construct()
	{
	}

/**
 * сбросить накопленные элементы тулбара. надо если не подходят дефолтные кнопки собранные предками и хочется сделать с нуля.
 */
	public function resetToolbar()
	{
		$this->__elements = [];
		return $this;
	}

/**
 * Получить скрытое поле __elements с содержимым тулбара
 */
	public function getElements()
	{
		return $this->__elements;
	}

/**
 * добавить кнопку на тублар
 * @param $header string заголовок
 * @param $action string ссылка/URL или функция на JS
 * @param $options string разные опции, каждая емеет право быть неопределена и должна иметь значение по умолчанию.
 */
	public function addButton($header, $action, $options = [])
	{
		$this->__elements[] = [
			'type'			=> 'button',
			'header'		=> $header,
			'action'		=> $action,
			'enabled'		=> isset($options['enabled']) ? $options['enabled'] : true,
			'enabled_hint'	=> isset($options['enabled_hint']) ? $options['enabled_hint'] : null,
			'disabled_hint'	=> isset($options['disabled_hint']) ? $options['disabled_hint'] : null,
			'width'			=> isset($options['width']) ? $options['width'] : round(strlen($header) * 0.6).'em',//magic factor!
		];
		return $this;
	}

/**
 * добавить разделитель на тублар
 */
	public function addDivider($options = [])
	{
		$this->__elements[] = [
			'type'	=> 'divider',
			'width'	=> (isset($options['width']) ? $options['width'] : '1em'),
		];
		return $this;
	}

/**
 * Возвращает тулбар с кнопками. Кнопки набираются в контроллере методами addDivider/addButton etc.
 */
	public function render($show_empty = false)
	{
		if ((count($this->__elements) == 0) && (!$show_empty))
		{
		    return '';
		}

		$buf = "
<script type='text/javascript'>
function __stopPropagation(e)
{
	if (!e)	var e = window.event;
	e.cancelBubble = true;
	if (e.stopPropagation) e.stopPropagation();
}
function __runToolbarButton(e, action, type)
{
	__stopPropagation(e);
	if (type == 'url')
	{
		document.location=action;
	}
	else
	{
		eval(action);
	}
}
</script>
<div class='toolbar'><table><tr>";
		foreach ($this->__elements as $element)
		{
			if ($element['type'] == 'button')
			{
				$hint_attr = '';
				$onclick = '';
				if ($element['enabled'])
				{
					$class = 'enabled';
					if (isset($element['enabled_hint']))
					{
						$hint = preg_replace("/\'/", '`', $element['enabled_hint']);
						$hint_attr = "title='$hint'";
					}
					$header = "<a onclick='__stopPropagation(event)' href='{$element['action']}'>{$element['header']}</a>";
					if (preg_match("/^javascript:(.+?)$/", $element['action'], $res))
					{
						$onclick = "onclick=\"__runToolbarButton(event,'{$res[1]}','')\"";
					}
					else
					{
						$onclick = "onclick=\"__runToolbarButton(event,'{$element['action']}','url')\"";
					}
				}
				else
				{
					$class = 'disabled';
					$hint = (isset($element['disabled_hint'])) ? preg_replace("/\'/", '`', $element['disabled_hint']) : 'Disabled';
					$hint_attr = "title='$hint'";
					$header = $element['header'];
					$onclick = "onclick=\"alert('$hint')\"";
				}
				$buf .= "\n<td class='$class' $hint_attr style='width: {$element['width']}'><div $onclick class='button'>$header</div></td>";
			}
			if ($element['type'] == 'divider')
			{
				$buf .= "\n<td style='width: 1px'><div class='divider'></div></td>";
			}
		}
		$buf .= "\n<td style='width: auto'>&nbsp;</td>";
		$buf .= "\n</tr></table></div>\n";
		return $buf;
	}
}