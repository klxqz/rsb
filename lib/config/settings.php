<?php
return array(

    'pemFile'    => array(
        'value'        => '',
        'title'        => '*.pem файл',
        'description'  => 'Файл сертификата выдаваемый банком, используется для авторизации по протоколу ssl',
        'control_type' => waHtmlControl::FILE,
    ),
    'keyFile'    => array(
        'value'        => '',
        'title'        => '*.key файл',
        'description'  => 'Файл сертификата выдаваемый банком, используется для авторизации по протоколу ssl',
        'control_type' => waHtmlControl::FILE,
    ),
    'crtFile'    => array(
        'value'        => '',
        'title'        => '*.crt файл',
        'description'  => 'Файл сертификата выдаваемый банком, используется для авторизации по протоколу ssl',
        'control_type' => waHtmlControl::FILE,
    ),
    'sandbox'  => array(
        'value'        => '1',
        'title'        => 'Тестовый режим',
        'description'  => '',
        'control_type' => 'checkbox',
    ),

);
