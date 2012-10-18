<?php
/*
	Функции для работы с XML
	ver1.3
*/

// Экранирование служебных символов XML
function shielding_xml_chars($text)
{
    $text = str_replace('&', "&amp;", $text);
    $text = str_replace('"', "&quot;", $text);
    $text = str_replace('>', "&gt;", $text);
    $text = str_replace('<', "&lt;", $text);
    $text = str_replace("'", "&apos;", $text);
    return $text;
}

// Функция формирует XML
// $data - древовидный массив с XML данными
// $depth - 1 или 0, 1 - включено добивание табами, 0 - отключенно
// $header - Заголовок XML
function create_xml($data, $depth = 1, $header = "<?xml version=\"1.0\" encoding=\"windows-1251\" ?>\n") //Генератор XML на основе древовоидного массива
{
    $str = $header;

    foreach ($data as $key => $value)
    {
        $key = preg_replace("/%.*/", "", $key); // Удаляем % с последующими символами
        $key = shielding_xml_chars($key);

        if (is_array($value)) // Если тег внутри содержите еще теги
        {
            $str_attr = '';
            if ($value['%attr%']) // Если наткнулись на ключевое слово '%attr%', значит массив внутри - это атрибуты тега а ключ %val% это значение тега
            {
                $found_value = '';
                $str_attr = '';
                foreach ($value['%attr%'] as $attr_name => $attr_val) // Перебираем все артрибуты тега
                {
                    if ($attr_name == '%val%')
                    {
                        $found_value = $attr_val; // если встретился ключ '%val%' то сохраняем кго значение как значение тега
                        continue;
                    }
                    $str_attr .= ' ' . $attr_name . '="' . $attr_val . '"';
                }

                $value = $found_value;
            }

            for ($i = 0; $i < $depth - 1; $i++) // Добиваем табы
                $str .= "	";
            $str .= '<' . $key . $str_attr . ">";
            if ($depth) $str .= "\n";

            $open_cdata = 0;
            if (is_array($value) && $value['%cdata%']) //Это означает что внутрь вставляются данные CDATA
            {
                $value = $value['%cdata%'];
                $str .= '<![CDATA[';
                $open_cdata = 1; // Выставляем флаг, открытия секции CDATA
            }

            if (is_array($value)) // Если значение это массив
                $str .= create_xml($value, $depth ? $depth + 1 : 0, ''); // то парсим его тоже
            else // Если значение тега это просто есго содержимое
            {
                $value = shielding_xml_chars($value);

                for ($i = 0; $i < $depth; $i++) // Добиваем табы
                    $str .= "	";
                $str .= $value; // иначе просто вписываем значение атрибута
                if ($depth) $str .= "\n";
            }

            if ($open_cdata) // Если ранее был выставлен флаг открытия секции CDATA
                $str .= ']]>';

            for ($i = 0; $i < $depth - 1; $i++) // Добиваем табы
                $str .= "	";
            $str .= '</' . $key . ">";
            if ($depth) $str .= "\n";
        }
        else // Если тег внутри НЕ содержите теги
        {
            for ($i = 0; $i < $depth - 1; $i++) // Добиваем табы
                $str .= "	";
            $value = shielding_xml_chars($value);
            $str .= '<' . $key . '>' . $value . '</' . $key . ">";
            if ($depth) $str .= "\n";
        }
    }

    return $str;
}


function parse_xml($xml) // Функция конвертирует XML в массив
{
    function _struct_to_array($values, &$i)
    {
        //dump($values);
        $child = array();
        if (isset($values[$i]['value'])) array_push($child, $values[$i]['value']);

        while ($i++ < (count($values) - 1))
        {
            switch ($values[$i]['type'])
            {
                case 'cdata':
                    array_push($child, $values[$i]['value']);
                    break;

                case 'complete':
                    //	dump($values[$i]);
                    $name = $values[$i]['tag'];
                    if (!empty($name))
                    {
                        $data['content'] = trim(($values[$i]['value']) ? ($values[$i]['value']) : '');
                        if (isset($values[$i]['attributes'])) $data['attr'] = $values[$i]['attributes'];
                        $child[$name][] = $data;
                    }
                    break;

                case 'open':
                    $name = $values[$i]['tag'];
                    $size = isset($child[$name]) ? sizeof($child[$name]) : 0;
                    if (isset($values[$i]['attributes'])) $child[$name][$size]['attr'] = $values[$i]['attributes'];
                    $child[$name][$size]['content'] = _struct_to_array($values, $i);
                    break;

                case 'close':
                    return $child;
            }
        }
        return $child;
    }

    $values = array();
    $index = array();
    $array = array();
    $parser = xml_parser_create();
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parse_into_struct($parser, $xml, $values, $index);
    xml_parser_free($parser);
    $i = 0;
    $name = $values[$i]['tag'];

    if (isset($values[$i]['attributes'])) $array[$name]['attributes'] = $values[$i]['attributes'];

    $array[$name]['content'] = _struct_to_array($values, $i);
    return $array;
}

?>