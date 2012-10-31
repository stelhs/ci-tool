<?php
/**
*	Шаблонизатор StrontiumTPL. 
*	Применяется для разделения программного кода и верстки.
*	Так как изначально шаблонизатор разрабатывался на работу только в режими интерпретации, 
*	а режим компиляции появился позже, то часть методов работает только в режиме интерпретации.
*	В режиме компиляции пока могут работать только те методы которые сильно отнимают производительность шаблонизатора в режиме интерпретации.
*
*	Класс tpl для работы с шаблонами
*	Методы :
*		open($filename, $default_marks = array(), $compile_enable = 0) - открыть файл шаблона (имя файла, метки по умолчанию, разрешить компиляцию или нет)
*		assign($block_name, $data) - Заполнить метки данными $data в блоке $block_name открытого шаблона
*		make_result() - Возвращает заполненный данными шаблон
*
*		open_buffer($buffer) - Загрузить шаблон с буффера, а также возвращает список блоков и их содержимое. Работает только в режиме интерпретации
*		load_block($block_name) - Получить соджержимое блока. Работает только в режиме интерпретации
*		set_default_marks($data) // Установить метки по умолчанию. Они будут автоматически добавленны во все блоки
*
*	By Michail Kurochkin 18.08.2008 (stelhs@ya.ru)
*   ver 2.11
*/

	// Режимы работы шаблонизатора
	define("TPL_INTERPRETER_MODE", 0); // Режим интерпретации
	define("TPL_COMPILER_MODE", 1); // Режим компиляции

	class tpl
	{
		var $source_content; // Содержимое исходного шаблона
		var $result_content; // Содержимое заполненного шаблона (используется для режима интерпретации)
		var $default_marks_val; // Список меток и их значений которые передаются поумолчанию в любой вызов assign()
		var $enable_compile; // Флаг режим компиляции разрешен или нет (1 или 0)
		var $assign_stack; // Стек. В режиме компиляции для выполнения метода assign необходим стек
		var $compiled_struct_tree; // В режиме компиляции этот массив заполняется данными из компилированного шаблона. Содержит в себе древовидный ассоциативный массив взаимосвязи блоков с подблоками
		var $assign_tree; // Дерево данных для вывода в компилированный шаблон. В режиме компиляции вызов метода assign формирует и дополняет этот деревовидный массив
		var $debug; // Если данный параметр не равен '' это означает что шаблон надо перекомпилировать даже если он скомпилирован ранее
        
        var $enable_comments; // Разрешены в шаблонах коменты или нет. 1 - да, 0 - нет
		
		/**
			Внутренняя функция предназначена для поиска статических меток и их значений для их дальнейшей подгрузки в подгружаемый шаблон
			Статические метки имею формат <mark></mark> где mark - имя метки
			Такие метки могут встречаться только в области между START INSERT и END INSERT
			@param $buffer - Текст шаблона
			@return массив в формате (метка => значение)
		*/
		private function find_static_marks_values($buffer) 
		{
			$marks_info = array(); 
			$buffer = preg_replace("/<!--[ ]*START[ ]+ASSIGN[ ]*:.*-->.*<!--[ ]*END[ ]+ASSIGN[ ]*:.*-->/Us", '', $buffer); // Удаляем все ассайны на блоки 
			preg_match_all("/<([a-zA-Z0-9_-]+)>/Us", $buffer, $extract); // ищем все метки
			$marks = $extract[1];
			foreach($marks as $mark) // Получаем значение каждой метки
			{
				preg_match("/<" . $mark .">(.*)<\/" . $mark .">/Us", $buffer, $extract);
				$marks_info[$mark] = trim($extract[1]); // Заполняем отчет в формате имя_метки => её_значение
			}
			
			return $marks_info; 
		}
		
		
		/**
			Внутренняя функция. Ищет блоки указанного в $block_type типа
			@param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
			@param $buffer - Текст шаблона
			@return массив в формате (имя_блока -> содержимое_блока)
		*/
		private function find_blocks($block_type, $buffer)
		{
			$blocks_data = array();
			$found_blocks = 1;
			while($found_blocks)
			{
				preg_match("/<!-- *START +" . $block_type . " *: *([a-zA-Z0-9_\.\/]*) *-->/s", $buffer, $found_blocks); 
                
                // Если блок не найден
                if (!isset($found_blocks[1]))
                    continue;
                    
				$block_name = $found_blocks[1];
                
                $rc = preg_match("/<!-- *START +" . $block_type . " *: *" . $this -> shielding_block_name($block_name) . " *-->(.*)<!-- *END +" . $block_type . " *: *" . $this -> shielding_block_name($block_name) . " *-->/Us", $buffer, $matches); // Извлекаем данные ассайн блока
                if(!$rc)
                {
                    echo 'Not found end block: <&iexcl;-- END ' . $block_type . ' : ' . $block_name . ' -->';
                    exit;
                }
                
                $block_data = $matches[1]; 
                $buffer = $this -> replace_block($block_type, $buffer, $block_name, ''); // Удаляем этот блок из буфера, чтобы не натыкаться на него повторно
                $blocks_data[][$block_name] = $block_data; // Добавляем в отчет найденный блок и его содержимое
			}
			
			return $blocks_data;
		}
		
		
		/**
			Внутренняя функция. Экранирует служебные символы которые могут встретиться в имени блока.
			Символы перечисленны в массиве $chars
			@param $str - имя блока
			@return экранированное имя блока
		*/
		private function shielding_block_name($str)
		{
			$chars = array('.', '/', ',');
			foreach($chars as $char)
				$str = str_replace($char, "\\" . $char, $str);
			
			return $str;
		}
		
		
		/**
			Внутренняя функция. Ищет блок указанного в $block_type типа с именем $block_name
			@param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
			@param $block_name - Имя блока
			@param $buffer - Текст шаблона
			@return содержимое найденного блока
		*/
		private function find_block($block_type, $block_name, $buffer)
		{
			preg_match("/<!--[ ]*START[ ]+" . $block_type . "[ ]*:[ ]*" . $block_name . "[ ]*-->(.*)<!--[ ]*END[ ]+" . $block_type . "[ ]*:[ ]*" . $block_name . "[ ]*-->/Us", $buffer, $matches);
			return $matches[1];
		}
		
		
		/**
			Внутренняя функция. Поиск и замена сожержимого указанного блока на строку $replace
			@param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
			@param $block_name - Имя блока
			@param $buffer - Текст шаблона
			@param $replace - Замена сожержимого указанного блока на строку $replace
		*/
		private function replace_block($block_type, $buffer, $block_name, $replace)
		{
			$block_name = $this -> shielding_block_name($block_name);
			return preg_replace("/<!--[ ]*START[ ]+" . $block_type . "[ ]*:[ ]*" . $block_name . "[ ]*-->(.*)<!--[ ]*END[ ]+" . $block_type . "[ ]*:[ ]*" . $block_name . "[ ]*-->/Us", $replace, $buffer, 1);
		}
		
		
		/**
			Внутренняя функция. Получить древовидный массив всех ASSIGN блоков с метками
			@param $block_type - Тип блока (возможные типы: BLOCK, INSERT, ASSIGN)
			@param $block_name - Имя блока
			@param $buffer - Текст шаблона
			@param $replace - Замена сожержимого указанного блока на строку $replace
			@return Возвращаем дерево ASSIGN блоков с метками
		*/
		private function get_preassign_data($preassign_content)
		{
			$preassigned_data = array();
			$preassigned_data['marks'] = $this -> find_static_marks_values($preassign_content); // Получаем список меток
			$assign_blocks = $this -> find_blocks('ASSIGN', $preassign_content); // Ищем ASSIGN блоки
			
			if($assign_blocks) // Если ASSIGN блоки найденны
				foreach($assign_blocks as $assign_block) // перебираем все ASSIGN блоки найденны
				{
					foreach($assign_block as $block_name => $block_data); // Получаем имя ASSIGN блока и его содержимое
					$preassigned_data['block'][][$block_name] = $this -> get_preassign_data($block_data); // Вызываемся рекурсивно для поиска меток и блоков в найденном блоке
				}
				
			return $preassigned_data; // Возвращаем дерево ASSIGN блоков с метками
		}
		
		
		/**
			Получить путь к файлу из имени файла
			@param $filename - Полный путь к файлу с именем файла
			@return Путь к файлу
		*/
		private function get_file_path($filename)
		{
			if(strchr($filename, '/')) // Если в имени файла встречается хотябы один символ '/'
				$result = substr($filename, 0, strrpos($filename, '/') + 1); // получаем путь без имени файла
			else
				$result = '';
				
			return $result;
		}


		/**
			Получить имя файла из полного пути к файлу
			@param $full_name - Полный путь к файлу с именем файла
			@return Путь к файлу
		*/
		private function get_file_name($full_name)
		{
			if(strchr($full_name, '/')) // Если в имени файла встречается хотябы один символ '/'
				$result = substr($full_name, strrpos($full_name, '/') + 1, strlen($full_name)); // имя файла без пути
			else
				$result = $full_name;
				
			return $result;
		}
			
		
		/**
			Рекурсивная функция разворачивает все INSERT блоки, возвращает развернутый шаблон
			@param $tpl_content - Текст шаблона
			@param $tpl_path - Путь к шаблону
			@param $parent_file - Этот параметр внутренний используется для рекурсивного обхода INSERT блоков. Путь к родительскому файлу внутри которого разворачивается текущий INSERT
			@return возвращает модифицированный шаблон
		*/
		private function do_insert_blocks($tpl_content, $tpl_path, $parent_file = '')
		{
			$insert_blocks = $this -> find_blocks('INSERT', $tpl_content); // Получаем список INSERT блоков
			if($insert_blocks)
			{
				foreach($insert_blocks as $insert_block) // перебираем все INSERT блоки
				{
					foreach($insert_block as $tpl_file_name => $preassign_content); // Поскольку внутри одной $insert_block записи может быть только один блок, то раскладываем его на имя_блока => содержимое_блока
					
					if(!file_exists($tpl_path . $tpl_file_name))
					{
						echo "Can not find teamplate file '" . $tpl_path . $tpl_file_name ."' in parent teamplate file: '" . $parent_file . "'\n";
						exit;
					}
					
					$insert_content = file_get_contents($tpl_path . $tpl_file_name); // Загружаем шаблон указанный в INSERT блоке
                    if ($this->enable_comments)
                        $insert_content = $this -> strip_comments($insert_content); // чистим от коментов вида /* */ и //
					$preassign_data = $this -> get_preassign_data($preassign_content); // Получаем древовидный массив всех блоков с метками для ассайна их в заргужаемый шаблон
					// асайним древовидный массив статический меток
					$preassign_tpl = new tpl;
					$preassign_tpl -> open_buffer($insert_content);
					$preassign_tpl -> assign(0, $preassign_data['marks']);
					
					$preassign_tpl -> assign_array($preassign_data); // Ассайним дерево блоков с меткаими в заргужаемый шаблон
					$preassigned_content = $preassign_tpl -> result_content; // Получаем результат

					preg_match_all("/<<-(.*)->>/Us", $preassigned_content, $extract); // ищем все позывные метки блоков и меняем их на реальные блоки
					$list_blocks = $extract[1];
					if($list_blocks)
						foreach($list_blocks as $block_name) // перебираем все позывные блоков и вместо них вставляем реальные блоки
						{
							$block_src = $this -> find_block('BLOCK', $block_name, $insert_content);
							$preassigned_content = str_replace("<<-" . $block_name . "->>", '<!-- START BLOCK : ' . $block_name . ' -->' . $block_src . '<!-- END BLOCK : ' . $block_name . ' -->', $preassigned_content);
						}
						
					// Поскольку вместо INSERT блока мы вставляем содержимое этого блока, то если в содержимом тоже встретятся INSERT блок, то к нему надо добавить текущие пути
					$insert_path_prefix = $this -> get_file_path($tpl_file_name); // получаем путь к файлу вставляемого шаблона
					
					$preassigned_content = $this -> do_insert_blocks($preassigned_content, $tpl_path . $insert_path_prefix, $tpl_file_name); //Запускаем себя рекурсивно для поиска новых INSERT блоков в загруженном шаблоне
					$tpl_content = $this -> replace_block('INSERT', $tpl_content, $tpl_file_name, $preassigned_content); // Заменили INSERT блок заполненными данными
				}
			}
			return $tpl_content;
		}
		
		
		/**
			Функция удаляет комментарии вида // или / *   * / из текста
			@param $text - исходный текст
			@return текст без комментариев
		*/
		private function strip_comments($text)
		{
			$found_slash = 0; // Флаг информирующий нахождение символа '/'
			$finded_quote = 0; // Флаг информирующий нахождение одинарной ковычки
			$finded_doble_quote = 0; // Флаг информирующий нахождение двойной ковычки
			$comment_opened = 0; // Флаг информирующий нахождение позиции открытия коментария
			$one_string_comment_opened = 0; // Флаг информирующий нахождение позиции открытия однострочного коментария
			$start_comment = 0; // Адрес начала найденного комментария
			$length = strlen($text); // Длинна всего текста
			
			for($p = 0; $p < $length; $p++) // Перебираем текст по символьно
			{
				switch($text[$p]) // Анализ символа
				{
					case '/':
						if($finded_quote || $finded_doble_quote) // Если этот символ встретился внутри одинарной или двойной ковычки
							break;
							
						if($found_star && $comment_opened && !$one_string_comment_opened) // Если предыдущий символ * а следующий / и было найдено начало комментария, то значит это конец этого комментария
						{
							$end_comment = $p + 1; // конец комментария
							$found_star = 0; // опускаем флаг нахождения *
							$comment_opened = 0; // Закрываем режим комментария
							
							// Удаляем комментарий
							$before = substr($text, 0, $start_comment); 
							$after = substr($text, $end_comment, $length);
							$text = $before . $after; 
							$length -= $end_comment - $start_comment; //Уменьшаем длинну текста на длинну вырезанного комментария
							$p -= $end_comment - $start_comment; // Уменьшаем позицию указателя на длинну вырезанного комментария
							$found_slash = 0;
							break;
						}
						
						if($found_slash && !$one_string_comment_opened)// Если предыдущий символ / и следующий / значит найден однострочный комментарий
						{
							$one_string_comment_opened = 1;
							$found_slash = 0;
							$start_comment = $p - 1; // Сохраняем поцицию начала комментария
							break;
						}
						
						$found_slash = 1;
					break;
					
					case '*':
						if($finded_quote || $finded_doble_quote) // Если этот символ встретился внутри одинарной или двойной ковычки
							break;
						
						if($found_slash && !$comment_opened && !$one_string_comment_opened) // Если предыдущий символ / а следующий * и найдено это не в нутри комментария, то значит это начало нового комментария
						{
							$start_comment = $p - 1; // Сохраняем поцицию начала комментария
							$found_slash = 0; // опускаем флаг нахождения /
							$comment_opened = 1; // Открываем режим комментария
						}
						else // Если предыдущий символ не '/' тогда устанавливаем флаг нахождения символа '*'
							$found_star = 1;
					break;
					
					case "'": // если найдена одинарная ковычка
						if($finded_doble_quote) // Если одинарная ковычка найденна внутри двойных
							break;
						
						if($comment_opened || $one_string_comment_opened)
							break;
						
						$finded_quote = !$finded_quote;
					break;
					
					case '"': // если найдена двойная ковычка
						if($finded_quote) // Если двойная ковычка найденна внутри одинарноых
							break;

						if($comment_opened || $one_string_comment_opened)
							break;
							
						$finded_doble_quote = !$finded_doble_quote;
					break;
					
					case "\n": // Если найден конец строки
						if(!$one_string_comment_opened) // Если небыло найденно однострочного комметария, то игнорируем этот символ
							break;
						
						$end_comment = $p + 1; // конец однострочного комментария
						
						// Удаляем комментарий
						$before = substr($text, 0, $start_comment); 
						$after = substr($text, $end_comment, $length);
						$text = $before . $after; 
						$length -= $end_comment - $start_comment; //Уменьшаем длинну текста на длинну вырезанного комментария
						$p -= $end_comment - $start_comment; // Уменьшаем позицию указателя на длинну вырезанного комментария
						$one_string_comment_opened = 0;
					break;
					
					default: // В случае находждения любого отличного символа, опускаем флаги нахождения слэша или звездочки
						$found_slash = 0;
						$found_star = 0;
				}
			}
			
			return $text;
		}		
		
		
		/**
			Функция формирует имена блоков в компилируемых шаблонах
			@param $block_name - название блока
			@return префикс для названия функций и массивов в компилируемом шаблоне
		*/
		private function get_tpl_compile_block_name($block_name)
		{
			return 'cb_' . $block_name;
		}
		
		
		/**
			Функция рекурсивно компилирует шаблон
			@param $block_text - Исходный текст шаблона
			@return скомпилированный PHP код шаблона
		*/
		private function tpl_compile_blocks($block_text, $current_block_name = 'root')
		{
			$block_code = '';
			
			$blocks = $this -> find_blocks('BLOCK', $block_text);
			foreach($blocks as $block) // Перебираем все блоки
			{
				foreach($block as $block_name => $block_data);
				$list_blocks[$block_name] = $block_data; // Сохроняем содержимое найденного блока
				$block_text = $this -> replace_block("BLOCK", $block_text, $block_name, '" . $' . $this -> get_tpl_compile_block_name($block_name) . ' . "'); // В результирующем контенте удаляем содержимое блоков и всмето них временно ставим блоковые метки в формате <<-имя_блока->>
			}
			
			$block_text = preg_replace("/{(\w+)}/Us", "\". \$b_" . $current_block_name . "['$1'] .\"", $block_text);
			$block_text = str_replace("\r", '\r', $block_text);
			$block_text = str_replace("\n", '\n', $block_text);
			$block_text = '$' . $this -> get_tpl_compile_block_name($current_block_name) .'.="' . $block_text . '";' . "\n";
			
			if($list_blocks)
				foreach($list_blocks as $block_name => $block_data)
				{
					$compile_block = '';
					
					$compile_block = $this -> tpl_compile_blocks($block_data, $block_name);

					$compile_code = "\$" . $this -> get_tpl_compile_block_name($block_name) . "='';\n";
					$compile_code .= "if(\$b_" . $current_block_name . "['<b>']['" . $block_name . "'])\n";
					$compile_code .= "foreach(\$b_" . $current_block_name . "['<b>']['" . $block_name . "'] as \$b_" . $block_name . "){\n";
					$compile_code .= $compile_block;
					$compile_code .= "}\n";
					$block_text = $compile_code . $block_text;
				}
			
			
			return $block_text;
		}
		
		
		/**
			Функция генерирует дерево блоков. Это нужно чтобы функция assign для генерации дерева данных могла ориентироваться в дереве блоков
			@param $block_text - текст шаблона
			@return дерево блоков в формате (имя_блока => массив с бодблоками)
		*/
		private function tpl_create_block_tree($block_text)
		{
			$list_blocks_tree = array();
			
			$blocks = $this -> find_blocks('BLOCK', $block_text);
			foreach($blocks as $block) // Перебираем все блоки
			{
				foreach($block as $block_name => $block_data);
				$list_blocks_tree[$block_name] = $this -> tpl_create_block_tree($block_data);
			}
			
			return $list_blocks_tree;
		}
		
		
		/**
			Функция компилирует массив как текст.
			@param $arr - ассоциативный массив
			@return PHP код формирующий данный массив
		*/
		private function array_compile($arr)
		{
			foreach($arr as $key => $val)
				$str .= "'" . $key . "'=>array(" . $this -> array_compile($val) . "),";
			
			return $str;
		}
		
		
		/**
			Функция компилирует шаблон и сохраняет его в файл $filename с расширением php
			@param $tpl_content - текст шаблона
			@param $filename - имя файла шаблона
		*/
		private function tpl_compile_teamplate($tpl_content, $filename)
		{
			// Формируем дерево блоков в шаблоне
			$tree['root'] = $this -> tpl_create_block_tree($tpl_content);
			
			// Формируем PHP код дерева блоков в шаблона
			$tpl_tree = "if(!\$run_teamplate)\n\t\$this->compiled_struct_tree=array(" . $this -> array_compile($tree) . ');';
			
			// Экранируем символы \ $ "
			$tpl_content = str_replace('\\', '\\\\', $tpl_content);
			$tpl_content = str_replace('$', '\\$', $tpl_content);
			$tpl_content = str_replace('"', '\\"', $tpl_content);
			
			// Компилируем текст шаблона $buffer
			$compile_code = $this -> tpl_compile_blocks($tpl_content);
			
			// Формируем содержимое файла шаблона
			$compile_code = "<?php\n" . $tpl_tree . "\n\nif(\$run_teamplate){\n\$" . $this -> get_tpl_compile_block_name('root') . "='';\n" . $compile_code . "}\n?>";
			
			// Сохраняем файл в каталог .compiled
			$path = $this -> get_file_path($filename);
			$file = $this -> get_file_name($filename);
			
			@mkdir($path . '.compiled/');
			file_put_contents($path . '.compiled/' . $file . '.php', $compile_code);
		}


		/**
			Получить список дочерних блоков из структуры дерева блоков (используется в режими компиляции)
			@param $parent_block - родительский блок
			@param $tree - Дерево блоков
		*/
		function get_children_blocks_by_tree($parent_block, $tree)
		{
			foreach($tree as $block_name => $sub_block_list)
			{
				$list = $this -> get_children_blocks_by_tree($parent_block, $sub_block_list); // рекурсивно ишем заданный блок в подблоке
				if($list)
					return $list;
					
				if ($block_name == $parent_block)
				{
					foreach($sub_block_list as $sub_block_name => $sub_block_array) // формируем список дочерних блоков
						$list[] = $sub_block_name;
						
					return $list;
				}
			}
			return false;
		}
		
		
		/**
			Добавить данные блока в дерево блоков (используется в режими компиляции)
			@param $stack - путь к родительскому блоку
			@param $data - данные которые надо добавить в дерево блоков
		*/
		function add_node_to_assign_tree($stack, $data)
		{
			// Извлекаем последний блок из списка, этот блок является точкой назначения и потому для него особый алгоритм
			$last_block = array_pop($stack);
			
			// Указатель $p в цикле будет двигаться по дереву до родительского блока (не включая посл)
			$p = &$this -> assign_tree;
			foreach($stack as $item)
			{
				$count_blocks = count($p['<b>'][$item]);
				$p = &$p['<b>'][$item][$count_blocks ? ($count_blocks - 1) : 0];
			}
			
			// После того как добрались до родительского элемента, то добавляем еще одну копию дочернего с данными $data
			$count_blocks = count($p['<b>'][$last_block]);
			$p['<b>'][$last_block][$count_blocks] = $data;
		}


		/**
			Конструктор данного класса
			@param $filename - Имя файла шаблона
			@param $default_marks - Массив меток с их значениями
		*/
		function tpl($filename = '', $default_marks = array(), $enable_compile = TPL_INTERPRETER_MODE, $debug = '', $enable_comments = 1)
		{
            $this->enable_comments = $enable_comments;
            
			if($filename)
				$this -> open($filename, $default_marks, $enable_compile, $debug);

			$this -> assign_stack[] = 'root';
		}


		/**
			Загрузить шаблон с буфера, а также возвращает список блоков и их содержимое
			@param $buffer - текст шаблона
			@param $tpl_path - Внутренний параметр нужен для рекурсии. Путь к файлу шаблона.
			@param $do_not_seacrh_insert_blocks - Внутренний параметр нужен для рекурсии. Флаг запрещает анализировать INSERT блоки
			@return возвращает список блоков и их содержимое
		*/
		function open_buffer($tpl_content)
		{
			if($this -> enable_compile)
			{
				echo 'Error: open_buffer() is not work in compiled mode';
				exit;
			}
			
            if ($this->enable_comments)
                $tpl_content = $this -> strip_comments($tpl_content); // чистим от коментов вида /* */ и //
			
			$this -> result_content = $this -> source_content = $tpl_content; // Сохраняем шаблон для дальнейшей обработки
			
			$blocks = $this -> find_blocks('BLOCK', $this -> result_content); // Заменяем все блоки на временные позывные метки
			foreach($blocks as $block) // Перебираем все блоки
			{
				foreach($block as $block_name => $block_data);
				$list_blocks[$block_name] = $block_data; // Сохроняем содержимое найденного блока
				$this -> result_content = $this -> replace_block("BLOCK", $this -> result_content, $block_name, '<<-' . $block_name . '->>'); // В результирующем контенте удаляем содержимое блоков и всмето них временно ставим блоковые метки в формате <<-имя_блока->>
			}
			
			return $list_blocks; // Возвращаем список блоков с их содержимым
		}
		
		
		/**
			Загрузить шаблон из файла
			@param $filename - путь и имя файла шаблона
			@param $default_marks - метки по умолчанию
			@param $enable_compile - Флаг разрешения компиляции шаблонов
			@return возвращает список блоков и их содержимое
		*/
		function open($filename, $default_marks = array(), $enable_compile = TPL_INTERPRETER_MODE, $debug = '')
		{
			if(!file_exists($filename))
			{
				echo 'Error: Teamplate "' . $filename . '" not exist';
				exit();
			}

			$this -> debug = $debug;

			if($default_marks)
				$this -> set_default_marks($default_marks);

			$path = $this -> get_file_path($filename);
			$file = $this -> get_file_name($filename);
			
			$this -> enable_compile = $enable_compile; // Сохраняем флаг компиляции шаблонов
			
			// Если компиляция разрешена
			if($enable_compile)
			{
				// Если компилируемый файл отсутсвует или присутствует файл debug или установлен режим дебага то компилируем шаблон
				if((!file_exists($path . '.compiled/' . $file . '.php')) || file_exists($path . 'debug') || $this -> debug)
				{
					// Разворачиваем INSERT блоки и подгружаем вместо них шаблоны
					$tpl_content = file_get_contents($filename);
					$tpl_content = $this -> do_insert_blocks($tpl_content, $this -> get_file_path($filename));
                    if ($this->enable_comments)
                        $tpl_content = $this -> strip_comments($tpl_content);
					
					// Удаляем лишние пробелы и табы
					$tpl_content = preg_replace('/( +)/', ' ', $tpl_content);
					$tpl_content = preg_replace('/(	+)/', '	', $tpl_content);
					$tpl_content = str_replace(' 	', ' ', $tpl_content);
					$tpl_content = str_replace('	 ', ' ', $tpl_content);
					
					// Компилируем шаблон
					$this -> tpl_compile_teamplate($tpl_content, $filename);
				}
				
				$run_teamplate = 0; // Опускаем флаг разрешающий запуск шаблона
				require($path . '.compiled/' . $file . '.php'); // Подгружаем шаблон
				$this -> tpl_filename = $filename; // Сохраняем имя файла шаблона, для последующей подгрузки шаблона в методе make_result()
			}
			else // Если компиляция запрещена
			{
				// Разворачиваем INSERT блоки и подгружаем вместо них шаблоны
				$tpl_content = file_get_contents($filename);
				$tpl_content = $this -> do_insert_blocks($tpl_content, $this -> get_file_path($filename));

				// Загружаем некомпилированный шаблон
				return $this -> open_buffer($tpl_content);
			}
		}

	
		/**
			Получить исходное содержимое блока
			@param $block_name - Имя блока
			@return содержимое блока
		*/
		function load_block($block_name)
		{
			if(!$block_name)
				return;
			
			return $this -> find_block('BLOCK', $block_name, $this -> source_content);
		}

		
		/**
			Заполнить указанный блок данными
			@param $block_name - Имя блока
			@param $data - список меток и их значения
		*/
		function assign($assign_block, $data)
		{
			if (!is_array($data))
				$data = array();
				
			if ($this -> default_marks_val) // добавляем метки по умолчанию и их значения. 
			{
				foreach($this -> default_marks_val as $mark => $val) 
					if(!isset($data[$mark])) // Если такая метка ранее небыла определена
						$data[$mark] = $val;
			}

			// Если включен режим компиляции, то дополняем дерево данных шаблона данными $data
			if ($this -> enable_compile)
			{
				// Получаем текущий блок в который производится асайн
				$curr_block = array_pop($this -> assign_stack); 
				array_push($this -> assign_stack, $curr_block);
				
				if ($assign_block == $curr_block) // Если асайним в тотже блок, куда асайнили в предыдущий раз
				{
					$this -> add_node_to_assign_tree($this -> assign_stack, $data); // Добавляем данные в дерево
					return;
				}
				
				// Извлекаем из стека блоки (идем назад) и на каждом уровне ищем дочерний блок соответсвующий $assign_block
				$stack = $this -> assign_stack; // Делаем копию стека на случай если имя блока окажется несуществующим
				while ($parent_block = array_pop($stack))
				{
					$children_blocks = $this -> get_children_blocks_by_tree($parent_block, $this -> compiled_struct_tree); // Для каждого блока parent_block в стеке получаем список дочерних блоков
					if($children_blocks) // Если дочерних блоков не найденно, то явно что чтото нетак
					{
						$key = array_search($assign_block, $children_blocks);
						if ($key !== FALSE) // Если блок $assign_block найден в списке дочерних блоков
							{
								array_push($stack, $parent_block);
								$this -> assign_stack = $stack;
								array_push($this -> assign_stack, $assign_block); // Добавляем в стек текущий блок
								$this -> add_node_to_assign_tree($this -> assign_stack, $data); // Добавляем данные в дерево
								return;
							}
					}
				}
					
				$this -> assign_stack = $stack; // Если неудалось найти нужный блок, то восстанавливаем стек и игнорим этот блок
				return;
			}
		
			// Если режим компиляции выключен, то выполняем весь остальной код

			if($assign_block) // Если указан блок, то загружаем его
				$content = $this -> load_block($assign_block);
			else // Иначе загружаем весь контент
				$content = $this -> source_content;
			
			while(preg_match("/<!--[ ]*START[ ]+BLOCK[ ]*:[ ]*([a-zA-Z0-9_\.\/]+)[ ]*-->/s", $content, $matches)) // Если в загруженном шаблоне встретились блоки
			{ // То в результирующем шаблоне делаем замену блоковых меток на реальные данные блоков встреченных загруженном шаблоне
				$content = $this -> replace_block("BLOCK", $content, $matches[1], '<<-' . $matches[1] . '->>'); // В результирующем контенте удаляем содержимое блоков и всмето них временно ставим блоковые метки в формате <<-имя_блока->>
				$this -> result_content = str_replace('<<-'.$matches[1].'->>', "", $this -> result_content);
			}
			
			if(is_array($data)) // Если есть что заасайнить, то асайним
				foreach($data as $key => $value)
					$content = str_replace('{'.$key.'}', $value, $content);

			if($assign_block) // Если асайнится в конкректный блок
			{
				$this -> result_content = str_replace('<<-'.$assign_block.'->>', $content . '<<-' . $assign_block . '->>', $this -> result_content); // Вставляем перед блоковой меткой заполненный код шаблона
			}
			else // если блок является корневым, то обновляем source_content. Это нужно для того чтобы в корневой блок можно было асайнить много раз подряд
			{
				$this -> result_content = $content; 
				
				preg_match_all("/<<-(.*)->>/Us", $content, $extract); // ищем все позывные метки блоков и меняем их на реальные блоки
				$list_blocks = $extract[1];
				foreach($list_blocks as $block_name) // перебираем все имена позывных блоков и вместо них вставляем реальные блоки
				{
					$block_src = $this -> find_block('BLOCK', $block_name, $this -> source_content);
					$content = preg_replace("/(<<-" . $block_name . "->>)/Us", '<!-- START BLOCK : ' . $block_name . ' -->' . $block_src . '<!-- END BLOCK : ' . $block_name . ' -->', $content);
				}
				// Получили из result_content - source_content и сохраняем его
				$this -> source_content = $content; 
			}
		}
		
		
		/**
			Осуществить рекурсивный ассайн дерева блоков с метками.
			@param $assign_data - Дерево блоков с метками
		*/
		function assign_array($assign_data)
		{
			if($assign_data['block']) // Если есть блоки, то ассайним их
			{
				foreach($assign_data['block'] as $assign_block_data)
				{
					foreach($assign_block_data as $block_name => $block_data); // Получаем имя блока и его содержимое
					$this -> assign($block_name, $block_data['marks']);
					if($block_data['block'])
						$this -> assign_array($block_data); // Вызываем себя для внутреннего блока
				}
			}
		}
		
		
		/**
			Получить заполненный шаблон
			@return результирующий текст сформированный из шаблонов и данных
		*/
		function make_result()
		{
			// Если включен режим компиляции, то запускаем формирование данных из компилированного шаблона
			if($this -> enable_compile)
			{
				$path = $this -> get_file_path($this -> tpl_filename);
				$file = $this -> get_file_name($this -> tpl_filename);

				$b_root = $this -> assign_tree['<b>']['root'][0];
				$run_teamplate = 1; // Устанавливаем режим запуска шаблона
				require($path . '.compiled/' . $file . '.php'); // Подгружаем шаблон
				return $cb_root;
			}
			
			// Если Выключен режим компиляции, то обрабатываем шаблон в режиме интерпретации
			$this -> result_content = preg_replace("/(<<-.*->>)/Us", "", $this -> result_content); // Делаем чистку от ненужных блоковых меток
			$this -> result_content = preg_replace("/({\w+})/Us", "", $this -> result_content); // чистку от простых меток
			$this -> result_content = preg_replace("/(<!--.*->)/Us", "", $this -> result_content); // чистим от HTML коментариев
			return $this -> result_content;
		}
		
		/**
			Установить метки поумолчанию.
			Эти метки будут добавленны во все блоки в которые будет вызываться assign
			@param $data - список меток и их значений
		*/
		function set_default_marks($data)
		{
			$this -> default_marks_val = $data;
		}
	}

?>
