Yet Another Volod's PHP Library

Простой фреймворк для простых проектов (1 человек или до 100000 строк кода)

Все подробности смотреть в заголовках файлов.

Я писал этот преймворк из своего опыта и своих потребностей.
Он закрывает ВСЕ мои потребности при минимальных размерах.
Мне нужен был стабильный (на 20+ лет) фреймворк и он развивается вместе с моими проектами с 2009 года.
Обучить кого-то этому фреймворку при необходимости - полдня в первом приближении.

Ядро для реализации MVC - Application/Controller/Model/View. 

Классы для СУБД/Почты/BasicUser/Документы самостоятельные модели.

Я не люблю декларативное программирование, лучше написать 3 строчки императивного кода, чем одно заклинание, 
к которому надо помнить целую страницу документации. Память программиста не безгранична, и если хочется 
писать и непрерывно изменять сравнительно крупные проекты, то минимум документации становится естественным.

Я склонен к процедурному стилю программирования и всё использование ООП в проектах, как правило, сводится 
к варианту реализации пространств имён.